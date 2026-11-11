<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\DatabaseRoleResetException;
use PDO;
use RuntimeException;
use Throwable;
use WeakMap;

final class DatabaseRoleManager
{
    private const ALLOWED_ROLES = [
        'aig_consultation',
        'aig_referentiel',
        'aig_imp',
    ];

    private const DEFAULT_ROLE = 'aig_consultation';

    /**
     * État partagé entre toutes les instances du gestionnaire.
     *
     * Chaque connexion PDO possède deux indicateurs :
     * - active : une opération avec changement de rôle est en cours ;
     * - invalid : la connexion a subi un échec de restauration.
     *
     * WeakMap évite de conserver artificiellement les connexions
     * en mémoire lorsqu'elles ne sont plus référencées ailleurs.
     */
    private static ?WeakMap $connectionStates = null;

    private ?PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function runWithRole(
        string $role,
        callable $operation
    ): mixed {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new RuntimeException(
                'Rôle MariaDB non autorisé.'
            );
        }

        if ($this->connection === null) {
            throw new RuntimeException(
                'Gestionnaire de connexion invalidé.'
            );
        }

        $connection = $this->connection;

        $state = self::getConnectionState($connection);

        if ($state['invalid']) {
            $this->connection = null;

            throw new RuntimeException(
                'Cette connexion MariaDB a été invalidée.'
            );
        }

        if ($state['active']) {
            throw new RuntimeException(
                'Une opération avec changement de rôle '
                . 'est déjà en cours sur cette connexion.'
            );
        }

        // Verrouillage logique partagé entre les instances.
        self::setConnectionState($connection, [
            'active' => true,
            'invalid' => false,
        ]);

        $result = null;
        $operationException = null;

        try {
            $connection->exec('SET ROLE ' . $role);

            $result = $operation($connection);

        } catch (Throwable $exception) {
            $operationException = $exception;
        }

        try {
            $connection->exec(
                'SET ROLE ' . self::DEFAULT_ROLE
            );

        } catch (Throwable $resetException) {

            // La connexion ne doit plus être utilisée.
            self::setConnectionState($connection, [
                'active' => false,
                'invalid' => true,
            ]);

            // Invalidation de la référence détenue par ce gestionnaire.
            $this->connection = null;

            // Invalidation du singleton si c'est la même connexion.
            DatabaseConnection::discardConnection($connection);

            throw new DatabaseRoleResetException(
                $operationException,
                $resetException
            );
        }

        // Le rôle minimal a été restauré.
        self::setConnectionState($connection, [
            'active' => false,
            'invalid' => false,
        ]);

        if ($operationException !== null) {
            throw $operationException;
        }

        return $result;
    }

    /**
     * Récupère l'état partagé d'une connexion.
     */
    private static function getConnectionState(
        PDO $connection
    ): array {
        self::$connectionStates ??= new WeakMap();

        return self::$connectionStates[$connection] ?? [
            'active' => false,
            'invalid' => false,
        ];
    }

    /**
     * Modifie l'état partagé d'une connexion.
     */
    private static function setConnectionState(
        PDO $connection,
        array $state
    ): void {
        self::$connectionStates ??= new WeakMap();

        self::$connectionStates[$connection] = $state;
    }
}
