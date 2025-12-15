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
    protected $signature = 'pull {environment : Environment to fetch, eg prod} {--fresh} {--company= : Filter by company IDs (comma-separated). Without this flag, fetches entire database.}';

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

        $useSelectiveExport = $this->option('company') !== null;

        if ($useSelectiveExport) {
            // Selective export with company filtering
            $companyIds = [1, 13, 32];
            if ($extraCompanies = $this->option('company')) {
                $extras = array_filter(array_map('intval', explode(',', $extraCompanies)));
                $companyIds = array_values(array_unique(array_merge($companyIds, $extras)));
            }

            $this->info("Selective export - Company IDs: " . implode(', ', $companyIds));

            if ($this->option('fresh') || !file_exists("/tmp/{$localDB}-schema.sql.gz") || !file_exists("/tmp/{$localDB}-data.sql.gz")) {
                $this->info("Fresh database fetch...");
                $exporter = new SelectiveDatabaseExport($this, $host, $db, $localDB, $companyIds);
                $exporter->export();
            } else {
                $this->info("Using cached database files...");
            }
        } else {
            // Full database dump (faster)
            $this->info("Full database export (no company filtering)");

            if ($this->option('fresh') || !file_exists("/tmp/{$localDB}-full.sql.gz")) {
                $this->info("Dumping full database...");
                $this->fullDatabaseDump($host, $db, $localDB);
            } else {
                $this->info("Using cached database file...");
            }
        }

        // Import
        $this->info("Importing database...");

        if ($useSelectiveExport) {
            $actions = [
                "gzip -cdf /tmp/{$localDB}-schema.sql.gz > /tmp/{$localDB}-schema.sql",
                "php artisan db:wipe --drop-types",
                "cat /tmp/{$localDB}-schema.sql | psql {$localDB}",
                "gzip -cdf /tmp/{$localDB}-data.sql.gz > /tmp/{$localDB}-data.sql",
                "cat /tmp/{$localDB}-data.sql | psql {$localDB}",
                "rm /tmp/{$localDB}-schema.sql /tmp/{$localDB}-data.sql",
            ];
        } else {
            $actions = [
                "php artisan db:wipe --drop-types",
                "gzip -cdf /tmp/{$localDB}-full.sql.gz | psql {$localDB}",
            ];
        }

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

        $this->info("Done!");
    }

    /**
     * Full database dump using pg_dump (faster than selective export)
     */
    protected function fullDatabaseDump(string $host, string $db, string $localDB): void
    {
        @unlink("/tmp/{$localDB}-full.sql.gz");

        $this->info("Streaming full database dump with compression...");

        // Use pg_dump with --no-owner --no-acl for cleaner import
        // Stream through gzip for compression during transfer
        $cmd = "ssh {$host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge /usr/bin/pg_dump --no-owner --no-acl {$db} | gzip -1' > /tmp/{$localDB}-full.sql.gz";

        $startTime = microtime(true);

        // Run with passthru to show progress
        passthru($cmd, $returnCode);

        $elapsed = microtime(true) - $startTime;
        $size = file_exists("/tmp/{$localDB}-full.sql.gz") ? filesize("/tmp/{$localDB}-full.sql.gz") : 0;
        $sizeMB = round($size / 1024 / 1024, 1);

        $this->info("Dump complete: {$sizeMB}MB in " . round($elapsed) . "s");
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
