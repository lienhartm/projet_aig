<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

use App\Core\Authorization;
use App\Exceptions\AuthorizationException;

$tests = [
    [
        'Compte inactif avec rôle attribué',
        [
            'id_utilisateur' => 1,
            'actif' => false,
            'roles' => ['AIG_CONSULTATION'],
        ],
        'AIG_CONSULTATION',
    ],
    [
        'Utilisateur non authentifié',
        null,
        'AIG_CONSULTATION',
    ],
];

$success = 0;

foreach ($tests as [$label, $context, $role]) {
    try {
        $authorization = new Authorization($context);
        $authorization->requireRole($role);

        echo "[ÉCHEC] $label : accès accordé.\n";
    } catch (AuthorizationException) {
        echo "[OK] $label : accès refusé.\n";
        $success++;
    }
}

echo "\nRésultat : $success / " . count($tests) . " tests réussis.\n";

if ($success !== count($tests)) {
    exit(1);
}

