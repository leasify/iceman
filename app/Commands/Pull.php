<?php

namespace App\Commands;

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
        $environment = $this->argument('environment');
        $host = $this->matchHost($environment);

        if (!$environment || !$host) {
            $this->error("<error>Environment `{$environment}` not possible to translate to a valid host.</error>");
            return;
        }

        echo $host . "\n";
    }

    public function matchHost($environment) : string {
        $result = "";
        if($environment == "prod" || $environment == "production") {
            $result = "c2698.cloudnet.cloud";
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
