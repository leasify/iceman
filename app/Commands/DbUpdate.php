<?php

namespace App\Commands;

use App\Helpers\Environment;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class DbUpdate extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'db:update {environment : Environment to database update, eg dev1}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Fetches production postgres db to target env';

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

        if (!$environment) {
            $this->error("<error>Environment name missing, eg 'dev2'.</error>");
            return;
        }

        if ($environment=="prod") {
            $this->error("<error>Don't ever run this on prod!</error>");
            return;
        }

        if ($environment=="production") {
            $this->error("<error>Don't ever run this on prod!</error>");
            return;
        }

        if ($environment=="staging") {
            $this->error("<error>Staging updated every deployment to master, even db.</error>");
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

        $replace = 'sudo -i -u postgres /usr/bin/psql ' . $db . ' -c \"UPDATE users SET email=concat(email,\'.cc\');\"';

        $actions = [
            "ssh deploy@c2698.cloudnet.cloud -o \"StrictHostKeyChecking no\" 'sudo -i -u forge /usr/bin/pg_dump production | gzip' > db_{$db}.sql.gz",
            "scp db_{$db}.sql.gz root@c7669.cloudnet.cloud:/tmp/db_{$db}.sql.gz",
            "rm db_{$db}.sql.gz",
            "ssh root@c7669.cloudnet.cloud -o \"StrictHostKeyChecking no\" 'gzip -df /tmp/db_{$db}.sql.gz'",
            "ssh root@c7669.cloudnet.cloud -o \"StrictHostKeyChecking no\" 'cd {$path}/current && php artisan db:wipe --drop-types --force'",
            "ssh root@c7669.cloudnet.cloud -o \"StrictHostKeyChecking no\" 'cat /tmp/db_{$db}.sql | sudo -i -u forge /usr/bin/psql {$db}'",
            "ssh root@c7669.cloudnet.cloud -o \"StrictHostKeyChecking no\" 'rm -f /tmp/db_{$db}.sql'",
            "ssh root@c7669.cloudnet.cloud -o \"StrictHostKeyChecking no\" \"{$replace}\"",
            "ssh root@c7669.cloudnet.cloud -o \"StrictHostKeyChecking no\" 'cd {$path}/current && php artisan migrate && php artisan optimize:clear'",
        ];

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
