<?php

namespace App\Commands;

use App\Helpers\Environment;
use App\Helpers\SelectiveDatabaseExport;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Pull extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'pull {environment : Environment to fetch, eg prod} {--fresh} {--company= : Extra company IDs to include, comma-separated (adds to default 1,13,32)}';

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

        // Bygg company ID-lista
        $companyIds = [1, 13, 32];
        if ($extraCompanies = $this->option('company')) {
            $extras = array_filter(array_map('intval', explode(',', $extraCompanies)));
            $companyIds = array_values(array_unique(array_merge($companyIds, $extras)));
        }

        $this->info("Company IDs to fetch: " . implode(', ', $companyIds));

        // Selektiv export
        if ($this->option('fresh') || !file_exists("/tmp/{$localDB}-schema.sql.gz") || !file_exists("/tmp/{$localDB}-data.sql.gz")) {
            $this->info("Fresh database fetch for this pull...");

            $exporter = new SelectiveDatabaseExport($this, $host, $db, $localDB, $companyIds);
            $exporter->export();
        } else {
            $this->info("Using cached database files...");
        }

        // Import
        $this->info("Importing database...");
        $actions = [
            "gzip -cdf /tmp/{$localDB}-schema.sql.gz > /tmp/{$localDB}-schema.sql",
            "php artisan db:wipe --drop-types",
            "cat /tmp/{$localDB}-schema.sql | psql {$localDB}",
            "gzip -cdf /tmp/{$localDB}-data.sql.gz > /tmp/{$localDB}-data.sql",
            "cat /tmp/{$localDB}-data.sql | psql {$localDB}",
            "rm /tmp/{$localDB}-schema.sql /tmp/{$localDB}-data.sql",
        ];

        if ($db == 'production') {
            $actions[] = "psql -d {$localDB} -c \"UPDATE users SET email=concat(email,'.cc');\"";
        }

        $actions[] = "php artisan cache:clear";

        foreach ($actions as $action) {
            $this->info($action);
            $result = shell_exec($action);
            if ($result) {
                $this->line($result);
            }
        }

        $this->info("Done! Database imported with company IDs: " . implode(', ', $companyIds));
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
