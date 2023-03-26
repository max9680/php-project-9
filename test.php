<?php

$params = parse_ini_file('./src/database.ini');

// var_dump($params);

$params = parse_url(getenv('DATABASE_URL'));
var_dump($params);

$dbName = ltrim($params['path'], '/');

$conStr = sprintf(
    "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
    $params['host'],
    $params['port'],
    $dbName,
    // $params['database'],
    $params['user'],
    $params['pass']
);

print_r($conStr);