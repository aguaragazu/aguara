<?php

use Illuminate\Database\Capsule\Manager as Capsule;

class DatabaseORM
{
    protected $capsule;

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

        // Make this Capsule instance available globally via static methods
        $capsule->setAsGlobal();

        // Setup the Eloquent ORM…
        $capsule->bootEloquent();
    }
}
