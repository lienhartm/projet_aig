<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Authentication
{
    private const ARGON2ID_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 3,
        'threads' => 1,
    ];

    private const LOCK_DURATIONS = [
        1 => 5,
        2 => 15,
        3 => 60,
    ];

    private static ?string $dummyPasswordHash = null;

    private readonly string $hmacKey;

    public function __construct(
        private readonly PDO $connection,
        private readonly SessionManager $sessionManager,
        string $hmacKeyHex
    ) {
        if (!preg_match('/\A[a-f0-9]{64}\z/i', $hmacKeyHex)) {
            throw new RuntimeException(
                'La clé HMAC est absente ou invalide.'
            );
        }

        $key = hex2bin($hmacKeyHex);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'Impossible de charger la clé HMAC.'
            );
        }

        $this->hmacKey = $key;
    }

    /**
     * Création à partir de la configuration de l'application.
     */
    public static function fromEnvironment(
        SessionManager $sessionManager
    ): self {
        $key = $_ENV['AUTH_IDENTIFIER_HMAC_KEY'] ?? '';

        if (!is_string($key) || $key === '') {
            throw new RuntimeException(
                'Configuration HMAC manquante.'
            );
        }

        return new self(
            AuthDatabaseConnection::getConnection(),
            $sessionManager,
            $key
        );
    }

    /**
     * Authentifie un utilisateur.
     *
     * Retourne true si l'authentification réussit.
     * Retourne false si les identifiants sont refusés.
     *
     * Les erreurs techniques de base de données sont propagées.
     */
    public function login(
        string $identifier,
        string $password
    ): bool {
	$identifier = trim($identifier);

	/*
	 * Validation de l'identifiant.
	 * La colonne identifiant accepte au maximum 100 caractères.
	 */
	if (
	    $identifier === ''
	    || preg_match('//u', $identifier) !== 1
	) {
	    return false;
	}

	$identifierLength = function_exists('mb_strlen')
	    ? mb_strlen($identifier, 'UTF-8')
	    : preg_match_all('/./us', $identifier);

	if (
	    $identifierLength === false
	    || $identifierLength > 100
	) {
	    return false;
	}

	/*
	 * Protection contre les mots de passe démesurés.
	 * Le mot de passe lui-même n'est jamais transformé.
	 */
	if (
	    $password === ''
	    || strlen($password) > 4096
	) {
	    return false;
	}

/*
 * Initialisation et vérification de la session.
 */
$this->sessionManager->start();

/*
 * Empêche le remplacement silencieux d'une session
 * déjà authentifiée par un autre compte.
 */
if ($this->sessionManager->getUserData() !== null) {
    throw new RuntimeException(
        'Une session est déjà authentifiée. '
        . 'Déconnectez-vous avant de changer de compte.'
    );
}

$identifierHmac = $this->createIdentifierHmac(
    $identifier
);

$userId = null;

$this->connection->beginTransaction();

        try {
            $statement = $this->connection->prepare(
                'SELECT
                    id_utilisateur,
                    identifiant,
                    mot_de_passe_hash,
                    statut,
                    echecs_connexion,
                    niveau_verrouillage,
                    verrouillage_jusqu_a,
                    (
                        verrouillage_jusqu_a IS NOT NULL
                        AND verrouillage_jusqu_a > UTC_TIMESTAMP(6)
                    ) AS verrouillage_actif
                 FROM auth_utilisateur
                 WHERE identifiant = :identifiant
                 FOR UPDATE'
            );

            $statement->execute([
                'identifiant' => $identifier,
            ]);

            $user = $statement->fetch();

            /*
             * Identifiant inconnu :
             * vérification fictive pour limiter les différences
             * de temps de traitement.
             */
            if ($user === false) {
                password_verify(
                    $password,
                    $this->getDummyPasswordHash()
                );

                $this->logEvent(
                    'AUTH_LOGIN_FAILURE',
                    'ECHEC',
                    null,
                    null,
                    $identifierHmac,
                    'INVALID_CREDENTIALS'
                );

                $this->connection->commit();

                return false;
            }

            $userId = (int) $user['id_utilisateur'];

            /*
             * Vérification du mot de passe avant le traitement
             * du statut et du verrouillage.
             */
            $passwordValid = password_verify(
                $password,
                $user['mot_de_passe_hash']
            );

            /*
             * Compte inactif ou bloqué.
             */
            if ($user['statut'] !== 'ACTIF') {
                $this->updateLastAttempt($userId);

                $this->logEvent(
                    'AUTH_LOGIN_FAILURE',
                    'ECHEC',
                    $userId,
                    null,
                    $identifierHmac,
                    'ACCOUNT_UNAVAILABLE'
                );

                $this->connection->commit();

                return false;
            }

            /*
             * Verrouillage temporaire toujours actif.
             *
             * Aucun nouvel échec n'est ajouté au compteur.
             */
            if ((int) $user['verrouillage_actif'] === 1) {
                $this->updateLastAttempt($userId);

                $this->logEvent(
                    'AUTH_LOGIN_LOCKED',
                    'ECHEC',
                    $userId,
                    null,
                    $identifierHmac,
                    'LOCK_PERIOD_ACTIVE'
                );

                $this->connection->commit();

                return false;
            }

            /*
             * Mot de passe incorrect.
             */
            if (!$passwordValid) {
                $this->registerFailedAttempt(
                    $user,
                    $identifierHmac
                );

                $this->connection->commit();

                return false;
            }

            /*
             * Authentification réussie.
             */
            $this->completeSuccessfulLogin(
                $user,
                $password,
                $identifierHmac
            );

            $this->connection->commit();

            $userId = (int) $user['id_utilisateur'];

	} catch (Throwable $exception) {
	    if ($this->connection->inTransaction()) {
		try {
		    $this->connection->rollBack();
		} catch (Throwable $rollbackException) {
		    throw new RuntimeException(
			'Une erreur est survenue et le rollback de la transaction a échoué.',
			0,
			$exception
		    );
		}
	    }

	    throw $exception;
	}
        /*
         * La session est établie après validation et commit SQL.
         */
        try {
            $this->sessionManager->establishAuthenticatedSession(
                $userId
            );
        } catch (Throwable $exception) {
            try {
                $this->logEvent(
                    'AUTH_SESSION_ESTABLISHMENT_FAILURE',
                    'ECHEC',
                    $userId,
                    $userId,
                    $identifierHmac,
                    'SESSION_CREATION_FAILURE'
                );
            } catch (Throwable) {
                // Ne pas masquer l'erreur initiale.
            }

            throw new RuntimeException(
                'Impossible d’établir la session authentifiée.',
                0,
                $exception
            );
        }

        return true;
    }

    /**
     * Enregistre un échec et applique le verrouillage progressif.
     */
    private function registerFailedAttempt(
        array $user,
        string $identifierHmac
    ): void {
        $userId = (int) $user['id_utilisateur'];

        $previousFailures = (int) $user['echecs_connexion'];
        $previousLevel = (int) $user['niveau_verrouillage'];

        /*
         * Protection contre le dépassement SMALLINT UNSIGNED.
         */
        $failures = min($previousFailures + 1, 65535);

        $level = match (true) {
            $failures >= 7 => 3,
            $failures === 6 => 2,
            $failures === 5 => 1,
            default => $previousLevel,
        };

        $minutes = self::LOCK_DURATIONS[$level] ?? null;

        $statement = $this->connection->prepare(
            'UPDATE auth_utilisateur
             SET
                echecs_connexion = :failures,
                niveau_verrouillage = :level,
                verrouillage_jusqu_a =
                    CASE
                        WHEN :minutes IS NULL THEN verrouillage_jusqu_a
                        ELSE TIMESTAMPADD(
                            MINUTE,
                            :lock_minutes,
                            UTC_TIMESTAMP(6)
                        )
                    END,
                derniere_tentative_connexion = UTC_TIMESTAMP(6),
                date_modification = UTC_TIMESTAMP(6)
             WHERE id_utilisateur = :user_id'
        );

        $statement->execute([
            'failures' => $failures,
            'level' => $level,
            'minutes' => $minutes,
            'lock_minutes' => $minutes,
            'user_id' => $userId,
        ]);

        $this->logEvent(
            'AUTH_LOGIN_FAILURE',
            'ECHEC',
            $userId,
            null,
            $identifierHmac,
            'INVALID_CREDENTIALS'
        );

        /*
         * Un événement distinct est enregistré lorsqu'un
         * nouveau niveau de verrouillage est atteint.
         */
	if ($minutes !== null) {
	    $this->logEvent(
		'AUTH_ACCOUNT_LOCKED',
		'INFORMATION',
		$userId,
		null,
		$identifierHmac,
		'PROGRESSIVE_LOCKOUT',
		[
		    'niveau' => $level,
		    'duree_minutes' => $minutes,
		]
	    );
	}
    }

    /**
     * Met à jour la dernière tentative sans modifier le compteur.
     */
    private function updateLastAttempt(int $userId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE auth_utilisateur
             SET derniere_tentative_connexion = UTC_TIMESTAMP(6)
             WHERE id_utilisateur = :user_id'
        );

        $statement->execute([
            'user_id' => $userId,
        ]);
    }

    /**
     * Finalise une authentification réussie.
     */
    private function completeSuccessfulLogin(
        array $user,
        string $password,
        string $identifierHmac
    ): void {
        $userId = (int) $user['id_utilisateur'];

        $algorithm = $this->getPasswordAlgorithm();
        $options = $this->getPasswordOptions();

        $passwordHash = $user['mot_de_passe_hash'];

        if (
            password_needs_rehash(
                $passwordHash,
                $algorithm,
                $options
            )
        ) {
            $passwordHash = password_hash(
                $password,
                $algorithm,
                $options
            );

            if ($passwordHash === false) {
                throw new RuntimeException(
                    'Impossible de renouveler le hash du mot de passe.'
                );
            }
        }

        $statement = $this->connection->prepare(
            'UPDATE auth_utilisateur
             SET
                mot_de_passe_hash = :password_hash,
                echecs_connexion = 0,
                niveau_verrouillage = 0,
                verrouillage_jusqu_a = NULL,
                derniere_tentative_connexion = UTC_TIMESTAMP(6),
                derniere_connexion = UTC_TIMESTAMP(6),
                date_modification = UTC_TIMESTAMP(6)
             WHERE id_utilisateur = :user_id'
        );

        $statement->execute([
            'password_hash' => $passwordHash,
            'user_id' => $userId,
        ]);

        $this->logEvent(
            'AUTH_LOGIN_SUCCESS',
            'SUCCES',
            $userId,
            $userId,
            $identifierHmac,
            null
        );
    }

	/**
	 * Retourne le contexte utilisateur actuel.
	 *
	 * Le statut du compte et ses rôles sont lus dans une seule requête.
	 * Les rôles sont systématiquement relus depuis MariaDB.
	 * Cette méthode ne prolonge pas la session.
	 */
	public function getAuthenticatedUser(): ?array
	{
	    $this->sessionManager->start();

	    $sessionUser = $this->sessionManager->getUserData();

	    if ($sessionUser === null) {
		return null;
	    }

	    $userId = filter_var(
		$sessionUser['id_utilisateur'] ?? null,
		FILTER_VALIDATE_INT
	    );

	    if ($userId === false || $userId <= 0) {
		$this->sessionManager->destroy();

		return null;
	    }

	    $statement = $this->connection->prepare(
		'SELECT
		    u.id_utilisateur,
		    u.statut,
		    r.code_role
		 FROM auth_utilisateur u
		 LEFT JOIN auth_utilisateur_role ur
		    ON ur.id_utilisateur = u.id_utilisateur
		    AND ur.date_retrait IS NULL
		 LEFT JOIN auth_role_application r
		    ON r.id_role = ur.id_role
		 WHERE u.id_utilisateur = :user_id
		 ORDER BY r.code_role'
	    );

	    $statement->execute([
		'user_id' => $userId,
	    ]);

	    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

	    /*
	     * Compte inexistant ou désactivé.
	     */
	    if (
		$rows === []
		|| $rows[0]['statut'] !== 'ACTIF'
	    ) {
		$targetUserId = $rows === []
		    ? null
		    : (int) $userId;

		$actorUserId = $targetUserId;

		try {
		    $this->logEvent(
		        'AUTH_SESSION_REJECTED',
		        'ECHEC',
		        $targetUserId,
		        $actorUserId,
		        null,
		        'ACCOUNT_UNAVAILABLE'
		    );
		} finally {
		    $this->sessionManager->destroy();
		}

		return null;
	    }

	    /*
	     * Extraction des rôles actifs.
	     */
	    $roles = [];

	    foreach ($rows as $row) {
		if (
		    isset($row['code_role'])
		    && is_string($row['code_role'])
		) {
		    $roles[] = $row['code_role'];
		}
	    }

	    return [
		'id_utilisateur' => (int) $userId,
		'actif' => true,
		'roles' => $roles,
	    ];
	}
    /**
     * Vérifie si un utilisateur est authentifié.
     */
    public function isAuthenticated(): bool
    {
        return $this->getAuthenticatedUser() !== null;
    }

    /**
     * Actualise explicitement l'activité.
     *
     * À appeler uniquement après validation de la requête.
     */
    public function refreshActivity(): void
    {
        if ($this->getAuthenticatedUser() === null) {
            throw new RuntimeException(
                'Aucun utilisateur authentifié.'
            );
        }

        $this->sessionManager->refreshActivity();
    }

    /**
     * Retourne le jeton CSRF.
     */
    public function getCsrfToken(): string
    {
        return $this->sessionManager->generateCsrfToken();
    }

    /**
     * Vérifie le jeton CSRF.
     */
    public function validateCsrfToken(string $token): bool
    {
        return $this->sessionManager->validateCsrfToken($token);
    }

	/**
	 * Déconnecte l'utilisateur.
	 *
	 * La destruction de session est exécutée même si
	 * la récupération des données ou la journalisation échoue.
	 */
	public function logout(): void
	{
	    try {
		if (session_status() !== PHP_SESSION_ACTIVE) {
		    return;
		}

		$sessionUser = $this->sessionManager->getUserData();

		if ($sessionUser !== null) {
		    $userId = (int) $sessionUser['id_utilisateur'];

		    $this->logEvent(
		        'AUTH_LOGOUT',
		        'SUCCES',
		        $userId,
		        $userId,
		        null,
		        null
		    );
		}
	    } finally {
		$this->sessionManager->destroy();
	    }
	}
    /**
     * Calcule le HMAC de l'identifiant tenté.
     */
    private function createIdentifierHmac(
        string $identifier
    ): string {
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($identifier, 'UTF-8')
            : strtolower($identifier);

        return hash_hmac(
            'sha256',
            $normalized,
            $this->hmacKey,
            true
        );
    }

    /**
     * Hash fictif utilisé pour les identifiants inconnus.
     */
    private function getDummyPasswordHash(): string
    {
        if (self::$dummyPasswordHash === null) {
            $hash = password_hash(
                bin2hex(random_bytes(32)),
                $this->getPasswordAlgorithm(),
                $this->getPasswordOptions()
            );

            if ($hash === false) {
                throw new RuntimeException(
                    'Impossible de générer le hash fictif.'
                );
            }

            self::$dummyPasswordHash = $hash;
        }

        return self::$dummyPasswordHash;
    }

    /**
     * Algorithme de hachage retenu.
     */
    private function getPasswordAlgorithm(): string|int
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return PASSWORD_ARGON2ID;
        }

        return PASSWORD_DEFAULT;
    }

    /**
     * Paramètres de hachage.
     */
    private function getPasswordOptions(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return self::ARGON2ID_OPTIONS;
        }

        return [];
    }

    /**
     * Insère un événement dans le journal d'authentification.
     */
    private function logEvent(
        string $eventCode,
        string $result,
        ?int $targetUserId,
        ?int $actorUserId,
        ?string $identifierHmac,
        ?string $reason,
        ?array $details = null
    ): void {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        if (
            !is_string($ipAddress)
            || filter_var($ipAddress, FILTER_VALIDATE_IP) === false
        ) {
            $ipAddress = null;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        if (is_string($userAgent)) {
            $userAgent = substr($userAgent, 0, 512);
        } else {
            $userAgent = null;
        }

        $detailsJson = $details === null
            ? null
            : json_encode($details, JSON_THROW_ON_ERROR);

	$statement = $this->connection->prepare(
	    'INSERT INTO auth_historique (
		code_evenement,
		resultat,
		id_utilisateur_cible,
		id_acteur,
		identifiant_tente_hmac,
		date_evenement,
		adresse_ip,
		user_agent,
		motif,
		details_json
	     ) VALUES (
		:event_code,
		:result,
		:target_user,
		:actor_user,
		:identifier_hmac,
		UTC_TIMESTAMP(6),
		:ip_address,
		:user_agent,
		:reason,
		:details
	     )'
	);

	$statement->bindValue(
	    ':event_code',
	    $eventCode,
	    PDO::PARAM_STR
	);

	$statement->bindValue(
	    ':result',
	    $result,
	    PDO::PARAM_STR
	);

	$statement->bindValue(
	    ':target_user',
	    $targetUserId,
	    $targetUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
	);

	$statement->bindValue(
	    ':actor_user',
	    $actorUserId,
	    $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
	);

	$statement->bindValue(
	    ':identifier_hmac',
	    $identifierHmac,
	    $identifierHmac === null ? PDO::PARAM_NULL : PDO::PARAM_STR
	);

	$statement->bindValue(
	    ':ip_address',
	    $ipAddress,
	    $ipAddress === null ? PDO::PARAM_NULL : PDO::PARAM_STR
	);

	$statement->bindValue(
	    ':user_agent',
	    $userAgent,
	    $userAgent === null ? PDO::PARAM_NULL : PDO::PARAM_STR
	);

	$statement->bindValue(
	    ':reason',
	    $reason,
	    $reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR
	);

	$statement->bindValue(
	    ':details',
	    $detailsJson,
	    $detailsJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR
	);

	$statement->execute();
	
	}
}
