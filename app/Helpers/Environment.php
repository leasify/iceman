<?php

namespace App\Helpers;

use Dotenv\Dotenv;

class Environment {

    public $currentPath;

    public static function make() : Environment {
        $dir = trim(shell_exec("echo \$PWD"));
        $dotenv = Dotenv::createImmutable($dir);
        $dotenv->load();
        $me = new static;
        $me->currentPath = $dir;
        return $me;
    }

}
