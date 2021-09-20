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
            $result = "root@c2698.cloudnet.cloud";
        }
        if($environment == "dev") {
            $result = "root@c7669.cloudnet.cloud";
        }
        if($environment == "dev1") {
            $result = "root@c7669.cloudnet.cloud";
        }
        if($environment == "dev2") {
            $result = "root@c7669.cloudnet.cloud";
        }
        if($environment == "dev3") {
            $result = "root@c7669.cloudnet.cloud";
        }
        if($environment == "dev4") {
            $result = "root@c7669.cloudnet.cloud";
        }
        if($environment == "dev5") {
            $result = "root@c7669.cloudnet.cloud";
        }
        return $result;
    }

    public static function matchDatabase($environment) : string {
        $result = "";
        if($environment == "prod" || $environment == "production") {
            $result = "production";
        }
        if($environment == "dev") {
            $result = "dev";
        }
        if($environment == "dev1") {
            $result = "dev1";
        }
        if($environment == "dev2") {
            $result = "dev2";
        }
        if($environment == "dev3") {
            $result = "dev3";
        }
        if($environment == "dev4") {
            $result = "dev4";
        }
        if($environment == "dev5") {
            $result = "dev5";
        }
        return $result;
    }

    public static function matchPath($environment) : string {
        $result = "";
        if($environment == "prod" || $environment == "production") {
            $result = "/mnt/persist/www/docroot_production";
        }
        if($environment == "dev") {
            $result = "/mnt/persist/www/docroot_dev";
        }
        if($environment == "dev1") {
            $result = "/mnt/persist/www/docroot_dev1";
        }
        if($environment == "dev2") {
            $result = "/mnt/persist/www/docroot_dev2";
        }
        if($environment == "dev3") {
            $result = "/mnt/persist/www/docroot_dev3";
        }
        if($environment == "dev4") {
            $result = "/mnt/persist/www/docroot_dev4";
        }
        if($environment == "dev5") {
            $result = "/mnt/persist/www/docroot_dev5";
        }
        return $result;
    }
}
