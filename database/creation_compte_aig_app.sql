-- ============================================================
-- PROJET_AIG - Création du compte de connexion PHP
-- SGBD : MariaDB 11.8.x
--
-- Compte : aig_app@localhost
-- Rôles attribués : aig_consultation, aig_referentiel, aig_imp
-- Rôle par défaut : aig_consultation
--
-- PRÉREQUIS :
--   - Les rôles doivent déjà exister.
--   - Leurs privilèges doivent avoir été configurés et vérifiés.
--   - Exécuter ce script avec un compte administrateur MariaDB.
--
-- IMPORTANT :
--   Remplacer le mot de passe fictif ci-dessous par un secret
--   long, unique et généré localement AVANT exécution.
--   Ne jamais communiquer ce secret ni versionner ce fichier
--   avec le mot de passe réel.
-- ============================================================

-- Création du compte applicatif local.
-- La commande échoue volontairement si le compte existe déjà :
-- vérifier alors son état avant toute modification.
CREATE USER 'aig_app'@'localhost'
IDENTIFIED BY 'TON_NOUVEAU_MOT_DE_PASSE';

-- Attribution des rôles existants.
GRANT aig_consultation TO 'aig_app'@'localhost';
GRANT aig_referentiel TO 'aig_app'@'localhost';
GRANT aig_imp TO 'aig_app'@'localhost';

-- Consultation seule par défaut à chaque nouvelle connexion.
SET DEFAULT ROLE aig_consultation
FOR 'aig_app'@'localhost';

-- Contrôles de configuration.
SHOW GRANTS FOR 'aig_app'@'localhost';

-- ============================================================
-- UTILISATION CÔTÉ PHP / PDO
--
-- Connexion :
--   hôte       : localhost
--   base       : projet_aig
--   utilisateur: aig_app
--   mot de passe : le secret défini ci-dessus
--
-- Après connexion, le rôle actif par défaut doit être
-- aig_consultation.
--
-- Pour une opération de référentiel :
--   SET ROLE aig_referentiel;
--
-- Pour une opération d'import :
--   SET ROLE aig_imp;
--
-- Pour revenir à la consultation :
--   SET ROLE aig_consultation;
--
-- MariaDB n'additionne pas ces rôles : SET ROLE remplace
-- le rôle actif. L'application doit donc choisir explicitement
-- le rôle adapté à chaque opération.
--
-- Ce compte n'est pas un compte de migration ni d'administration.
-- Aucun droit CREATE, ALTER, DROP ou DELETE n'est accordé
-- directement à ce compte.
-- ============================================================
