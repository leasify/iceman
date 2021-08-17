<?php

namespace App\Commands;

use App\Helpers\Environment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use LaravelZero\Framework\Commands\Command;

class Deploy extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'feature {branch : the full git AP-feature branch}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Deploy feature site to forge';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $feature = null;
        $ap = null;
        $serverId = 0;
        $siteId = 0;
        $shortUnique = "AP" . uniqid();

        Environment::make();

        /*
        * --feature flag required to get the full git branch
        */
        $feature = $this->argument('branch');
        preg_match('#^feature\/(AP\-\d+).*#i', $feature, $matches);
        if (is_array($matches) && isset($matches[1])) {
            $ap = $matches[1];
            $shortUnique = "AP" . $matches[1];
        }

        if (!$feature || !$ap) {
            $this->error("<error>Deploy feature site requires feature branch with flag --feature=feature/AP-NNNN-name-and-stuff, given</error>");
            return;
        }

        /*
        * Get env FORGE_API key installed for the API
        */
        $this->info("<info>Logging in to Laravel Forge</info>");
        $apiKey = env("FORGE_API");

        if(!$apiKey) {
            $this->error("Environment key FORGE_API is missing");
            return;
        }

        /*
        * Get the server ID
        */
        $raw = $this->forgeAPI("/api/v1/servers", [], "GET");
        $servers = collect($raw->servers);
        $server = $servers->where('name', 'leasify-dev-1-server')->first();
        $serverId = $server ? $server->id : 0;
        $this->info("<info>Fetching server data, ID = {$serverId}</info>");

        /*
        * Get SiteId or 0 if not exists
        */
        $siteDomain = strtolower($ap) . ".leasify.dev";
        $this->info("<info>Ensures the site {$siteDomain}</info>");
        $raw = $this->forgeAPI("/api/v1/servers/{$serverId}/sites", [], "GET");
        $sites = collect($raw->sites);
        $site = $sites->where('name', $siteDomain)->first();
        $siteId = $site ? $site->id : 0;

        /*
        * Create site if not exists
        */
        if (!$siteId) {

            // Make site
            $this->info("<info>Creating missing site {$siteDomain}</info>");
            $raw = $this->forgeAPI("/api/v1/servers/{$serverId}/sites", [
                "domain" => $siteDomain,
                "project_type" => "php",
                "php_version" => "php80",
                "directory" => "/public",
            ]);
            $siteId = $raw->site->id;

            // Attach SSL
            $this->info("<info>Creating SSL for {$siteDomain}</info>");
            $authorization = "Authorization: Bearer {$apiKey}";
            $rawMakeCert = $this->forgeAPI("/api/v1/servers/{$serverId}/sites/{$siteId}/certificates/letsencrypt", ['domains' => [$siteDomain]]);

            // Make Git application
            $this->info("<info>Creating a git project for branch {$feature}</info>");
            $this->forgeAPI("/api/v1/servers/{$serverId}/sites/{$siteId}/git", [
                "provider" => "bitbucket",
                "repository" => "leasifyab/leasifyse",
                "branch" => $feature,
                "composer" => true
            ]);

            // waiting max 300s for the git / repo project to be created
            for ($i = 0; $i < 30; $i++) {
                $this->info("<info>Waiting 10 more seconds for the git app to be installed in {$siteDomain}...</info>");
                sleep(10);
                $raw = $this->forgeAPI("/api/v1/servers/{$serverId}/sites/{$siteId}", [], "GET");
                $site = $raw->site;
                if ($site->repository_status == "installed") break;
            }

            // Update deployment script inside Laravel Forge
            $this->info("<info>Updating the deployment script</info>");
            $this->forgeAPI("/api/v1/servers/{$serverId}/sites/{$siteId}/deployment/script", [
                "content" => $this->forgeDeployScript($siteDomain, $feature, $shortUnique),
            ], "PUT");

            // Attach a worker
            $this->info("<info>Attaching daemon to the site</info>");
            $this->forgeAPI("/api/v1/servers/{$serverId}/daemons", [
                "command" => "php7.4 artisan horizon",
                "user" => "forge",
                "directory" => "/home/forge/{$siteDomain}",
            ], "POST");
        }

        // Send signal to Laravel Forge, update the code and run migrate!
        $this->info("<info>Sending deployment update to server {$serverId} and site {$siteId}</info>");
        $this->forgeAPI("/api/v1/servers/{$serverId}/sites/{$siteId}/deployment/deploy", []);

        $sitesToRemove = new Collection;

        // Get old AP-Sites
        $oldSitesToRemove = $sites->filter(function ($site) {
            return strpos($site->name, "ap-") === 0 &&
                $site->created_at < date('Y-m-d H:i:s', strtotime("-30 days"));
        });
        $sitesToRemove = $sitesToRemove->merge($oldSitesToRemove);

        // get actual branches from git
        $branchResult = shell_exec('git fetch && git branch -r');

        // Get old AP-Sites
        $closedBranchSites = $sites->filter(function ($site) use ($branchResult) {
            if (strpos($site->name, 'ap-') !== 0) return false;
            $position = strpos($branchResult, $site->repository_branch);
            return $position === false;
        });
        $sitesToRemove = $sitesToRemove->merge($closedBranchSites);

        // Remove old AP-sites
        if ($sitesToRemove) {

            $daemonRequest = $this->forgeAPI("/api/v1/servers/{$serverId}/daemons", [], "GET");
            $daemons = $daemonRequest->daemons;

            foreach ($sitesToRemove as $site) {
                $this->info("<info>Removing site {$site->name} due to age or closed branch</info>");
                $this->forgeAPI("/api/v1/servers/{$serverId}/sites/{$site->id}", [], "DELETE");

                foreach ($daemons as $daemon) {
                    if (strpos($daemon->directory, $site->name)) {
                        $this->info("<info>Removing daemon for {$daemon->directory}</info>");
                        $this->forgeAPI("/api/v1/servers/{$serverId}/daemons/{$daemon->id}", [], "DELETE");
                    }
                }
            }
        }
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    /*
* API Helper for Laravel Forge
*/
    public function forgeAPI(string $url, array $payload, $method = "POST")
    {
        $apiKey = getenv("FORGE_API");
        $authorization = "Authorization: Bearer {$apiKey}";
        $payload = json_encode($payload);
        //writeln("<info>Calling ForgeAPI: '{$url}' with payload '{$payload}'</info>");
        $ch = curl_init("https://forge.laravel.com{$url}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', $authorization]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $result = curl_exec($ch);
        curl_close($ch);
        //writeln("<info>Getting json result from ForgeAPI: '{$result}'</info>");
        return json_decode($result);
    }

    /*
* Get deployment script from string and attached variables
*/
    public function forgeDeployScript($siteDomain, $feature, $shortUnique)
    {
        $date = date('Y-m-d H:i:s');
        return <<<EOT
# deployment script from feature-site generated {$date}
cd /home/forge/{$siteDomain}
\$FORGE_PHP artisan down
git reset --hard HEAD
git pull origin {$feature}
\$FORGE_COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs
cp -f .env.feature .env
sed -i 's/ap-feature.leasify.dev/{$siteDomain}/g' .env
sed -i 's/AP_HORIZON_PREFIX/{$shortUnique}/g' .env
( flock -w 10 9 || exit 1
echo 'Restarting FPM...'; sudo -S service \$FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
if [ ! -e database/database.sqlite ]
then
    touch database/database.sqlite
    \$FORGE_PHP artisan migrate:fresh --seed
else
    \$FORGE_PHP artisan migrate
fi
\$FORGE_PHP artisan view:clear
\$FORGE_PHP artisan cache:clear
npm i && npm run dev
rm -f public/index.html
\$FORGE_PHP artisan up
\$FORGE_PHP artisan horizon:terminate
EOT;
    }
}
