<?php

namespace App\Helpers;

use Illuminate\Console\Command;

class SelectiveDatabaseExport
{
    protected Command $command;
    protected string $host;
    protected string $db;
    protected string $localDB;
    protected array $companyIds;

    // Systemtabeller som alltid ska hämtas i sin helhet
    protected array $excludedTables = [
        'migrations',
        'password_resets',
        'password_reset_tokens',
        'failed_jobs',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'personal_access_tokens',
    ];

    protected array $tableCategories = [
        'with_company_id' => [],
        'with_ifrs_setting_id' => [],
        'with_ifrs_settings_id' => [],
        'all_tables' => [],
    ];

    public function __construct(Command $command, string $host, string $db, string $localDB, array $companyIds)
    {
        $this->command = $command;
        $this->host = $host;
        $this->db = $db;
        $this->localDB = $localDB;
        $this->companyIds = $companyIds;
    }

    public function export(): void
    {
        $this->command->info("Analyzing database structure...");
        $this->fetchTableCategories();

        $this->command->info("Dumping schema...");
        $this->dumpSchema();

        $this->command->info("Exporting data selectively for company IDs: " . implode(', ', $this->companyIds));
        $this->exportData();
    }

    protected function fetchTableCategories(): void
    {
        // Hämta alla tabeller
        $allTablesQuery = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name";
        $result = $this->runRemoteQuery($allTablesQuery);
        $this->tableCategories['all_tables'] = array_filter(explode("\n", trim($result)));

        // Hämta tabeller med company_id
        $companyQuery = "SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'company_id'";
        $result = $this->runRemoteQuery($companyQuery);
        $this->tableCategories['with_company_id'] = array_filter(explode("\n", trim($result)));

        // Hämta tabeller med ifrs_setting_id
        $ifrsQuery = "SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'ifrs_setting_id'";
        $result = $this->runRemoteQuery($ifrsQuery);
        $this->tableCategories['with_ifrs_setting_id'] = array_filter(explode("\n", trim($result)));

        // Hämta tabeller med ifrs_settings_id (plural)
        $ifrsQueryPlural = "SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'ifrs_settings_id'";
        $result = $this->runRemoteQuery($ifrsQueryPlural);
        $this->tableCategories['with_ifrs_settings_id'] = array_filter(explode("\n", trim($result)));

        $this->command->info("Found " . count($this->tableCategories['all_tables']) . " tables");
        $this->command->info("  - " . count($this->tableCategories['with_company_id']) . " with company_id");
        $this->command->info("  - " . count($this->tableCategories['with_ifrs_setting_id']) . " with ifrs_setting_id");
        $this->command->info("  - " . count($this->tableCategories['with_ifrs_settings_id']) . " with ifrs_settings_id");
    }

    protected function dumpSchema(): void
    {
        $schemaFile = "/tmp/{$this->localDB}-schema.sql.gz";
        $cmd = "ssh {$this->host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge /usr/bin/pg_dump --schema-only {$this->db} | gzip' > {$schemaFile}";
        shell_exec($cmd);
    }

    protected function exportData(): void
    {
        $tables = $this->tableCategories['all_tables'];
        $totalTables = count($tables);
        $companyIdList = implode(',', $this->companyIds);

        $progressBar = $this->command->getOutput()->createProgressBar($totalTables);
        $progressBar->setFormat(" %current%/%max% [%bar%] %percent:3s%% %message%");
        $progressBar->start();

        $dataFile = "/tmp/{$this->localDB}-data.sql";
        $handle = fopen($dataFile, 'w');

        // Disable triggers och foreign key checks för smidigare import
        fwrite($handle, "SET session_replication_role = 'replica';\n\n");

        foreach ($tables as $table) {
            $progressBar->setMessage("Exporting {$table}...");

            $copyCommand = $this->buildCopyCommand($table, $companyIdList);
            $exportCmd = "ssh {$this->host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge psql {$this->db} -c \"" . addslashes($copyCommand) . "\"' 2>/dev/null";

            $data = shell_exec($exportCmd);

            if ($data && trim($data) !== '') {
                // Konvertera COPY output till INSERT eller använd COPY FROM stdin
                $this->writeTableData($handle, $table, $data);
            }

            $progressBar->advance();
        }

        // Re-enable triggers
        fwrite($handle, "\nSET session_replication_role = 'origin';\n");

        fclose($handle);
        $progressBar->finish();
        $this->command->newLine();

        // Gzipa datafilen
        $this->command->info("Compressing data file...");
        shell_exec("gzip -f {$dataFile}");
    }

    protected function buildCopyCommand(string $table, string $companyIdList): string
    {
        // Kontrollera om tabellen är undantagen (systemtabeller)
        if ($this->isExcludedTable($table)) {
            return "\\COPY {$table} TO STDOUT WITH (FORMAT csv, HEADER true, NULL 'NULL')";
        }

        // Kontrollera om tabellen har company_id
        if (in_array($table, $this->tableCategories['with_company_id'])) {
            return "\\COPY (SELECT * FROM {$table} WHERE company_id IN ({$companyIdList})) TO STDOUT WITH (FORMAT csv, HEADER true, NULL 'NULL')";
        }

        // Kontrollera om tabellen har ifrs_setting_id
        if (in_array($table, $this->tableCategories['with_ifrs_setting_id'])) {
            return "\\COPY (SELECT t.* FROM {$table} t INNER JOIN ifrs_settings i ON t.ifrs_setting_id = i.id WHERE i.company_id IN ({$companyIdList})) TO STDOUT WITH (FORMAT csv, HEADER true, NULL 'NULL')";
        }

        // Kontrollera om tabellen har ifrs_settings_id (plural)
        if (in_array($table, $this->tableCategories['with_ifrs_settings_id'])) {
            return "\\COPY (SELECT t.* FROM {$table} t INNER JOIN ifrs_settings i ON t.ifrs_settings_id = i.id WHERE i.company_id IN ({$companyIdList})) TO STDOUT WITH (FORMAT csv, HEADER true, NULL 'NULL')";
        }

        // Ingen filtrering - hämta allt
        return "\\COPY {$table} TO STDOUT WITH (FORMAT csv, HEADER true, NULL 'NULL')";
    }

    protected function isExcludedTable(string $table): bool
    {
        foreach ($this->excludedTables as $excluded) {
            if ($table === $excluded || str_starts_with($table, 'telescope_')) {
                return true;
            }
        }
        return false;
    }

    protected function writeTableData($handle, string $table, string $csvData): void
    {
        $lines = explode("\n", trim($csvData));
        if (count($lines) < 2) {
            // Endast header, ingen data
            return;
        }

        // Första raden är header med kolumnnamn
        $header = str_getcsv(array_shift($lines));
        $columns = implode(', ', array_map(fn($col) => '"' . $col . '"', $header));

        fwrite($handle, "-- Table: {$table}\n");
        fwrite($handle, "COPY {$table} ({$columns}) FROM stdin WITH (FORMAT csv, NULL 'NULL');\n");

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                fwrite($handle, $line . "\n");
            }
        }

        fwrite($handle, "\\.\n\n");
    }

    protected function runRemoteQuery(string $query): string
    {
        $cmd = "ssh {$this->host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge psql -t -A {$this->db} -c \"" . addslashes($query) . "\"' 2>/dev/null";
        return shell_exec($cmd) ?? '';
    }

    public function getSchemaFile(): string
    {
        return "/tmp/{$this->localDB}-schema.sql.gz";
    }

    public function getDataFile(): string
    {
        return "/tmp/{$this->localDB}-data.sql.gz";
    }
}
