<?php

namespace App\Commands;

use App\Helpers\Environment;
use Dotenv\Dotenv;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Env extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'env';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Check current environment';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $env = Environment::make();
        $this->info("Loading [{$env->currentPath}]/.env");
        $this->info("FORGE_API=" . env('FORGE_API'));
        $this->info("DB_DATABASE=" . env('DB_DATABASE'));
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
