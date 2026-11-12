# Gestion des migrations SQL — Projet AIG

## 1. Objectif

Ce répertoire centralise les migrations SQL du projet AIG.

Le dispositif vise à assurer :
- la traçabilité des évolutions du schéma MariaDB ;
- l'identification des fichiers par empreinte SHA-256 ;
- la prévention des exécutions répétées ;
- la vérification des préconditions et des résultats ;
- la conservation de l'historique des migrations ;
- la gestion explicite des migrations préexistantes et des incidents.

La base métier concernée est `projet_aig`.

**Attention :** aucune migration ne doit recréer cette base ni intervenir sur la base `ProjetAuthentificationBDD`.

## 2. Organisation

```text
database/
└── migrations/
    ├── bootstrap/
    │   └── 000_create_schema_migration.sql
    ├── imp/
    │   └── IMP_001_version_canonicalisation.sql
    └── README.md
```

### Répertoires

- `bootstrap/` : initialisation du mécanisme de suivi.
- `imp/` : migrations du module d'import.

Les futures migrations seront classées par domaine fonctionnel.

## 3. Table de suivi

La table `schema_migration` conserve les informations suivantes :

| Colonne | Description |
|---|---|
| `id_migration` | Identifiant unique de migration |
| `description` | Objet de la migration |
| `sha256_fichier` | Empreinte SHA-256 du fichier SQL |
| `date_application` | Date d'enregistrement |
| `applique_par` | Compte MariaDB ayant effectué l'enregistrement |

L'identifiant constitue la clé primaire.

Une migration enregistrée ne doit jamais être rejouée automatiquement.

L'empreinte permet de détecter une modification du fichier après son enregistrement.

Le registre ne remplace pas la vérification du schéma réel.

## 4. Règles de gestion

### MIG-P01 — Identifiant unique

Chaque migration possède un identifiant stable et unique.

### MIG-P02 — Empreinte SHA-256

Chaque migration est identifiée par l'empreinte SHA-256 de son fichier SQL.

Toute modification d'un fichier déjà enregistré doit être considérée comme une anomalie.

Une migration appliquée ne doit pas être réécrite pour modifier son comportement.

### MIG-P03 — Prévention des réexécutions

Avant toute exécution, vérifier si l'identifiant existe déjà dans `schema_migration`.

Une migration déjà enregistrée ne doit pas être exécutée une seconde fois.

### MIG-P04 — Vérification des préconditions

Avant exécution, contrôler :
- la base sélectionnée ;
- l'existence des tables concernées ;
- l'existence ou l'absence des colonnes concernées ;
- les contraintes et index nécessaires ;
- la cohérence entre le schéma réel et le résultat attendu.

### MIG-P05 — Sauvegarde préalable

Toute modification structurelle nécessite une sauvegarde préalable adaptée.

La sauvegarde doit être vérifiée avant l'exécution.

### MIG-P06 — Contrôle du résultat

Après exécution, vérifier effectivement les modifications dans MariaDB.

La réussite de la commande SQL ne suffit pas à valider une migration.

### MIG-P07 — Enregistrement après validation

L'enregistrement dans `schema_migration` intervient uniquement après vérification du résultat réel.

Le registre ne doit jamais annoncer comme appliquée une migration dont le résultat n'a pas été contrôlé.

### MIG-P08 — Réconciliation

Une modification déjà présente dans la base, mais absente du registre, peut être réconciliée.

La description doit préciser qu'il s'agit d'une réconciliation et non d'une nouvelle exécution.

L'origine inconnue d'une modification doit être explicitement mentionnée.

### MIG-P09 — Intégrité des fichiers

Si un identifiant existe déjà avec une empreinte différente, l'opération est refusée.

Il faut analyser l'écart avant toute intervention.

### MIG-P10 — Gestion des échecs

Une migration échouée ou interrompue nécessite une intervention explicite.

Il est interdit de relancer aveuglément un script dont certaines instructions auraient déjà été exécutées.

L'état réel de la base doit être contrôlé avant toute reprise.

## 5. Procédure d'application

### Étape 1 — Identification

Déterminer :
- l'identifiant de migration ;
- le fichier concerné ;
- les tables et objets SQL modifiés ;
- les décisions fonctionnelles associées.

### Étape 2 — Vérification du registre

Consulter le registre :

```sql
SELECT *
FROM schema_migration
WHERE id_migration = 'IDENTIFIANT_MIGRATION';
```

Vérifier également l'absence de migration concurrente ou de situation incohérente.

- Si l'identifiant existe avec la même empreinte, ne pas réexécuter la migration.
- Si l'identifiant existe avec une empreinte différente, interrompre la procédure et analyser l'anomalie.

### Étape 3 — Vérification du fichier

Calculer son empreinte :

```bash
sha256sum database/migrations/imp/NOM_MIGRATION.sql
```

Conserver l'empreinte obtenue dans le compte rendu d'application.

### Étape 4 — Sauvegarde

Réaliser une sauvegarde adaptée à la migration.

Vérifier que le fichier de sauvegarde est exploitable avant de poursuivre.

### Étape 5 — Préconditions

Contrôler l'état réel du schéma.

Ne jamais supposer qu'une colonne, une table ou une contrainte est absente sans vérification.

### Étape 6 — Exécution

Exécuter uniquement le fichier validé, sur la base `projet_aig`.

Les opérations DDL MariaDB peuvent provoquer des validations implicites. Une transaction classique ne garantit donc pas le retour arrière d'une migration structurelle.

### Étape 7 — Vérification

Contrôler :
- les colonnes ;
- les types ;
- les valeurs par défaut ;
- les index ;
- les contraintes ;
- les données éventuellement concernées.

### Étape 8 — Enregistrement

Après validation seulement, enregistrer la migration :

```sql
INSERT INTO schema_migration (
    id_migration,
    description,
    sha256_fichier,
    applique_par
)
VALUES (
    'IDENTIFIANT_MIGRATION',
    'Description de la migration',
    'EMPREINTE_SHA256',
    CURRENT_USER()
);
```

### Étape 9 — Contrôle final

Vérifier l'enregistrement :

```sql
SELECT *
FROM schema_migration
ORDER BY date_application;
```

Comparer le registre et le schéma réel.

## 6. Réconciliation d'une migration préexistante

Lorsqu'une modification existe déjà dans MariaDB sans être enregistrée :

1. identifier précisément la modification ;
2. vérifier sa conformité avec le fichier de migration ;
3. contrôler les objets SQL concernés ;
4. déterminer si son origine est connue ;
5. documenter toute incertitude ;
6. enregistrer la migration avec une description explicite de réconciliation.

Une réconciliation ne doit jamais être présentée comme une exécution du fichier SQL.

Une migration peut être enregistrée selon deux situations distinctes :

- **Migration exécutée** : le fichier SQL a effectivement été appliqué à la base de données après vérification des préconditions. Son empreinte SHA-256 correspond au fichier exécuté.
- **Migration réconciliée** : les objets attendus étaient déjà présents dans la base et leur conformité avec le fichier de référence a été vérifiée. Le fichier SQL n'a pas été exécuté dans le cadre de cette réconciliation.

Dans le second cas, l'empreinte SHA-256 enregistrée correspond à la version du fichier de référence utilisée pour effectuer le contrôle. Elle ne constitue pas une preuve de son exécution.

La table `schema_migration` ne disposant pas d'une colonne dédiée au statut, la distinction entre exécution et réconciliation doit être explicitement portée par la description de l'enregistrement et par la documentation du projet.

Une réconciliation ne permet pas de déterminer rétrospectivement l'origine des objets déjà présents lorsque celle-ci n'est pas documentée.

## 7. Historique initial

### 000_create_schema_migration

- Objet : création de la table de suivi.
- Statut : exécutée et enregistrée.
- Exécutant : `root@localhost`.
- SHA-256 :

```text
d83fc66991f5e9be2b625a5946bb6a0d3f5b58a35a96aba58bec0c74950b2a78
```

### IMP_001_version_canonicalisation

- Objet : versionnement de la méthode de canonicalisation.
- Table concernée : `import_previsualisation`.
- Colonne : `version_canonicalisation`.
- Valeur initiale : `AIG_IMPORT_DATA_V1`.
- Statut : réconciliée et enregistrée.
- Origine de la modification : non déterminée.
- SHA-256 :

```text
8a3d827bc1f7ba6ac3dffdfec100deba513e763eae8e9e397cd532d8dcc6d8ff
```

La colonne était déjà présente lors de la mise en place du suivi des migrations. Le fichier IMP-001 n'a donc pas été exécuté dans le cadre de cette procédure.

## 8. Précautions de sécurité

- Ne pas utiliser les comptes applicatifs pour administrer les migrations.
- Ne pas accorder de privilèges supplémentaires aux rôles applicatifs sans justification.
- Ne jamais stocker de mot de passe dans un fichier SQL de migration.
- Ne pas supprimer les anciennes entrées du registre.
- Ne pas modifier rétroactivement une migration enregistrée.
- Ne pas exécuter automatiquement les migrations sans contrôle préalable.
- Conserver les sauvegardes selon la politique de conservation du projet.

Les migrations applicatives et les opérations d'administration du serveur MariaDB doivent rester distinctes.

Les migrations peuvent créer ou faire évoluer les objets du schéma applicatif et intégrer des données de référence nécessaires au fonctionnement de l'application.

En revanche, les opérations d'administration des comptes et des privilèges MariaDB, notamment `CREATE USER`, `CREATE ROLE`, `GRANT`, `REVOKE` et `ALTER USER`, doivent être gérées au moyen de procédures administratives séparées.

Ces opérations doivent faire l'objet d'une justification, d'une vérification des privilèges concernés et d'une exécution explicite par une personne habilitée.

Les scripts d'administration ne doivent donc pas être intégrés au répertoire des migrations applicatives. Les identifiants et secrets d'authentification ne doivent jamais être stockés dans les scripts versionnés.

## 9. État du dispositif

À la date du 12 novembre 2026 :

- table `schema_migration` créée ;
- migration bootstrap exécutée et enregistrée ;
- migration IMP-001 réconciliée et enregistrée ;
- empreintes SHA-256 contrôlées ;
- organisation des répertoires définie ;
- règles MIG-P01 à MIG-P10 validées.

La gestion des migrations repose actuellement sur une procédure manuelle et contrôlée. Aucun mécanisme d'exécution automatique des migrations n'est considéré comme faisant partie du dispositif opérationnel établi à cette date.

Toute évolution vers une automatisation devra faire l'objet d'une décision et d'une validation spécifiques.
