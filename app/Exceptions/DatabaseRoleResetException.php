<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class DatabaseRoleResetException extends RuntimeException
{
    public function __construct(
        private readonly ?Throwable $operationException,
        private readonly Throwable $resetException
    ) {
        parent::__construct(
            $operationException !== null
                ? 'Échec de l’opération et de la restauration du rôle MariaDB.'
                : 'Impossible de restaurer le rôle minimal MariaDB.',
            0,
            $resetException
        );
    }

    public function getOperationException(): ?Throwable
    {
        return $this->operationException;
    }

    public function getResetException(): Throwable
    {
        return $this->resetException;
    }
}
