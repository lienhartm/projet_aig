<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EntityNotFoundException extends RuntimeException
{
    public function __construct(
        private readonly string $entity,
        private readonly int $identifier,
        ?Throwable $previous = null
    ) {
        if (trim($this->entity) === '') {
            throw new InvalidArgumentException(
                'Le nom de l’entité est obligatoire.'
            );
        }

        if ($this->identifier <= 0) {
            throw new InvalidArgumentException(
                'L’identifiant de l’entité doit être positif.'
            );
        }

        parent::__construct(
            'L’entité demandée est introuvable.',
            0,
            $previous
        );
    }

    public function getEntity(): string
    {
        return $this->entity;
    }

    public function getIdentifier(): int
    {
        return $this->identifier;
    }
}
