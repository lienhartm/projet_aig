<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$rootDirectory = dirname(__DIR__);

require_once $rootDirectory . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable($rootDirectory);
$dotenv->load();
