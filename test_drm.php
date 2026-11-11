<?php

declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

use App\Core\DatabaseConnection;
use App\Core\DatabaseRoleManager;
use App\Exceptions\DatabaseRoleResetException;

final class FakePDO extends PDO
{
    public array $queries = [];
    public array $failAt = [];

    public function __construct()
    {
    }

    public function exec(string $statement): int|false
    {
        $this->queries[] = $statement;

        $call = count($this->queries);

        if (in_array($call, $this->failAt, true)) {
            throw new RuntimeException(
                "Erreur PDO simulée à l'appel $call."
            );
        }

        return 0;
    }
}

$success = 0;
$total = 11;

function check(bool $condition, string $label): void
{
    global $success;

    if (!$condition) {
        echo "[ÉCHEC] $label\n";
        return;
    }

    echo "[OK] $label\n";
    $success++;
}

// DRM-01 : activation et restauration normales.
$pdo = new FakePDO();
$manager = new DatabaseRoleManager($pdo);

$result = $manager->runWithRole(
    'aig_imp',
    fn(PDO $connection) => 'résultat attendu'
);

check(
    $result === 'résultat attendu'
    && $pdo->queries === [
        'SET ROLE aig_imp',
        'SET ROLE aig_consultation',
    ],
    'DRM-01 : activation et restauration normales'
);

// DRM-02 : rôle non autorisé.
$pdo = new FakePDO();
$manager = new DatabaseRoleManager($pdo);

try {
    $manager->runWithRole('aig_admin', fn() => null);
    $refused = false;
} catch (RuntimeException) {
    $refused = true;
}

check(
    $refused && $pdo->queries === [],
    'DRM-02 : rôle non autorisé refusé sans SQL'
);

// DRM-03 : résultat du callback.
$pdo = new FakePDO();
$manager = new DatabaseRoleManager($pdo);

$result = $manager->runWithRole(
    'aig_referentiel',
    fn() => 123
);

check(
    $result === 123,
    'DRM-03 : résultat du callback retourné'
);

// DRM-04 : exception du callback et restauration.
$pdo = new FakePDO();
$manager = new DatabaseRoleManager($pdo);
$original = new RuntimeException('Erreur métier simulée.');

try {
    $manager->runWithRole(
        'aig_imp',
        function () use ($original) {
            throw $original;
        }
    );
    $caught = null;
} catch (Throwable $exception) {
    $caught = $exception;
}

check(
    $caught === $original
    && end($pdo->queries) === 'SET ROLE aig_consultation',
    'DRM-04 : exception métier propagée après restauration'
);

// DRM-05 : activation en échec, restauration tentée.
$pdo = new FakePDO();
$pdo->failAt = [1];
$manager = new DatabaseRoleManager($pdo);

try {
    $manager->runWithRole('aig_imp', fn() => null);
    $caught = null;
} catch (Throwable $exception) {
    $caught = $exception;
}

check(
    $caught instanceof RuntimeException
    && count($pdo->queries) === 2
    && $pdo->queries[1] === 'SET ROLE aig_consultation',
    'DRM-05 : restauration tentée après échec activation'
);

// DRM-06 : échec de restauration et invalidation.
$pdo = new FakePDO();
$pdo->failAt = [2];

$managerA = new DatabaseRoleManager($pdo);
$managerB = new DatabaseRoleManager($pdo);

try {
    $managerA->runWithRole('aig_imp', fn() => null);
    $caught = null;
} catch (DatabaseRoleResetException $exception) {
    $caught = $exception;
}

$queriesBefore = count($pdo->queries);

try {
    $managerB->runWithRole('aig_imp', fn() => null);
    $refusedB = false;
} catch (RuntimeException) {
    $refusedB = true;
}

try {
    $managerA->runWithRole('aig_imp', fn() => null);
    $refusedA = false;
} catch (RuntimeException) {
    $refusedA = true;
}

check(
    $caught instanceof DatabaseRoleResetException
    && $refusedA
    && $refusedB
    && count($pdo->queries) === $queriesBefore,
    'DRM-06 : connexion invalidée et non réutilisable'
);

// DRM-07 : conservation des deux exceptions.
$pdo = new FakePDO();
$pdo->failAt = [2];

$manager = new DatabaseRoleManager($pdo);
$operationError = new RuntimeException('Erreur opération.');

try {
    $manager->runWithRole(
        'aig_imp',
        function () use ($operationError) {
            throw $operationError;
        }
    );
    $caught = null;
} catch (DatabaseRoleResetException $exception) {
    $caught = $exception;
}

check(
    $caught instanceof DatabaseRoleResetException
    && $caught->getOperationException() === $operationError
    && $caught->getResetException() instanceof RuntimeException
    && $caught->getPrevious() === $caught->getResetException(),
    'DRM-07 : deux exceptions accessibles'
);

// DRM-08 : nouvel objet PDO utilisable après invalidation.
$pdo = new FakePDO();
$pdo->failAt = [2];

$manager = new DatabaseRoleManager($pdo);

try {
    $manager->runWithRole('aig_imp', fn() => null);
} catch (DatabaseRoleResetException) {
    // Invalidation attendue.
}

$newPdo = new FakePDO();
$newManager = new DatabaseRoleManager($newPdo);

$result = $newManager->runWithRole(
    'aig_imp',
    fn() => 'nouvelle connexion'
);

check(
    $result === 'nouvelle connexion'
    && count($newPdo->queries) === 2,
    'DRM-08 : nouvelle connexion indépendante utilisable'
);

// DRM-09 : appel imbriqué dans la même instance.
$pdo = new FakePDO();
$manager = new DatabaseRoleManager($pdo);
$nestedRefused = false;

$manager->runWithRole(
    'aig_imp',
    function () use ($manager, &$nestedRefused) {
        try {
            $manager->runWithRole('aig_referentiel', fn() => null);
        } catch (RuntimeException) {
            $nestedRefused = true;
        }
    }
);

check(
    $nestedRefused && count($pdo->queries) === 2,
    'DRM-09 : appel imbriqué même instance refusé'
);

// DRM-10 : appel imbriqué entre deux instances.
$pdo = new FakePDO();
$managerA = new DatabaseRoleManager($pdo);
$managerB = new DatabaseRoleManager($pdo);
$nestedRefused = false;

$managerA->runWithRole(
    'aig_imp',
    function () use ($managerB, &$nestedRefused) {
        try {
            $managerB->runWithRole('aig_referentiel', fn() => null);
        } catch (RuntimeException) {
            $nestedRefused = true;
        }
    }
);

check(
    $nestedRefused && count($pdo->queries) === 2,
    'DRM-10 : appel imbriqué entre deux instances refusé'
);

// DRM-11 : invalidation ciblée du singleton.
$property = new ReflectionProperty(
    DatabaseConnection::class,
    'connection'
);

$pdoA = new FakePDO();
$pdoB = new FakePDO();

$property->setValue(null, $pdoA);

DatabaseConnection::discardConnection($pdoB);
$preserved = $property->getValue() === $pdoA;

DatabaseConnection::discardConnection($pdoA);
$discarded = $property->getValue() === null;

check(
    $preserved && $discarded,
    'DRM-11 : invalidation ciblée du singleton'
);

echo "\nRésultat : $success / $total tests réussis.\n";

if ($success !== $total) {
    exit(1);
}

