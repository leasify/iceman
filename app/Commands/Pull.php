<?php

namespace App\Commands;

use Dotenv\Dotenv;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Pull extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'pull {environment : Environment to fetch, eg prod}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Fetches postgres and local files to your local dev env';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $dotenv = Dotenv::createImmutable(getcwd());
        $dotenv->load();

        $environment = $this->argument('environment');
        $host = $this->matchHost($environment);
        $db = $this->matchDatabase($environment);
        $path = $this->matchPath($environment);
        $localDB = env("DB_DATABASE");

        if (!$environment) {
            $this->error("<error>Environment name missing, eg 'prod'.</error>");
            return;
        }

        if (!$host) {
            $this->error("<error>Can´t resolve a valid host from environment `{$environment}`.</error>");
            return;
        }

        if (!$db) {
            $this->error("<error>Can´t resolve a valid database from environment `{$environment}`.</error>");
            return;
        }

        if (!$path) {
            $this->error("<error>Can´t resolve a valid path from environment `{$environment}`.</error>");
            return;
        }

        $actions = [
            "ssh {$host} -o \"StrictHostKeyChecking no\" 'sudo -i -u postgres /usr/bin/pg_dump {$db} | gzip' > db.sql.gz",
            "gzip -df db.sql.gz",
            "php artisan db:wipe --drop-types",
            "cat db.sql | psql {$localDB}",
            "rm db.sql",
        ];

        if($db == 'production') {
            $actions[] = "psql -d {$localDB} -c \"UPDATE users SET email=concat(email,'.cc');\"";
        }

        $actions[] = "php artisan cache:clear";
        $actions[] = "rsync -av {$host}:{$path}/shared/public/logos public";

        foreach ($actions as $action) {
            $this->info($action);
            $result = shell_exec($action);
            $this->info($result);
        }
    }

    public function matchHost($environment) : string {
        $result = "";
        if($environment == "prod" || $environment == "production") {
            $result = "root@c2698.cloudnet.cloud";
        }
        return $result;
    }

    public function matchDatabase($environment) : string {
        $result = "";
        if($environment == "prod" || $environment == "production") {
            $result = "production";
        }
        return $result;
    }

    public function matchPath($environment) : string {
        $result = "";
        if($environment == "prod" || $environment == "production") {
            $result = "/mnt/persist/www/docroot_production";
        }
        return $result;
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
}
