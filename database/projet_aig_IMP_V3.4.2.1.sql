/* =============================================================================
 * Projet AIG / EXF-023
 * Module IMP - Références commerciales, conditionnements et imports
 *
 * Version : V3.4.2.1 - Révision corrective (proposée, non exécutée)
 * SGBD cible : MariaDB 11.8+ (Moteur InnoDB)
 * ============================================================================= */

SET NAMES utf8mb4;

/* ============================================================
   1. RÉFÉRENTIEL DES UNITÉS DE MESURE
   ============================================================ */
CREATE TABLE IF NOT EXISTS `unite_mesure` (
    `id_unite_mesure` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(30) NOT NULL,
    `libelle` VARCHAR(100) NOT NULL,
    `symbole` VARCHAR(20) NULL,
    `dimension` VARCHAR(50) NOT NULL,
    `precision_decimale` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `facteur_conversion` DECIMAL(20,10) NOT NULL DEFAULT 1.0000000000,
    `id_unite_reference` BIGINT UNSIGNED NULL,
    `est_unite_reference` TINYINT(1) NOT NULL DEFAULT 0,
    `reference_cible_attendue` TINYINT(1) NOT NULL DEFAULT 1,
    `reference_cible_active_attendue` TINYINT(1) NOT NULL DEFAULT 1,
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `date_creation` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    `dimension_reference_active` VARCHAR(50) 
        GENERATED ALWAYS AS (
            CASE 
                WHEN `est_unite_reference` = 1 AND `actif` = 1 
                THEN `dimension` 
                ELSE NULL 
            END
        ) PERSISTENT,

    PRIMARY KEY (`id_unite_mesure`),
    UNIQUE KEY `uq_unite_mesure_code` (`code`),
    UNIQUE KEY `uq_unite_mesure_identite_reference` 
        (`id_unite_mesure`, `dimension`, `est_unite_reference`, `actif`),
    UNIQUE KEY `uq_unite_mesure_reference_active` (`dimension_reference_active`),
    KEY `idx_unite_mesure_dimension` (`dimension`),
    KEY `idx_unite_mesure_actif` (`actif`),

    CONSTRAINT `chk_unite_actif` CHECK (`actif` IN (0, 1)),
    CONSTRAINT `chk_unite_est_reference` CHECK (`est_unite_reference` IN (0, 1)),
    CONSTRAINT `chk_unite_precision` CHECK (`precision_decimale` <= 10),
    CONSTRAINT `chk_unite_facteur_positif` CHECK (`facteur_conversion` > 0),
    CONSTRAINT `chk_unite_reference_coherence` CHECK (
        `reference_cible_attendue` = 1 
        AND `reference_cible_active_attendue` = 1 
        AND (
            (`est_unite_reference` = 1 AND `id_unite_reference` IS NULL AND `facteur_conversion` = 1)
            OR 
            (`est_unite_reference` = 0 AND `id_unite_reference` IS NOT NULL)
        )
    ),
    CONSTRAINT `fk_unite_unite_reference` 
        FOREIGN KEY (`id_unite_reference`, `dimension`, `reference_cible_attendue`, `reference_cible_active_attendue`)
        REFERENCES `unite_mesure` (`id_unite_mesure`, `dimension`, `est_unite_reference`, `actif`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ============================================================
   2. TYPES DE CONDITIONNEMENT
   ============================================================ */
CREATE TABLE IF NOT EXISTS `type_conditionnement` (
    `id_type_conditionnement` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(30) NOT NULL,
    `libelle` VARCHAR(100) NOT NULL,
    `description` VARCHAR(500) NULL,
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `date_creation` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (`id_type_conditionnement`),
    UNIQUE KEY `uq_type_conditionnement_code` (`code`),
    KEY `idx_type_conditionnement_actif` (`actif`),
    CONSTRAINT `chk_type_conditionnement_actif` CHECK (`actif` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ============================================================
   3. CONDITIONNEMENTS ÉLÉMENTAIRES OU COMPOSÉS
   ============================================================ */
CREATE TABLE IF NOT EXISTS `conditionnement` (
    `id_conditionnement` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_type_conditionnement` BIGINT UNSIGNED NOT NULL,
    `mode_contenu` VARCHAR(15) NOT NULL,
    `quantite_contenu` DECIMAL(20,10) NULL,
    `id_unite_mesure` BIGINT UNSIGNED NULL,
    `designation` VARCHAR(200) NULL,
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `date_creation` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (`id_conditionnement`),
    KEY `idx_conditionnement_type` (`id_type_conditionnement`),
    KEY `idx_conditionnement_unite` (`id_unite_mesure`),
    KEY `idx_conditionnement_actif` (`actif`),

    CONSTRAINT `chk_conditionnement_actif` CHECK (`actif` IN (0, 1)),
    CONSTRAINT `chk_conditionnement_mode` CHECK (`mode_contenu` IN ('ELEMENTAIRE', 'COMPOSE')),
    CONSTRAINT `chk_conditionnement_contenu` CHECK (
        (`mode_contenu` = 'ELEMENTAIRE' AND `quantite_contenu` IS NOT NULL AND `quantite_contenu` > 0 AND `id_unite_mesure` IS NOT NULL)
        OR
        (`mode_contenu` = 'COMPOSE' AND `quantite_contenu` IS NULL AND `id_unite_mesure` IS NULL)
    ),
    CONSTRAINT `fk_conditionnement_type` FOREIGN KEY (`id_type_conditionnement`) REFERENCES `type_conditionnement` (`id_type_conditionnement`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_conditionnement_unite` FOREIGN KEY (`id_unite_mesure`) REFERENCES `unite_mesure` (`id_unite_mesure`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ============================================================
   4. COMPOSITION DES CONDITIONNEMENTS (HIÉRARCHIQUE)
   ============================================================ */
CREATE TABLE IF NOT EXISTS `composition_conditionnement` (
    `id_composition_conditionnement` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_conditionnement_parent` BIGINT UNSIGNED NOT NULL,
    `id_conditionnement_enfant` BIGINT UNSIGNED NOT NULL,
    `quantite_enfant` DECIMAL(20,10) NOT NULL,
    `date_creation` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (`id_composition_conditionnement`),
    UNIQUE KEY `uq_composition_parent_enfant` (`id_conditionnement_parent`, `id_conditionnement_enfant`),
    KEY `idx_composition_enfant` (`id_conditionnement_enfant`),

    CONSTRAINT `chk_composition_quantite` CHECK (`quantite_enfant` > 0),
    CONSTRAINT `chk_composition_pas_autoreference` CHECK (`id_conditionnement_parent` <> `id_conditionnement_enfant`),
    CONSTRAINT `fk_composition_parent` FOREIGN KEY (`id_conditionnement_parent`) REFERENCES `conditionnement` (`id_conditionnement`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_composition_enfant` FOREIGN KEY (`id_conditionnement_enfant`) REFERENCES `conditionnement` (`id_conditionnement`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
   COR-V34-03 — GARDE-FOU HIÉRARCHIQUE
   Les CHECK/FK ne peuvent pas garantir seuls l'absence de cycle transitif.
   Toute écriture dans composition_conditionnement doit donc passer par le
   service IMP, dans une transaction, sous verrou applicatif exclusif commun
   (GET_LOCK avec un nom stable, libéré en fin de transaction), puis :
     1) vérifier que le parent est COMPOSE ;
     2) vérifier que l'enfant respecte les règles de composition ;
     3) refuser parent = enfant et tout chemin enfant -> parent déjà existant ;
     4) vérifier qu'un ELEMENTAIRE n'est jamais parent et qu'un COMPOSE
        possède au moins un enfant avant activation/utilisation ;
     5) effectuer l'écriture et valider la transaction.
   Les accès directs INSERT/UPDATE/DELETE à cette table sont interdits au
   compte runtime ; seuls les traitements IMP autorisés peuvent écrire.
*/

/* ============================================================
   5. FOURNISSEURS
   ============================================================ */
CREATE TABLE IF NOT EXISTS `fournisseur` (
    `id_fournisseur` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code_interne` VARCHAR(50) NOT NULL,
    `raison_sociale` VARCHAR(200) NOT NULL,
    `nom_commercial` VARCHAR(200) NULL,
    `type_identifiant_legal` VARCHAR(40) NULL,
    `identifiant_legal` VARCHAR(100) NULL,
    `pays_iso2` CHAR(2) NOT NULL,
    `courriel_general` VARCHAR(254) NULL,
    `telephone_general` VARCHAR(40) NULL,
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `date_creation` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `date_modification` DATETIME(6) NULL,

    PRIMARY KEY (`id_fournisseur`),
    UNIQUE KEY `uq_fournisseur_code_interne` (`code_interne`),
    KEY `idx_fournisseur_raison_sociale` (`raison_sociale`),
    KEY `idx_fournisseur_actif` (`actif`),
    CONSTRAINT `chk_fournisseur_actif` CHECK (`actif` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ============================================================
   6. PRODUITS
   ============================================================ */
CREATE TABLE IF NOT EXISTS `produit` (
    `id_produit` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `designation` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `id_unite_base` BIGINT UNSIGNED NOT NULL,
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `date_creation` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `date_modification` DATETIME(6) NULL,

    PRIMARY KEY (`id_produit`),
    UNIQUE KEY `uq_produit_code` (`code`),
    KEY `idx_produit_designation` (`designation`),
    KEY `idx_produit_actif` (`actif`),
    CONSTRAINT `chk_produit_actif` CHECK (`actif` IN (0, 1)),
    CONSTRAINT `fk_produit_unite_base` FOREIGN KEY (`id_unite_base`) REFERENCES `unite_mesure` (`id_unite_mesure`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ============================================================
   7. ASSOCIATION COMMERCIALE FOURNISSEUR / PRODUIT / CONDITIONNEMENT
   ============================================================ */
CREATE TABLE IF NOT EXISTS `fournisseur_produit` (
    `id_fournisseur_produit` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_fournisseur` BIGINT UNSIGNED NOT NULL,
    `id_produit` BIGINT UNSIGNED NOT NULL,
    `id_conditionnement` BIGINT UNSIGNED NOT NULL,
    `reference_commerciale` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    
    `reference_normalisee` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin
        GENERATED ALWAYS AS (
            CASE 
                WHEN `reference_commerciale` IS NULL THEN NULL 
                ELSE UPPER(TRIM(`reference_commerciale`)) 
            END
        ) STORED,
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `date_creation` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `date_modification` DATETIME(6) NULL,

    PRIMARY KEY (`id_fournisseur_produit`),
UNIQUE KEY uq_fournisseur_produit_conditionnement (id_fournisseur, id_produit, id_conditionnement),
UNIQUE KEY uq_fp_identite_complete (id_fournisseur_produit, id_fournisseur, id_produit, id_conditionnement),
UNIQUE KEY uq_fp_reference_fournisseur (id_fournisseur, reference_normalisee),
KEY idx_fp_produit (id_produit),
KEY idx_fp_actif (actif),
CONSTRAINT chk_fp_actif CHECK (actif IN (0, 1)),
CONSTRAINT chk_fp_reference_format CHECK (
reference_commerciale IS NULL
OR (
CHAR_LENGTH(TRIM(reference_commerciale)) >= 1
AND reference_commerciale REGEXP '^[A-Za-z0-9./_ -]+$'
AND reference_commerciale NOT REGEXP '^[[:space:]]|[[:space:]]$'
)
),
CONSTRAINT chk_fp_reference_coherence CHECK (
(reference_commerciale IS NULL AND reference_normalisee IS NULL)
OR
(reference_commerciale IS NOT NULL AND reference_normalisee IS NOT NULL)
),
CONSTRAINT fk_fp_fournisseur FOREIGN KEY (id_fournisseur) REFERENCES fournisseur (id_fournisseur) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT fk_fp_produit FOREIGN KEY (id_produit) REFERENCES produit (id_produit) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT fk_fp_conditionnement FOREIGN KEY (id_conditionnement) REFERENCES conditionnement (id_conditionnement) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/* ============================================================
8. HISTORIQUE DES RÉFÉRENCES COMMERCIALES (APPEND-ONLY)
============================================================ */
CREATE TABLE IF NOT EXISTS historique_reference (
id_historique_reference BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
id_fournisseur_produit BIGINT UNSIGNED NOT NULL,
ancienne_reference VARCHAR(100) NULL,
nouvelle_reference VARCHAR(100) NULL,
type_operation VARCHAR(30) NOT NULL,
justification VARCHAR(500) NULL,
id_acteur BIGINT UNSIGNED NULL,
date_operation DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
fournisseur_code_snapshot VARCHAR(50) NOT NULL,
fournisseur_raison_sociale_snapshot VARCHAR(200) NOT NULL,
produit_code_snapshot VARCHAR(50) NOT NULL,
produit_designation_snapshot VARCHAR(200) NOT NULL,
conditionnement_snapshot VARCHAR(500) NOT NULL,
details JSON NULL,
PRIMARY KEY (id_historique_reference),
KEY idx_hist_ref_association_date (id_fournisseur_produit, date_operation),
KEY idx_hist_ref_date (date_operation),
CONSTRAINT fk_hist_ref_association FOREIGN KEY (id_fournisseur_produit) REFERENCES fournisseur_produit (id_fournisseur_produit) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/* ============================================================
9. LOTS D'IMPORTATION (CORRIGÉ V3.3)
============================================================ */
CREATE TABLE IF NOT EXISTS lot_import (
id_lot_import BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
nom_fichier_original VARCHAR(255) NOT NULL,
nom_fichier_stocke VARCHAR(255) NOT NULL,
chemin_stockage VARCHAR(500) NOT NULL,
type_mime_declare VARCHAR(150) NULL,
type_fichier_detecte VARCHAR(100) NOT NULL,
taille_fichier BIGINT UNSIGNED NOT NULL,
sha256_fichier CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
statut VARCHAR(30) NOT NULL,
id_acteur_createur BIGINT UNSIGNED NULL,
date_creation DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
date_analyse DATETIME(6) NULL,
date_debut_integration DATETIME(6) NULL,
date_fin_integration DATETIME(6) NULL,
date_cloture DATETIME(6) NULL,
motif_annulation VARCHAR(500) NULL,
motif_erreur VARCHAR(500) NULL,
PRIMARY KEY (id_lot_import),
UNIQUE KEY uq_lot_nom_stocke (nom_fichier_stocke),
KEY idx_lot_statut (statut),
KEY idx_lot_sha256 (sha256_fichier),
KEY idx_lot_date_creation (date_creation),
KEY idx_lot_acteur_createur (id_acteur_createur),
CONSTRAINT chk_lot_taille_positive CHECK (taille_fichier > 0),
CONSTRAINT chk_lot_sha256_format CHECK (sha256_fichier REGEXP '^[0-9A-Fa-f]{64}$'),
CONSTRAINT chk_lot_statut CHECK (statut IN (
'DEPOSE', 'ANALYSE', 'A_PREVISUALISER', 'PREVISUALISE',
'CONFIRME', 'INTEGRATION', 'INTEGRE', 'ANNULE', 'ERREUR'
)),
CONSTRAINT chk_lot_dates_integration CHECK (
    (date_fin_integration IS NULL OR date_debut_integration IS NOT NULL)
    AND (date_debut_integration IS NULL OR date_debut_integration >= COALESCE(date_analyse, date_creation))
    AND (date_fin_integration IS NULL OR date_fin_integration >= date_debut_integration)
    AND (date_analyse IS NULL OR date_analyse >= date_creation)
    AND (date_cloture IS NULL OR date_cloture >= date_creation)
),
CONSTRAINT chk_lot_statut_dates CHECK (
    (statut = 'DEPOSE'
        AND date_analyse IS NULL
        AND date_debut_integration IS NULL
        AND date_fin_integration IS NULL
        AND date_cloture IS NULL
        AND motif_annulation IS NULL AND motif_erreur IS NULL)
    OR (statut IN ('ANALYSE', 'A_PREVISUALISER', 'PREVISUALISE')
        AND date_analyse IS NOT NULL
        AND date_debut_integration IS NULL
        AND date_fin_integration IS NULL
        AND date_cloture IS NULL
        AND motif_annulation IS NULL AND motif_erreur IS NULL)
    OR (statut = 'CONFIRME'
        AND date_analyse IS NOT NULL
        AND date_debut_integration IS NULL
        AND date_fin_integration IS NULL
        AND date_cloture IS NULL
        AND motif_annulation IS NULL AND motif_erreur IS NULL)
    OR (statut = 'INTEGRATION'
        AND date_analyse IS NOT NULL
        AND date_debut_integration IS NOT NULL
        AND date_fin_integration IS NULL
        AND date_cloture IS NULL
        AND motif_annulation IS NULL AND motif_erreur IS NULL)
    OR (statut = 'INTEGRE'
        AND date_analyse IS NOT NULL
        AND date_debut_integration IS NOT NULL
        AND date_fin_integration IS NOT NULL
        AND date_cloture IS NOT NULL
        AND date_cloture >= date_fin_integration
        AND motif_annulation IS NULL AND motif_erreur IS NULL)
    OR (statut = 'ANNULE'
        AND date_cloture IS NOT NULL
        AND motif_annulation IS NOT NULL
        AND CHAR_LENGTH(TRIM(motif_annulation)) > 0
        AND motif_erreur IS NULL
        AND date_cloture >= COALESCE(date_fin_integration, date_debut_integration, date_analyse, date_creation))
    OR (statut = 'ERREUR'
        AND date_cloture IS NOT NULL
        AND motif_annulation IS NULL
        AND motif_erreur IS NOT NULL
        AND CHAR_LENGTH(TRIM(motif_erreur)) > 0
        AND date_cloture >= COALESCE(date_fin_integration, date_debut_integration, date_analyse, date_creation))
)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/* ============================================================
10. LIGNES D'IMPORTATION
============================================================ */
CREATE TABLE IF NOT EXISTS import_ligne (
id_import_ligne BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
id_lot_import BIGINT UNSIGNED NOT NULL,
numero_ligne INT UNSIGNED NOT NULL,
donnees_brutes JSON NOT NULL,
fournisseur_identifie BIGINT UNSIGNED NULL,
produit_identifie BIGINT UNSIGNED NULL,
conditionnement_identifie BIGINT UNSIGNED NULL,
reference_lue VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
reference_normalisee VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
statut_ligne VARCHAR(30) NOT NULL DEFAULT 'EN_ATTENTE',
motif_resultat VARCHAR(500) NULL,
id_fournisseur_produit BIGINT UNSIGNED NULL,
date_analyse DATETIME(6) NULL,
date_integration DATETIME(6) NULL,
PRIMARY KEY (id_import_ligne),
UNIQUE KEY uq_import_ligne_numero (id_lot_import, numero_ligne),
UNIQUE KEY uq_import_ligne_lot (id_import_ligne, id_lot_import),
KEY idx_import_ligne_statut (statut_ligne),
KEY idx_import_ligne_lot_statut (id_lot_import, statut_ligne),
CONSTRAINT chk_import_ligne_numero CHECK (numero_ligne > 0),
CONSTRAINT chk_import_ligne_reference_normalisee CHECK (
    reference_normalisee IS NULL
    OR (
        reference_lue IS NOT NULL
        AND CHAR_LENGTH(TRIM(reference_lue)) BETWEEN 1 AND 100
        AND reference_lue REGEXP '^[A-Za-z0-9./_ -]+$'
        AND reference_lue NOT REGEXP '^[[:space:]]|[[:space:]]$'
        AND reference_normalisee = UPPER(TRIM(reference_lue))
    )
),
CONSTRAINT chk_import_ligne_dates_statut CHECK (
    (statut_ligne = 'EN_ATTENTE' AND date_analyse IS NULL AND date_integration IS NULL)
    OR
    (statut_ligne IN ('ACCEPTEE', 'REJETEE', 'IGNOREE', 'ERREUR')
        AND date_analyse IS NOT NULL AND date_integration IS NULL)
    OR
    (statut_ligne = 'INTEGREE'
        AND date_analyse IS NOT NULL AND date_integration IS NOT NULL
        AND date_integration >= date_analyse)
),
CONSTRAINT chk_import_ligne_statut CHECK (statut_ligne IN (
'EN_ATTENTE', 'ACCEPTEE', 'REJETEE', 'IGNOREE', 'INTEGREE', 'ERREUR'
)),
CONSTRAINT chk_import_ligne_atome_identifiants CHECK (
    (id_fournisseur_produit IS NULL
        AND fournisseur_identifie IS NULL
        AND produit_identifie IS NULL
        AND conditionnement_identifie IS NULL)
    OR
    (id_fournisseur_produit IS NOT NULL
        AND fournisseur_identifie IS NOT NULL
        AND produit_identifie IS NOT NULL
        AND conditionnement_identifie IS NOT NULL)
),
CONSTRAINT chk_import_ligne_identifiants_statut CHECK (
    (
        statut_ligne = 'EN_ATTENTE'
        AND id_fournisseur_produit IS NULL
        AND fournisseur_identifie IS NULL
        AND produit_identifie IS NULL
        AND conditionnement_identifie IS NULL
    )
    OR
    (
        statut_ligne IN ('ACCEPTEE', 'INTEGREE')
        AND id_fournisseur_produit IS NOT NULL
        AND fournisseur_identifie IS NOT NULL
        AND produit_identifie IS NOT NULL
        AND conditionnement_identifie IS NOT NULL
        AND (reference_lue IS NULL OR reference_normalisee IS NOT NULL)
    )
    OR
    (
        statut_ligne IN ('REJETEE', 'IGNOREE', 'ERREUR')
        AND (
            (id_fournisseur_produit IS NULL
                AND fournisseur_identifie IS NULL
                AND produit_identifie IS NULL
                AND conditionnement_identifie IS NULL)
            OR
            (id_fournisseur_produit IS NOT NULL
                AND fournisseur_identifie IS NOT NULL
                AND produit_identifie IS NOT NULL
                AND conditionnement_identifie IS NOT NULL)
        )
    )
),
CONSTRAINT fk_import_ligne_lot FOREIGN KEY (id_lot_import) REFERENCES lot_import (id_lot_import) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT fk_import_ligne_fournisseur FOREIGN KEY (fournisseur_identifie) REFERENCES fournisseur (id_fournisseur) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT fk_import_ligne_produit FOREIGN KEY (produit_identifie) REFERENCES produit (id_produit) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT fk_import_ligne_conditionnement FOREIGN KEY (conditionnement_identifie) REFERENCES conditionnement (id_conditionnement) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT fk_import_ligne_association_coherente FOREIGN KEY (id_fournisseur_produit, fournisseur_identifie, produit_identifie, conditionnement_identifie) REFERENCES fournisseur_produit (id_fournisseur_produit, id_fournisseur, id_produit, id_conditionnement) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*
   REV-V34.1 — COHÉRENCE INTER-TABLES DES DATES
   Les CHECK de lot_import et import_ligne valident les dates présentes dans
   chaque ligne, mais MariaDB ne permet pas à un CHECK de comparer directement
   les dates d'une ligne avec celles de son lot parent.
   Le service IMP doit donc vérifier dans la même transaction que :
     - import_ligne.date_analyse est cohérente avec lot_import.date_analyse ;
     - import_ligne.date_integration est comprise dans l'intervalle
       date_debut_integration / date_fin_integration du lot ;
     - une ligne ne peut être intégrée que lorsque le lot est en INTEGRATION ;
     - le lot ne peut passer à INTEGRE tant que toutes ses lignes destinées
       à l'intégration ne sont pas traitées.
   Ces contrôles inter-tables ne sont pas présentés comme garantis par les
   seules contraintes CHECK ci-dessus.
*/

/* ============================================================
11. PRÉVISUALISATIONS D'IMPORTATION
============================================================ */
CREATE TABLE IF NOT EXISTS import_previsualisation (
id_previsualisation BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
id_lot_import BIGINT UNSIGNED NOT NULL,
numero_version INT UNSIGNED NOT NULL,
sha256_fichier CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
empreinte_donnees CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
jeton_confirmation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
nombre_lignes INT UNSIGNED NOT NULL,
nombre_acceptees INT UNSIGNED NOT NULL,
nombre_rejetees INT UNSIGNED NOT NULL,
nombre_ignorees INT UNSIGNED NOT NULL,
nombre_erreurs INT UNSIGNED NOT NULL,
statut_previsualisation VARCHAR(20) NOT NULL DEFAULT 'VALIDE',
date_creation DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
date_expiration DATETIME(6) NOT NULL,
date_invalidation DATETIME(6) NULL,
motif_invalidation VARCHAR(500) NULL,
date_confirmation DATETIME(6) NULL,
id_acteur_confirmation BIGINT UNSIGNED NULL,
PRIMARY KEY (id_previsualisation),
UNIQUE KEY uq_previsualisation_version (id_lot_import, numero_version),
UNIQUE KEY uq_previsualisation_lot (id_previsualisation, id_lot_import),
UNIQUE KEY uq_previsualisation_jeton_hash (jeton_confirmation_hash),
KEY idx_previsualisation_statut (statut_previsualisation),
KEY idx_previsualisation_expiration (date_expiration),
CONSTRAINT chk_previsualisation_sha256_format CHECK (sha256_fichier REGEXP '^[0-9A-Fa-f]{64}$'),
CONSTRAINT chk_previsualisation_empreinte_format CHECK (empreinte_donnees REGEXP '^[0-9A-Fa-f]{64}$'),
CONSTRAINT chk_previsualisation_jeton_hash_format CHECK (jeton_confirmation_hash REGEXP '^[0-9A-Fa-f]{64}$'),
CONSTRAINT chk_previsualisation_version CHECK (numero_version > 0),
CONSTRAINT chk_previsualisation_comptages CHECK (nombre_acceptees + nombre_rejetees + nombre_ignorees + nombre_erreurs = nombre_lignes),
CONSTRAINT chk_previsualisation_statut CHECK (statut_previsualisation IN ('VALIDE', 'EXPIRE', 'INVALIDE', 'CONFIRMEE', 'ANNULEE')),
CONSTRAINT chk_previsualisation_dates CHECK (
    date_expiration > date_creation
    AND (date_invalidation IS NULL OR date_invalidation >= date_creation)
    AND (date_confirmation IS NULL OR (date_confirmation >= date_creation AND date_confirmation < date_expiration))
),
CONSTRAINT chk_previsualisation_statut_dates CHECK (
(statut_previsualisation = 'VALIDE' AND date_confirmation IS NULL AND date_invalidation IS NULL AND id_acteur_confirmation IS NULL AND motif_invalidation IS NULL)
OR (statut_previsualisation = 'CONFIRMEE' AND date_confirmation IS NOT NULL AND id_acteur_confirmation IS NOT NULL AND date_invalidation IS NULL AND motif_invalidation IS NULL)
OR (statut_previsualisation = 'INVALIDE' AND date_invalidation IS NOT NULL AND motif_invalidation IS NOT NULL AND date_confirmation IS NULL AND id_acteur_confirmation IS NULL)
OR (statut_previsualisation = 'EXPIRE' AND date_invalidation IS NOT NULL AND date_invalidation >= date_expiration AND motif_invalidation IS NOT NULL AND date_confirmation IS NULL AND id_acteur_confirmation IS NULL)
OR (statut_previsualisation = 'ANNULEE' AND date_invalidation IS NOT NULL AND motif_invalidation IS NOT NULL AND date_confirmation IS NULL AND id_acteur_confirmation IS NULL)
),
CONSTRAINT fk_previsualisation_lot FOREIGN KEY (id_lot_import) REFERENCES lot_import (id_lot_import) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/* ============================================================
12. HISTORIQUE DES IMPORTS (APPEND-ONLY)
============================================================ */
CREATE TABLE IF NOT EXISTS historique_import (
id_historique_import BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
id_lot_import BIGINT UNSIGNED NOT NULL,
id_import_ligne BIGINT UNSIGNED NULL,
id_previsualisation BIGINT UNSIGNED NULL,
type_evenement VARCHAR(40) NOT NULL,
ancien_statut VARCHAR(30) NULL,
nouveau_statut VARCHAR(30) NULL,
description VARCHAR(1000) NULL,
motif VARCHAR(500) NULL,
id_acteur BIGINT UNSIGNED NULL,
date_evenement DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
details JSON NULL,
PRIMARY KEY (id_historique_import),
KEY idx_hist_import_lot_date (id_lot_import, date_evenement),
KEY idx_hist_import_type (type_evenement),
CONSTRAINT fk_hist_import_lot FOREIGN KEY (id_lot_import) REFERENCES lot_import (id_lot_import) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT fk_hist_import_ligne_meme_lot FOREIGN KEY (id_import_ligne, id_lot_import) REFERENCES import_ligne (id_import_ligne, id_lot_import) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT fk_hist_import_previsualisation_meme_lot FOREIGN KEY (id_previsualisation, id_lot_import) REFERENCES import_previsualisation (id_previsualisation, id_lot_import) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/* =============================================================================
   COR-V3.4.2.1-04 — PRIVILÈGES APPEND-ONLY (À EXÉCUTER APRÈS CRÉATION DES COMPTES)
   Ne pas remplacer les noms de comptes au hasard : adapter à l'identité réelle
   du compte runtime IMP. Le compte de migration reste distinct et contrôlé.

   Exemple à adapter :
   REVOKE UPDATE, DELETE ON projet_aig.historique_reference FROM 'imp_runtime'@'localhost';
   REVOKE UPDATE, DELETE ON projet_aig.historique_import FROM 'imp_runtime'@'localhost';
   GRANT SELECT, INSERT ON projet_aig.historique_reference TO 'imp_runtime'@'localhost';
   GRANT SELECT, INSERT ON projet_aig.historique_import TO 'imp_runtime'@'localhost';

   Vérifier également qu'aucun privilège global, rôle ou privilège hérité ne
   réaccorde UPDATE/DELETE sur ces deux tables. Ces commandes sont volontairement
   commentées : le compte runtime n'existe pas encore et son hôte reste à définir.

   COR-V3.4.2.1-02 — TRANSITIONS APPLICATIVES
   Les CHECK ci-dessus garantissent la cohérence de l'état stocké, mais pas
   l'ordre légal des transitions. Le service IMP devra imposer une machine à
   états explicite, avec UPDATE conditionnel sur l'ancien statut, contrôle du
   nombre de lignes affectées, transaction et ajout simultané de l'événement
   dans historique_import. Aucun changement de statut ne doit être fait hors
   de ce service.
============================================================================= */


/* =============================================================================
   V3.4.2.1 — CONTRATS D'EXECUTION À RESPECTER PAR LE SERVICE IMP
   =============================================================================

   COR-342-02 — PRÉVISUALISATION LIÉE AU FICHIER ET AUX LIGNES
   Avant confirmation puis avant intégration, le service doit, dans une
   transaction cohérente :
     - verrouiller le lot et la prévisualisation concernés (SELECT ... FOR UPDATE) ;
     - vérifier import_previsualisation.sha256_fichier = lot_import.sha256_fichier ;
     - recalculer/valider l'empreinte des données selon l'algorithme versionné ;
     - vérifier que nombre_lignes correspond au nombre réel de lignes analysées ;
     - vérifier que chaque ligne est classée dans une seule catégorie et que les
       compteurs acceptées/rejetées/ignorées/erreurs correspondent aux lignes ;
     - refuser la confirmation si le fichier, les lignes ou les compteurs ont changé ;
     - revalider ces éléments au moment de l'intégration (concurrence possible).

   Le CHECK des compteurs garantit seulement la somme déclarée. Il ne prouve pas
   la correspondance avec les lignes réellement stockées.

   COR-342-03 — IDENTIFIANTS DES LIGNES SELON LE STATUT
     - EN_ATTENTE : aucun identifiant commercial ; la ligne n'a pas encore été
       analysée.
     - ACCEPTEE / INTEGREE : les quatre identifiants doivent être présents et
       cohérents par la FK composite.
     - REJETEE / IGNOREE / ERREUR : les quatre identifiants peuvent être tous
       absents ou tous présents. S'ils sont présents, ils conservent le résultat
       d'identification utile au diagnostic et à la traçabilité.
   Une ligne ne doit jamais conserver un sous-ensemble des quatre identifiants.

   COR-342-04 — MOTIFS TERMINAUX DES LOTS
     - ANNULE : motif_annulation obligatoire et motif_erreur NULL.
     - ERREUR : motif_erreur obligatoire et motif_annulation NULL.
     - Tout autre statut : les deux motifs doivent être NULL.
   Les CHECK imposent également un motif non NULL et non vide après TRIM ; le service
   doit conserver une validation applicative et journaliser l'événement.

   COR-342-05 — COMPOSITION HIÉRARCHIQUE
   Le verrou applicatif commun doit être acquis AVANT START TRANSACTION et
   conservé jusqu'après COMMIT/ROLLBACK. Procédure recommandée :
     1) GET_LOCK('projet_aig:composition_conditionnement', délai) ;
     2) START TRANSACTION ;
     3) verrouiller les conditionnements concernés dans un ordre déterministe ;
     4) vérifier parent COMPOSE, enfant autorisé, absence de chemin enfant->parent,
        absence d'enfant pour un parent ELEMENTAIRE ;
     5) INSERT/UPDATE/DELETE autorisé ;
     6) COMMIT ;
     7) RELEASE_LOCK(...), y compris dans le chemin d'erreur.
   Un verrou nommé est lié à la connexion et n'est pas libéré par COMMIT.
   Les écritures directes hors service doivent être interdites. Si le compte runtime
   ne reçoit pas les droits DML sur composition_conditionnement, l'écriture doit
   être exposée via une procédure stockée contrôlée (SQL SECURITY DEFINER) ou un
   composant dédié disposant des seuls droits nécessaires.

   COR-342-06 — HISTORIQUES APPEND-ONLY
   Le compte runtime ne doit disposer d'aucun UPDATE/DELETE effectif sur
   historique_reference et historique_import, y compris par privilège global,
   rôle ou privilège hérité. Les droits de migration restent séparés du runtime.

   MIGRATION
   Ce fichier est le schéma cible de référence. CREATE TABLE IF NOT EXISTS ne
   modifie jamais une table déjà créée. Pour une base existante, utiliser le
   script de migration V3.4.1 -> V3.4.2.1 après sauvegarde et contrôles préalables.
   Ne jamais supprimer/recréer projet_aig ni les tables pour appliquer cette
   révision.
============================================================================= */
