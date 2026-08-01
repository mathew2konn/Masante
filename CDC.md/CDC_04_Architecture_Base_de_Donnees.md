# CAHIER DES CHARGES N°4 — ARCHITECTURE BASE DE DONNÉES
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Ce document définit **la persistance** : il est consommé par CDC_03 (backend), alimente CDC_05/CDC_07 (IA), CDC_06 (paiement), CDC_09 (référentiels nationaux), CDC_13 (Data Lake/Warehouse et gouvernance), et applique CDC_10 (sécurité).

**Distinction importante** : CDC_04 traite des **bases opérationnelles** (moteurs, schémas, tables, index, réplication). CDC_13 traite de la **plateforme de données analytiques** (Data Lake, Data Warehouse, pipelines, gouvernance nationale).

---

## 1. Principes fondamentaux

1. **Polyglot Persistence** : « la bonne base de données pour le bon usage ». Une seule technologie ne peut répondre à tous les besoins.
2. **Database per Service** : chaque microservice possède sa propre base ; aucun service n'accède directement à la base d'un autre (Rule-001). Les échanges passent par API ou événements.
3. **Centralisation logique, distribution physique** : gouvernance nationale centralisée, données distribuées par domaine, synchronisation vers la plateforme nationale.
4. **Aucune règle métier codée en dur** : les protocoles médicaux, seuils cliniques, référentiels et tarifs sont **stockés en base** (CDC_08, CDC_09) afin qu'un nouveau pays s'ajoute sans modifier le code.
5. **Migrations obligatoires** : chaque nouvelle table ou modification produit une migration versionnée. Aucune modification manuelle en production.
6. **Fichiers hors base** : la base ne stocke que métadonnées, chemins d'accès, informations de sécurité et empreintes d'intégrité (fichiers dans MinIO/S3).
7. **Sécurité et traçabilité par conception** : chiffrement, audit, cycle de vie des données.

---

## 2. Moteurs retenus et rôles

| Moteur | Rôle exclusif | Exemples |
|--------|---------------|----------|
| **PostgreSQL** | Données structurées et transactions critiques | identité, patients, rendez-vous, consultations, prescriptions, facturation, établissements, référentiels |
| **MongoDB** | Documents et données semi-structurées | observations médicales, comptes rendus, historiques, logs médicaux, événements |
| **Redis** | Cache, sessions, tokens, files, temps réel | cache API/SQL, sessions, JWT blacklist, OTP, géolocalisation, tracking ambulance |
| **Elasticsearch** | Recherche | recherche médicale, patients, médicaments, documents, read models CQRS |
| **Neo4j** | Relations complexes (graphe) | recommandations, parcours de soins, réseaux de prise en charge, détection de fraude relationnelle |
| **InfluxDB / TimescaleDB** | Séries temporelles | constantes vitales, IoT médical, métriques cliniques continues |
| **MinIO (S3-compatible)** | Stockage objet | images DICOM, PDF, ordonnances, comptes rendus, pièces jointes |
| **Qdrant** | Base vectorielle | embeddings et RAG (CDC_07) |
| **Data Lake / Data Warehouse** | Analytique | voir CDC_13 |

**ADR-001 — Choix de PostgreSQL** : retenu contre MySQL pour la gestion des transactions complexes, le support JSONB, les fonctionnalités avancées de recherche et d'indexation, son adoption dans les SaaS et systèmes de santé, et son intégration avec les outils d'analyse. La phase MVP peut démarrer sur une base unique, mais le schéma est conçu dès le départ pour le découpage par service.

---

## 3. Stratégie d'évolution (phases)

### Phase 1 — MVP
Une base PostgreSQL unique, organisée en **schémas** correspondant aux futurs services :
```
masante
 ├── schema identite        (utilisateurs, roles, permissions, sessions)
 ├── schema patients
 ├── schema etablissements  (hopitaux, cliniques, pharmacies, laboratoires)
 ├── schema medical         (consultations, diagnostics, prescriptions, dossiers)
 ├── schema pharmacie       (stocks, delivrances, commandes)
 ├── schema laboratoire
 ├── schema radiologie
 ├── schema rendezvous
 ├── schema facturation
 ├── schema assurance
 ├── schema referentiels    (données nationales)
 └── schema audit
```

### Phase 2 — Microservices
Chaque schéma devient une base autonome : `patient_db`, `consultation_db`, `prescription_db`, `pharmacy_db`, `laboratory_db`, `radiology_db`, `appointment_db`, `billing_db`, `insurance_db`, `identity_db`, `referential_db`, `ai_db`, `audit_db`. Reliées par événements (Outbox/Saga) et API, jamais par jointures inter-bases.

### Phase 3 — Multi-pays
Un profil national par pays : référentiels CI, Sénégal, Bénin, Mali… Le moteur de triage utilise les protocoles du pays de l'établissement. **Aucun changement de code** : on ajoute des référentiels et des protocoles.

---

## 4. Conventions de nommage (PostgreSQL) — obligatoires

- Tables : **pluriel, minuscules, snake_case, français** — `patients`, `rendez_vous`, `consultations`, `ordonnances`, `stocks_pharmacies`, `districts_sanitaires`.
- Colonnes : snake_case — `date_naissance`, `groupe_sanguin`, `numero_national_sante`.
- Clés primaires : `id` de type **UUID v7** (ou BIGSERIAL selon ADR), plus un identifiant métier lisible quand requis (`numero_dossier`, `numero_facture`).
- Clés étrangères : `<table_singulier>_id` — `patient_id`, `medecin_id`, `hopital_id`.
- Index : `idx_<table>_<colonnes>` ; index uniques : `uq_<table>_<colonnes>` ; contraintes : `ck_<table>_<règle>`, `fk_<table>_<table_cible>`.
- Colonnes techniques systématiques : `created_at`, `updated_at`, `deleted_at` (suppression logique), `created_by`, `updated_by`, `version` (verrouillage optimiste).
- Multi-pays / multi-établissement : `pays_code`, `etablissement_id` sur toutes les tables opérationnelles concernées.
- Types : `TIMESTAMPTZ` (jamais TIMESTAMP nu), `NUMERIC` pour les montants (jamais FLOAT), `JSONB` pour les structures variables, ENUM/table de référence pour les statuts.

---

## 5. Modèle de données

### 5.1 Modélisation attendue
Livrer **MCD**, **MLD**, **MPD** et diagrammes UML pour chaque domaine. Chaque table est documentée : colonnes, types, contraintes, relations, index, règles métier associées, sensibilité (donnée de santé, donnée personnelle, donnée financière), durée de conservation.

### 5.2 Domaines et tables principales

**Identité et accès** : `utilisateurs`, `roles`, `permissions`, `role_permission`, `utilisateur_role`, `sessions`, `mfa_facteurs`, `certificats_numeriques`, `tentatives_connexion`.

**Patients** : `patients` (identifiant national de santé, nom, prénoms, sexe, date/lieu de naissance, nationalité, groupe sanguin, photo, biométrie référencée), `patient_contacts`, `patient_adresses`, `personnes_autorisees`, `contacts_urgence`, `patient_fusions` (historique MPI — CDC_09), `patient_consentements`.

**Établissements** : `etablissements` (type public/privé/universitaire/militaire ; catégorie hôpital/clinique/centre médical/centre de santé/laboratoire/cabinet), `etablissement_informations_legales` (n° autorisation, n° fiscal, registre du commerce, date de création, statut, licence, autorité de tutelle), `etablissement_coordonnees` (adresse, pays, ville, commune, quartier, latitude, longitude, téléphone, email, site web), `etablissement_horaires` (7 jours + urgence 24h/24), `etablissement_images` (logo, photos), `etablissement_services` (service, tarif, durée moyenne, responsable), `etablissement_demandes_inscription` (méthode 2 d'onboarding), `chambres`, `lits`, `equipements`.

**Professionnels** : `professionnels_sante` (numéro professionnel, ordre, autorisation d'exercer, signature électronique), `specialites`, `professionnel_specialite`, `professionnel_etablissement`, `professionnel_horaires`, `professionnel_langues`, `professionnel_diplomes`.

**Rendez-vous** : `rendez_vous` (états : `EN_ATTENTE_VALIDATION`, `PREVALIDE_SECRETAIRE`, `CONFIRME_EN_ATTENTE_PAIEMENT`, `PAYE`, `ANNULE`, `REFUSE`, `TERMINE`), `creneaux`, `disponibilites`, `conges`, `quotas_consultation`, `file_attente`.

**Médical (EHR)** : `dossiers_medicaux`, `consultations`, `observations`, `diagnostics` (codés CIM-10/CIM-11), `symptomes` (SNOMED CT), `antecedents`, `allergies`, `maladies_chroniques`, `traitements`, `vaccinations`, `hospitalisations`, `admissions`, `sorties`, `constantes_vitales` (série temporelle), `scores_cliniques` (NEWS2, qSOFA, Glasgow…), `documents_medicaux` (métadonnées + chemin MinIO), `comptes_rendus`.

**Prescriptions** : `ordonnances`, `ordonnance_lignes` (médicament, dosage, posologie, durée, instructions), `delivrances`, `renouvellements`, `signatures_electroniques`.

**Pharmacie** : `pharmacies`, `medicaments_pharmacie` (prix, TVA, stock, stock minimum, date d'expiration, disponibilité), `mouvements_stock`, `commandes`, `commande_lignes`, `livraisons`, `alertes_rupture`, `integrations_logiciels` (pharmacies avec ERP/caisse externe).

**Laboratoire** : `demandes_analyses`, `prelevements` (identifiant unique, code-barres/QR, cycle de vie), `analyses`, `resultats_analyses` (valeur, unité, valeurs de référence), `validations_biologiques`, `automates`.

**Radiologie** : `examens_imagerie` (radio, scanner, IRM, échographie), `etudes_dicom` (métadonnées ; images dans MinIO/PACS), `comptes_rendus_imagerie`, `analyses_ia_imagerie` (suspicion, score de confiance, version de modèle, validation radiologue).

**Urgences / Ambulances** : `appels_urgence`, `ambulances`, `dispatchs`, `positions_ambulance` (série temporelle), `transferts_patients`.

**Triage** : `triages` (numéro, date/heure, réponses, niveau de priorité, recommandation, service recommandé, établissements proposés, QR code, version du protocole, version du modèle IA), `triage_reponses`.

**Facturation et paiement** (détails CDC_06) : `factures`, `facture_lignes`, `devis`, `recus`, `avoirs`, `notes_credit`, `transactions`, `wallets`, `wallet_operations` (double écriture), `remboursements`, `reversements_etablissements`, `taxes`, `reductions`.

**Assurance / CNAM** : `assureurs`, `contrats_assurance`, `affiliations_cnam`, `garanties`, `plafonds`, `exclusions`, `prises_en_charge`, `demandes_remboursement`, `rejets`, `regularisations`.

**Référentiels nationaux** (CDC_09) : `protocoles_triage`, `protocoles_medicaux`, `medicaments_nationaux`, `maladies`, `actes_medicaux`, `classifications`, `communes`, `quartiers`, `districts_sanitaires`, `specialites_nationales`, `laboratoires_nationaux`, `analyses_catalogue`, `etablissements_nationaux`, `professionnels_nationaux`.

**IA** : `predictions_ia`, `explications_ia`, `versions_modeles`, `jeux_donnees_entrainement` (anonymisés), `validations_medecins`, `metriques_modeles`, `alertes_drift`.

**Audit et notifications** : `journaux_audit` (immuable), `evenements_systeme`, `outbox_events`, `notifications`, `notification_livraisons`, `consentements`, `acces_dossiers` (qui a consulté quel dossier, quand, pourquoi).

### 5.3 Règles de conception
- Toute donnée de santé est rattachée au **numéro national de santé** (CDC_09).
- Toute table opérationnelle porte `etablissement_id` et `pays_code` pour l'isolement multi-tenant.
- Les statuts sont des valeurs contrôlées (ENUM PostgreSQL ou table de référence) — jamais de texte libre.
- Suppression **logique** par défaut (`deleted_at`) ; suppression physique uniquement selon les règles de conservation réglementaires.
- Toute écriture métier significative alimente `outbox_events` dans **la même transaction** (Outbox Pattern — CDC_03 §8.5).

---

## 6. Intégrité, contraintes et objets avancés PostgreSQL

- **Contraintes** : clés primaires, clés étrangères avec `ON DELETE RESTRICT` par défaut, `UNIQUE` (numéro national de santé, numéro professionnel, numéro de facture), `CHECK` (ex. date de naissance non postérieure à aujourd'hui, montant ≥ 0, SpO2 entre 0 et 100), `NOT NULL` sur toute donnée obligatoire.
- **Triggers** : mise à jour de `updated_at`, alimentation de l'audit, contrôle d'intégrité métier ne pouvant être garanti autrement. Les triggers ne remplacent jamais la logique métier applicative.
- **Views** et **Materialized Views** : vues de lecture pour les tableaux de bord et rapports ; rafraîchissement planifié (batch).
- **Functions** : uniquement pour des opérations techniques (calculs d'index, normalisation) — jamais de règle médicale.
- **Partitions** : `consultations`, `ordonnances`, `journaux_audit`, `constantes_vitales`, `transactions`, `evenements_systeme` partitionnées par jour/semaine/mois/année selon le volume.
- **Extensions** : `uuid-ossp`/`pgcrypto`, `pg_trgm` (recherche floue), `postgis` si géospatial avancé requis, `pgvector` en option de secours (Qdrant reste le choix principal).

---

## 7. Performance

### 7.1 Objectifs contractuels
- Lecture simple < **50 ms**
- Recherche < **200 ms**
- Authentification < **100 ms**
- Consultation d'un dossier patient < **300 ms**
- Disponibilité **99,99 %** (< 53 minutes d'arrêt par an)

### 7.2 Volumétrie cible
Dizaines de millions de patients, milliards de consultations, milliards d'examens, plusieurs pétaoctets d'images médicales, milliards de logs.

### 7.3 Moyens
- **Index** obligatoires sur `patient_id`, `medecin_id`, `hopital_id`, `date_consultation`, `specialite`, `telephone`, `email`, `numero_national_sante`, plus index composites (ex. `(date_consultation, medecin_id)`, `(etablissement_id, statut, date)`).
- Interdiction de `SELECT *` ; projection explicite des colonnes.
- Pagination `LIMIT/OFFSET` pour les petits volumes, **keyset pagination** pour les grands.
- **EXPLAIN ANALYZE** obligatoire sur toute requête importante avant mise en production : détection des scans complets, vérification des index, mesure du coût.
- **Partitionnement** (voir §6) et archivage des partitions anciennes.
- **Cache Redis** avec TTL imposés : médecins 15 min, hôpitaux 24 h, pharmacies 12 h, données géographiques 30 jours, statistiques selon fraîcheur. Invalidation par TTL, événement, versioning, Cache-Aside.
- **Read replicas** : toutes les lectures non critiques dirigées vers les replicas ; écritures sur le primary.
- **Read models CQRS** (Elasticsearch, Redis, MongoDB) pour le dossier patient, les urgences, les statistiques et le reporting — dénormalisation autorisée.
- **Sharding** des données patients quand nécessaire (ex. A-F / G-M / N-T / U-Z) ou par région/district sanitaire.
- Pool de connexions (PgBouncer) et limites par service.

---

## 8. Haute disponibilité, réplication et sauvegarde

- **Cluster** : Primary + Replicas (minimum 2, cible 3), réplication en streaming, **failover automatique** avec promotion d'un replica en quelques secondes.
- **Réplication géographique** : Abidjan (principal) → Yamoussoukro → Bouaké → San Pedro → sauvegarde cloud. Bascule automatique si une région devient indisponible.
- **RTO < 30 minutes** (services critiques), **RPO < 5 minutes** grâce à la réplication continue.
- **Sauvegardes 3-2-1** : 3 copies, 2 supports différents (disque + stockage objet), 1 copie hors site. Types : complète, incrémentale, différentielle. Sauvegardes **immuables** et snapshots contre les ransomwares ; coffre-fort de sauvegarde et séparation réseau.
- **Tests de restauration réguliers** et exercices de bascule complets (perte d'un replica, perte du primary, perte d'un datacenter).
- Ordre de restauration imposé : IAM → base patients → API médicales → paiements → applications utilisateurs.

---

## 9. Sécurité des données (détails CDC_10)

- **Chiffrement au repos AES-256** (données, sauvegardes, stockage objet) ; **TLS 1.3 en transit** ; clés gérées par **HSM/KMS**, rotation planifiée.
- Chiffrement au niveau colonne pour les données ultra-sensibles (biométrie, identifiants d'assurance, données financières).
- **Contrôle d'accès RBAC + ABAC** appliqué aussi au niveau base : comptes techniques distincts par service, privilèges minimaux (Least Privilege), aucun compte applicatif superutilisateur, `Row Level Security` PostgreSQL pour l'isolement par établissement lorsque pertinent.
- **Audit** : `journaux_audit` horodaté, inaltérable (append-only, hachage chaîné ; blockchain privée optionnelle), traçant connexions, consultations de dossiers, modifications d'ordonnances, suppressions, exports, paiements, téléchargements, échecs de connexion. Table `acces_dossiers` traçant tout accès à un dossier patient.
- **Anonymisation / pseudonymisation** obligatoire avant toute utilisation en IA ou recherche (CDC_05, CDC_13).
- **Souveraineté** : données hébergées sur des infrastructures nationales contrôlées (cloud privé gouvernemental, hybrid cloud, datacenters nationaux).

---

## 10. Cycle de vie des données

```
Création → Validation → Stockage → Réplication → Consultation → Archivage → Suppression (selon la réglementation)
```
Chaque étape est tracée. Règles :
- **Qualité** : champs obligatoires, cohérence (ex. interdiction d'une date de naissance future), détection de doublons (MPI — CDC_09), contrôles de format (téléphone, identifiants).
- **Conservation** : durées définies par la réglementation médicale (ex. conservation longue durée des dossiers) ; archivage sur stockage froid ; purge encadrée et journalisée.
- **Data Ownership** (CDC_13) : patient → Ministère de la Santé ; prescription → médecin ; résultat de laboratoire → biologiste ; facture → administration.

---

## 11. Migrations et gestion du schéma

- Migrations versionnées (Laravel migrations / outil équivalent par service), **réversibles** quand c'est possible, testées en CI, exécutées comme processus admin séparé (`php artisan migrate`).
- Aucune modification destructive sans plan de migration en plusieurs étapes (expand → migrate → contract) pour garantir le zéro downtime.
- Seeders pour les référentiels nationaux et les données de test ; jamais de données réelles en environnement de développement.
- Chaque changement de schéma majeur produit un **ADR**.

---

## 12. Ordre de construction recommandé

1. Conventions, extensions, schémas, table d'audit, outbox.
2. Identité et accès (`identite`) + RBAC/ABAC.
3. Référentiels nationaux (CDC_09) — prérequis de tout le reste.
4. Établissements, professionnels, spécialités, services.
5. Patients + MPI + consentements.
6. Rendez-vous et créneaux.
7. Dossier médical (consultations, diagnostics, observations, constantes).
8. Prescriptions et délivrances.
9. Pharmacie et stocks.
10. Laboratoire, puis Radiologie (métadonnées DICOM).
11. Facturation, paiement, assurance/CNAM.
12. Triage, IA, urgences/ambulances.
13. Partitionnement, read models CQRS, réplication multi-sites, archivage.

Chaque étape est livrée avec migrations, index, contraintes, seeders, tests et documentation (MCD/MLD/MPD à jour).

---

*Fin du CDC_04 — Architecture Base de Données.*
