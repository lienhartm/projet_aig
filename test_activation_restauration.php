<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\DatabaseConnection;
use App\Core\DatabaseRoleManager;
use App\Exceptions\DatabaseRoleResetException;
use PDO;
use RuntimeException;
use Throwable;

function getCurrentRole(PDO $pdo): string
{
    return (string) $pdo->query(
        'SELECT CURRENT_ROLE()'
    )->fetchColumn();
}

$pdo = DatabaseConnection::getConnection();
$manager = new DatabaseRoleManager($pdo);

$expectedDefault = 'aig_consultation';

echo "=== TEST D'INTÉGRATION DATABASE ROLE MANAGER ===\n";

$initialRole = getCurrentRole($pdo);
echo "Rôle initial : {$initialRole}\n";

if ($initialRole !== $expectedDefault) {
    echo "[ERREUR] Le rôle initial n'est pas celui attendu.\n";
    exit(1);
}

$roles = [
    'aig_consultation',
    'aig_referentiel',
    'aig_imp',
];

$success = 0;
$failure = 0;
$connectionInvalidated = false;

foreach ($roles as $role) {
    echo "\n--- Test du rôle {$role} ---\n";

    try {
        $activeRole = $manager->runWithRole(
            $role,
            function (PDO $connection) use ($role): string {
                $current = getCurrentRole($connection);

                if ($current !== $role) {
                    throw new RuntimeException(
                        "Rôle actif inattendu : {$current}"
                    );
                }

                return $current;
            }
        );

        echo "[OK] Rôle actif dans le callback : {$activeRole}\n";

        $restoredRole = getCurrentRole($pdo);

        if ($restoredRole !== $expectedDefault) {
            throw new RuntimeException(
                "Rôle non restauré : {$restoredRole}"
            );
        }

        echo "[OK] Rôle restauré : {$restoredRole}\n";
        $success++;

    } catch (DatabaseRoleResetException $e) {
        echo "[ERREUR CRITIQUE] Échec de restauration du rôle.\n";
        echo "Exception de restauration : "
            . get_class($e->getResetException()) . "\n";

        if ($e->getOperationException() !== null) {
            echo "Exception métier : "
                . get_class($e->getOperationException()) . "\n";
        }

        $failure++;
        $connectionInvalidated = true;
        break;

    } catch (Throwable $e) {
        echo "[ÉCHEC] " . get_class($e) . "\n";
        echo $e->getMessage() . "\n";
        $failure++;

        try {
            $restoredRole = getCurrentRole($pdo);
            echo "Rôle après échec : {$restoredRole}\n";
        } catch (Throwable $verificationError) {
            echo "[ERREUR] Vérification impossible.\n";
            $connectionInvalidated = true;
            break;
        }
    }
}

if (!$connectionInvalidated) {
    echo "\n--- Test de restauration après exception métier ---\n";

    try {
        $manager->runWithRole(
            'aig_consultation',
            function (PDO $connection): void {
                throw new RuntimeException(
                    'Exception métier volontaire pour le test.'
                );
            }
        );

        echo "[ERREUR] L'exception métier aurait dû être propagée.\n";
        $failure++;

    } catch (RuntimeException $e) {
        if ($e->getMessage() !==
            'Exception métier volontaire pour le test.') {
            echo "[ÉCHEC] Exception inattendue : "
                . $e->getMessage() . "\n";
            $failure++;
        } else {
            echo "[OK] Exception métier propagée.\n";

            $restoredRole = getCurrentRole($pdo);

            if ($restoredRole === $expectedDefault) {
                echo "[OK] Rôle restauré après exception.\n";
                $success++;
            } else {
                echo "[ÉCHEC] Rôle actif : {$restoredRole}\n";
                $failure++;
            }
        }
    }
}

echo "\n=== BILAN ===\n";
echo "Succès : {$success}\n";
echo "Échecs : {$failure}\n";

if ($failure > 0) {
    exit(1);
}

echo "Tous les contrôles d'intégration sont réussis.\n";

