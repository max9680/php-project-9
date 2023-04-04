<?php

require __DIR__ . '/../vendor/autoload.php';

echo('hello, world');

// $str = getenv('DATABASE_URL');
$str = getenv('DATABASE_URL');
// $params = parse_url($_ENV['DATABASE_URL']);

// $str = $_SERVER['DATABASE_URL'];

var_dump($str);
echo($str);

$str = getenv($_ENV['DATABASE_URL']);

var_dump($str);
echo $_ENV['DATABASE_URL'];

echo phpinfo();