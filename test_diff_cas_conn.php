<?php

declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

use App\Core\Authorization;
use App\Exceptions\AuthorizationException;

$tests = [
    [
        'Un rôle attribué',
        ['AIG_CONSULTATION'],
        ['AIG_CONSULTATION'],
        true,
    ],
    [
        'Plusieurs rôles, un attribué',
        ['AIG_CONSULTATION', 'AIG_REFERENTIEL'],
        ['AIG_CONSULTATION'],
        true,
    ],
    [
        'Aucun rôle attribué',
        ['AIG_REFERENTIEL', 'AIG_IMP'],
        ['AIG_CONSULTATION'],
        false,
    ],
    [
        'Rôle inconnu seul',
        ['ROLE_INCONNU'],
        ['AIG_CONSULTATION'],
        false,
    ],
    [
        'Rôle attribué suivi d’un rôle inconnu',
        ['AIG_CONSULTATION', 'ROLE_INCONNU'],
        ['AIG_CONSULTATION'],
        false,
    ],
    [
        'Rôle inconnu suivi d’un rôle attribué',
        ['ROLE_INCONNU', 'AIG_CONSULTATION'],
        ['AIG_CONSULTATION'],
        false,
    ],
    [
        'Liste vide',
        [],
        ['AIG_CONSULTATION'],
        false,
    ],
];

$success = 0;

foreach ($tests as [$label, $requested, $assigned, $expected]) {
    $authorization = new Authorization([
        'id_utilisateur' => 1,
        'actif' => true,
        'roles' => $assigned,
    ]);

    $authorized = true;

    try {
        $authorization->requireAnyRole($requested);
    } catch (AuthorizationException) {
        $authorized = false;
    }

    if ($authorized === $expected) {
        echo "[OK] $label\n";
        $success++;
    } else {
        echo "[ÉCHEC] $label\n";
    }
}

echo "\nRésultat : $success / " . count($tests) . " tests réussis.\n";

if ($success !== count($tests)) {
    exit(1);
}

