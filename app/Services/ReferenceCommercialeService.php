<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Authorization;
use App\Core\DatabaseRoleManager;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ConcurrencyException;
use App\Exceptions\EntityNotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\TransactionRollbackException;
use App\Validators\ReferenceCommercialeValidator;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use JsonException;

final class ReferenceCommercialeService
{
    private const ROLE_READ = 'AIG_CONSULTATION';
    private const ROLE_WRITE = 'AIG_REFERENTIEL';

    private const MAX_SNAPSHOT_LENGTH = 500;
    private const MAX_COMPOSITION_DEPTH = 10;
    private const MAX_COMPOSITION_COMPONENTS = 100;

    public function __construct(
        private readonly DatabaseRoleManager $roleManager,
        private readonly Authorization $authorization,
        private readonly ReferenceCommercialeValidator $validator
    ) {
    }

    /**
     * Recherche les associations commerciales.
     *
     * @return list<array<string, mixed>>
     */
    public function rechercherAssociations(array $criteres): array
    {
        $role = $this->getDatabaseRole(self::ROLE_READ);
        $criteres = $this->validator->validerCriteresRecherche($criteres);

        $limite = $criteres['limite'];
        $page = $criteres['page'];

        if ($page - 1 > intdiv(PHP_INT_MAX, $limite)) {
            throw new ValidationException(
                'La pagination demandée est trop importante.',
                [
                    'page' => [
                        'La valeur demandée dépasse la capacité de pagination.',
                    ],
                ]
            );
        }

        $offset = ($page - 1) * $limite;

        return $this->roleManager->runWithRole(
            $role,
            function (PDO $pdo) use (
                $criteres,
                $limite,
                $offset
            ): array {
                $where = [];
                $parameters = [];

                foreach (
                    [
                        'id_fournisseur',
                        'id_produit',
                        'id_conditionnement',
                    ] as $field
                ) {
                    if (array_key_exists($field, $criteres)) {
                        $where[] = 'fp.' . $field . ' = :' . $field;
                        $parameters[$field] = $criteres[$field];
                    }
                }

                if (array_key_exists('actif', $criteres)) {
                    $where[] = 'fp.actif = :actif';
                    $parameters['actif'] = $criteres['actif'];
                }

                if (
                    array_key_exists(
                        'reference_commerciale',
                        $criteres
                    )
                ) {
                    $reference = strtr(
                        $criteres['reference_commerciale'],
                        [
                            '!' => '!!',
                            '%' => '!%',
                            '_' => '!_',
                        ]
                    );

                    $where[] = "
                        UPPER(fp.reference_commerciale)
                        LIKE UPPER(:reference)
                        ESCAPE '!'
                    ";

                    $parameters['reference'] = '%' . $reference . '%';
                }

                $sql = '
                    SELECT
                        fp.id_fournisseur_produit,
                        fp.id_fournisseur,
                        fp.id_produit,
                        fp.id_conditionnement,
                        fp.reference_commerciale,
                        fp.actif,
                        fp.date_creation,
                        fp.date_modification,
                        f.code_interne AS fournisseur_code,
                        f.raison_sociale AS fournisseur_raison_sociale,
                        p.code AS produit_code,
                        p.designation AS produit_designation,
                        c.designation AS conditionnement_designation
                    FROM fournisseur_produit fp
                    INNER JOIN fournisseur f
                        ON f.id_fournisseur = fp.id_fournisseur
                    INNER JOIN produit p
                        ON p.id_produit = fp.id_produit
                    INNER JOIN conditionnement c
                        ON c.id_conditionnement = fp.id_conditionnement
                ';

                if ($where !== []) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }

                $orderColumns = [
                    'id_fournisseur_produit' =>
                        'fp.id_fournisseur_produit',
                    'date_creation' => 'fp.date_creation',
                    'reference_commerciale' =>
                        'fp.reference_commerciale',
                    'fournisseur_code' => 'f.code_interne',
                    'fournisseur_raison_sociale' =>
                        'f.raison_sociale',
                    'produit_code' => 'p.code',
                    'produit_designation' => 'p.designation',
                    'conditionnement_designation' =>
                        'c.designation',
                ];

                $orderColumn = $orderColumns[$criteres['ordre']];
                $direction = $criteres['direction'];

                $sql .= "
                    ORDER BY {$orderColumn} {$direction},
                             fp.id_fournisseur_produit DESC
                    LIMIT :limite OFFSET :offset
                ";

                $statement = $pdo->prepare($sql);

                foreach ($parameters as $name => $value) {
                    $statement->bindValue(
                        ':' . $name,
                        $value,
                        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
                    );
                }

                $statement->bindValue(
                    ':limite',
                    $limite,
                    PDO::PARAM_INT
                );

                $statement->bindValue(
                    ':offset',
                    $offset,
                    PDO::PARAM_INT
                );

                $statement->execute();

                return $statement->fetchAll(PDO::FETCH_ASSOC);
            }
        );
    }

    /**
     * Récupère une association.
     */
    public function obtenirAssociation(int $idAssociation): ?array
    {
        $role = $this->getDatabaseRole(self::ROLE_READ);

        $idAssociation = $this->validator->validerIdentifiant(
            $idAssociation,
            'id_fournisseur_produit'
        );

        return $this->roleManager->runWithRole(
            $role,
            function (PDO $pdo) use ($idAssociation): ?array {
                $statement = $pdo->prepare('
                    SELECT
                        fp.id_fournisseur_produit,
                        fp.id_fournisseur,
                        fp.id_produit,
                        fp.id_conditionnement,
                        fp.reference_commerciale,
                        fp.actif,
                        fp.date_creation,
                        fp.date_modification,
                        f.code_interne AS fournisseur_code,
                        f.raison_sociale AS fournisseur_raison_sociale,
                        p.code AS produit_code,
                        p.designation AS produit_designation,
                        c.designation AS conditionnement_designation
                    FROM fournisseur_produit fp
                    INNER JOIN fournisseur f
                        ON f.id_fournisseur = fp.id_fournisseur
                    INNER JOIN produit p
                        ON p.id_produit = fp.id_produit
                    INNER JOIN conditionnement c
                        ON c.id_conditionnement = fp.id_conditionnement
                    WHERE fp.id_fournisseur_produit = :id
                ');

                $statement->execute(['id' => $idAssociation]);

                $result = $statement->fetch(PDO::FETCH_ASSOC);

                return $result === false ? null : $result;
            }
        );
    }

    /**
     * Crée une association commerciale et son historique.
     */
    public function creerAssociation(array $donnees): int
    {
        $acteur = $this->authorization->getAuthenticatedUserId();
        $role = $this->getDatabaseRole(self::ROLE_WRITE);

        $donnees = $this->validator->validerCreation($donnees);

        return $this->roleManager->runWithRole(
            $role,
            function (PDO $pdo) use ($donnees, $acteur): int {
                return $this->transaction(
                    $pdo,
                    function () use ($pdo, $donnees, $acteur): int {
                        $snapshots = $this->verrouillerReferentiels(
                            $pdo,
                            $donnees['id_fournisseur'],
                            $donnees['id_produit'],
                            $donnees['id_conditionnement'],
                            true
                        );

                        $statement = $pdo->prepare('
                            INSERT INTO fournisseur_produit (
                                id_fournisseur,
                                id_produit,
                                id_conditionnement,
                                reference_commerciale,
                                actif
                            ) VALUES (
                                :fournisseur,
                                :produit,
                                :conditionnement,
                                :reference,
                                1
                            )
                        ');

                        $statement->execute([
                            'fournisseur' => $donnees['id_fournisseur'],
                            'produit' => $donnees['id_produit'],
                            'conditionnement' =>
                                $donnees['id_conditionnement'],
                            'reference' =>
                                $donnees['reference_commerciale'],
                        ]);

                        $idAssociation = $this->getLastInsertId($pdo);

                        $this->enregistrerHistorique(
                            $pdo,
                            $idAssociation,
                            null,
                            $donnees['reference_commerciale'],
                            'CREATION_ASSOCIATION',
                            null,
                            $acteur,
                            $snapshots
                        );

                        return $idAssociation;
                    },
                    'association'
                );
            }
        );
    }

    /**
     * Modifie une référence commerciale.
     */
    public function modifierReference(
        int $idAssociation,
        string $nouvelleReference,
        string $justification
    ): void {
        $acteur = $this->authorization->getAuthenticatedUserId();
        $role = $this->getDatabaseRole(self::ROLE_WRITE);

        $idAssociation = $this->validator->validerIdentifiant(
            $idAssociation,
            'id_fournisseur_produit'
        );

        $nouvelleReference =
            $this->validator->validerNouvelleReference(
                $nouvelleReference
            );

        $justification =
            $this->validator->validerJustification($justification);

        $this->roleManager->runWithRole(
            $role,
            function (PDO $pdo) use (
                $idAssociation,
                $nouvelleReference,
                $justification,
                $acteur
            ): void {
                $this->transaction(
                    $pdo,
                    function () use (
                        $pdo,
                        $idAssociation,
                        $nouvelleReference,
                        $justification,
                        $acteur
                    ): void {
                        $association = $this->verrouillerAssociation(
                            $pdo,
                            $idAssociation
                        );

                        $ancienneReference =
                            $association['reference_commerciale'];

                        if ($ancienneReference === $nouvelleReference) {
                            return;
                        }

                        $snapshots = $this->verrouillerReferentiels(
                            $pdo,
                            (int) $association['id_fournisseur'],
                            (int) $association['id_produit'],
                            (int) $association['id_conditionnement'],
                            false
                        );

                        $statement = $pdo->prepare('
                            UPDATE fournisseur_produit
                            SET
                                reference_commerciale = :reference,
                                date_modification = CURRENT_TIMESTAMP(6)
                            WHERE id_fournisseur_produit = :id
                        ');

                        $statement->execute([
                            'reference' => $nouvelleReference,
                            'id' => $idAssociation,
                        ]);

                        $this->enregistrerHistorique(
                            $pdo,
                            $idAssociation,
                            $ancienneReference,
                            $nouvelleReference,
                            'MODIFICATION_REFERENCE',
                            $justification,
                            $acteur,
                            $snapshots
                        );
                    },
                    'reference_commerciale'
                );
            }
        );
    }

    /**
     * Supprime une référence sans supprimer l'association.
     */
    public function supprimerReference(
        int $idAssociation,
        string $justification
    ): void {
        $acteur = $this->authorization->getAuthenticatedUserId();
        $role = $this->getDatabaseRole(self::ROLE_WRITE);

        $idAssociation = $this->validator->validerIdentifiant(
            $idAssociation,
            'id_fournisseur_produit'
        );

        $justification =
            $this->validator->validerJustification($justification);

        $this->roleManager->runWithRole(
            $role,
            function (PDO $pdo) use (
                $idAssociation,
                $justification,
                $acteur
            ): void {
                $this->transaction(
                    $pdo,
                    function () use (
                        $pdo,
                        $idAssociation,
                        $justification,
                        $acteur
                    ): void {
                        $association = $this->verrouillerAssociation(
                            $pdo,
                            $idAssociation
                        );

                        $ancienneReference =
                            $association['reference_commerciale'];

                        if ($ancienneReference === null) {
                            return;
                        }

                        $snapshots = $this->verrouillerReferentiels(
                            $pdo,
                            (int) $association['id_fournisseur'],
                            (int) $association['id_produit'],
                            (int) $association['id_conditionnement'],
                            false
                        );

                        $statement = $pdo->prepare('
                            UPDATE fournisseur_produit
                            SET
                                reference_commerciale = NULL,
                                date_modification = CURRENT_TIMESTAMP(6)
                            WHERE id_fournisseur_produit = :id
                        ');

                        $statement->execute(['id' => $idAssociation]);

                        $this->enregistrerHistorique(
                            $pdo,
                            $idAssociation,
                            $ancienneReference,
                            null,
                            'SUPPRESSION_REFERENCE',
                            $justification,
                            $acteur,
                            $snapshots
                        );
                    },
                    'reference_commerciale'
                );
            }
        );
    }

    /**
     * Désactive une association sans la supprimer.
     */
    public function desactiverAssociation(
        int $idAssociation,
        string $justification
    ): void {
        $acteur = $this->authorization->getAuthenticatedUserId();
        $role = $this->getDatabaseRole(self::ROLE_WRITE);

        $idAssociation = $this->validator->validerIdentifiant(
            $idAssociation,
            'id_fournisseur_produit'
        );

        $justification =
            $this->validator->validerJustification($justification);

        $this->roleManager->runWithRole(
            $role,
            function (PDO $pdo) use (
                $idAssociation,
                $justification,
                $acteur
            ): void {
                $this->transaction(
                    $pdo,
                    function () use (
                        $pdo,
                        $idAssociation,
                        $justification,
                        $acteur
                    ): void {
                        $association = $this->verrouillerAssociation(
                            $pdo,
                            $idAssociation
                        );

                        if ((int) $association['actif'] === 0) {
                            return;
                        }

                        $snapshots = $this->verrouillerReferentiels(
                            $pdo,
                            (int) $association['id_fournisseur'],
                            (int) $association['id_produit'],
                            (int) $association['id_conditionnement'],
                            false
                        );

                        $statement = $pdo->prepare('
                            UPDATE fournisseur_produit
                            SET
                                actif = 0,
                                date_modification = CURRENT_TIMESTAMP(6)
                            WHERE id_fournisseur_produit = :id
                        ');

                        $statement->execute(['id' => $idAssociation]);

                        $this->enregistrerHistorique(
                            $pdo,
                            $idAssociation,
                            $association['reference_commerciale'],
                            $association['reference_commerciale'],
                            'DESACTIVATION_ASSOCIATION',
                            $justification,
                            $acteur,
                            $snapshots
                        );
                    },
                    'association'
                );
            }
        );
    }

    /**
     * Réactive une association si ses référentiels sont actifs.
     */
    public function reactiverAssociation(int $idAssociation): void
    {
        $acteur = $this->authorization->getAuthenticatedUserId();
        $role = $this->getDatabaseRole(self::ROLE_WRITE);

        $idAssociation = $this->validator->validerIdentifiant(
            $idAssociation,
            'id_fournisseur_produit'
        );

        $this->roleManager->runWithRole(
            $role,
            function (PDO $pdo) use ($idAssociation, $acteur): void {
                $this->transaction(
                    $pdo,
                    function () use ($pdo, $idAssociation, $acteur): void {
                        $association = $this->verrouillerAssociation(
                            $pdo,
                            $idAssociation
                        );

                        if ((int) $association['actif'] === 1) {
                            return;
                        }

                        $snapshots = $this->verrouillerReferentiels(
                            $pdo,
                            (int) $association['id_fournisseur'],
                            (int) $association['id_produit'],
                            (int) $association['id_conditionnement'],
                            true
                        );

                        $statement = $pdo->prepare('
                            UPDATE fournisseur_produit
                            SET
                                actif = 1,
                                date_modification = CURRENT_TIMESTAMP(6)
                            WHERE id_fournisseur_produit = :id
                        ');

                        $statement->execute(['id' => $idAssociation]);

                        $this->enregistrerHistorique(
                            $pdo,
                            $idAssociation,
                            $association['reference_commerciale'],
                            $association['reference_commerciale'],
                            'REACTIVATION_ASSOCIATION',
                            null,
                            $acteur,
                            $snapshots
                        );
                    },
                    'association'
                );
            }
        );
    }

    /**
     * Consulte l'historique d'une association.
     *
     * @return list<array<string, mixed>>
     */
    public function consulterHistorique(int $idAssociation): array
    {
        $role = $this->getDatabaseRole(self::ROLE_READ);

        $idAssociation = $this->validator->validerIdentifiant(
            $idAssociation,
            'id_fournisseur_produit'
        );

        return $this->roleManager->runWithRole(
            $role,
            function (PDO $pdo) use ($idAssociation): array {
                $check = $pdo->prepare('
                    SELECT id_fournisseur_produit
                    FROM fournisseur_produit
                    WHERE id_fournisseur_produit = :id
                ');

                $check->execute(['id' => $idAssociation]);

                if ($check->fetchColumn() === false) {
                    throw new EntityNotFoundException(
                        'fournisseur_produit',
                        $idAssociation
                    );
                }

                $statement = $pdo->prepare('
                    SELECT
                        id_historique_reference,
                        id_fournisseur_produit,
                        ancienne_reference,
                        nouvelle_reference,
                        type_operation,
                        justification,
                        id_acteur,
                        date_operation,
                        fournisseur_code_snapshot,
                        fournisseur_raison_sociale_snapshot,
                        produit_code_snapshot,
                        produit_designation_snapshot,
                        conditionnement_snapshot,
                        details
                    FROM historique_reference
                    WHERE id_fournisseur_produit = :id
                    ORDER BY
                        date_operation DESC,
                        id_historique_reference DESC
                ');

                $statement->execute(['id' => $idAssociation]);

                $historique = $statement->fetchAll(PDO::FETCH_ASSOC);

		foreach ($historique as &$ligne) {
		    if ($ligne['details'] !== null) {
			try {
			    $details = json_decode(
				$ligne['details'],
				true,
				512,
				JSON_THROW_ON_ERROR
			    );
			} catch (JsonException $e) {
			    throw new RuntimeException(
				'Les détails d’un historique sont illisibles.',
				0,
				$e
			    );
			}

			if (!is_array($details)) {
			    throw new RuntimeException(
				'Les détails d’un historique doivent contenir un objet ou un tableau JSON.'
			    );
			}

			$ligne['details'] = $details;
		    }
		}

		unset($ligne);

                return $historique;
            }
        );
    }

    /**
     * Vérifie un rôle applicatif et retourne son rôle MariaDB.
     */
    private function getDatabaseRole(string $applicationRole): string
    {
        $databaseRole =
            $this->authorization->getDatabaseRoleForAuthorizedRole(
                $applicationRole
            );

        if ($databaseRole === null) {
            throw new AuthorizationException(
                'Aucun rôle MariaDB associé au rôle applicatif.'
            );
        }

        return $databaseRole;
    }

    /**
     * Exécute une transaction complète.
     *
     * La transaction est terminée avant le retour au DatabaseRoleManager.
     */
    private function transaction(
        PDO $pdo,
        callable $operation,
        string $duplicateField
    ): mixed {
        try {
            $pdo->beginTransaction();

            $result = $operation();

            $pdo->commit();

            return $result;
	} catch (Throwable $e) {
	    if ($pdo->inTransaction()) {
		try {
		    $pdo->rollBack();
		} catch (Throwable $rollbackException) {
		    throw new TransactionRollbackException(
			$e,
			$rollbackException
		    );
		}
	    }

	    if ($e instanceof PDOException) {
                $driverCode = (int) ($e->errorInfo[1] ?? 0);

                if ($driverCode === 1205 || $driverCode === 1213) {
                    throw new ConcurrencyException(
                        'La transaction a été interrompue par un conflit concurrent.',
                        $e
                    );
                }

		if ($driverCode === 1062) {
		    $duplicateMessage = (string) (
			$e->errorInfo[2] ?? $e->getMessage()
		    );

		    if (str_contains(
			$duplicateMessage,
			'uq_fp_reference_fournisseur'
		    )) {
			throw new ValidationException(
			    'Cette référence commerciale existe déjà chez ce fournisseur.',
			    [
				'reference_commerciale' => [
				    'Une référence identique existe déjà chez ce fournisseur.',
				],
			    ],
			    $e
			);
		    }

		    if (
			str_contains(
			    $duplicateMessage,
			    'uq_fournisseur_produit_conditionnement'
			)
			|| str_contains(
			    $duplicateMessage,
			    'uq_fp_identite_complete'
			)
		    ) {
			throw new ValidationException(
			    'Cette association fournisseur-produit-conditionnement existe déjà.',
			    [
				'association' => [
				    'Une association identique existe déjà.',
				],
			    ],
			    $e
			);
		    }

		    throw new ValidationException(
			'Une contrainte d’unicité empêche cette opération.',
			[
			    $duplicateField => [
				'Une valeur déjà utilisée empêche cette opération.',
			    ],
			],
			$e
		    );
		}
            }

            throw $e;
        }
    }

    /**
     * Verrouille une association existante.
     */
    private function verrouillerAssociation(
        PDO $pdo,
        int $idAssociation
    ): array {
        $statement = $pdo->prepare('
            SELECT
                id_fournisseur_produit,
                id_fournisseur,
                id_produit,
                id_conditionnement,
                reference_commerciale,
                actif
            FROM fournisseur_produit
            WHERE id_fournisseur_produit = :id
            FOR UPDATE
        ');

        $statement->execute(['id' => $idAssociation]);

        $association = $statement->fetch(PDO::FETCH_ASSOC);

        if ($association === false) {
            throw new EntityNotFoundException(
                'fournisseur_produit',
                $idAssociation
            );
        }

        return $association;
    }

    /**
     * Verrouille les référentiels et construit leurs snapshots.
     */
    private function verrouillerReferentiels(
        PDO $pdo,
        int $idFournisseur,
        int $idProduit,
        int $idConditionnement,
        bool $exigerActifs
    ): array {
        $fournisseur = $this->fetchLockedEntity(
            $pdo,
            '
                SELECT
                    id_fournisseur,
                    code_interne,
                    raison_sociale,
                    actif
                FROM fournisseur
                WHERE id_fournisseur = :id
                FOR UPDATE
            ',
            $idFournisseur,
            'fournisseur'
        );

        $produit = $this->fetchLockedEntity(
            $pdo,
            '
                SELECT
                    id_produit,
                    code,
                    designation,
                    actif
                FROM produit
                WHERE id_produit = :id
                FOR UPDATE
            ',
            $idProduit,
            'produit'
        );

        $conditionnement = $this->fetchLockedEntity(
            $pdo,
            '
                SELECT id_conditionnement, actif
                FROM conditionnement
                WHERE id_conditionnement = :id
                FOR UPDATE
            ',
            $idConditionnement,
            'conditionnement'
        );

        if ($exigerActifs) {
            foreach (
                [
                    'fournisseur' => $fournisseur,
                    'produit' => $produit,
                    'conditionnement' => $conditionnement,
                ] as $entity => $row
            ) {
                if ((int) $row['actif'] !== 1) {
                    throw new ValidationException(
                        'Un référentiel nécessaire est inactif.',
                        [
                            $entity => [
                                'Le référentiel doit être actif pour cette opération.',
                            ],
                        ]
                    );
                }
            }
        }

        return [
            'fournisseur_code' => $fournisseur['code_interne'],
            'fournisseur_raison_sociale' =>
                $fournisseur['raison_sociale'],
            'produit_code' => $produit['code'],
            'produit_designation' => $produit['designation'],
            'conditionnement' =>
                $this->construireSnapshotConditionnement(
                    $pdo,
                    $idConditionnement
                ),
        ];
    }

    /**
     * Charge et verrouille un référentiel.
     */
    private function fetchLockedEntity(
        PDO $pdo,
        string $sql,
        int $identifier,
        string $entity
    ): array {
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $identifier]);

        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if ($result === false) {
            throw new EntityNotFoundException($entity, $identifier);
        }

        return $result;
    }

    /**
     * Construit le snapshot complet d'un conditionnement.
     */
    private function construireSnapshotConditionnement(
        PDO $pdo,
        int $idConditionnement
    ): string {
        $root = $this->fetchConditionnementNode(
            $pdo,
            $idConditionnement
        );

        $counter = 0;

        $snapshot = $this->formaterConditionnementNode(
            $pdo,
            $root,
            [],
            1,
            $counter
        );

        $length = preg_match_all('/./us', $snapshot);

        if (
            $length === false
            || $length > self::MAX_SNAPSHOT_LENGTH
        ) {
            throw new ValidationException(
                'Le snapshot du conditionnement est trop long.',
                [
                    'conditionnement' => [
                        'Le snapshot dépasse la limite de 500 caractères.',
                    ],
                ]
            );
        }

        return $snapshot;
    }

    /**
     * Charge un nœud de conditionnement avec ses informations de référence.
     */
    private function fetchConditionnementNode(
        PDO $pdo,
        int $idConditionnement
    ): array {
        return $this->fetchLockedEntity(
            $pdo,
            '
                SELECT
                    c.id_conditionnement,
                    c.mode_contenu,
                    c.quantite_contenu,
                    c.designation,
                    t.code AS type_code,
                    t.libelle AS type_libelle,
                    u.code AS unite_code
                FROM conditionnement c
                INNER JOIN type_conditionnement t
                    ON t.id_type_conditionnement =
                       c.id_type_conditionnement
                LEFT JOIN unite_mesure u
                    ON u.id_unite_mesure = c.id_unite_mesure
                WHERE c.id_conditionnement = :id
                FOR UPDATE
            ',
            $idConditionnement,
            'conditionnement'
        );
    }

/**
 * Formate le conditionnement racine avec ses informations complètes.
 *
 * @param list<int> $path
 */
private function formaterConditionnementNode(
    PDO $pdo,
    array $node,
    array $path,
    int $depth,
    int &$counter
): string {
    $id = (int) $node['id_conditionnement'];

    if (in_array($id, $path, true)) {
        throw new ValidationException(
            'Un cycle existe dans la composition du conditionnement.',
            [
                'conditionnement' => [
                    'La composition contient une référence circulaire.',
                ],
            ]
        );
    }

    if ($depth > self::MAX_COMPOSITION_DEPTH) {
        throw new ValidationException(
            'La composition du conditionnement est trop profonde.',
            [
                'conditionnement' => [
                    'La profondeur maximale de 10 niveaux est dépassée.',
                ],
            ]
        );
    }

    $path[] = $id;

    $designation = $node['designation'] ?? '';

    $type = $node['type_code']
        . ' ('
        . $node['type_libelle']
        . ')';

    $snapshot = sprintf(
        'ID=%d | TYPE=%s | MODE=%s | DESIGNATION=%s',
        $id,
        $type,
        $node['mode_contenu'],
        $designation
    );

    if ($node['mode_contenu'] === 'ELEMENTAIRE') {
        $quantite = $this->normaliserDecimal(
            (string) $node['quantite_contenu']
        );

        return $snapshot
            . ' | CONTENU='
            . $quantite
            . ' '
            . $node['unite_code'];
    }

    $statement = $pdo->prepare('
        SELECT
            cc.quantite_enfant,
            c.id_conditionnement,
            c.mode_contenu,
            c.quantite_contenu,
            c.designation,
            t.code AS type_code,
            t.libelle AS type_libelle,
            u.code AS unite_code
        FROM composition_conditionnement cc
        INNER JOIN conditionnement c
            ON c.id_conditionnement = cc.id_conditionnement_enfant
        INNER JOIN type_conditionnement t
            ON t.id_type_conditionnement = c.id_type_conditionnement
        LEFT JOIN unite_mesure u
            ON u.id_unite_mesure = c.id_unite_mesure
        WHERE cc.id_conditionnement_parent = :id
        ORDER BY cc.id_conditionnement_enfant
        FOR UPDATE
    ');

    $statement->execute(['id' => $id]);

    $components = [];

    while ($child = $statement->fetch(PDO::FETCH_ASSOC)) {
        $counter++;

        if ($counter > self::MAX_COMPOSITION_COMPONENTS) {
            throw new ValidationException(
                'La composition du conditionnement est trop importante.',
                [
                    'conditionnement' => [
                        'La limite de 100 composants parcourus est dépassée.',
                    ],
                ]
            );
        }

        $quantity = $this->normaliserDecimal(
            (string) $child['quantite_enfant']
        );

        $childSnapshot = $this->formaterComposantConditionnement(
            $pdo,
            $child,
            $path,
            $depth + 1,
            $counter
        );

        $components[] = $quantity . 'x' . $childSnapshot;
    }

    return $snapshot
        . ' | COMPOSITION='
        . implode(',', $components);
}

	/**
	 * Formate un composant sous une forme compacte et récursive.
	 *
	 * Exemples :
	 * CARTON#12
	 * PALETTE#15{10xCARTON#12}
	 *
	 * @param list<int> $path
	 */
	private function formaterComposantConditionnement(
	    PDO $pdo,
	    array $node,
	    array $path,
	    int $depth,
	    int &$counter
	): string {
	    $id = (int) $node['id_conditionnement'];

	    if (in_array($id, $path, true)) {
		throw new ValidationException(
		    'Un cycle existe dans la composition du conditionnement.',
		    [
		        'conditionnement' => [
		            'La composition contient une référence circulaire.',
		        ],
		    ]
		);
	    }

	    if ($depth > self::MAX_COMPOSITION_DEPTH) {
		throw new ValidationException(
		    'La composition du conditionnement est trop profonde.',
		    [
		        'conditionnement' => [
		            'La profondeur maximale de 10 niveaux est dépassée.',
		        ],
		    ]
		);
	    }

	    $component = $node['type_code'] . '#' . $id;

	    if ($node['mode_contenu'] === 'ELEMENTAIRE') {
		return $component;
	    }

	    $path[] = $id;

	    $statement = $pdo->prepare('
		SELECT
		    cc.quantite_enfant,
		    c.id_conditionnement,
		    c.mode_contenu,
		    c.quantite_contenu,
		    c.designation,
		    t.code AS type_code,
		    t.libelle AS type_libelle,
		    u.code AS unite_code
		FROM composition_conditionnement cc
		INNER JOIN conditionnement c
		    ON c.id_conditionnement = cc.id_conditionnement_enfant
		INNER JOIN type_conditionnement t
		    ON t.id_type_conditionnement = c.id_type_conditionnement
		LEFT JOIN unite_mesure u
		    ON u.id_unite_mesure = c.id_unite_mesure
		WHERE cc.id_conditionnement_parent = :id
		ORDER BY cc.id_conditionnement_enfant
		FOR UPDATE
	    ');

	    $statement->execute(['id' => $id]);

	    $components = [];

	    while ($child = $statement->fetch(PDO::FETCH_ASSOC)) {
		$counter++;

		if ($counter > self::MAX_COMPOSITION_COMPONENTS) {
		    throw new ValidationException(
		        'La composition du conditionnement est trop importante.',
		        [
		            'conditionnement' => [
		                'La limite de 100 composants parcourus est dépassée.',
		            ],
		        ]
		    );
		}

		$quantity = $this->normaliserDecimal(
		    (string) $child['quantite_enfant']
		);

		$childSnapshot = $this->formaterComposantConditionnement(
		    $pdo,
		    $child,
		    $path,
		    $depth + 1,
		    $counter
		);

		$components[] = $quantity . 'x' . $childSnapshot;
	    }

	    return $component
		. '{'
		. implode(',', $components)
		. '}';
	}
    /**
     * Normalise l'affichage d'une valeur DECIMAL.
     */
    private function normaliserDecimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? '0' : $value;
    }

    /**
     * Insère une entrée d'historique.
     */
    private function enregistrerHistorique(
        PDO $pdo,
        int $idAssociation,
        ?string $ancienneReference,
        ?string $nouvelleReference,
        string $typeOperation,
        ?string $justification,
        int $acteur,
        array $snapshots
    ): void {
        $statement = $pdo->prepare('
            INSERT INTO historique_reference (
                id_fournisseur_produit,
                ancienne_reference,
                nouvelle_reference,
                type_operation,
                justification,
                id_acteur,
                fournisseur_code_snapshot,
                fournisseur_raison_sociale_snapshot,
                produit_code_snapshot,
                produit_designation_snapshot,
                conditionnement_snapshot,
                details
            ) VALUES (
                :association,
                :ancienne,
                :nouvelle,
                :operation,
                :justification,
                :acteur,
                :fournisseur_code,
                :fournisseur_raison_sociale,
                :produit_code,
                :produit_designation,
                :conditionnement,
                NULL
            )
        ');

        $statement->execute([
            'association' => $idAssociation,
            'ancienne' => $ancienneReference,
            'nouvelle' => $nouvelleReference,
            'operation' => $typeOperation,
            'justification' => $justification,
            'acteur' => $acteur,
            'fournisseur_code' => $snapshots['fournisseur_code'],
            'fournisseur_raison_sociale' =>
                $snapshots['fournisseur_raison_sociale'],
            'produit_code' => $snapshots['produit_code'],
            'produit_designation' => $snapshots['produit_designation'],
            'conditionnement' => $snapshots['conditionnement'],
        ]);
    }

    /**
     * Récupère l'identifiant créé par MariaDB.
     */
    private function getLastInsertId(PDO $pdo): int
    {
        $identifier = $pdo->lastInsertId();

        $validated = filter_var(
            $identifier,
            FILTER_VALIDATE_INT
        );

        if ($validated === false || $validated <= 0) {
            throw new RuntimeException(
                'MariaDB a retourné un identifiant invalide.'
            );
        }

        return $validated;
    }
}
