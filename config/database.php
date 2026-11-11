<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$requiredVariables = [
    'APP_ENV',
    'APP_DEBUG',
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD',
];

foreach ($requiredVariables as $variable) {
    if (!isset($_ENV[$variable]) || $_ENV[$variable] === '') {
        throw new RuntimeException(
            sprintf('Configuration manquante : %s', $variable)
        );
    }
}

$appEnvironment = $_ENV['APP_ENV'];

if (!in_array($appEnvironment, ['development', 'test', 'production'], true)) {
    throw new RuntimeException('APP_ENV contient une valeur invalide.');
}

$debug = filter_var(
    $_ENV['APP_DEBUG'],
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE
);

if ($debug === null) {
    throw new RuntimeException('APP_DEBUG doit être un booléen.');
}

$port = filter_var(
    $_ENV['DB_PORT'],
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
            'max_range' => 65535,
        ],
    ]
);

if ($port === false) {
    throw new RuntimeException('DB_PORT contient une valeur invalide.');
}

$host = $_ENV['DB_HOST'];

if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host)) {
    throw new RuntimeException('DB_HOST contient une valeur invalide.');
}

$database = $_ENV['DB_NAME'];

if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
    throw new RuntimeException('DB_NAME contient une valeur invalide.');
}

return [
    'app' => [
        'environment' => $appEnvironment,
        'debug' => $debug,
    ],
    'database' => [
        'host' => $host,
        'port' => $port,
        'name' => $database,
        'username' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASSWORD'],
        'charset' => 'utf8mb4',
    ],
];
