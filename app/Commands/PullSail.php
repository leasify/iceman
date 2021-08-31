<?php

namespace App\Commands;

use App\Helpers\Environment;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class PullSail extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'pull:sail {environment : Environment to fetch, eg prod}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'For a Laravel Sail developer environment, fetches postgres and local files to your local dev env';

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
        $localDBUsername = env("DB_USERNAME");

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
            "vendor/bin/sail artisan db:wipe --drop-types",
            "cat db.sql | docker exec -i leasifyse_pgsql_1 psql -U {$localDBUsername} {$localDB}",
            "rm db.sql",
        ];

        if($db == 'production') {
            $actions[] = "vendor/bin/sail psql -d {$localDB} -c \"UPDATE users SET email=concat(email,'.cc');\"";
        }

        $actions[] = "vendor/bin/sail artisan cache:clear";
        $actions[] = "rsync -av {$host}:{$path}/shared/public/logos public";

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
