<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\DatabaseConnection;
use App\Core\AuthDatabaseConnection;

$connections = [
    'Métier' => DatabaseConnection::getConnection(),
    'Authentification' => AuthDatabaseConnection::getConnection(),
];

foreach ($connections as $label => $pdo) {
    $result = $pdo->query(
        'SELECT CURRENT_USER() AS compte,
                CURRENT_ROLE() AS role_actif,
                DATABASE() AS base_active'
    )->fetch(PDO::FETCH_ASSOC);

    echo "\n=== {$label} ===\n";
    echo "Compte : {$result['compte']}\n";
    echo "Rôle   : {$result['role_actif']}\n";
    echo "Base   : {$result['base_active']}\n";
}

