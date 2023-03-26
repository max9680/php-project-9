<?php

require __DIR__ . '/vendor/autoload.php';

use PostgreSQLTutorial\Connection;

try {
    $pdo = Connection::get()->connect();
    // echo 'A connection to the PostgreSQL database sever has been established successfully.';
} catch (\PDOException $e) {
    echo $e->getMessage();
}