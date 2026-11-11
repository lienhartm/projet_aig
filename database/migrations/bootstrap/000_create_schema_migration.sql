/*
 * PROJET AIG
 * Initialisation du suivi des migrations SQL
 *
 * Identifiant : 000_create_schema_migration
 * Type        : Bootstrap
 *
 * Cette opération crée la table de suivi des migrations.
 * Elle doit être exécutée avec un compte d'administration
 * du schéma, jamais avec un compte applicatif.
 */

CREATE TABLE schema_migration (
    id_migration VARCHAR(100)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    description VARCHAR(255) NOT NULL,

    sha256_fichier CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    date_application DATETIME(6)
        NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    applique_par VARCHAR(384) NOT NULL,

    PRIMARY KEY (id_migration),

    CONSTRAINT chk_schema_migration_sha256
        CHECK (
            sha256_fichier REGEXP '^[0-9a-f]{64}$'
        )

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
