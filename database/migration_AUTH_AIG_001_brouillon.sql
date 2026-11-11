-- ============================================================================
-- PROJET AIG — Migration AUTH-AIG-001
-- Cible : MariaDB 11.8.x / InnoDB
-- Statut : BROUILLON À RELIRE — NE PAS EXÉCUTER TEL QUEL EN PRODUCTION
--
-- Objet :
--   1. Ajouter les quatre tables d'authentification et d'audit locales.
--   2. Initialiser le référentiel immuable des rôles applicatifs.
--   3. Créer le rôle SQL isolé aig_auth et lui attribuer les droits AUTH.
--   4. Retirer le SELECT global des trois rôles métier, puis le remplacer
--      par des SELECT explicites sur les 12 tables métier existantes.
--
-- GARDE-FOUS :
--   - Ne crée, ne supprime et ne recrée pas la base projet_aig.
--   - Ne modifie pas ProjetAuthentificationBDD ni user_php.
--   - Ne crée aucun compte MariaDB applicatif.
--   - Ne supprime aucune table métier.
--   - N'utilise pas CREATE OR REPLACE ROLE.
--   - Les instructions DDL MariaDB provoquent des validations implicites :
--     ce script n'est donc PAS atomique. Sauvegarde et fenêtre de maintenance
--     nécessaires avant toute exécution.
--
-- PRÉREQUIS À CONTRÔLER MANUELLEMENT AVANT EXÉCUTION :
--   A. Une sauvegarde vérifiée de projet_aig existe.
--   B. Les 12 tables métier listées plus bas existent bien.
--   C. Les rôles aig_consultation, aig_referentiel et aig_imp existent.
--   D. Les privilèges globaux/table-level ont été audités avec SHOW GRANTS.
--   E. Les tables auth_* n'existent pas déjà, ou leur structure a été comparée.
--   F. Le rôle aig_auth n'existe pas déjà avec des privilèges inconnus.
--   G. La collation utf8mb4_uca1400_ai_ci est disponible sur le serveur.
--
-- IMPORTANT — portée de aig_auth :
--   Le rôle est destiné à la phase d'authentification et aux opérations
--   contrôlées de gestion des comptes/habilitations. Il ne donne aucun accès
--   aux 12 tables métier et aucun privilège administratif MariaDB.
--
-- IMPORTANT — compte applicatif :
--   La liaison de aig_auth et des rôles métier au compte applicatif est
--   volontairement laissée en section commentée. Le compte aig_app n'est pas
--   présumé créé. Ne pas décommenter avant validation du compte et de son
--   mode de connexion.
-- ============================================================================

USE `projet_aig`;

-- ============================================================================
-- 0. CONTRÔLES PRÉALABLES (lecture seule)
-- Exécuter et examiner les résultats avant de poursuivre.
-- ============================================================================

SELECT VERSION() AS version_mariadb,
       DATABASE() AS base_courante;

SHOW COLLATION LIKE 'utf8mb4_uca1400_ai_ci';

SHOW GRANTS FOR `aig_consultation`;
SHOW GRANTS FOR `aig_referentiel`;
SHOW GRANTS FOR `aig_imp`;

-- Vérifier que les tables métier attendues sont présentes.
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'projet_aig'
  AND TABLE_NAME IN (
      'unite_mesure',
      'type_conditionnement',
      'conditionnement',
      'composition_conditionnement',
      'fournisseur',
      'produit',
      'fournisseur_produit',
      'historique_reference',
      'lot_import',
      'import_ligne',
      'import_previsualisation',
      'historique_import'
  )
ORDER BY TABLE_NAME;

-- Vérifier l'absence préalable des tables AUTH.
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'projet_aig'
  AND TABLE_NAME IN (
      'auth_utilisateur',
      'auth_role_application',
      'auth_utilisateur_role',
      'auth_historique'
  );

-- ============================================================================
-- 1. TABLE auth_utilisateur
-- ============================================================================
-- statut = INACTIF : désactivation administrative.
-- statut = BLOQUE  : blocage administratif.
-- verrouillage_jusqu_a : verrouillage temporaire automatique après échecs.
-- Le verrouillage temporaire ne change pas le statut du compte.
--
-- niveau_verrouillage permet de distinguer les paliers progressifs :
--   0 = aucun palier ; 1 = premier palier ; 2 = deuxième ; 3 = troisième
-- et suivants, plafonnés par la politique PHP (30 minutes maximum).
--
-- Les dates sont fournies et maintenues par PHP, conformément aux décisions
-- de conception ; aucun trigger n'est créé.
-- ============================================================================

CREATE TABLE `auth_utilisateur` (
    `id_utilisateur` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifiant` VARCHAR(100) NOT NULL,
    `email` VARCHAR(254) NULL,
    `mot_de_passe_hash` VARCHAR(255) NOT NULL,
    `statut` ENUM('ACTIF', 'INACTIF', 'BLOQUE')
        NOT NULL DEFAULT 'ACTIF',
    `echecs_connexion` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `niveau_verrouillage` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `verrouillage_jusqu_a` DATETIME(6) NULL,
    `derniere_tentative_connexion` DATETIME(6) NULL,
    `derniere_connexion` DATETIME(6) NULL,
    `date_creation` DATETIME(6) NOT NULL,
    `date_modification` DATETIME(6) NOT NULL,
    `cree_par` BIGINT UNSIGNED NULL,
    `modifie_par` BIGINT UNSIGNED NULL,

    PRIMARY KEY (`id_utilisateur`),
    UNIQUE KEY `uq_auth_utilisateur_identifiant` (`identifiant`),
    UNIQUE KEY `uq_auth_utilisateur_email` (`email`),
    KEY `idx_auth_utilisateur_statut` (`statut`),
    KEY `idx_auth_utilisateur_verrouillage` (`verrouillage_jusqu_a`),
    KEY `idx_auth_utilisateur_cree_par` (`cree_par`),
    KEY `idx_auth_utilisateur_modifie_par` (`modifie_par`),

    CONSTRAINT `fk_auth_utilisateur_cree_par`
        FOREIGN KEY (`cree_par`)
        REFERENCES `auth_utilisateur` (`id_utilisateur`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT `fk_auth_utilisateur_modifie_par`
        FOREIGN KEY (`modifie_par`)
        REFERENCES `auth_utilisateur` (`id_utilisateur`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT `ck_auth_utilisateur_echecs`
        CHECK (`echecs_connexion` >= 0),

    CONSTRAINT `ck_auth_utilisateur_niveau_verrouillage`
        CHECK (`niveau_verrouillage` <= 3)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Comptes locaux du projet AIG';

-- ============================================================================
-- 2. TABLE auth_role_application
-- ============================================================================
-- Référentiel technique immuable.
-- L'application dispose uniquement du SELECT sur cette table via aig_auth.
-- INSERT/UPDATE/DELETE sont réservés aux migrations SQL contrôlées.
-- ============================================================================

CREATE TABLE `auth_role_application` (
    `id_role` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code_role` VARCHAR(50) NOT NULL,
    `libelle` VARCHAR(100) NOT NULL,
    `description` VARCHAR(500) NULL,
    `role_mariadb` VARCHAR(64) NULL,
    `est_systeme` BOOLEAN NOT NULL DEFAULT TRUE,
    `date_creation` DATETIME(6) NOT NULL,

    PRIMARY KEY (`id_role`),
    UNIQUE KEY `uq_auth_role_code` (`code_role`),
    UNIQUE KEY `uq_auth_role_mariadb` (`role_mariadb`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Référentiel technique immuable des rôles applicatifs';

-- Initialisation contrôlée. INSERT IGNORE évite de modifier un rôle déjà
-- présent ; les valeurs doivent néanmoins être comparées après insertion.
INSERT IGNORE INTO `auth_role_application`
    (`id_role`, `code_role`, `libelle`, `description`,
     `role_mariadb`, `est_systeme`, `date_creation`)
VALUES
    (1, 'AIG_CONSULTATION', 'Consultation',
     'Consultation des données métier AIG',
     'aig_consultation', TRUE, UTC_TIMESTAMP(6)),
    (2, 'AIG_REFERENTIEL', 'Gestion des référentiels',
     'Gestion contrôlée des référentiels métier',
     'aig_referentiel', TRUE, UTC_TIMESTAMP(6)),
    (3, 'AIG_IMP', 'Gestion des imports',
     'Prévisualisation et intégration des imports',
     'aig_imp', TRUE, UTC_TIMESTAMP(6)),
    (4, 'AIG_ADMIN_SECURITE', 'Administration de la sécurité',
     'Gestion des comptes et habilitations ; aucun rôle SQL administratif',
     NULL, TRUE, UTC_TIMESTAMP(6));

-- Contrôle obligatoire des quatre enregistrements après initialisation.
SELECT `id_role`, `code_role`, `role_mariadb`, `est_systeme`
FROM `auth_role_application`
ORDER BY `id_role`;

-- ============================================================================
-- 3. TABLE auth_utilisateur_role
-- ============================================================================
-- Historisation des périodes d'attribution.
-- Une attribution retirée est conservée. Une nouvelle attribution crée une
-- nouvelle ligne. La colonne générée vaut 1 si active, NULL si retirée.
-- L'index unique autorise plusieurs NULL mais une seule valeur 1 par couple.
-- ============================================================================

CREATE TABLE `auth_utilisateur_role` (
    `id_attribution` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_utilisateur` BIGINT UNSIGNED NOT NULL,
    `id_role` SMALLINT UNSIGNED NOT NULL,
    `date_attribution` DATETIME(6) NOT NULL,
    `attribue_par` BIGINT UNSIGNED NULL,
    `date_retrait` DATETIME(6) NULL,
    `retire_par` BIGINT UNSIGNED NULL,
    `motif` VARCHAR(500) NULL,

    `attribution_active` TINYINT UNSIGNED
        AS (
            CASE
                WHEN `date_retrait` IS NULL THEN 1
                ELSE NULL
            END
        ) PERSISTENT,

    PRIMARY KEY (`id_attribution`),
    UNIQUE KEY `uq_auth_utilisateur_role_actif`
        (`id_utilisateur`, `id_role`, `attribution_active`),
    KEY `idx_auth_utilisateur_role_role` (`id_role`),
    KEY `idx_auth_utilisateur_role_attribue_par` (`attribue_par`),
    KEY `idx_auth_utilisateur_role_retire_par` (`retire_par`),

    CONSTRAINT `fk_auth_utilisateur_role_utilisateur`
        FOREIGN KEY (`id_utilisateur`)
        REFERENCES `auth_utilisateur` (`id_utilisateur`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT `fk_auth_utilisateur_role_role`
        FOREIGN KEY (`id_role`)
        REFERENCES `auth_role_application` (`id_role`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT `fk_auth_utilisateur_role_attribue_par`
        FOREIGN KEY (`attribue_par`)
        REFERENCES `auth_utilisateur` (`id_utilisateur`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT `fk_auth_utilisateur_role_retire_par`
        FOREIGN KEY (`retire_par`)
        REFERENCES `auth_utilisateur` (`id_utilisateur`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT `ck_auth_utilisateur_role_retrait`
        CHECK (
            (`date_retrait` IS NULL AND `retire_par` IS NULL)
            OR
            (`date_retrait` IS NOT NULL AND `retire_par` IS NOT NULL)
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Historique des attributions de rôles applicatifs';

-- ============================================================================
-- 4. TABLE auth_historique
-- ============================================================================
-- Journal append-only des événements de sécurité.
-- Les tentatives visant un identifiant inconnu sont traçables sans FK vers
-- auth_utilisateur grâce à identifiant_tente_hmac (HMAC-SHA256 calculé en PHP
-- avec une clé secrète conservée hors de la base).
--
-- Ne jamais stocker dans details_json : mot de passe, hash de mot de passe,
-- jeton de session, jeton CSRF, secret, cookie ou contenu d'authentification.
-- L'application doit appliquer une liste blanche de clés autorisées.
--
-- La durée de conservation et la purge éventuelle doivent être définies dans
-- une politique distincte. Aucun droit DELETE/UPDATE applicatif n'est prévu.
-- ============================================================================

CREATE TABLE `auth_historique` (
    `id_evenement` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code_evenement` VARCHAR(64) NOT NULL,
    `resultat` ENUM('SUCCES', 'ECHEC', 'INFORMATION') NOT NULL,
    `id_utilisateur_cible` BIGINT UNSIGNED NULL,
    `id_acteur` BIGINT UNSIGNED NULL,
    `identifiant_tente_hmac` BINARY(32) NULL,
    `date_evenement` DATETIME(6) NOT NULL,
    `adresse_ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(512) NULL,
    `motif` VARCHAR(500) NULL,
    `details_json` LONGTEXT NULL,

    PRIMARY KEY (`id_evenement`),
    KEY `idx_auth_historique_date` (`date_evenement`),
    KEY `idx_auth_historique_code_date` (`code_evenement`, `date_evenement`),
    KEY `idx_auth_historique_cible_date`
        (`id_utilisateur_cible`, `date_evenement`),
    KEY `idx_auth_historique_acteur_date`
        (`id_acteur`, `date_evenement`),
    KEY `idx_auth_historique_identifiant_hmac_date`
        (`identifiant_tente_hmac`, `date_evenement`),

    CONSTRAINT `fk_auth_historique_cible`
        FOREIGN KEY (`id_utilisateur_cible`)
        REFERENCES `auth_utilisateur` (`id_utilisateur`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT `fk_auth_historique_acteur`
        FOREIGN KEY (`id_acteur`)
        REFERENCES `auth_utilisateur` (`id_utilisateur`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT `ck_auth_historique_details_json`
        CHECK (`details_json` IS NULL OR JSON_VALID(`details_json`)),

    CONSTRAINT `ck_auth_historique_identifiant_hmac`
        CHECK (
            `identifiant_tente_hmac` IS NULL
            OR OCTET_LENGTH(`identifiant_tente_hmac`) = 32
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT='Journal append-only des événements de sécurité AUTH';

-- ============================================================================
-- 5. RÔLE SQL aig_auth
-- ============================================================================
-- Ne pas utiliser CREATE OR REPLACE ROLE : cela détruirait les privilèges
-- déjà attribués à un rôle existant.
--
-- Si aig_auth existe déjà, interrompre et auditer ses GRANT avant de
-- poursuivre. CREATE ROLE IF NOT EXISTS ne nettoie pas d'anciens privilèges.
-- ============================================================================

CREATE ROLE IF NOT EXISTS `aig_auth`;

-- Accès aux comptes : le rôle PHP doit lire le hash pour password_verify().
-- La non-divulgation du hash dans l'API relève aussi du code PHP.
GRANT SELECT ON `projet_aig`.`auth_utilisateur`
    TO `aig_auth`;

GRANT INSERT (
    `identifiant`,
    `email`,
    `mot_de_passe_hash`,
    `statut`,
    `echecs_connexion`,
    `niveau_verrouillage`,
    `verrouillage_jusqu_a`,
    `derniere_tentative_connexion`,
    `derniere_connexion`,
    `date_creation`,
    `date_modification`,
    `cree_par`,
    `modifie_par`
) ON `projet_aig`.`auth_utilisateur`
    TO `aig_auth`;

GRANT UPDATE (
    `email`,
    `mot_de_passe_hash`,
    `statut`,
    `echecs_connexion`,
    `niveau_verrouillage`,
    `verrouillage_jusqu_a`,
    `derniere_tentative_connexion`,
    `derniere_connexion`,
    `date_modification`,
    `modifie_par`
) ON `projet_aig`.`auth_utilisateur`
    TO `aig_auth`;

-- Le référentiel des rôles est consultable, jamais modifiable par l'application.
GRANT SELECT ON `projet_aig`.`auth_role_application`
    TO `aig_auth`;

-- Les attributions peuvent être créées et retirées logiquement.
GRANT SELECT ON `projet_aig`.`auth_utilisateur_role`
    TO `aig_auth`;

GRANT INSERT (
    `id_utilisateur`,
    `id_role`,
    `date_attribution`,
    `attribue_par`,
    `date_retrait`,
    `retire_par`,
    `motif`
) ON `projet_aig`.`auth_utilisateur_role`
    TO `aig_auth`;

GRANT UPDATE (
    `date_retrait`,
    `retire_par`,
    `motif`
) ON `projet_aig`.`auth_utilisateur_role`
    TO `aig_auth`;

-- Journal de sécurité : consultation et insertion uniquement.
GRANT SELECT ON `projet_aig`.`auth_historique`
    TO `aig_auth`;

GRANT INSERT (
    `code_evenement`,
    `resultat`,
    `id_utilisateur_cible`,
    `id_acteur`,
    `identifiant_tente_hmac`,
    `date_evenement`,
    `adresse_ip`,
    `user_agent`,
    `motif`,
    `details_json`
) ON `projet_aig`.`auth_historique`
    TO `aig_auth`;

-- Aucun DELETE n'est accordé.
-- Aucun accès aux tables métier n'est accordé à aig_auth.

-- ============================================================================
-- 6. ISOLATION DES TABLES AUTH : retrait des SELECT au niveau base
-- ============================================================================
-- Les privilèges MariaDB sont additifs : un REVOKE au niveau table ne neutralise
-- pas un SELECT accordé au niveau base. Il faut donc retirer le SELECT global
-- puis le réattribuer explicitement aux 12 tables métier.
--
-- Les droits INSERT/UPDATE par colonne déjà accordés aux rôles métier ne sont
-- pas remplacés par ces REVOKE SELECT.
-- ============================================================================

REVOKE SELECT ON `projet_aig`.* FROM `aig_consultation`;
REVOKE SELECT ON `projet_aig`.* FROM `aig_referentiel`;
REVOKE SELECT ON `projet_aig`.* FROM `aig_imp`;

-- ============================================================================
-- 7. RÉTABLISSEMENT DES SELECT SUR LES 12 TABLES MÉTIER
-- ============================================================================

GRANT SELECT ON `projet_aig`.`unite_mesure`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`type_conditionnement`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`conditionnement`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`composition_conditionnement`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`fournisseur`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`produit`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`fournisseur_produit`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`historique_reference`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`lot_import`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`import_ligne`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`import_previsualisation`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;
GRANT SELECT ON `projet_aig`.`historique_import`
    TO `aig_consultation`, `aig_referentiel`, `aig_imp`;

-- ============================================================================
-- 8. LIAISON AU COMPTE APPLICATIF — À NE PAS EXÉCUTER AVANT VALIDATION
-- ============================================================================
-- Le compte aig_app n'est pas présumé exister.
-- Après création/validation du compte, l'administrateur pourra attribuer les
-- rôles nécessaires. Le rôle par défaut doit rester NONE : PHP active
-- explicitement un seul rôle avec SET ROLE selon l'opération.
--
-- Exemple à adapter et exécuter séparément après validation :
--
-- GRANT `aig_auth` TO 'aig_app'@'localhost';
-- GRANT `aig_consultation` TO 'aig_app'@'localhost';
-- GRANT `aig_referentiel` TO 'aig_app'@'localhost';
-- GRANT `aig_imp` TO 'aig_app'@'localhost';
-- SET DEFAULT ROLE NONE FOR 'aig_app'@'localhost';
--
-- Ne pas accorder aig_admin_securite comme rôle MariaDB : il s'agit d'un rôle
-- applicatif PHP sans équivalent administratif SQL.

-- ============================================================================
-- 9. CONTRÔLES POST-MIGRATION
-- ============================================================================
-- Exécuter après migration, puis vérifier les résultats.
-- ============================================================================

SHOW CREATE TABLE `auth_utilisateur`;
SHOW CREATE TABLE `auth_role_application`;
SHOW CREATE TABLE `auth_utilisateur_role`;
SHOW CREATE TABLE `auth_historique`;

SHOW GRANTS FOR `aig_auth`;
SHOW GRANTS FOR `aig_consultation`;
SHOW GRANTS FOR `aig_referentiel`;
SHOW GRANTS FOR `aig_imp`;

-- Vérifier les privilèges SELECT au niveau base : le résultat doit être vide
-- pour les trois rôles métier.
SELECT GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE
FROM information_schema.SCHEMA_PRIVILEGES
WHERE TABLE_SCHEMA = 'projet_aig'
  AND PRIVILEGE_TYPE = 'SELECT'
  AND (
      GRANTEE LIKE '%aig_consultation%'
      OR GRANTEE LIKE '%aig_referentiel%'
      OR GRANTEE LIKE '%aig_imp%'
  );

-- Vérifier que les rôles métier n'ont aucun privilège sur les tables AUTH.
SELECT GRANTEE, TABLE_NAME, PRIVILEGE_TYPE
FROM information_schema.TABLE_PRIVILEGES
WHERE TABLE_SCHEMA = 'projet_aig'
  AND TABLE_NAME IN (
      'auth_utilisateur',
      'auth_role_application',
      'auth_utilisateur_role',
      'auth_historique'
  )
ORDER BY GRANTEE, TABLE_NAME, PRIVILEGE_TYPE;

-- Vérifier la présence des quatre rôles applicatifs.
SELECT COUNT(*) AS nombre_roles_attendus
FROM `auth_role_application`
WHERE `code_role` IN (
    'AIG_CONSULTATION',
    'AIG_REFERENTIEL',
    'AIG_IMP',
    'AIG_ADMIN_SECURITE'
);

-- Résultat attendu : 4.

-- ============================================================================
-- 10. TESTS FONCTIONNELS À EFFECTUER AVEC UN COMPTE DE TEST DÉDIÉ
-- ============================================================================
-- A. Avec SET ROLE aig_auth :
--    - SELECT auth_utilisateur : autorisé.
--    - SELECT auth_role_application : autorisé.
--    - SELECT auth_utilisateur_role : autorisé.
--    - SELECT auth_historique : autorisé (consultation fonctionnelle filtrée
--      côté PHP selon le rôle AIG_ADMIN_SECURITE).
--    - INSERT/UPDATE autorisés selon les colonnes accordées.
--    - INSERT auth_historique : autorisé ; UPDATE/DELETE : refusé.
--    - DELETE sur les trois tables : refusé.
--    - SELECT sur une table métier : refusé.
--
-- B. Avec SET ROLE aig_consultation :
--    - SELECT sur les 12 tables métier : autorisé.
--    - SELECT sur les trois tables AUTH : refusé.
--    - INSERT/UPDATE/DELETE métier : refusés selon les GRANT existants.
--
-- C. Avec SET ROLE aig_referentiel ou aig_imp :
--    - SELECT métier : autorisé.
--    - privilèges INSERT/UPDATE par colonne : inchangés.
--    - SELECT sur les tables AUTH : refusé.
--
-- D. Après chaque opération métier :
--    SET ROLE aig_consultation; ou SET ROLE NONE;
--
-- E. Avant authentification :
--    SET ROLE aig_auth;
--    Après authentification, ne jamais conserver aig_auth actif pour une
--    opération métier ; sélectionner le rôle métier requis explicitement.
--
-- ============================================================================
-- FIN DU BROUILLON AUTH-AIG-001
-- ============================================================================
