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

    public static function matchHost($environment) : string {
        $result = "";
        if($environment == "prod" || $environment == "production") {
            $result = "forge@13.50.158.198";
        }
        if($environment == "develop") {
            $result = "forge@13.49.49.40";
        }
        return $result;
    }

    public static function matchDatabase($environment) : string {
        $result = "";
        if($environment == "prod" || $environment == "production") {
            $result = "production";
        }
        if($environment == "develop") {
            $result = "develop";
        }
        return $result;
    }

    public static function matchPath($environment) : string {
        $result = "";
        if($environment == "prod" || $environment == "production") {
            $result = "/home/forge/app.leasify.se";
        }
        if($environment == "develop") {
            $result = "/home/forge/develop.leasify.se";
        }
        return $result;
    }
}
