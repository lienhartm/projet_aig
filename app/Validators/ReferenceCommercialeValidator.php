<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use InvalidArgumentException;

final class ReferenceCommercialeValidator
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    private const ALLOWED_ORDER_COLUMNS = [
        'id_fournisseur_produit',
        'date_creation',
        'reference_commerciale',
        'fournisseur_code',
        'fournisseur_raison_sociale',
        'produit_code',
        'produit_designation',
        'conditionnement_designation',
    ];

    /**
     * Valide et normalise les critères de recherche.
     *
     * @return array<string, int|string>
     */
    public function validerCriteresRecherche(array $criteres): array
    {
        $allowedKeys = [
            'id_fournisseur',
            'id_produit',
            'id_conditionnement',
            'reference_commerciale',
            'actif',
            'limite',
            'page',
            'ordre',
            'direction',
        ];

        $errors = [];

        foreach (array_keys($criteres) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                $errors['criteres'][] =
                    sprintf('Le critère "%s" n’est pas autorisé.', $key);
            }
        }

        $result = [
            'limite' => self::DEFAULT_LIMIT,
            'page' => 1,
            'ordre' => 'date_creation',
            'direction' => 'DESC',
        ];

        foreach (
            ['id_fournisseur', 'id_produit', 'id_conditionnement']
            as $field
        ) {
            if (!array_key_exists($field, $criteres)) {
                continue;
            }

            try {
                $result[$field] = $this->validerIdentifiant(
                    $criteres[$field],
                    $field
                );
            } catch (ValidationException $e) {
                $errors = array_merge_recursive(
                    $errors,
                    $e->getErrors()
                );
            }
        }

        if (array_key_exists('reference_commerciale', $criteres)) {
            $reference = $criteres['reference_commerciale'];

            if (
                !is_string($reference)
                || strlen(trim($reference)) > 100
                || (
                    trim($reference) !== ''
                    && preg_match(
                        '/\A[A-Za-z0-9.\/_ %\-]+\z/',
                        trim($reference)
                    ) !== 1
                )
            ) {
                $errors['reference_commerciale'][] =
                    'Le critère de référence commerciale est invalide.';
            } elseif (trim($reference) !== '') {
                $result['reference_commerciale'] = trim($reference);
            }
        }

        if (array_key_exists('actif', $criteres)) {
            $actif = $criteres['actif'];

            if (
                $actif === true
                || $actif === 1
                || $actif === '1'
            ) {
                $result['actif'] = 1;
            } elseif (
                $actif === false
                || $actif === 0
                || $actif === '0'
            ) {
                $result['actif'] = 0;
            } else {
                $errors['actif'][] =
                    'Le filtre actif doit être égal à 0 ou 1.';
            }
        }

        if (array_key_exists('limite', $criteres)) {
            try {
                $limite = $this->validerIdentifiant(
                    $criteres['limite'],
                    'limite'
                );

                if ($limite > self::MAX_LIMIT) {
                    $errors['limite'][] =
                        'La limite ne peut pas dépasser 200.';
                } else {
                    $result['limite'] = $limite;
                }
            } catch (ValidationException $e) {
                $errors = array_merge_recursive(
                    $errors,
                    $e->getErrors()
                );
            }
        }

        if (array_key_exists('page', $criteres)) {
            try {
                $result['page'] = $this->validerIdentifiant(
                    $criteres['page'],
                    'page'
                );
            } catch (ValidationException $e) {
                $errors = array_merge_recursive(
                    $errors,
                    $e->getErrors()
                );
            }
        }

        if (array_key_exists('ordre', $criteres)) {
            $ordre = $criteres['ordre'];

            if (
                !is_string($ordre)
                || !in_array(
                    $ordre,
                    self::ALLOWED_ORDER_COLUMNS,
                    true
                )
            ) {
                $errors['ordre'][] =
                    'La colonne de tri demandée n’est pas autorisée.';
            } else {
                $result['ordre'] = $ordre;
            }
        }

        if (array_key_exists('direction', $criteres)) {
            $direction = $criteres['direction'];

            if (!is_string($direction)) {
                $errors['direction'][] =
                    'La direction doit être ASC ou DESC.';
            } else {
                $direction = strtoupper($direction);

                if (!in_array($direction, ['ASC', 'DESC'], true)) {
                    $errors['direction'][] =
                        'La direction doit être ASC ou DESC.';
                } else {
                    $result['direction'] = $direction;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException(
                'Les critères de recherche sont invalides.',
                $errors
            );
        }

        return $result;
    }

    /**
     * Valide les données nécessaires à la création d'une association.
     *
     * @return array{
     *     id_fournisseur: int,
     *     id_produit: int,
     *     id_conditionnement: int,
     *     reference_commerciale: ?string
     * }
     */
    public function validerCreation(array $donnees): array
    {
        $allowedKeys = [
            'id_fournisseur',
            'id_produit',
            'id_conditionnement',
            'reference_commerciale',
        ];

        $errors = [];

        foreach (array_keys($donnees) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                $errors['donnees'][] =
                    sprintf('Le champ "%s" n’est pas autorisé.', $key);
            }
        }

        $result = [];

        foreach (
            ['id_fournisseur', 'id_produit', 'id_conditionnement']
            as $field
        ) {
            if (!array_key_exists($field, $donnees)) {
                $errors[$field][] = 'Ce champ est obligatoire.';
                continue;
            }

            try {
                $result[$field] = $this->validerIdentifiant(
                    $donnees[$field],
                    $field
                );
            } catch (ValidationException $e) {
                $errors = array_merge_recursive(
                    $errors,
                    $e->getErrors()
                );
            }
        }

        $reference = $donnees['reference_commerciale'] ?? null;

        if ($reference !== null) {
            if (!is_string($reference)) {
                $errors['reference_commerciale'][] =
                    'La référence commerciale doit être une chaîne ou null.';
            } else {
                try {
                    $result['reference_commerciale'] =
                        $this->validerNouvelleReference($reference);
                } catch (ValidationException $e) {
                    $errors = array_merge_recursive(
                        $errors,
                        $e->getErrors()
                    );
                }
            }
        } else {
            $result['reference_commerciale'] = null;
        }

        if ($errors !== []) {
            throw new ValidationException(
                'Les données de création sont invalides.',
                $errors
            );
        }

        return $result;
    }

    /**
     * Valide une nouvelle référence commerciale.
     */
    public function validerNouvelleReference(string $reference): string
    {
        if (
            $reference === ''
            || strlen($reference) > 100
            || trim($reference) !== $reference
            || preg_match('/\A[A-Za-z0-9.\/_ -]+\z/', $reference) !== 1
        ) {
            throw new ValidationException(
                'La référence commerciale est invalide.',
                [
                    'reference_commerciale' => [
                        'La référence doit contenir entre 1 et 100 caractères autorisés, sans espace périphérique.',
                    ],
                ]
            );
        }

        return $reference;
    }

    /**
     * Valide une justification obligatoire.
     */
    public function validerJustification(string $justification): string
    {
        $justification = trim($justification);
	$nombreCaracteres = preg_match_all('/./us', $justification);

	if (
	    $justification === ''
	    || $nombreCaracteres === false
	    || $nombreCaracteres > 500
	) {
	    throw new ValidationException(
		'La justification est invalide.',
		[
		    'justification' => [
		        'La justification doit contenir entre 1 et 500 caractères UTF-8 valides.',
		    ],
		]
	    );
	}

        return $justification;
    }

    /**
     * Valide un identifiant entier strictement positif.
     */
    public function validerIdentifiant(
        mixed $identifiant,
        string $nomChamp
    ): int {
        if (trim($nomChamp) === '') {
            throw new InvalidArgumentException(
                'Le nom du champ est obligatoire.'
            );
        }

        if (is_int($identifiant)) {
            if ($identifiant > 0) {
                return $identifiant;
            }
        } elseif (
            is_string($identifiant)
            && preg_match('/\A[0-9]+\z/', $identifiant) === 1
        ) {
            $normalise = ltrim($identifiant, '0');

            if (
                $normalise !== ''
                && (
                    strlen($normalise) < strlen((string) PHP_INT_MAX)
                    || (
                        strlen($normalise) === strlen((string) PHP_INT_MAX)
                        && strcmp(
                            $normalise,
                            (string) PHP_INT_MAX
                        ) <= 0
                    )
                )
            ) {
                return (int) $normalise;
            }
        }

        throw new ValidationException(
            'Un ou plusieurs identifiants sont invalides.',
            [
                $nomChamp => [
                    'L’identifiant doit être un entier strictement positif.',
                ],
            ]
        );
    }
}
