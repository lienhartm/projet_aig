<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$requiredVariables = [
    'DB_AUTH_HOST',
    'DB_AUTH_PORT',
    'DB_AUTH_NAME',
    'DB_AUTH_USER',
    'DB_AUTH_PASSWORD',
];

foreach ($requiredVariables as $variable) {
    if (!isset($_ENV[$variable]) || $_ENV[$variable] === '') {
        throw new RuntimeException(
            sprintf('Configuration manquante : %s', $variable)
        );
    }
}

$port = filter_var(
    $_ENV['DB_AUTH_PORT'],
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
            'max_range' => 65535,
        ],
    ]
);

if ($port === false) {
    throw new RuntimeException(
        'DB_AUTH_PORT contient une valeur invalide.'
    );
}

$host = $_ENV['DB_AUTH_HOST'];

if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host)) {
    throw new RuntimeException(
        'DB_AUTH_HOST contient une valeur invalide.'
    );
}

$database = $_ENV['DB_AUTH_NAME'];

if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
    throw new RuntimeException(
        'DB_AUTH_NAME contient une valeur invalide.'
    );
}

return [
    'database' => [
        'host' => $host,
        'port' => $port,
        'name' => $database,
        'username' => $_ENV['DB_AUTH_USER'],
        'password' => $_ENV['DB_AUTH_PASSWORD'],
        'charset' => 'utf8mb4',
    ],
];
