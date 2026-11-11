<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\AuthorizationException;
use InvalidArgumentException;

final class Authorization
{
    private const APPLICATION_ROLES = [
        'AIG_CONSULTATION',
        'AIG_REFERENTIEL',
        'AIG_IMP',
        'AIG_ADMIN_SECURITE',
    ];

    private const DATABASE_ROLE_MAPPING = [
        'AIG_CONSULTATION' => 'aig_consultation',
        'AIG_REFERENTIEL' => 'aig_referentiel',
        'AIG_IMP' => 'aig_imp',
        'AIG_ADMIN_SECURITE' => null,
    ];

    /**
     * Le contexte est fourni exclusivement par Authentication.
     *
     * Structure attendue :
     * [
     *     'id_utilisateur' => 123,
     *     'actif' => true,
     *     'roles' => [
     *         'AIG_CONSULTATION',
     *         'AIG_REFERENTIEL'
     *     ]
     * ]
     */
    public function __construct(
        private readonly ?array $userContext
    ) {
        if ($this->userContext === null) {
            return;
        }

        $id = filter_var(
            $this->userContext['id_utilisateur'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($id === false || $id <= 0) {
            throw new InvalidArgumentException(
                'Identifiant utilisateur invalide.'
            );
        }

        if (!is_array($this->userContext['roles'] ?? null)) {
            throw new InvalidArgumentException(
                'Liste des rôles utilisateur invalide.'
            );
        }

        foreach ($this->userContext['roles'] as $role) {
            if (
                !is_string($role)
                || !in_array($role, self::APPLICATION_ROLES, true)
            ) {
                throw new InvalidArgumentException(
                    'Un rôle applicatif est invalide.'
                );
            }
        }
    }

    public function isAuthenticated(): bool
    {
        if ($this->userContext === null) {
            return false;
        }

        $id = filter_var(
            $this->userContext['id_utilisateur'] ?? null,
            FILTER_VALIDATE_INT
        );

        return $id !== false && $id > 0;
    }

    public function isAccountActive(): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }

        $active = $this->userContext['actif'] ?? false;

        return $active === true
            || $active === 1
            || $active === '1';
    }

    public function requireAuthenticated(): void
    {
        if (!$this->isAuthenticated()) {
            throw new AuthorizationException(
                'Authentification requise.'
            );
        }
    }

    public function hasRole(string $role): bool
    {
        if (
            !in_array($role, self::APPLICATION_ROLES, true)
            || !$this->isAccountActive()
        ) {
            return false;
        }

        return in_array(
            $role,
            $this->userContext['roles'],
            true
        );
    }

    public function requireRole(string $role): void
    {
        $this->requireAuthenticated();

        if (!$this->isAccountActive()) {
            throw new AuthorizationException(
                'Le compte utilisateur est désactivé.'
            );
        }

        if (!in_array($role, self::APPLICATION_ROLES, true)) {
            throw new AuthorizationException(
                'Rôle applicatif inconnu.'
            );
        }

        if (!$this->hasRole($role)) {
            throw new AuthorizationException(
                'Droits insuffisants pour cette opération.'
            );
        }
    }

	public function requireAnyRole(array $roles): void
	{
	    $this->requireAuthenticated();

	    if (!$this->isAccountActive()) {
		throw new AuthorizationException(
		    'Le compte utilisateur est désactivé.'
		);
	    }

	    if ($roles === []) {
		throw new AuthorizationException(
		    'Aucun rôle autorisé n’a été spécifié.'
		);
	    }

	    // Première étape : valider intégralement les rôles demandés.
	    foreach ($roles as $role) {
		if (
		    !is_string($role)
		    || !in_array($role, self::APPLICATION_ROLES, true)
		) {
		    throw new AuthorizationException(
		        'Un rôle applicatif demandé est inconnu.'
		    );
		}
	    }

	    // Deuxième étape : rechercher au moins un rôle attribué.
	    foreach ($roles as $role) {
		if ($this->hasRole($role)) {
		    return;
		}
	    }

	    throw new AuthorizationException(
		'Aucun des rôles requis ne vous est attribué.'
	    );
	}
    public function getDatabaseRoleForAuthorizedRole(
        string $applicationRole
    ): ?string {
        $this->requireRole($applicationRole);

        return self::DATABASE_ROLE_MAPPING[$applicationRole];
    }
}
