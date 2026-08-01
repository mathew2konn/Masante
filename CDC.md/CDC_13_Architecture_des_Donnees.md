# CAHIER DES CHARGES N°13 — ARCHITECTURE DES DONNÉES
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Ce document couvre la **plateforme de données** : ingestion, Data Lake, Data Warehouse, Big Data, pipelines, gouvernance, qualité, souveraineté. Il complète CDC_04 (bases opérationnelles) et CDC_09 (référentiels nationaux), alimente CDC_05/CDC_07 (IA) et les portails Statistiques/Ministère (CDC_11), et applique CDC_10 (sécurité).

---

## 1. Vision

Dans un système de santé numérique national, **la donnée est l'actif le plus stratégique**. MASANTÉ n'est pas une collection d'applications, mais une **plateforme nationale de collecte, stockage, traitement et valorisation des données médicales**.

Chaque interaction produit des données : un patient crée son identité médicale, un médecin réalise une consultation, un laboratoire génère un résultat, une pharmacie délivre un médicament, un hôpital consomme des ressources, une ambulance produit des données géographiques, l'IA analyse des tendances sanitaires.

**Objectif** : construire une infrastructure nationale de données de santé **sécurisée, interopérable et intelligente**.

---

## 2. Principes fondamentaux (7 principes)

1. **Centralisation logique** : les données appartiennent à une plateforme nationale unique, sans imposer une base unique physique. Données distribuées par domaine, synchronisation nationale, gouvernance centralisée.
```
Hôpital A (base locale) ─┐
                          ├→ Synchronisation → Plateforme Nationale MASANTÉ
Hôpital B (base locale) ─┘
```
2. **Séparation des responsabilités** : chaque domaine possède ses propres données (Patient Service → identité et informations administratives ; EHR Service → historique médical et diagnostics ; Pharmacy Service → médicaments et stocks ; Billing → facturation).
3. **Interopérabilité médicale** : HL7 FHIR, DICOM, SNOMED CT, CIM-10/CIM-11, LOINC, OpenHIE.
4. **Qualité par conception** : contrôles à l'ingestion, jamais de correction silencieuse.
5. **Sécurité et confidentialité** : chiffrement, contrôle d'accès, anonymisation avant tout usage secondaire.
6. **Traçabilité intégrale** : toute donnée a une origine, un responsable, un cycle de vie et un journal.
7. **Souveraineté** : localisation contrôlée des données, accès réglementé, hébergement national.

---

## 3. Architecture globale des données

```
SOURCES DE DONNÉES
Patients │ Médecins │ Hôpitaux │ Laboratoires │ Pharmacies │ Ambulances │ Assurances │ IoT médical
                        │
              DATA INGESTION LAYER
        (API, événements Kafka, batch, connecteurs partenaires)
                        │
 ┌──────────────────────┼───────────────────────┐
 Bases opérationnelles │ Data Warehouse │ Data Lake
 (CDC_04)              │ (décisionnel)  │ (brut, tous formats)
 └──────────────────────┼───────────────────────┘
                        │
                 IA / ANALYTICS
                        │
        Décisions médicales et sanitaires nationales
```

### 3.1 Couche d'ingestion
- **Temps réel** : événements publiés sur **Kafka** par les microservices (CDC_12).
- **API** : données issues des partenaires (logiciels hospitaliers, pharmacies, assureurs).
- **Batch** : imports massifs, synchronisations nocturnes, historiques.
- **IoT / dispositifs médicaux** : flux de constantes vitales.
Règles : validation à l'entrée, horodatage, identification de la source, idempotence, quarantaine des données non conformes.

---

## 4. Base Nationale de Santé (BNS)

Référentiel central unifiant les informations sanitaires du pays : identité médicale, historiques, statistiques, événements de santé.

**Identité Nationale de Santé** : chaque citoyen possède un identifiant unique (ex. `CI-SANTE-000045789` / `CIS241200012547` — format arrêté par ADR, voir CDC_09 §3) permettant de retrouver consultations, examens, prescriptions et hospitalisations.
```
Citoyen → Identité Nationale Santé → Toutes les applications MASANTÉ
```

---

## 5. Dossier Médical Électronique National (DMEN)

Cœur médical de MASANTÉ, il rassemble :
```
Patient
 ├── Consultations (→ Symptômes, Diagnostic, Prescription, Examens)
 ├── Diagnostics
 ├── Prescriptions
 ├── Analyses laboratoire
 ├── Images médicales
 └── Vaccinations
```
Le DMEN est alimenté par événements (CDC_12 §5.3) et exposé via un **read model** optimisé (CQRS — CDC_03 §9) garantissant une ouverture quasi instantanée du dossier.

---

## 6. Persistance polyglotte (rappel structurant — détails CDC_04)

| Type | Technologie | Usage |
|------|-------------|-------|
| Relationnel | PostgreSQL | patients, facturation, prescriptions, transactions |
| NoSQL documentaire | MongoDB | logs médicaux, événements, historiques, données semi-structurées |
| Séries temporelles | InfluxDB / TimescaleDB | constantes vitales, IoT médical |
| Stockage objet | MinIO (S3) | images médicales, documents, scanners |
| Recherche | Elasticsearch | recherche documentaire et clinique |
| Graphe | Neo4j | relations, parcours de soins, recommandations |
| Vectoriel | Qdrant | RAG (CDC_07) |

Exemple de série temporelle :
```
Patient 001 — 12:00 Température 38.2 °C — 12:30 Température 37.9 °C
```

---

## 7. Data Lake National de Santé

### 7.1 Pourquoi
Les données de santé sont extrêmement variées : textes médicaux, images, vidéos, signaux biométriques, données GPS, historiques. Une base classique ne suffit pas.
```
Sources → Data Lake → Processing → IA / BI
```

### 7.2 Types de données stockées
- **Structurées** : âge, sexe, poids, diagnostic, résultats codés.
- **Semi-structurées** : JSON FHIR — `{"diagnostic":"Paludisme","date":"2026-07-24"}`.
- **Non structurées** : images de scanner, comptes rendus PDF, vidéos, enregistrements audio.

### 7.3 Organisation en zones
- **Raw / Bronze** : données brutes immuables, telles que reçues, avec métadonnées d'origine.
- **Cleansed / Silver** : données nettoyées, normalisées, dédoublonnées, codées selon les référentiels.
- **Curated / Gold** : jeux de données prêts à l'usage (analytique, IA, reporting).
- **Sandbox** : espaces de travail pour la recherche, alimentés uniquement en données anonymisées.
Chaque zone a ses droits d'accès propres ; le passage d'une zone à l'autre est traçable.

---

## 8. Data Warehouse Santé

Contrairement au Data Lake, le Data Warehouse contient des **données nettoyées destinées aux analyses**.
**Utilisateurs** : Ministère, chercheurs, décideurs, directions d'établissement.
**Exemple** :
```
Nombre de cas de paludisme — 2026
Abidjan : 12 500 cas
Bouaké : 6 200 cas
```
Modélisation dimensionnelle (faits/dimensions) : faits (consultations, hospitalisations, délivrances, examens, transactions), dimensions (temps, géographie, établissement, professionnel, patient pseudonymisé, pathologie, médicament, assurance). Historisation des dimensions (SCD) pour permettre les analyses rétrospectives.

---

## 9. Pipeline Data Engineering

```
Collecte → Validation → Transformation → Stockage → Analyse
```
**Technologies imposées** :
- **Streaming** : Apache Kafka
- **Traitement** : Apache Spark
- **Orchestration** : Apache Airflow

### 9.1 Architecture Big Data
MASANTÉ produit potentiellement des millions de consultations, des milliards d'événements et des images médicales volumineuses.
```
Kafka → Spark → Data Lake → Machine Learning
```

### 9.2 Règles des pipelines
Idempotence, reprise sur incident, versionnage du code de transformation, tests de données (contrats de schéma), journalisation complète, alertes en cas d'échec, exécution planifiée par Airflow (horaire, nocturne, hebdomadaire, mensuelle) et via CronJobs Kubernetes.

### 9.3 Traitements batch typiques
Génération des statistiques nationales, calcul des indicateurs de santé, synchronisation avec la CNAM, import massif de dossiers médicaux, export de rapports, sauvegardes complètes, entraînement des modèles d'IA, nettoyage des journaux système.

---

## 10. Architecture IA et données

```
Données médicales → Nettoyage → Feature Engineering → Modèle ML → Prédiction
```
Exemple :
```
Entrées : âge, poids, glycémie, antécédents
Sortie : Risque diabète 78 % — Niveau : Élevé
```
**Règle absolue** (CDC_05) : anonymisation puis validation médicale avant constitution du jeu d'entraînement. Les jeux de données sont **versionnés** et rattachés à la version du modèle qu'ils ont produit (MLflow).

---

## 11. Gouvernance des données

### 11.1 Data Ownership
| Donnée | Responsable |
|--------|-------------|
| Patient | Ministère de la Santé |
| Prescription | Médecin |
| Résultat de laboratoire | Biologiste |
| Facture | Administration |

Chaque jeu de données possède un propriétaire identifié, un intendant (data steward) et une classification de sensibilité.

### 11.2 Catalogue de données
Inventaire de tous les jeux de données : description, schéma, source, propriétaire, sensibilité, fraîcheur, lignage (data lineage), usages autorisés. Le **lignage** doit permettre de remonter d'un indicateur national jusqu'à la source opérationnelle.

### 11.3 Qualité des données
Contrôles : données obligatoires, cohérence, doublons, erreurs. Exemple imposé :
```
Interdire : Date de naissance 2028 pour un patient né en 1990
```
Indicateurs de qualité suivis (complétude, unicité, validité, cohérence, fraîcheur), tableau de bord qualité, alertes et procédure de correction tracée à la source (jamais dans l'entrepôt).

### 11.4 Conformité et éthique
Registre des traitements, base légale de chaque usage, comité d'éthique pour les usages de recherche, contrats d'usage pour les partenaires, revue périodique des accès.

---

## 12. Sécurité des données

- **Chiffrement** : **AES-256** au repos, **TLS 1.3** en transit, chiffrement au niveau colonne pour les données ultra-sensibles.
- **Contrôle d'accès** : **RBAC + ABAC**.
```
Médecin : CONSULTER_DOSSIER
Pharmacien : VOIR_PRESCRIPTION
```
Accès au Data Lake et au Warehouse strictement limité, par zone et par jeu de données ; aucun accès direct aux données brutes identifiantes hors cas justifié.
- **Anonymisation / pseudonymisation** obligatoire pour l'IA, la recherche et les statistiques : suppression des identifiants directs, généralisation des quasi-identifiants, contrôle du risque de réidentification (k-anonymat ou équivalent), interdiction de croisement non autorisé.
- **Audit et traçabilité** : chaque action enregistrée.
```
2026-07-24 — Dr Kouassi — Consultation dossier patient 00125 — Action : Lecture
```
Technologies : ELK Stack, base d'audit dédiée, **blockchain privée optionnelle** pour l'inaltérabilité renforcée.

---

## 13. Réplication, haute disponibilité et conservation

- **Réplication** : Datacenter Abidjan → datacenter secondaire (Yamoussoukro, Bouaké, San Pedro) → sauvegarde cloud. Techniques : réplication SQL, sauvegardes automatiques, snapshots, Disaster Recovery (CDC_10 §10).
- **Objectifs** : RTO < 30 minutes, RPO < 5 minutes, disponibilité 99,99 %.
- **Cycle de vie** :
```
Création → Validation → Stockage → Réplication → Consultation → Archivage → Suppression (selon la réglementation)
```
- **Archivage** : stockage froid pour les données anciennes, restauration possible, index conservé.
- **Rétention** : durées définies par type de donnée conformément à la réglementation médicale ; purge journalisée et irréversible ; conservation obligatoire des journaux d'audit.

---

## 14. Souveraineté nationale

MASANTÉ doit respecter : la **souveraineté numérique ivoirienne**, la **localisation contrôlée des données**, un **accès réglementé**.
**Architecture recommandée** : cloud privé gouvernemental, hybrid cloud, datacenters nationaux. Aucune donnée patient identifiante ne quitte le territoire sans base légale explicite. Les traitements IA (y compris LLM — CDC_07) s'exécutent sur l'infrastructure nationale.

---

## 15. Synthèse des composants

| Composant | Rôle |
|-----------|------|
| Bases Microservices | Données opérationnelles |
| DMEN | Dossier médical patient |
| Data Lake | Stockage massif brut |
| Data Warehouse | Analyse décisionnelle |
| Kafka | Flux temps réel |
| Spark | Traitement Big Data |
| Airflow | Orchestration |
| IA Platform | Intelligence médicale |
| Gouvernance | Contrôle et conformité |

---

## 16. Résultats attendus

Fournir un dossier médical universel ; améliorer la prise en charge du patient ; anticiper les crises sanitaires ; alimenter l'intelligence artificielle ; aider le Ministère dans les décisions publiques ; garantir sécurité et souveraineté numérique.

---

## 17. Tests

- Tests de contrat de schéma à l'ingestion (rejet et quarantaine des données non conformes).
- Tests d'idempotence et de reprise des pipelines.
- Tests de qualité (complétude, unicité, validité, cohérence, fraîcheur) exécutés à chaque run.
- Tests d'anonymisation : vérification de l'absence d'identifiants directs et évaluation du risque de réidentification.
- Tests d'accès : un utilisateur ne doit jamais accéder à une zone ou un jeu de données non autorisé.
- Tests de lignage : traçabilité complète d'un indicateur jusqu'à sa source.
- Tests de restauration et de bascule multi-sites.

---

## 18. Ordre de construction recommandé

1. Couche d'ingestion événementielle (Kafka) + contrats de schéma + quarantaine.
2. Data Lake — zone Raw (immuable, horodatée, sourcée).
3. Catalogue de données + lignage + classification de sensibilité + propriétaires.
4. Pipelines de nettoyage/normalisation (Spark) → zone Cleansed, codage selon les référentiels (CDC_09).
5. Contrôles de qualité automatisés + tableau de bord qualité.
6. Zone Curated + Data Warehouse (modèle dimensionnel) + premiers indicateurs nationaux.
7. Anonymisation/pseudonymisation + sandbox de recherche.
8. Read models CQRS pour le DMEN et les tableaux de bord.
9. Orchestration Airflow complète + batchs planifiés.
10. Alimentation des modèles IA (jeux de données versionnés) + boucle de validation médicale.
11. Réplication multi-sites, archivage, rétention, purge, exercices de restauration.

Chaque étape est testée et validée avant de passer à la suivante ; en cas de problème, correction ciblée de la seule partie fautive.

---

*Fin du CDC_13 — Architecture des Données.*
