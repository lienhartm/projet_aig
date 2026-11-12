<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        string $message = 'Les données fournies sont invalides.',
        private readonly array $errors = [],
        ?Throwable $previous = null
    ) {
        foreach ($this->errors as $field => $messages) {
            if (
                !is_string($field)
                || trim($field) === ''
                || !is_array($messages)
                || !array_is_list($messages)
                || $messages === []
            ) {
                throw new InvalidArgumentException(
                    'Structure des erreurs de validation invalide.'
                );
            }

            foreach ($messages as $error) {
                if (!is_string($error) || trim($error) === '') {
                    throw new InvalidArgumentException(
                        'Un message de validation est invalide.'
                    );
                }
            }
        }

        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, list<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
