<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class SessionManager
{
    private const SESSION_NAME = 'AIGSESSID';

    private const INACTIVITY_TIMEOUT = 1800;

    private const ABSOLUTE_TIMEOUT = 28800;

    private const REGENERATION_INTERVAL = 900;

    private const CSRF_TOKEN_BYTES = 32;

    private const SECURITY_KEY = '_security';

    private const USER_KEY = 'user';

    /**
     * Démarre et vérifie une session sécurisée.
     */
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== self::SESSION_NAME) {
                throw new RuntimeException(
                    'Une autre session PHP est déjà active.'
                );
            }

            $this->validateCurrentSession();
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException(
                'Impossible de démarrer la session : les en-têtes sont déjà envoyés.'
            );
        }

        $this->configureSession();

        if (!session_start()) {
            throw new RuntimeException(
                'Impossible de démarrer la session PHP.'
            );
        }

        $this->validateCurrentSession();
    }

    /**
     * Configure les paramètres de sécurité PHP.
     */
    private function configureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new RuntimeException(
                'La configuration doit intervenir avant session_start().'
            );
        }

        $secureCookie = $this->isSecureConnection();

        $settings = [
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
            'session.cookie_httponly' => '1',
            'session.cookie_secure' => $secureCookie ? '1' : '0',
            'session.cookie_samesite' => 'Strict',
        ];

        foreach ($settings as $setting => $value) {
            if (ini_set($setting, $value) === false) {
                throw new RuntimeException(
                    'Impossible de configurer le paramètre PHP : ' . $setting
                );
            }
        }

        session_name(self::SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Détermine si la connexion utilise HTTPS.
     */
    private function isSecureConnection(): bool
    {
        $environment = $_ENV['APP_ENV'] ?? 'development';

        if ($environment === 'production') {
            return true;
        }

        return isset($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== ''
            && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }

    /**
     * Vérifie les données de sécurité de la session.
     */
    private function validateCurrentSession(): void
    {
        if (!$this->hasValidSecurityData()) {
            $this->restartAsAnonymous();
            return;
        }

        if ($this->isExpired()) {
            $this->restartAsAnonymous();
            return;
        }

        $this->regenerateIfNecessary();
    }

    /**
     * Vérifie la structure des données de sécurité.
     */
    private function hasValidSecurityData(): bool
    {
        $security = $_SESSION[self::SECURITY_KEY] ?? null;

        if (!is_array($security)) {
            return false;
        }

        $timestamps = [
            'created_at',
            'last_activity',
            'regenerated_at',
        ];

        foreach ($timestamps as $key) {
            if (
                !isset($security[$key])
                || !is_int($security[$key])
                || $security[$key] <= 0
            ) {
                return false;
            }
        }

        $now = time();

        if (
            $security['created_at'] > $now
            || $security['last_activity'] > $now
            || $security['regenerated_at'] > $now
        ) {
            return false;
        }

        if (!array_key_exists('authenticated_at', $security)) {
            return false;
        }

        $authenticatedAt = $security['authenticated_at'];

        if (
            $authenticatedAt !== null
            && (
                !is_int($authenticatedAt)
                || $authenticatedAt < $security['created_at']
                || $authenticatedAt > $now
            )
        ) {
            return false;
        }

        $token = $security['csrf_token'] ?? null;

        if (
            !is_string($token)
            || strlen($token) !== self::CSRF_TOKEN_BYTES * 2
            || !ctype_xdigit($token)
        ) {
            return false;
        }

        if (isset($_SESSION[self::USER_KEY])) {
            $user = $_SESSION[self::USER_KEY];

            if (
                !is_array($user)
                || !isset($user['id_utilisateur'])
                || !is_int($user['id_utilisateur'])
                || $user['id_utilisateur'] <= 0
                || $authenticatedAt === null
            ) {
                return false;
            }
        }

        if ($authenticatedAt !== null && !isset($_SESSION[self::USER_KEY])) {
            return false;
        }

        return true;
    }

    /**
     * Initialise une session anonyme.
     */
    private function initializeSecurityData(): void
    {
        $now = time();

        $_SESSION = [
            self::SECURITY_KEY => [
                'created_at' => $now,
                'last_activity' => $now,
                'authenticated_at' => null,
                'regenerated_at' => $now,
                'csrf_token' => $this->createCsrfToken(),
            ],
        ];
    }

    /**
     * Vérifie les délais d'expiration.
     */
    public function isExpired(): bool
    {
        if (!$this->hasValidSecurityData()) {
            return true;
        }

        $security = $_SESSION[self::SECURITY_KEY];

        $now = time();

        return ($now - $security['last_activity'])
                >= self::INACTIVITY_TIMEOUT
            || ($now - $security['created_at'])
                >= self::ABSOLUTE_TIMEOUT;
    }

    /**
     * Actualise l'activité après validation de la requête.
     *
     * Cette méthode doit être appelée par le composant
     * applicatif après validation de la requête.
     */
    public function refreshActivity(): void
    {
        $this->requireActiveSession();

        $_SESSION[self::SECURITY_KEY]['last_activity'] = time();
    }

    /**
     * Renouvellement périodique de l'identifiant.
     */
    private function regenerateIfNecessary(): void
    {
        $security = $_SESSION[self::SECURITY_KEY];

        if (
            time() - $security['regenerated_at']
            < self::REGENERATION_INTERVAL
        ) {
            return;
        }

        $this->regenerate();
    }

    /**
     * Renouvelle l'identifiant de session.
     */
    public function regenerate(): void
    {
        $this->requireActiveSession();

        if (headers_sent()) {
            throw new RuntimeException(
                'Impossible de renouveler la session après envoi des en-têtes.'
            );
        }

        if (!session_regenerate_id(true)) {
            $this->destroy();

            throw new RuntimeException(
                'Échec du renouvellement de l’identifiant de session.'
            );
        }

        $_SESSION[self::SECURITY_KEY]['regenerated_at'] = time();
    }

    /**
     * Établit une session après authentification réussie.
     */
    public function establishAuthenticatedSession(int $userId): void
    {
        $this->requireActiveSession();

        if ($userId <= 0) {
            throw new RuntimeException(
                'Identifiant utilisateur invalide.'
            );
        }

        if (headers_sent()) {
            throw new RuntimeException(
                'Impossible de renouveler la session après authentification.'
            );
        }

        if (!session_regenerate_id(true)) {
            $this->destroy();

            throw new RuntimeException(
                'Impossible de renouveler la session après authentification.'
            );
        }

        $now = time();

        $_SESSION = [
            self::SECURITY_KEY => [
                'created_at' => $now,
                'last_activity' => $now,
                'authenticated_at' => $now,
                'regenerated_at' => $now,
                'csrf_token' => $this->createCsrfToken(),
            ],
            self::USER_KEY => [
                'id_utilisateur' => $userId,
            ],
        ];
    }

    /**
     * Génère un jeton CSRF cryptographiquement aléatoire.
     */
    private function createCsrfToken(): string
    {
        return bin2hex(random_bytes(self::CSRF_TOKEN_BYTES));
    }

    /**
     * Retourne le jeton CSRF.
     */
    public function generateCsrfToken(): string
    {
        $this->requireActiveSession();

        return $_SESSION[self::SECURITY_KEY]['csrf_token'];
    }

    /**
     * Vérifie un jeton CSRF.
     */
    public function validateCsrfToken(string $token): bool
    {
        if (
            session_status() !== PHP_SESSION_ACTIVE
            || !$this->hasValidSecurityData()
            || $this->isExpired()
            || $token === ''
        ) {
            return false;
        }

        return hash_equals(
            $_SESSION[self::SECURITY_KEY]['csrf_token'],
            $token
        );
    }

    /**
     * Retourne les données utilisateur.
     */
    public function getUserData(): ?array
    {
        $this->requireActiveSession();

        $user = $_SESSION[self::USER_KEY] ?? null;

        return is_array($user) ? $user : null;
    }

    /**
     * Vérifie qu'une session est active et valide.
     */
    private function requireActiveSession(): void
    {
        if (
            session_status() !== PHP_SESSION_ACTIVE
            || session_name() !== self::SESSION_NAME
            || !$this->hasValidSecurityData()
            || $this->isExpired()
        ) {
            throw new RuntimeException(
                'Aucune session active et valide.'
            );
        }
    }

    /**
     * Détruit la session et expire son cookie.
     */
    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (
            ini_get('session.use_cookies')
            && !headers_sent()
        ) {
            $parameters = session_get_cookie_params();

            $cookie = [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'] ?? 'Strict',
            ];

            if ($parameters['domain'] !== '') {
                $cookie['domain'] = $parameters['domain'];
            }

            setcookie(session_name(), '', $cookie);
        }

        if (!session_destroy()) {
            throw new RuntimeException(
                'Impossible de détruire la session.'
            );
        }

        session_id('');
    }

    /**
     * Détruit une session invalide et crée une session anonyme.
     */
    private function restartAsAnonymous(): void
    {
        $this->destroy();

        $this->configureSession();

        if (!session_start()) {
            throw new RuntimeException(
                'Impossible de créer une nouvelle session anonyme.'
            );
        }

        if (!session_regenerate_id(true)) {
            $this->destroy();

            throw new RuntimeException(
                'Impossible de sécuriser la nouvelle session.'
            );
        }

        $this->initializeSecurityData();
    }
}
