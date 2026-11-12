<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class TransactionRollbackException extends RuntimeException
{
    public function __construct(
        private readonly Throwable $operationException,
        private readonly Throwable $rollbackException
    ) {
        parent::__construct(
            'L’opération a échoué et le rollback de la transaction a également échoué.',
            0,
            $operationException
        );
    }

    public function getOperationException(): Throwable
    {
        return $this->operationException;
    }

    public function getRollbackException(): Throwable
    {
        return $this->rollbackException;
    }
}
