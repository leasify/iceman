<?php

namespace App\Commands;

use App\Helpers\Environment;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Pull extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'pull {environment : Environment to fetch, eg prod} {--fresh}';

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
        Environment::make();

        $environment = $this->argument('environment');
        $host = Env::matchHost($environment);
        $db = Env::matchDatabase($environment);
        $path = Env::matchPath($environment);
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

        $actions = [];

        if($this->option('fresh') || !file_exists("/tmp/{$localDB}-db.sql.gz")) {
            $this->info("Fresh database fetch for this pull...");
            $actions[] = "ssh {$host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge /usr/bin/pg_dump {$db} | gzip' > /tmp/{$localDB}-db.sql.gz";
        }

        $actions = array_merge($actions, [
            "gzip -cdf /tmp/{$localDB}-db.sql.gz > db.sql",
            "php artisan db:wipe --drop-types",
            "cat db.sql | psql {$localDB}",
            "rm db.sql",
        ]);

        if($db == 'production') {
            $actions[] = "psql -d {$localDB} -c \"UPDATE users SET email=concat(email,'.cc');\"";
        }

        $actions[] = "php artisan cache:clear";

        foreach ($actions as $action) {
            $this->info($action);
            $result = shell_exec($action);
            $this->info($result);
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
}
