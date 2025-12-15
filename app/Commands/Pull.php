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

            if ($this->option('fresh') || !file_exists("/tmp/{$localDB}-full.tar.gz")) {
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

            foreach ($actions as $action) {
                $this->info($action);
                $result = shell_exec($action);
                if ($result) {
                    $this->line($result);
                }
            }
        } else {
            // Parallel restore with pg_restore
            $this->parallelRestore($localDB);
        }

        if ($db == 'production') {
            $this->info("Updating emails for safety...");
            shell_exec("psql -d {$localDB} -c \"UPDATE users SET email=concat(email,'.cc');\"");
        }

        $this->info("Clearing cache...");
        shell_exec("php artisan cache:clear");

        $this->info("Done!");
    }

    /**
     * Full database dump using pg_dump with parallel jobs (fastest method)
     */
    protected function fullDatabaseDump(string $host, string $db, string $localDB): void
    {
        $outputFile = "/tmp/{$localDB}-full.tar.gz";
        $remoteDir = "/tmp/{$db}-dump-dir";
        @unlink($outputFile);

        // Detect number of CPU cores on remote server
        $cores = (int) trim(shell_exec("ssh {$host} -o \"StrictHostKeyChecking no\" 'nproc' 2>/dev/null")) ?: 4;
        $jobs = min($cores, 8); // Cap at 8 jobs

        $this->info("Parallel dump with {$jobs} jobs (directory format)...");

        $startTime = microtime(true);

        // Step 1: Parallel dump to directory on remote server
        $this->info("Step 1/3: Dumping on remote server...");
        $dumpCmd = "ssh {$host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge bash -c \"rm -rf {$remoteDir} && /usr/bin/pg_dump -Fd -j {$jobs} -Z 0 --no-owner --no-acl -f {$remoteDir} {$db}\"' 2>&1";
        $this->runWithSpinner($dumpCmd, "Dumping");

        $dumpTime = round(microtime(true) - $startTime);
        $this->info("Remote dump complete in {$dumpTime}s");

        // Step 2: Compress and transfer
        $this->info("Step 2/3: Compressing and transferring...");
        $transferStart = microtime(true);
        $transferCmd = "ssh {$host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge tar -cf - -C /tmp {$db}-dump-dir | gzip -1' > {$outputFile}";

        $this->runWithSpinner($transferCmd, "Transferring", $outputFile);

        $size = file_exists($outputFile) ? filesize($outputFile) : 0;
        $sizeMB = round($size / 1024 / 1024, 1);
        $transferTime = round(microtime(true) - $transferStart);
        $this->info("Transfer complete: {$sizeMB}MB in {$transferTime}s");

        // Step 3: Cleanup remote
        shell_exec("ssh {$host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge rm -rf {$remoteDir}' 2>/dev/null");

        $elapsed = round(microtime(true) - $startTime);
        $this->info("Total dump time: {$elapsed}s");
    }

    /**
     * Parallel restore using pg_restore with multiple jobs
     */
    protected function parallelRestore(string $localDB): void
    {
        $tarFile = "/tmp/{$localDB}-full.tar.gz";
        $dumpDir = "/tmp/{$localDB}-dump-dir";

        // Detect local CPU cores
        $cores = (int) trim(shell_exec("nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null")) ?: 4;
        $jobs = min($cores, 8);

        $this->info("Step 3/3: Parallel restore with {$jobs} jobs...");
        $startTime = microtime(true);

        // Extract the dump directory
        $this->info("Extracting dump...");
        shell_exec("rm -rf {$dumpDir} && mkdir -p /tmp && tar -xzf {$tarFile} -C /tmp");

        // Find the actual dump directory (might have db name in path)
        $extractedDir = trim(shell_exec("ls -d /tmp/*-dump-dir 2>/dev/null | head -1")) ?: $dumpDir;

        // Wipe existing database
        $this->info("Wiping existing database...");
        shell_exec("php artisan db:wipe --drop-types 2>&1");

        // Restore with parallel jobs
        $this->info("Restoring with pg_restore -j {$jobs}...");
        $restoreCmd = "pg_restore -d {$localDB} -j {$jobs} --no-owner --no-acl {$extractedDir} 2>&1";

        $process = popen($restoreCmd, 'r');
        if ($process) {
            $spinner = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
            $i = 0;

            while (!feof($process)) {
                $line = fgets($process);
                $elapsed = round(microtime(true) - $startTime);
                $spin = $spinner[$i % count($spinner)];
                $this->output->write("\r {$spin} Restoring... {$elapsed}s  ");
                $i++;
            }

            pclose($process);
            $this->output->write("\r" . str_repeat(' ', 40) . "\r");
        }

        // Cleanup
        shell_exec("rm -rf {$extractedDir}");

        $elapsed = round(microtime(true) - $startTime);
        $this->info("Restore complete in {$elapsed}s");
    }

    /**
     * Run command with spinner progress
     */
    protected function runWithSpinner(string $cmd, string $action, ?string $watchFile = null): void
    {
        $process = popen($cmd, 'r');
        if (!$process) return;

        $spinner = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $i = 0;
        $startTime = microtime(true);

        while (!feof($process)) {
            fread($process, 1024);

            $elapsed = round(microtime(true) - $startTime);
            $spin = $spinner[$i % count($spinner)];

            if ($watchFile && file_exists($watchFile)) {
                $sizeMB = round(filesize($watchFile) / 1024 / 1024, 1);
                $speed = $elapsed > 0 ? round($sizeMB / $elapsed, 1) : 0;
                $this->output->write("\r {$spin} {$action}... {$sizeMB}MB ({$speed}MB/s) - {$elapsed}s  ");
            } else {
                $this->output->write("\r {$spin} {$action}... {$elapsed}s  ");
            }

            $i++;
            usleep(200000);
        }

        pclose($process);
        $this->output->write("\r" . str_repeat(' ', 60) . "\r");
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
