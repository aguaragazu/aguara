<?php

namespace Models;

use Illuminate\Database\Capsule\Manager as Capsule;

class DatabaseORM
{
    public function __construct()
    {
        $capsule = new Capsule();
        $capsule->addConnection([
         'driver' => $_ENV['DB_DRIVER'],
         'host' => $_ENV['DB_HOST'],
         'database' => $_ENV['DB_NAME'],
         'username' => $_ENV['DB_USER'],
         'password' => $_ENV['DB_PASS'],
         'charset' => $_ENV['DB_CHARSET'],
         'collation' => 'utf8_unicode_ci',
         'prefix' => '',
        ]);
        // Setup the Eloquent ORM…
        $capsule->bootEloquent();
    }
}
