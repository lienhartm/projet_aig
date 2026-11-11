use projet_aig;

-- DROIT INSERT

-- UNITÉ DE MESURE
GRANT INSERT (
    code,
    libelle,
    symbole,
    dimension,
    precision_decimale,
    facteur_conversion,
    id_unite_reference,
    est_unite_reference,
    reference_cible_attendue,
    reference_cible_active_attendue,
    actif
)
ON projet_aig.unite_mesure TO aig_referentiel;


-- TYPE DE CONDITIONNEMENT
GRANT INSERT (
    code,
    libelle,
    description,
    actif
)
ON projet_aig.type_conditionnement TO aig_referentiel;


-- CONDITIONNEMENT
GRANT INSERT (
    id_type_conditionnement,
    mode_contenu,
    quantite_contenu,
    id_unite_mesure,
    designation,
    actif
)
ON projet_aig.conditionnement TO aig_referentiel;


-- COMPOSITION DE CONDITIONNEMENT
GRANT INSERT (
    id_conditionnement_parent,
    id_conditionnement_enfant,
    quantite_enfant
)
ON projet_aig.composition_conditionnement TO aig_referentiel;


-- FOURNISSEUR
GRANT INSERT (
    code_interne,
    raison_sociale,
    nom_commercial,
    type_identifiant_legal,
    identifiant_legal,
    pays_iso2,
    courriel_general,
    telephone_general,
    actif
)
ON projet_aig.fournisseur TO aig_referentiel;


-- PRODUIT
GRANT INSERT (
    code,
    designation,
    description,
    id_unite_base,
    actif
)
ON projet_aig.produit TO aig_referentiel;


-- ASSOCIATION FOURNISSEUR / PRODUIT / CONDITIONNEMENT
GRANT INSERT (
    id_fournisseur,
    id_produit,
    id_conditionnement,
    reference_commerciale,
    actif
)
ON projet_aig.fournisseur_produit TO aig_referentiel;


-- HISTORIQUE DES RÉFÉRENCES
GRANT INSERT (
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
)
ON projet_aig.historique_reference TO aig_referentiel;

-- ASSOCIATION FOURNISSEUR / PRODUIT / CONDITIONNEMENT
GRANT INSERT (
    id_fournisseur,
    id_produit,
    id_conditionnement,
    reference_commerciale,
    actif
)
ON projet_aig.fournisseur_produit TO aig_imp;


-- LOT D'IMPORTATION
GRANT INSERT (
    nom_fichier_original,
    nom_fichier_stocke,
    chemin_stockage,
    type_mime_declare,
    type_fichier_detecte,
    taille_fichier,
    sha256_fichier,
    statut,
    id_acteur_createur
)
ON projet_aig.lot_import TO aig_imp;


-- PRÉVISUALISATION
GRANT INSERT (
    id_lot_import,
    numero_version,
    sha256_fichier,
    empreinte_donnees,
    jeton_confirmation_hash,
    nombre_lignes,
    nombre_acceptees,
    nombre_rejetees,
    nombre_ignorees,
    nombre_erreurs,
    statut_previsualisation,
    date_expiration
)
ON projet_aig.import_previsualisation TO aig_imp;


-- LIGNES D'IMPORTATION
GRANT INSERT (
    id_lot_import,
    numero_ligne,
    donnees_brutes,
    fournisseur_identifie,
    produit_identifie,
    conditionnement_identifie,
    reference_lue,
    reference_normalisee,
    statut_ligne,
    motif_resultat,
    id_fournisseur_produit,
    date_analyse
)
ON projet_aig.import_ligne TO aig_imp;


-- HISTORIQUE DES RÉFÉRENCES
GRANT INSERT (
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
)
ON projet_aig.historique_reference TO aig_imp;


-- HISTORIQUE DES IMPORTATIONS
GRANT INSERT (
    id_lot_import,
    id_import_ligne,
    id_previsualisation,
    type_evenement,
    ancien_statut,
    nouveau_statut,
    description,
    motif,
    id_acteur,
    date_evenement,
    details
)
ON projet_aig.historique_import TO aig_imp;

-- DROIT UPDATE

-- UNITÉ DE MESURE
GRANT UPDATE (
    libelle,
    symbole,
    precision_decimale,
    facteur_conversion,
    id_unite_reference,
    actif
)
ON projet_aig.unite_mesure TO aig_referentiel;


-- TYPE DE CONDITIONNEMENT
GRANT UPDATE (
    libelle,
    description,
    actif
)
ON projet_aig.type_conditionnement TO aig_referentiel;


-- CONDITIONNEMENT
GRANT UPDATE (
    mode_contenu,
    quantite_contenu,
    id_unite_mesure,
    designation,
    actif
)
ON projet_aig.conditionnement TO aig_referentiel;


-- COMPOSITION DE CONDITIONNEMENT
GRANT UPDATE (
    quantite_enfant
)
ON projet_aig.composition_conditionnement TO aig_referentiel;


-- FOURNISSEUR
GRANT UPDATE (
    raison_sociale,
    nom_commercial,
    type_identifiant_legal,
    identifiant_legal,
    pays_iso2,
    courriel_general,
    telephone_general,
    actif
)
ON projet_aig.fournisseur TO aig_referentiel;


-- PRODUIT
GRANT UPDATE (
    designation,
    description,
    id_unite_base,
    actif
)
ON projet_aig.produit TO aig_referentiel;

GRANT UPDATE (date_modification)
ON projet_aig.fournisseur
TO aig_referentiel;

GRANT UPDATE (date_modification)
ON projet_aig.produit
TO aig_referentiel;

GRANT UPDATE (
    reference_commerciale,
    actif,
    date_modification
)
ON projet_aig.fournisseur_produit TO aig_referentiel;

GRANT UPDATE (
    reference_commerciale,
    actif,
    date_modification
)
ON projet_aig.fournisseur_produit TO aig_imp;

GRANT UPDATE (
    statut_previsualisation,
    date_confirmation,
    id_acteur_confirmation,
    date_invalidation,
    motif_invalidation
)
ON projet_aig.import_previsualisation TO aig_imp;

GRANT UPDATE (
    statut,
    date_analyse,
    date_debut_integration,
    date_fin_integration,
    date_cloture,
    motif_annulation,
    motif_erreur
)
ON projet_aig.lot_import TO aig_imp;

-- =========================================================
-- DROITS DE CONSULTATION
-- =========================================================

GRANT SELECT ON projet_aig.* TO aig_consultation;
GRANT SELECT ON projet_aig.* TO aig_referentiel;
GRANT SELECT ON projet_aig.* TO aig_imp;

