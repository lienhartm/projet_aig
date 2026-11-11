ALTER TABLE import_previsualisation
ADD COLUMN version_canonicalisation VARCHAR(30)
    CHARACTER SET ascii
    COLLATE ascii_bin
    NOT NULL
    DEFAULT 'AIG_IMPORT_DATA_V1'
    AFTER empreinte_donnees;
