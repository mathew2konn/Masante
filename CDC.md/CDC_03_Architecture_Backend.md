# CAHIER DES CHARGES N°3 — ARCHITECTURE BACKEND
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Le backend est le **cœur d'exécution** : il fournit les API consommées par CDC_01 (mobile) et CDC_02 (web), s'appuie sur CDC_04 (base de données), délègue à CDC_05 (IA), CDC_06 (paiement), CDC_07 (IA générative), CDC_08 (protocoles médicaux), s'appuie sur CDC_09 (référentiels nationaux), applique CDC_10 (sécurité), implémente les fonctionnalités décrites en CDC_11 (applications), est découpé selon CDC_12 (microservices) et produit les données décrites en CDC_13 (architecture des données).

---

## 1. Principes d'architecture non négociables

### 1.1 Règles fondatrices du projet (Architecture Handbook MASANTÉ)
- **Rule-001** : aucun module ne dépend directement d'un autre module. Toute communication passe par API internes, événements ou contrats.
- **Rule-002** : le code métier ne dépend jamais de Laravel, de React ni de PostgreSQL. Le framework est un détail d'implémentation (Clean Architecture).
- **Rule-003** : chaque décision importante produit un **ADR** (contexte, décision, conséquences, alternatives étudiées). Chaque endpoint possède une documentation **OpenAPI**.
- **Rule-004** : chaque fonctionnalité doit répondre à 5 questions avant d'être intégrée : pourquoi existe-t-elle ? qui l'utilise ? quelles données manipule-t-elle ? quels modules appelle-t-elle ? comment évoluera-t-elle dans 5 ans ?
- **Rule-005** : aucune IA ne prend de décision médicale sans expliquer son raisonnement (données utilisées, protocoles appliqués, score de confiance, limites).
- Chaque nouvelle table possède une **migration**. Aucune modification manuelle de schéma.

### 1.2 Styles d'architecture appliqués
**API First**, **Domain-Driven Design**, **Clean Architecture**, **Architecture Hexagonale**, **Microservices Cloud Native**, **Event-Driven Architecture**, **CQRS** (sur les domaines à forte valeur), **SOLID**, **Twelve-Factor App**, **Security by Design**, **Privacy by Design**, **Observability**, **Documentation First**, **Capability-Driven Architecture**.

### 1.3 Structure interne obligatoire de chaque service
```
service/
  Domain/          # Entities, Value Objects, Interfaces (aucune dépendance framework)
  Application/     # Use Cases, DTO, Commands, Queries
  Infrastructure/  # Database, Messaging, External APIs, Adapters
  Presentation/    # Controllers, Resources, Requests, Routes
  tests/
```
Le domaine ne dépend d'aucune couche extérieure (DIP). Les modules externes (paiement, SMS, assurance, laboratoire, IA, cloud) sont encapsulés derrière des **adaptateurs** implémentant des interfaces métier (OCP).

---

## 2. Stack technique imposée

| Composant | Technologie | Rôle |
|-----------|-------------|------|
| Cœur métier | **Laravel (dernière version) / PHP 8.4** | Services métier, API REST, workflows hospitaliers |
| IA / science | **Python + FastAPI** | Triage, prédiction, vision, OCR, NLP (CDC_05) |
| IA générative | **Python + FastAPI** | LLM privé, RAG (CDC_07) |
| Paiement | **Java + Spring Boot** | Microservice financier critique (CDC_06) |
| Temps réel | **NodeJS (NestJS + Socket.IO)** | Chat, notifications, GPS ambulance, événements |
| Base relationnelle | **PostgreSQL** | Données transactionnelles (décision actée ; MySQL était l'option initiale, PostgreSQL est retenu — ADR-001) |
| Documents | **MongoDB** | Observations, comptes rendus, historiques, logs médicaux |
| Cache/sessions | **Redis** | Cache, sessions, files, tokens, temps réel |
| Recherche | **Elasticsearch** | Recherche médicale et documentaire |
| Graphe | **Neo4j** | Relations complexes, recommandations |
| Séries temporelles | **InfluxDB / TimescaleDB** | Constantes vitales, IoT médical |
| Stockage objet | **MinIO (S3-compatible)** | Images médicales, PDF, documents |
| Vectoriel | **Qdrant** | RAG (CDC_07) |
| Messagerie | **RabbitMQ** (métier) + **Apache Kafka** (flux massifs) | Événements, asynchrone |
| Traitement Big Data | **Apache Spark** + **Apache Airflow** | Pipelines (CDC_13) |
| Gateway | **Kong Gateway** (ou Traefik/Spring Cloud Gateway) | Point d'entrée unique |
| Reverse proxy | **Nginx** (frontal HTTPS) + **Traefik** (Kubernetes) | SSL, compression, routage, auto-découverte |
| Service Mesh | **Istio / Envoy** | mTLS interne, contrôle du trafic, observabilité |
| Conteneurs | **Docker** + **Kubernetes** | Déploiement, scaling, auto-healing |
| IAM | **Keycloak** | OAuth2, OpenID Connect, JWT, rôles |
| Observabilité | **Prometheus, Grafana, ELK, OpenTelemetry** | Metrics, logs, traces |

### 2.1 Outils Laravel obligatoires
**Sanctum** (auth API mobile/portails/partenaires, en complément de Keycloak pour les clients internes), **Queue** (SMS, rapports, notifications, documents), **Horizon** (supervision des queues Redis), **Octane** (Swoole/RoadRunner pour la performance).

---

## 3. Architecture en couches

```
Applications (Web, Mobile, Portails, Partenaires)
        │
   Load Balancer  →  Nginx / Traefik
        │
   API Gateway (Kong)  — auth, routage, rate limiting, logs, sécurité
        │
 ┌──────┴───────────────────────────────────────────────┐
 │ Laravel (métier)  │ FastAPI (IA)  │ NodeJS (temps réel) │ Spring Boot (paiement)
 └──────┬───────────────────────────────────────────────┘
        │
  RabbitMQ / Kafka  (événements)      Redis (cache, sessions, files)
        │
  PostgreSQL │ MongoDB │ Elasticsearch │ Neo4j │ InfluxDB │ MinIO
        │
  Data Lake → Data Warehouse → BI / IA
        │
  Kubernetes / Docker / Cloud
```

### 3.1 Responsabilités par couche
- **Présentation** : contrôleurs, validation des requêtes, transformation en Resources. Aucune logique métier.
- **Application** : Use Cases, orchestration, transactions, publication d'événements.
- **Domaine** : entités, agrégats, value objects, règles métier pures, interfaces de repository.
- **Infrastructure** : implémentations de repositories, adaptateurs externes, messaging, stockage.

---

## 4. Modules métier Laravel

`Authentication`, `Patient`, `MedicalRecord (EHR)`, `Appointment`, `Consultation`, `Prescription`, `Pharmacy`, `Laboratory`, `Radiology`, `Hospitalization`, `Emergency/Ambulance`, `Billing`, `Insurance`, `CNAM`, `Establishment`, `Staff`, `Notification`, `Triage`, `Teleconsultation`, `Administration`, `Statistics`, `Referential` (référentiels nationaux), `Audit`.

Chaque module expose ses Use Cases ; les échanges inter-modules se font par API interne ou par événement, jamais par appel direct d'une classe d'un autre module (Rule-001).

### 4.1 Composants Laravel attendus par module
Controllers, Form Requests (validation), Services/Use Cases, Repositories (interface + implémentation), Policies (autorisation), Events, Listeners, Jobs (asynchrone), Middleware, Exceptions typées, DTO, API Resources, Observers si nécessaire, Factories et Seeders, Migrations, Tests.

---

## 5. Conception des API REST

### 5.1 Conventions
- Versionnement dans l'URL : `/api/v1/...`.
- Ressources au pluriel, en minuscules, séparateur `-` : `/api/v1/rendez-vous`, `/api/v1/dossiers-medicaux`.
- Verbes HTTP : `GET` (lecture), `POST` (création), `PUT/PATCH` (mise à jour), `DELETE` (suppression logique par défaut).
- Codes : 200, 201, 204, 400, 401, 403, 404, 409 (conflit), 410, 422 (validation), 429 (rate limit), 500, 503.
- **Idempotence** obligatoire sur les écritures sensibles via header `Idempotency-Key` (paiements, rendez-vous, prescriptions).
- Pagination par défaut (`page`, `per_page`, ou curseur/keyset pour les gros volumes), filtrage (`?specialite=`, `?ville=`), tri (`?sort=-date`), projection (`?fields=`).
- Documentation **OpenAPI** générée et publiée pour chaque endpoint (Rule-003).

### 5.2 Format de réponse normalisé
```json
{
  "data": { },
  "meta": { "page": 1, "per_page": 20, "total": 154 },
  "links": { "next": "...", "prev": null }
}
```

### 5.3 Format d'erreur normalisé
```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Les données fournies sont invalides.",
    "details": [{ "field": "telephone", "message": "Format invalide." }],
    "request_id": "req-8f2c...",
    "timestamp": "2026-07-29T10:30:00Z"
  }
}
```
Aucune donnée médicale ni technique sensible dans les messages d'erreur.

### 5.4 Domaines d'API à implémenter
Patients, Identité/Auth, Établissements, Médecins et personnel, Spécialités, Services, Rendez-vous, Consultations, Diagnostics, Prescriptions/Ordonnances, Dossier médical, Laboratoire (demandes, prélèvements, résultats), Radiologie (examens, DICOM, comptes rendus), Pharmacie (stock, délivrance, commandes, livraisons), Hospitalisation (chambres, lits, admissions, sorties), Urgences et ambulances, Triage, Facturation, Paiements, Assurances, CNAM, Wallet, Notifications, Téléconsultation, Statistiques, Référentiels nationaux, Audit, Administration, Intégration partenaires (logiciels hospitaliers, logiciels de pharmacie/caisse/ERP, assureurs, prestataires de paiement).

Pour chaque domaine : `GET` liste, `GET` détail, `POST` création, `PUT/PATCH` mise à jour, `DELETE` suppression logique, plus les endpoints d'action métier exprimant une **intention** (`POST /api/v1/prescriptions/{id}/valider`, `POST /api/v1/rendez-vous/{id}/confirmer`) — jamais `updateStatus` générique (règle CQRS).

---

## 6. Authentification et autorisation (détails CDC_10)

- **OAuth2 + OpenID Connect** via **Keycloak** ; **JWT** signé (clés protégées par HSM) ; refresh tokens ; sessions gérées côté Redis.
- **Laravel Sanctum** pour les clients applicatifs internes/mobiles selon la stratégie retenue par ADR.
- **MFA** obligatoire : médecins, administrateurs, ministère, assurances, super administrateurs ; recommandée pour les patients.
- **RBAC** : rôles Patient, Médecin, Infirmier, Secrétaire/Accueil, Pharmacien, Laborantin, Radiologue, Administrateur établissement, Super Administrateur, Ministère, Assurance, Service IA.
- **ABAC** : l'accès dépend en plus de l'établissement, du service, du lien de prise en charge patient-médecin, de l'heure, du pays et du contexte d'urgence. Exemple imposé : deux cardiologues de même rôle, seul celui qui suit le patient peut ouvrir son dossier.
- Chaque microservice **revalide** systématiquement le JWT (signature, expiration, permissions) — aucune confiance implicite (Zero Trust).
- Politiques d'autorisation implémentées en **Policies** Laravel + vérification ABAC centralisée.

---

## 7. Workflows métier à implémenter

### 7.1 Onboarding d'un établissement (2 méthodes obligatoires)
1. **Création par l'administrateur** : l'admin crée le compte établissement → le directeur/responsable reçoit un accès → l'établissement renseigne lui-même médecins, spécialités, horaires, chambres, services, urgences, prix.
2. **Demande d'inscription** : l'établissement remplit un formulaire → l'équipe MASANTÉ vérifie les informations → validation → publication.

### 7.2 Rendez-vous à validation en deux étapes
`Réservation patient → pré-validation secrétaire (créneaux, quotas, congés, urgences) → validation finale médecin → notification de confirmation → paiement du patient → confirmation finale`. Le système vérifie : disponibilité, conflit, paiement, confirmation, notification. Si le médecin n'a pas de secrétaire, il assure les deux étapes. États persistés et auditables.

### 7.3 Consultation
`Accueil → consultation → observations → diagnostic (CIM-10/CIM-11) → demandes d'examens → prescription électronique signée → clôture` → publication de l'événement `ConsultationClosed`.

### 7.4 Prescription et délivrance
`Création ordonnance → vérifications (référentiel médicaments, interactions, allergies, contre-indications, posologie selon âge/poids/insuffisance rénale) → signature électronique du médecin → événement PrescriptionCreated → réception pharmacie → vérification pharmacien → délivrance → mise à jour du stock → traçabilité nationale`.

### 7.5 Laboratoire
`Demande d'analyse → enregistrement du prélèvement → étiquetage code-barres/QR → transport → réception → analyse → validation biologique → événement LaboratoryResultAvailable → insertion dans l'EHR → notification médecin et patient → analyse IA de risque`.

### 7.6 Radiologie
`Demande → réalisation → stockage DICOM dans PACS/MinIO (métadonnées en SQL) → analyse IA (suspicion + score de confiance) → validation radiologue → compte rendu signé → EHR`.

### 7.7 Hospitalisation
`Admission → affectation chambre/lit → soins et constantes (infirmier) → traitements administrés (médicament, heure, dose, signature) → surveillance et alertes → sortie → synthèse d'hospitalisation → facturation`.

### 7.8 Urgence / Ambulance
`Appel → centre d'urgence → dispatch de l'ambulance disponible la plus proche (optimisation IA : disponibilité, trafic, hôpital adapté) → suivi GPS temps réel → transfert → prise en charge`.

### 7.9 Triage
`Questionnaire dynamique (questions fournies par le moteur de protocoles) → moteur de règles médicales (CDC_08) → moteur IA (CDC_05) → niveau de priorité + recommandation + service recommandé + établissements proches → fiche de triage (PDF + QR) → transmission au médecin`. **Le triage n'est jamais un diagnostic.**

### 7.10 Paiement et facturation
`Acte(s) → tarification → TVA → réductions → assurance → CNAM → facture PDF (QR, signature, numérotation unique, horodatage) → paiement (CDC_06) → reçu → reversement établissement → comptabilité`. Paiement direct : la plateforme ne manipule jamais les fonds ; les prestataires de l'établissement/pharmacie traitent la transaction.

### 7.11 Achat de médicament
`Recherche → pharmacies avec stock + itinéraire → panier → retrait ou livraison → paiement → commande`. Si ordonnance obligatoire : upload → validation pharmacien → vente autorisée. Interdiction absolue de vendre un médicament sous ordonnance sur la seule base du triage.

### 7.12 Assurance / CNAM
`Identification assureur → vérification contrat/affiliation → plafonds, exclusions, ticket modérateur → calcul de la prise en charge → reste à charge → ajustement de la facture → justificatifs → suivi des remboursements, rejets, corrections, régularisations`.

---

## 8. Architecture événementielle (EDA)

### 8.1 Brokers
- **RabbitMQ** : événements métier, tâches asynchrones, notifications, workflows transactionnels.
- **Kafka** : flux massifs — IoT médical, données nationales, statistiques temps réel, logs, événements analytiques.

### 8.2 Format d'événement
```json
{
  "eventId": "evt-984521",
  "eventType": "PrescriptionValidated",
  "eventVersion": "1.0",
  "timestamp": "2026-07-24T10:30:00Z",
  "producer": "prescription-service",
  "correlationId": "req-8f2c...",
  "payload": { "patientId": "PAT-00125", "doctorId": "MED-0089", "prescriptionId": "ORD-55421" }
}
```

### 8.3 Catalogue d'événements (non exhaustif)
- **Domain Events** : `PatientCreated`, `PatientUpdated`, `AppointmentRequested`, `AppointmentConfirmed`, `ConsultationCompleted`, `ConsultationClosed`, `DiagnosisRecorded`, `PrescriptionCreated`, `PrescriptionValidated`, `LaboratoryResultAvailable`, `ImagingReportPublished`, `AdmissionRegistered`, `DischargeCompleted`, `EmergencyCallReceived`, `AmbulanceDispatched`, `PaymentConfirmed`, `InvoiceIssued`, `TriagePerformed`, `RiskDetectedByAI`.
- **Integration Events** : `FHIRPatientUpdated`, `InsuranceClaimSubmitted`, `PaymentReceived`, `DICOMStudyCompleted`, `PharmacyStockSynchronized`.
- **Technical Events** : `ServiceUnavailable`, `DatabaseBackupCompleted`, `ModelTrainingFinished`.

### 8.4 Règles d'implémentation obligatoires
1. Un événement représente un **fait métier passé**.
2. Un événement ne contient **jamais** de logique métier.
3. Les événements sont **versionnés**.
4. Les consommateurs sont **idempotents**.
5. Les messages sont **persistés**.
6. Les erreurs sont gérées par **Dead Letter Queues**.
7. Les événements critiques sont **auditables**.
8. Les données médicales sensibles sont **minimisées** dans les messages (identifiants plutôt que contenu clinique).

### 8.5 Cohérence distribuée
- **Saga Pattern** pour les transactions multi-services (ex. admission : Emergency → Patient → Billing → Insurance) avec actions compensatoires en cas d'échec.
- **Outbox Pattern** obligatoire : l'événement n'est publié qu'après commit de la transaction (table `outbox` + publisher).

---

## 9. CQRS

Appliqué **uniquement** aux domaines à forte valeur : dossier médical national, urgences, statistiques sanitaires, IA, recherche médicale, facturation nationale, historique patient, reporting ministère.
- **Command Model** : PostgreSQL, règles métier, transactions, cohérence forte pour les données médicales critiques.
- **Query Model** : Elasticsearch (recherche), Redis (données fréquentes), MongoDB (vues documentaires dénormalisées).
- Flux : `Command → transaction → Domain Event → Event Bus → mise à jour du Read Model → Query disponible`.
- Les Commands portent une **intention métier** (`ValidatePrescription`, jamais `UpdatePrescriptionStatus`). Les Queries ne modifient jamais les données.
- **Eventual consistency** maîtrisée : événements persistés, retries automatiques, monitoring des synchronisations, mécanismes de compensation.
- **Event Sourcing** réservé aux domaines nécessitant une traçabilité complète (historique médical, audits médicaux, recherche clinique) — décision par ADR.

---

## 10. Services spécialisés et intégrations

### 10.1 Intégration IA (CDC_05 / CDC_07)
```
Laravel → (REST, timeout court, circuit breaker) → FastAPI → modèle → JSON → Laravel → Frontend
```
Exemple de contrat de triage :
```
POST /api/v1/triage
{ "age": 35, "temperature": 39.1, "frequence_cardiaque": 120, "spo2": 91, "douleur": 8, "symptomes": [...] }
→ { "priorite": "Orange", "score": 0.94, "explication": ["Température élevée", "Saturation faible", "Tachycardie"], "model_version": "triage-xgb-2.3.1" }
```
Toute réponse IA stockée avec la version du modèle et l'explication (Rule-005). Panne du service IA = dégradation gracieuse : les protocoles médicaux restent utilisables, les médecins continuent de travailler.

### 10.2 Notifications
Service transversal : Email, SMS, Push (FCM/APNs), WhatsApp. Déclenché par événements. Templates multilingues. Aucune donnée médicale sensible dans le corps des messages. Retries et suivi de livraison.

### 10.3 Intégration des logiciels existants (hôpitaux et pharmacies)
Deux options offertes au partenaire :
1. Utilisation directe de la plateforme.
2. **API d'intégration** : le logiciel de caisse/stock/ERP pousse automatiquement stock, prix, disponibilité, ordonnances, commandes — aucune ressaisie. Authentification par client OAuth2 dédié, quotas, webhooks signés, journalisation complète.
Standards : **HL7 FHIR** (dossiers, patients, observations), **DICOM** (imagerie), **CIM-10/CIM-11**, **SNOMED CT**.

### 10.4 Prestataires de paiement
Délégués au microservice Java Spring Boot (CDC_06) via contrat interne. Le cœur Laravel ne connaît jamais les API opérateurs.

---

## 11. Gestion des fichiers médicaux

Les fichiers (ordonnances, comptes rendus, certificats, radios, scanners, IRM, résultats biologiques, documents administratifs) **ne sont jamais stockés dans la base de données**. La base conserve uniquement : métadonnées, chemins d'accès, informations de sécurité, empreintes d'intégrité. Stockage objet MinIO/S3, URLs signées à durée limitée, chiffrement au repos, antivirus à l'upload, contrôle du type MIME et de la taille.

---

## 12. Performance (objectifs contractuels)

- **API < 150 ms** (P95), pages web < 2 s, disponibilité > **99,99 %**, plusieurs centaines de milliers de requêtes simultanées.
- Temps de réponse base : lecture simple < 50 ms, recherche < 200 ms, authentification < 100 ms, consultation dossier patient < 300 ms.
- **Cache Redis** avec TTL imposés : médecins 15 min, hôpitaux 24 h, pharmacies 12 h, données géographiques 30 jours, statistiques selon fraîcheur requise. Invalidation par TTL, événements, versioning, Cache-Aside.
- **SQL** : index sur `patient_id`, `medecin_id`, `hopital_id`, `date_consultation`, `specialite`, `telephone`, `email` ; index composites (ex. `(date_consultation, medecin_id)`) ; interdiction de `SELECT *` ; pagination `LIMIT/OFFSET` ou keyset ; `EXPLAIN ANALYZE` sur toute requête importante ; partitionnement des tables volumineuses par jour/semaine/mois/année.
- **API** : pagination, filtrage, projection, agrégation, HTTP/2 et HTTP/3, rate limiting, cache HTTP (`Cache-Control`, `ETag`, `Last-Modified`).
- **Compression** : Gzip/Brotli sur HTML/CSS/JS/JSON/SVG/XML ; images WebP/AVIF ; vidéos H.265/AV1 ; compression des documents avant stockage.
- **CDN** (Cloudflare ou équivalent) pour les ressources statiques.
- **Queues** : traitements lourds en asynchrone (Laravel Queue + Horizon, workers RabbitMQ). L'utilisateur reçoit une confirmation immédiate.
- **Batch** planifiés par CronJobs Kubernetes : statistiques nationales, indicateurs de santé, synchronisation CNAM, imports massifs, exports, sauvegardes complètes, entraînement IA, nettoyage des journaux.
- **Octane** activé pour les services Laravel à fort trafic.

---

## 13. Temps réel (NodeJS)

Services NestJS + Socket.IO + Redis Pub/Sub pour : chat médecin-patient, notifications instantanées, suivi GPS des ambulances, surveillance des constantes, files d'attente, statuts de paiement. WebRTC pour la téléconsultation. gRPC streaming possible entre services. Authentification JWT sur la poignée de main WebSocket, autorisation par salon (room) selon RBAC/ABAC.

---

## 14. Sécurité backend (résumé — détails CDC_10)

- **Zero Trust** : chaque requête passe par API Gateway → Identity Provider → validation JWT → RBAC → ABAC → Audit → microservice. Aucune API exposée directement.
- **OWASP** : protection injection SQL (requêtes préparées/ORM), XSS, CSRF, SSRF, IDOR (contrôle systématique de la propriété des ressources), upload malveillant, mass assignment, exposition de données.
- Validation stricte de **toutes** les entrées (Form Requests + DTO typés).
- **Chiffrement** : TLS 1.3 en transit, AES-256 au repos (bases, sauvegardes, stockage objet), clés gérées par HSM/KMS, mTLS entre microservices via Service Mesh.
- **Signature électronique** (PKI, certificats X.509) pour ordonnances, comptes rendus, certificats médicaux, prescriptions biologiques, rapports de radiologie — authenticité, intégrité, non-répudiation. Vérification avant chaque signature : identité, certificat, autorisation d'exercer, expiration, révocation.
- **Rate limiting** et protection anti-abus au niveau Gateway et applicatif.
- **Audit immuable** : connexion, déconnexion, consultation de dossier, modification d'ordonnance, suppression, paiement, téléchargement de document, export de données, échec de connexion, accès administrateur. Champs : acteur, action, ressource, horodatage, IP, appareil, résultat.
- **SIEM** + **SOC 24/7** pour la corrélation et la réponse aux incidents.
- **RGPD / Privacy by Design** : minimisation, consentement, droit d'accès, rectification, portabilité, effacement encadré par les obligations légales de conservation médicale ; anonymisation/pseudonymisation pour l'IA et la recherche.
- Souveraineté : hébergement contrôlé (cloud privé gouvernemental, hybrid cloud, datacenters nationaux).

---

## 15. Observabilité et exploitation

- **Logs** : format structuré JSON vers **stdout** (Twelve-Factor facteur 11), centralisés (ELK/Loki). Corrélation via `request_id`/`correlationId`. Aucune donnée médicale sensible en clair dans les logs.
- **Metrics** : Prometheus (latence P50/P95/P99, taux d'erreur, RPS, CPU/mémoire, cache hit ratio, temps SQL, longueur des files), tableaux de bord Grafana.
- **Tracing distribué** : OpenTelemetry de bout en bout (Mobile/Web → Gateway → service → base).
- **Health checks** : `GET /health` (liveness) et `/ready` (readiness) sur chaque service, sondés toutes les 5 s.
- **Alerting** avant dégradation perçue par l'utilisateur.

---

## 16. Haute disponibilité et résilience (détails CDC_10 §HA et chapitre dédié)

- Aucun Single Point of Failure ; clusters applicatifs et Kubernetes (réplicas par service), auto-healing, rolling updates.
- Base : Primary + replicas, failover automatique avec promotion en quelques secondes, réplication multi-sites Abidjan → Yamoussoukro → Bouaké → sauvegarde cloud.
- Autoscaling horizontal (HPA : CPU > 70 % +1 pod, > 80 % +2 pods) et vertical.
- **RTO < 30 minutes** (services critiques), **RPO < 5 minutes**.
- Sauvegardes **3-2-1** (3 copies, 2 supports, 1 hors site), complètes/incrémentales/différentielles, sauvegardes immuables anti-ransomware.
- Ordre de restauration imposé : IAM → base patients → API médicales → paiements → applications utilisateurs.
- Tolérance aux pannes : une panne SMS ne bloque pas les consultations ; une panne IA n'empêche pas les médecins de travailler ; une panne d'un prestataire de paiement permet la bascule vers un autre. Circuit breakers, timeouts, retries avec backoff, bulkheads.
- PCA : maintien des urgences, dossiers patients, prescription électronique, résultats de laboratoire et authentification en mode dégradé.

---

## 17. Qualité logicielle et livraison

- **Clean Code** obligatoire : nommage explicite, fonctions courtes, une responsabilité, DRY, KISS, YAGNI, pas de God Object, injection de dépendances, interfaces = contrats métier, adaptateurs pour tout module externe, métier indépendant des frameworks, extension plutôt que modification.
- **Aucune logique métier dans les contrôleurs.** Aucun SQL brut dans les contrôleurs.
- Outils : **PHPStan** (niveau élevé), **PHP CS Fixer**, **Pylint/Black** (Python), **ESLint/Prettier** (NodeJS), **SonarQube**.
- Tests : **PHPUnit/Pest** (unitaires, intégration, API), **PyTest**, **Jest**, tests de contrat (OpenAPI), tests de charge, tests de résilience (panne de nœud, coupure réseau, perte de replica, restauration, bascule sur site de secours).
- Toute fonctionnalité critique est testée ; toute modification passe par PR + Code Review + CI/CD (lint, typecheck, tests, build Docker, scan de sécurité) ; le code doit être compréhensible par un nouveau développeur.
- **Twelve-Factor** : un dépôt Git par microservice, dépendances déclarées (composer.json, requirements.txt, package.json, pom.xml), config par environnement, services externes attachés, build/release/run séparés, processus stateless, port binding, concurrence par processus, disposability, parité dev/prod (Docker partout), logs stdout, tâches admin en processus séparés (`php artisan migrate`, `python train_model.py`).
- **Documentation First** : toute évolution commence par la mise à jour de la documentation (Handbook, ADR, OpenAPI, Knowledge Book).

---

## 18. Ordre de construction recommandé (module par module)

1. Socle : monorepo/dépôts, Docker Compose local, PostgreSQL, Redis, migrations de base, conventions, CI.
2. **IAM/Authentification** (Keycloak, OAuth2/OIDC, JWT, RBAC/ABAC, MFA, audit) — testé avant tout le reste.
3. Référentiels nationaux (CDC_09) + établissements + personnel.
4. Patients + Dossier médical (EHR) + fichiers.
5. Rendez-vous (workflow deux étapes) + Notifications.
6. Consultation + Diagnostic + Prescription électronique signée.
7. Pharmacie + stock + intégration logiciels externes.
8. Laboratoire, puis Radiologie (DICOM/PACS).
9. Facturation + intégration Paiement (CDC_06) + Assurance/CNAM.
10. Triage + intégration Protocoles (CDC_08) + IA (CDC_05).
11. Hospitalisation, Urgences/Ambulance, Téléconsultation, Temps réel.
12. Statistiques, Data Platform (CDC_13), Portails Ministère/Assurance.
13. CQRS/Event Sourcing sur les domaines ciblés, durcissement sécurité, HA/DR, tests de charge et de résilience.

Chaque module est validé par des tests (dont les tests mobiles via Expo Go SDK 54 + tunnel Ngrok) avant de passer au suivant ; en cas de problème, analyse ciblée et correction de la seule partie fautive.

---

*Fin du CDC_03 — Architecture Backend.*
