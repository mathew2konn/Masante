# CDC_00 — INDEX GÉNÉRAL ET RÈGLES TRANSVERSES
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document maître du corpus**

---

## 1. Le corpus

Les 13 cahiers des charges forment **une seule et même application**. Aucun ne se lit isolément.

| N° | Document | Destinataire |
|----|----------|--------------|
| CDC_01 | Architecture Mobile | **Google AI Studio** |
| CDC_02 | Architecture Web | **Google AI Studio** |
| CDC_03 | Architecture Backend | Claude Code |
| CDC_04 | Architecture Base de Données | Claude Code |
| CDC_05 | Architecture Intelligence Artificielle | Claude Code |
| CDC_06 | Architecture Paiement | Claude Code |
| CDC_07 | Architecture IA Générative | Claude Code |
| CDC_08 | Architecture des Protocoles Médicaux | Claude Code |
| CDC_09 | Architecture des Données Nationales | Claude Code |
| CDC_10 | Architecture Sécurité | Claude Code |
| CDC_11 | Architecture des Applications | Claude Code |
| CDC_12 | Architecture Microservices détaillée | Claude Code |
| CDC_13 | Architecture des Données | Claude Code |

### 1.1 Ordre de lecture recommandé
`CDC_00 → CDC_11 (le quoi) → CDC_09 (référentiels) → CDC_10 (sécurité) → CDC_12 (découpage) → CDC_04 (données) → CDC_03 (backend) → CDC_08 → CDC_05 → CDC_07 → CDC_06 → CDC_13 → CDC_01 / CDC_02 (interfaces)`

### 1.2 Règle de résolution des conflits
1. **CDC_10 (Sécurité)** prévaut sur tout.
2. **CDC_08 (Protocoles médicaux)** prévaut sur toute proposition d'IA.
3. **CDC_09 (Données nationales)** est la source unique de vérité des référentiels.
4. Toute ambiguïté restante se résout par un **ADR**, jamais par une invention locale.

---

## 2. Identité du projet

**Nom officiel** : MaSanté (MASANTÉ). **Vision** : construire la plateforme numérique de santé de référence en Afrique — évolutive, sécurisée, interopérable, intelligente, conforme aux standards internationaux, pensée pour durer plusieurs décennies. Lancement en **Côte d'Ivoire**, extension à d'autres pays africains **sans réécriture**.

### 2.1 Valeurs
1. Le patient avant tout — 2. Simplicité — 3. Sécurité — 4. Fiabilité — 5. Interopérabilité — 6. Traçabilité — 7. Évolutivité.

### 2.2 Principes fondateurs
API First · Domain-Driven Design · Clean Architecture · Architecture Hexagonale · Microservices · Event-Driven Architecture · CQRS · SOLID · Twelve-Factor App · Security by Design · Privacy by Design · Observability · Documentation First · Capability-Driven Architecture.

---

## 3. Les 5 règles d'architecture fondatrices (applicables partout)

- **Rule-001** — Aucun module ne dépend directement d'un autre module. Toute communication passe par API internes, événements ou contrats. Corollaire : aucun accès direct à la base de données depuis le frontend.
- **Rule-002** — Le code métier ne dépend jamais de Laravel, React ou PostgreSQL. Le framework est un détail d'implémentation.
- **Rule-003** — Chaque décision importante produit un **ADR**. Chaque endpoint possède une documentation **OpenAPI**. Chaque nouvelle table possède une **migration**.
- **Rule-004** — Chaque fonctionnalité répond à 5 questions avant intégration : pourquoi existe-t-elle ? qui l'utilise ? quelles données manipule-t-elle ? quels modules appelle-t-elle ? comment évoluera-t-elle dans 5 ans ?
- **Rule-005** — Aucune IA ne prend de décision médicale sans expliquer son raisonnement : données utilisées, protocoles appliqués, score de confiance, limites.

---

## 4. Interdits absolus (toutes équipes, tous documents)

1. Coder une règle médicale en dur (`if temperature > 39`) — elles vivent en base (CDC_08).
2. Présenter le triage comme un diagnostic.
3. Laisser l'IA décider seule d'une prise en charge.
4. Entraîner un modèle sur des données de production non anonymisées et non validées.
5. Stocker un secret, une clé ou un mot de passe dans le code.
6. Stocker un numéro de carte bancaire (tokenisation obligatoire).
7. Manipuler les fonds : le paiement est **direct**, traité par le prestataire de l'établissement.
8. Vendre un médicament sous ordonnance sur la seule base du triage.
9. Stocker un fichier médical dans la base de données (métadonnées seulement, fichier en stockage objet).
10. Mettre de la logique métier dans un contrôleur ou dans un composant d'interface.
11. Accéder à un dossier patient sans lien de prise en charge (hors bris de glace justifié et audité).
12. Publier un protocole non validé (clinique, réglementaire, scientifique, technique).

---

## 5. Chiffres de référence (contractuels)

| Indicateur | Valeur |
|------------|--------|
| Temps de réponse API (P95) | < 150 ms |
| Chargement page web | < 2 s |
| Lecture simple en base | < 50 ms |
| Recherche | < 200 ms |
| Authentification | < 100 ms |
| Ouverture d'un dossier patient | < 300 ms |
| Évaluation d'un protocole | < 100 ms |
| Disponibilité | 99,99 % min. (99,999 % services critiques) |
| RTO | < 30 minutes |
| RPO | < 5 minutes |
| Sauvegardes | Règle 3-2-1, copies immuables |
| Chiffrement | AES-256 au repos · TLS 1.3 en transit |

**TTL de cache imposés** : médecins 15 min · hôpitaux 24 h · pharmacies 12 h · données géographiques 30 jours.

---

## 6. Stack technologique consolidée

| Couche | Technologies |
|--------|--------------|
| Mobile | React Native · Expo (SDK 54) · TypeScript · NativeWind · TanStack Query · Zustand · SQLite/MMKV/SecureStore |
| Web | React · Next.js · TypeScript · Tailwind · Shadcn UI · TanStack Query · Zustand · PWA |
| Backend métier | Laravel / PHP 8.4 (Sanctum, Queue, Horizon, Octane) |
| IA | Python · FastAPI · XGBoost · Scikit-learn · Pandas · NumPy · Pydantic · SHAP · MLflow · PyTorch/TensorFlow |
| IA générative | LLM privé (Llama 3 / Mistral…) · RAG · Qdrant · embeddings |
| Paiement | Java · Spring Boot |
| Temps réel | NodeJS · NestJS · Socket.IO · WebRTC |
| Données | PostgreSQL · MongoDB · Redis · Elasticsearch · Neo4j · InfluxDB/TimescaleDB · MinIO · Qdrant |
| Messagerie | RabbitMQ · Apache Kafka |
| Big Data | Apache Spark · Apache Airflow · Data Lake · Data Warehouse |
| Infrastructure | Docker · Kubernetes · Kong · Nginx · Traefik · Istio/Envoy |
| Sécurité | Keycloak · OAuth2 · OpenID Connect · JWT · MFA · PKI · HSM · RBAC · ABAC · SIEM · SOC |
| Observabilité | Prometheus · Grafana · ELK/Loki · OpenTelemetry |
| Cartographie | OpenStreetMap |

---

## 7. Méthode de travail imposée

1. **Construction module par module**, en suivant l'ordre indiqué à la fin de chaque cahier des charges.
2. À la fin de chaque module : **test obligatoire** avant de continuer. Pour le mobile : application **Expo Go (SDK 54)** sur le téléphone du propriétaire, via **tunnel Ngrok**.
3. En cas de problème : **analyse complète pour détecter uniquement la partie qui cause le problème**, correction ciblée, **sans modifier une autre partie**.
4. On ne passe au module suivant que **lorsque tout fonctionne correctement**.
5. Toute modification passe par Pull Request, revue de code et CI bloquante (lint, typecheck, tests, scan de sécurité).
6. **Documentation First** : toute évolution commence par la mise à jour de la documentation (Handbook, ADR, OpenAPI, Knowledge Book).

---

## 8. Documents de gouvernance à maintenir en parallèle

- **MASANTÉ Architecture Handbook** : règles de nommage, structure des projets, conception des API, principes DDD, style de code, stratégie de tests, versionnement, migrations, politique de sécurité, qualité logicielle.
- **Architecture Decision Records (ADR)** : une décision = un document (contexte, décision, conséquences, alternatives étudiées).
- **MASANTÉ Knowledge Book** : la mémoire du projet — raisons de chaque choix, problèmes rencontrés et résolutions, décisions abandonnées et pourquoi, bonnes pratiques validées, pièges à éviter, retours d'expérience.

---

*Fin du CDC_00 — Index et règles transverses.*
