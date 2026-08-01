# CAHIER DES CHARGES N°12 — ARCHITECTURE MICROSERVICES DÉTAILLÉE
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Ce document définit le **découpage en services** et leurs contrats. Il complète CDC_03 (architecture backend interne de chaque service), CDC_04 (base par service), CDC_11 (fonctionnalités portées), CDC_10 (sécurité inter-services), CDC_13 (flux de données).

---

## 1. Justification et principes

### 1.1 Pourquoi des microservices
MASANTÉ doit supporter : plusieurs millions de citoyens, des milliers d'établissements sanitaires, des dizaines de milliers de professionnels médicaux, des volumes importants de données médicales et des systèmes externes (assurances, ministère, laboratoires, pharmacies). Une architecture monolithique ne serait pas adaptée.

Objectifs : indépendance par domaine métier, haute disponibilité, maintenabilité, scalabilité horizontale, résistance aux pannes, évolutivité.

### 1.2 Principes non négociables
1. **Un service = une responsabilité métier**. Chaque microservice possède son propre domaine fonctionnel, ses règles métier, **sa propre base de données** et son cycle de développement indépendant.
2. **Rule-001** : aucun service n'accède directement à la base d'un autre. Communication par API ou événements uniquement.
3. **Database per Service** (CDC_04).
4. Structure interne identique pour tous : `Domain / Application / Infrastructure / Presentation` (CDC_03 §1.3).
5. **Isolation des pannes** : si Orange Money modifie son API, seul le microservice Paiement est mis à jour — la pharmacie, les consultations et les urgences ne changent pas. Si un nouveau modèle IA est entraîné, seul le service IA est redéployé.
6. **API First** + documentation **OpenAPI** obligatoire par service (Rule-003).
7. Chaque service est **conteneurisé**, **stateless**, observable et déployable indépendamment (Twelve-Factor).

---

## 2. Vue d'ensemble

```
APPLICATIONS FRONTEND
Patient │ Médecin │ Infirmier │ Pharmacie │ Laboratoire │ Radiologie │ Ministère │ Assurance
                          │
              API Gateway Nationale (Kong)
                          │
                    Service Mesh (Istio / Envoy — mTLS)
                          │
SERVICES MÉTIERS
IAM │ Patient │ EHR │ Consultation │ Prescription │ Pharmacy │ Laboratory │ Radiology
Appointment │ Emergency/Ambulance │ Billing │ Payment │ Insurance │ CNAM │ Hospital Admin
Referential │ Protocol │ Triage │ Notification │ Teleconsultation │ Analytics │ AI │ GenAI │ Audit
                          │
        RabbitMQ (événements métier) │ Kafka (flux massifs)
                          │
INFRASTRUCTURE DATA
PostgreSQL │ MongoDB │ Redis │ Elasticsearch │ Neo4j │ InfluxDB │ MinIO │ Qdrant │ Data Lake
                          │
              Kubernetes / Docker / Cloud
```

---

## 3. Bounded Contexts (Domain-Driven Design)

Les 14 domaines autonomes identifiés :
1. Identité et accès — 2. Gestion patient — 3. Dossier médical — 4. Consultation — 5. Prescription — 6. Pharmacie — 7. Laboratoire — 8. Radiologie — 9. Ambulance et urgence — 10. Facturation — 11. Assurance — 12. Administration hospitalière — 13. Statistiques nationales — 14. Intelligence artificielle.

Domaines transverses complémentaires : Référentiels nationaux, Protocoles médicaux, Triage, Notification, Téléconsultation, Audit, Paiement.

---

## 4. Catalogue détaillé des microservices

### 4.1 `iam-service` — Identity & Access Management
**Technologie** : Keycloak + service d'extension (Laravel/Java selon ADR).
**Responsabilités** : inscription, authentification, autorisation, rôles, permissions, sessions, MFA, révocation, journalisation.
**Utilisateurs gérés** : patients, médecins, infirmiers, pharmaciens, administrateurs, agents ministère, services techniques.
**Exemple** :
```
Utilisateur : Dr Kouassi
Rôle : MEDECIN_CARDIOLOGUE
Permissions : CONSULTER_DOSSIER, CREER_PRESCRIPTION
```
**Technologies** : OAuth 2.0, OpenID Connect, JWT, Keycloak.
**Base** : `identity_db` (PostgreSQL) + Redis (sessions, blacklist).
**Événements** : `UserCreated`, `UserRoleChanged`, `UserRevoked`, `MfaEnrolled`, `LoginFailed`.

### 4.2 `patient-service` — Patient Management
**Responsabilités** : création patient, informations personnelles, contacts, historique administratif, consentements, personnes autorisées.
**Base** : `patient_db`.
```
patient : id, numero_sante, nom, prenom, date_naissance, groupe_sanguin, allergies
```
**API** : `POST /patients`, `GET /patients/{id}`, `PUT /patients/{id}`.
**Dépendance** : interroge le **MPI** (CDC_09) avant toute création.
**Événements** : `PatientCreated`, `PatientUpdated`, `PatientMerged`.

### 4.3 `ehr-service` — Electronic Health Record
**Cœur médical de MASANTÉ.** Stocke : consultations, diagnostics, antécédents, vaccinations, hospitalisations, allergies, traitements, documents.
**Standards** : **HL7 FHIR**, **SNOMED CT**, **ICD-10/ICD-11**.
**Base** : `medical_records_db` (PostgreSQL + MongoDB pour les documents) ; read model CQRS pour l'ouverture instantanée du dossier.
**Événements consommés** : `ConsultationCompleted`, `LaboratoryResultAvailable`, `ImagingReportPublished`, `PrescriptionValidated`.

### 4.4 `consultation-service`
**Responsabilités** : création de consultation, notes médicales, symptômes, observations.
```
Patient prend rendez-vous → Médecin consulte → Consultation créée → Diagnostic enregistré
```
**Événements** : `ConsultationStarted`, `ConsultationCompleted`, `ConsultationClosed`, `DiagnosisRecorded`.

### 4.5 `prescription-service`
**Responsabilités** : création d'ordonnance, validation, signature électronique, transmission à la pharmacie.
```
Prescription : id 202607001 | Patient 001254 | Médecin DR45
Médicament : Paracétamol | Dose : 500 mg | Durée : 5 jours
```
**Communication** : `Prescription → Pharmacie` via l'événement `PrescriptionCreated`.
**Dépendances** : référentiel médicaments (CDC_09), vérification d'interactions (CDC_05).

### 4.6 `pharmacy-service`
**Responsabilités** : réception d'ordonnance, délivrance de médicaments, stock, traçabilité.
**Services internes** : `Inventory Service`, `Drug Verification Service`.
**Intégration** : API pour les logiciels externes (caisse, stock, ERP) — synchronisation stock, prix, disponibilité, ordonnances, commandes.
**Événements** : `PrescriptionDelivered`, `StockUpdated`, `StockShortageDetected`, `PharmacyStockSynchronized`.

### 4.7 `laboratory-service`
**Responsabilités** : demande d'analyse, prélèvement, résultat, validation biologique, connexion aux automates.
**Événement clé** : `LabResultAvailable` / `LaboratoryResultAvailable`.
**Transmission** : `Laboratoire → EHR Patient → Médecin` (+ notification patient, + analyse IA de risque).

### 4.8 `radiology-service`
**Responsabilités** : examens d'imagerie, fichiers **DICOM**, comptes rendus.
```
Radiology Service → PACS Storage → DICOM Server
```
**Stockage** : images lourdes → **Object Storage (MinIO)** ; métadonnées → **base SQL**.
**Événements** : `ImagingStudyCreated`, `DICOMStudyCompleted`, `ImagingReportPublished`.

### 4.9 `appointment-service`
**Responsabilités** : créneaux, disponibilités, congés, quotas, **workflow de validation à deux étapes**, file d'attente.
**Événements** : `AppointmentRequested`, `AppointmentPreValidated`, `AppointmentConfirmed`, `AppointmentCancelled`, `AppointmentCompleted`.

### 4.10 `emergency-service` — Emergency & Ambulance
**Responsabilités** : appel d'urgence, dispatch d'ambulance, GPS, transfert du patient.
```
Emergency Request → Dispatch Service → GPS Engine → Ambulance
```
**IA** : optimisation (ambulance disponible, trafic, hôpital adapté).
**Stockage** : positions en série temporelle (InfluxDB) + Redis pour le temps réel.

### 4.11 `billing-service` et `payment-service`
**Billing** : facture, tarification, TVA, remises, versionnage des factures.
**Payment** (Java Spring Boot — CDC_06) : encaissement, remboursement, Mobile Money, cartes, Wallet, reversements.
```
Consultation → Billing Service → Payment Gateway → Transaction
```
Compatible : Orange Money, MTN Mobile Money, Wave, Moov Money, cartes bancaires.

### 4.12 `insurance-service` et `cnam-service`
**Responsabilités** : vérification de couverture/affiliation, validation de prise en charge, calcul du reste à charge, remboursement, rejets, régularisations.
```
Patient → Facture médicale → Assurance → Validation → Paiement
```

### 4.13 `hospital-admin-service` — Administration hospitalière
**Responsabilités** : établissements, personnel, lits, équipements.
**Modules** : `Bed Management`, `Staff Management`, `Equipment Management`.

### 4.14 `referential-service` (CDC_09)
Référentiels nationaux : MPI, NIS, établissements, professionnels, médicaments, laboratoires, analyses, maladies, actes, découpage sanitaire. Exposé en lecture à tous les services, avec cache et invalidation par événement.

### 4.15 `protocol-service` (CDC_08)
Registre des protocoles, moteur de règles, sélection et priorisation, questionnaire dynamique, journal des décisions.

### 4.16 `triage-service`
Orchestration du triage : questionnaire → protocoles → IA → niveau de priorité → fiche PDF/QR → orientation.

### 4.17 `notification-service` — Service transversal
**Envois** : SMS, Email, Push mobile, WhatsApp.
**Exemples** : « Votre rendez-vous est confirmé. », « Votre résultat laboratoire est disponible. »
**Technologies** : Kafka, RabbitMQ, **Firebase Cloud Messaging** (+ APNs).
**Règle** : aucune donnée médicale sensible dans le corps du message.

### 4.18 `teleconsultation-service`
Salles virtuelles, signalisation WebRTC, chat sécurisé, partage de documents, enregistrement de la trace de consultation (jamais du flux vidéo sans consentement explicite).

### 4.19 `analytics-service` — Data Analytics
Collecte des données nationales (hôpitaux, pharmacies, laboratoires, patients).
```
Microservices → Event Bus → Data Lake → BI Platform
```
Détails en CDC_13.

### 4.20 `ai-service` et `genai-service`
```
Applications → AI Gateway → AI Services → Machine Learning Models
```
**Services IA** : Diagnostic Prediction, Medical Risk Prediction (diabète, AVC, complications), Hospital Optimization (lits, personnel, flux patients), triage, vision, OCR, speech, recommandations, détection de fraude, épidémiologie (CDC_05) ; LLM privé et RAG (CDC_07).

### 4.21 `audit-service`
Collecte, stockage inaltérable et consultation des journaux d'audit (CDC_10 §6). Alimente le SIEM.

---

## 5. Communication entre microservices

### 5.1 Communication synchrone (demandes immédiates)
Technologies : **REST API**, **GraphQL** (agrégation côté portails), **gRPC** (interservices performants).
```
Patient Service → EHR Service → Retour dossier médical
```
Règles : timeouts stricts, circuit breakers, retries uniquement sur opérations idempotentes, propagation du `correlationId`.

### 5.2 Communication asynchrone (événements)
Technologies : **Apache Kafka**, **RabbitMQ**.
```
Prescription créée → Kafka/RabbitMQ Event → Pharmacie notifiée
```
Règles complètes en CDC_03 §8 (8 règles d'implémentation, Saga, Outbox, DLQ, idempotence, versionnage).

### 5.3 Exemple de propagation événementielle
```
Consultation Service → ConsultationClosed
        │
   Event Bus (RabbitMQ/Kafka)
   ┌────┼────┬────────┬────────┐
 EHR  Pharmacy Billing  AI   Analytics  Notification
```
Chaque service décide lui-même s'il réagit. **Aucun service n'est directement dépendant des autres.**

---

## 6. API Gateway Nationale

**Rôle** : porte d'entrée unique.
**Responsabilités** : authentification, routage, limitation de trafic (rate limiting), sécurité, monitoring, journalisation, agrégation éventuelle.
**Technologies** : **Kong Gateway** (retenu), NGINX ou Spring Cloud Gateway en alternative.
**Règles** : aucun service exposé directement sur Internet ; versionnement d'API géré au niveau du Gateway ; quotas différenciés par type de client (application officielle, partenaire, ministère).

---

## 7. Service Mesh

**Solutions** : **Istio** + **Envoy Proxy**.
**Fonctions** : chiffrement interne (**mTLS obligatoire**), contrôle du trafic (routage, canary, retries, timeouts), observabilité (métriques et traces automatiques), politiques d'autorisation service-à-service.

---

## 8. Service Discovery et Load Balancing

**Problème** : les services changent constamment d'adresse.
**Solution** : **Service Discovery** — Kubernetes DNS (principal), Consul ou Eureka en alternative.
```
Service Registry
  Patient Service      192.168.1.20
  IA Service           192.168.1.30
  Notification Service 192.168.1.40
```
**Load Balancing** : algorithmes Round Robin et Least Connection, **health checks** automatiques avec retrait des instances défaillantes, SSL termination au niveau du reverse proxy (Nginx/Traefik).

---

## 9. Observabilité

- **Monitoring** : Prometheus + Grafana.
- **Logs** : ELK Stack (ou Loki), format JSON vers stdout.
- **Tracing** : OpenTelemetry, propagation du contexte de bout en bout.
- **Health checks** : `/health` (liveness) et `/ready` (readiness) sur chaque service.
- **SLO par service** : disponibilité, latence P95/P99, taux d'erreur — avec alertes.

---

## 10. Déploiement

Chaque microservice est **conteneurisé** :
```
Docker Container → Kubernetes Cluster → Cloud Infrastructure
```
- Un **dépôt Git par microservice** (Twelve-Factor facteur 1) : `patient-service`, `medical-record-service`, `payment-service`, `ai-service`…
- Chaque dépôt contient : `src/`, `tests/`, `Dockerfile`, fichier de dépendances (`composer.json`, `requirements.txt`, `package.json`, `pom.xml`), `README.md`, documentation OpenAPI, manifestes Kubernetes/Helm.
- Pipeline : `Git Push → CI/CD (lint, tests, scan sécurité) → Docker Build → Deploy Kubernetes`.
- Séparation **Build / Release / Run** ; configuration par variables d'environnement et secrets (Kubernetes Secrets / Vault).
- Autoscaling (HPA), auto-healing, rolling updates, parité dev/prod (Docker partout).
- Tâches administratives en processus séparés (`php artisan migrate`, `python train_model.py`).

---

## 11. Résilience par service

| Service | Criticité | Comportement en cas de panne |
|---------|-----------|------------------------------|
| IAM | Critique | Aucun accès — priorité maximale de restauration |
| Patient / EHR | Critique | Mode lecture depuis read model / cache ; offline mobile |
| Payment | Critique | Bascule d'opérateur ; paiement différé possible |
| Protocol / Triage | Élevée | Triage protocolaire seul si l'IA est indisponible |
| AI / GenAI | Moyenne | Dégradation gracieuse — les protocoles restent utilisables |
| Notification | Faible | File d'attente, rattrapage ultérieur — ne bloque rien |
| Analytics | Faible | Traitement différé |

Mécanismes : circuit breakers, bulkheads, timeouts, DLQ, retries avec backoff, caches de secours, mode dégradé annoncé à l'utilisateur.

---

## 12. Bénéfices attendus (critères de réussite)

Indépendance des domaines métiers, montée en charge massive, meilleure sécurité, évolution continue, intégration facile avec les systèmes externes, préparation à l'intelligence artificielle médicale.

---

## 13. Ordre de construction recommandé

1. Socle : API Gateway, Service Discovery, observabilité, CI/CD, conventions de contrat (OpenAPI, événements).
2. `iam-service` (prérequis absolu).
3. `referential-service` (MPI, NIS, référentiels — prérequis de tout le métier).
4. `patient-service`, puis `ehr-service`.
5. `appointment-service` + `notification-service`.
6. `consultation-service` + `prescription-service`.
7. `pharmacy-service` (+ intégrations externes).
8. `laboratory-service`, puis `radiology-service`.
9. `billing-service` + `payment-service` + `insurance-service` + `cnam-service`.
10. `protocol-service` + `triage-service` + `ai-service`.
11. `emergency-service`, `teleconsultation-service`, `hospital-admin-service`.
12. `analytics-service`, `genai-service`, `audit-service`.
13. Service Mesh (mTLS), CQRS sur les domaines ciblés, durcissement, tests de charge et de résilience.

Chaque service est testé et validé isolément (tests de contrat inclus) avant intégration ; en cas de problème, correction ciblée de la seule partie fautive.

---

*Fin du CDC_12 — Architecture Microservices détaillée.*
