<?php

require __DIR__ . '/vendor/autoload.php';

use Analyzer\CheckUrl;

$check = new CheckUrl("http://yaderew.ru");

var_dump($check->getStatus());
