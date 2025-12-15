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

    /**
     * Tabeller som alltid ska hämtas i sin helhet (ej filtreras på company_id).
     *
     * INSTRUKTION FÖR ATT LÄGGA TILL FLER TABELLER:
     * Om en tabell behöver hämtas i sin helhet (utan company_id-filtrering),
     * lägg till tabellnamnet i denna array. Tabeller som börjar med 'telescope_'
     * hanteras automatiskt.
     *
     * Exempel: Om tabellen 'settings' ska hämtas helt, lägg till 'settings' här.
     */
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
        // Ta bort gamla filer så att avbruten export inte läser gammal data
        @unlink("/tmp/{$this->localDB}-data.sql");
        @unlink("/tmp/{$this->localDB}-data.sql.gz");

        $this->command->info("Analyzing database structure...");
        $this->fetchTableCategories();

        $this->command->info("Dumping schema...");
        $this->dumpSchema();

        $this->command->info("Exporting data selectively for company IDs: " . implode(', ', $this->companyIds));
        $this->exportData();
    }

    /**
     * Lägg till tabeller som ska hämtas i sin helhet (ej filtreras på company_id)
     */
    public function addExcludedTables(array $tables): void
    {
        $this->excludedTables = array_unique(array_merge($this->excludedTables, $tables));
    }

    /**
     * Hämta nuvarande lista över undantagna tabeller
     */
    public function getExcludedTables(): array
    {
        return $this->excludedTables;
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

        // Hämta foreign key-relationer för att sortera tabeller
        $this->fetchTableDependencies();
    }

    protected function fetchTableDependencies(): void
    {
        $this->command->info("Sorting tables by dependencies...");

        // Explicit tier-ordning baserad på Eloquent model relations
        $tiers = [
            // Tier 0 - Root (cirkulära beroenden - kopiera först)
            0 => ['companies', 'users'],

            // Tier 1 - Beror på users ELLER companies
            1 => [
                'units', 'company_types', 'currencies', 'interest_rates', 'scb_indexes',
                'plans', 'acl_permissions', 'acl_roles', 'tags', 'agents', 'applications',
                'api_keys', 'product_categories', 'help_texts', 'languages', 'sign_templates',
                'suppliers', 'report_templates', 'report_designers', 'currency_sets',
                'currency_rates', 'currency_rate_pairs', 'folders', 'groups', 'taxes',
                'price_lists', 'licenses', 'ifrs_settings', 'portfolios', 'kleer_clients',
                'pe_agreements', 'onboarding_processes', 'campaign_rules', 'auctions', 'faqs',
                'files', 'messages', 'stories', 'blogposts', 'bookmarks', 'reco_lists',
                'reminders', 'chat_rooms', 'ifrs_business_areas', 'sdg_assessments', 'workflows',
                'contract_nodes', 'contract_custom_fields', 'contract_views', 'dimensions',
                'menus', 'form_entities', 'procurement_nodes', 'company_invoices', 'company_metas',
                'company_s3_s', 'company_presentations', 'company_revenues', 'products', 'tenders',
            ],

            // Tier 2 - Beror på Tier 1
            2 => [
                'contracts', 'contract_bulks', 'documents', 'group_objects', 'tax_values',
                'portfolio_versions', 'procurements', 'product_specification_documents',
                'currency_rate_pair_values', 'scb_index_values', 'interest_rate_values',
                'kleer_agreements', 'pe_invoices', 'report_designer_versions', 'onboarding_steps',
                'campaigns', 'auctionevents', 'faq_tracks', 'chat_items', 'sdg_assessment_goals',
                'agent_threads', 'application_users', 'plan_features', 'plan_subscriptions',
                'user_metas', 'user_consents', 'ifrs_contract_categories', 'ifrs_cost_places',
                'ifrs_currencies', 'ifrs_indexes', 'ifrs_interests', 'ifrs_reports',
                'ifrs_setting_unit', 'finance_requests', 'backups', 'exports', 'imports',
                'form_fields', 'procurement_subscriptions', 'product_category_subscriptions',
                'reseller_settlements', 'filament_filter_sets', 'company_users', 'sign_receivers',
            ],

            // Tier 3 - Beror på Tier 2
            3 => [
                'contract_versions', 'contract_errors', 'contract_metas', 'contract_ocrs',
                'contract_todos', 'contract_balances', 'contract_bulk_imports', 'contract_forecasts',
                'document_metas', 'comments', 'signs', 'procurement_invites', 'procurement_responses',
                'procurement_forms', 'agent_messages', 'plan_subscription_usage',
                'ifrs_currency_values', 'ifrs_index_values', 'ifrs_interest_values',
                'ifrs_report_generated', 'ifrs_report_unit', 'ifrs_report_ifrs_cost_place',
                'finance_request_files', 'failed_import_rows', 'gdpr_people',
            ],

            // Tier 4 - Beror på Tier 3
            4 => [
                'sign_emails', 'procurement_response_forms', 'agent_message_attachments',
                'agent_message_chunks', 'ifrs_report_generated_files', 'contract_version_report',
                'report_generators',
            ],

            // Tier 5 - Pivot/junction-tabeller
            5 => [
                'company_type', 'company_price_list', 'company_contract_node', 'contract_product',
                'contract_workflow', 'contract_view_user', 'campaign_contract', 'campaign_user',
                'chat_room_user', 'group_user', 'dimension_ifrs_report', 'portfolio_product',
                'pe_agreement_plan_subscription', 'company_invoice_plan_subscription',
                'acl_model_has_roles', 'acl_model_has_permissions', 'acl_role_has_permissions',
                'taggables', 'reseller_users', 'reseller_companies', 'campaign_unsubs',
            ],

            // Tier 6 - Tabeller utan belongsTo (polymorphic, no FK)
            6 => [
                'activity_log', 'action_events', 'accesses', 'ai_chunks', 'notifications',
                'personal_access_tokens', 'external_objects', 'media', 'stibors',
            ],
        ];

        // Skapa lookup för tier per tabell
        $tableTier = [];
        foreach ($tiers as $tier => $tables) {
            foreach ($tables as $table) {
                $tableTier[$table] = $tier;
            }
        }

        // Sortera alla tabeller efter tier (okända tabeller hamnar sist)
        $allTables = $this->tableCategories['all_tables'];
        usort($allTables, function($a, $b) use ($tableTier) {
            $tierA = $tableTier[$a] ?? 999;
            $tierB = $tableTier[$b] ?? 999;
            if ($tierA === $tierB) {
                return strcmp($a, $b); // Alfabetiskt inom samma tier
            }
            return $tierA - $tierB;
        });

        $this->tableCategories['all_tables'] = $allTables;
        $this->command->info("  - Sorted " . count($allTables) . " tables by tier dependencies");
    }

    protected function dumpSchema(): void
    {
        $schemaFile = "/tmp/{$this->localDB}-schema.sql.gz";
        $cmd = "ssh {$this->host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge /usr/bin/pg_dump --schema-only {$this->db} | gzip' > {$schemaFile}";
        shell_exec($cmd);
    }

    protected function exportData(): void
    {
        // Tabeller är redan sorterade efter FK-beroenden i fetchTableDependencies()
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

            $selectQuery = $this->buildSelectQuery($table, $companyIdList);

            // Hämta kolumnnamn för tabellen (med korrekt ORDER BY syntax)
            $columnsQuery = "SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '{$table}'";
            $columns = trim($this->runRemoteQuery($columnsQuery));

            if (empty($columns)) {
                $progressBar->advance();
                continue;
            }

            // Formatera kolumnnamn med quotes
            $columnList = implode(', ', array_map(fn($col) => '"' . trim($col) . '"', explode(',', $columns)));

            // Skriv COPY header
            fwrite($handle, "-- Table: {$table}\n");
            fwrite($handle, "COPY \"{$table}\" ({$columnList}) FROM stdin;\n");

            // Använd heredoc och skriv direkt till fil (streama för att undvika minnesöverskridning)
            $exportCmd = "ssh {$this->host} -o \"StrictHostKeyChecking no\" 'sudo -i -u forge psql -q {$this->db} << ENDSQL
\\copy ({$selectQuery}) TO STDOUT
ENDSQL' 2>/dev/null";

            // Kör kommandot och skriv output direkt till filen
            $process = popen($exportCmd, 'r');
            $hasData = false;
            if ($process) {
                while (($line = fgets($process)) !== false) {
                    $hasData = true;
                    fwrite($handle, $line);
                }
                pclose($process);
            }

            // Avsluta COPY-blocket om vi hade data, annars ta bort header
            if ($hasData) {
                fwrite($handle, "\\.\n\n");
            } else {
                // Spola tillbaka och skriv över header om ingen data
                // (vi hoppar över detta för enkelhet - tom COPY är OK)
                fwrite($handle, "\\.\n\n");
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

    protected function buildSelectQuery(string $table, string $companyIdList): string
    {
        // Kontrollera om tabellen är undantagen (systemtabeller) - hämta allt
        if ($this->isExcludedTable($table)) {
            return "SELECT * FROM \"{$table}\"";
        }

        // Specialfall: companies-tabellen filtreras på id (den ÄR company-tabellen)
        if ($table === 'companies') {
            return "SELECT * FROM \"{$table}\" WHERE id IN ({$companyIdList})";
        }

        // Kontrollera om tabellen har company_id
        if (in_array($table, $this->tableCategories['with_company_id'])) {
            return "SELECT * FROM \"{$table}\" WHERE company_id IN ({$companyIdList})";
        }

        // Kontrollera om tabellen har ifrs_setting_id
        if (in_array($table, $this->tableCategories['with_ifrs_setting_id'])) {
            return "SELECT t.* FROM \"{$table}\" t INNER JOIN ifrs_settings i ON t.ifrs_setting_id = i.id WHERE i.company_id IN ({$companyIdList})";
        }

        // Kontrollera om tabellen har ifrs_settings_id (plural)
        if (in_array($table, $this->tableCategories['with_ifrs_settings_id'])) {
            return "SELECT t.* FROM \"{$table}\" t INNER JOIN ifrs_settings i ON t.ifrs_settings_id = i.id WHERE i.company_id IN ({$companyIdList})";
        }

        // Ingen filtrering - hämta allt
        return "SELECT * FROM \"{$table}\"";
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

    protected function runRemoteQuery(string $query): string
    {
        // Bygg kommando med double quotes och escape för att undvika quote-problem
        $cmd = "ssh {$this->host} -o \"StrictHostKeyChecking no\" \"sudo -i -u forge psql -t -A {$this->db} -c \\\"" . str_replace('"', '\\"', $query) . "\\\"\" 2>/dev/null";

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
