<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class ConcurrencyException extends RuntimeException
{
    public function __construct(
        string $message = 'Une modification concurrente empêche cette opération.',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
