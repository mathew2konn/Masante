# CAHIER DES CHARGES N°10 — ARCHITECTURE SÉCURITÉ
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). **Ce document est transversal et prioritaire** : ses règles s'appliquent à tous les autres cahiers des charges. En cas de contradiction apparente entre un document et le CDC_10, **le CDC_10 prévaut**.

---

## 1. Enjeux et principes fondateurs

### 1.1 Enjeux
La plateforme manipule des données extrêmement sensibles : dossiers médicaux, examens biologiques, ordonnances, informations d'assurance, données financières, données biométriques, identité des patients. Une faille peut provoquer : fuite de millions de dossiers, usurpation d'identité, **modification d'un traitement médical**, fraude aux assurances, attaque des hôpitaux, interruption des soins.

### 1.2 Principes imposés
**Zero Trust**, **Least Privilege**, **Defense in Depth**, **Security by Design**, **Privacy by Design**, **Observability**. La sécurité est une **exigence de conception**, jamais une fonctionnalité ajoutée après coup.

---

## 2. Architecture Zero Trust

### 2.1 Principe
> Ne jamais faire confiance automatiquement à un utilisateur, un appareil ou un service, **même s'il est déjà à l'intérieur du réseau**.

### 2.2 Chaîne de contrôle obligatoire pour chaque requête
```
Utilisateur → MFA → IAM → OAuth2 + OpenID Connect → JWT signé
→ API Gateway → RBAC + ABAC → Microservice
→ TLS/mTLS (communications) → Bases de données chiffrées
→ Audit → SIEM → SOC
```
**Aucune API n'est accessible directement.** Chaque microservice **revalide** le token : signature, expiration, permissions, contexte. Aucun service ne fait confiance à un token sans validation.

### 2.3 Scénario de référence
Un malware vole le cookie d'un médecin. Sans Zero Trust, l'attaquant devient le médecin. Avec Zero Trust, le système vérifie encore : adresse IP, appareil, MFA, contexte, comportement → l'attaquant est bloqué.

---

## 3. Identité et accès (IAM)

### 3.1 IAM — source unique de vérité
**Keycloak** centralise : création des comptes, authentification, autorisation, rôles, groupes, permissions, révocation, journalisation.

### 3.2 OAuth2
Protocole d'autorisation : l'application accède à une ressource **sans jamais connaître le mot de passe** de l'utilisateur. Tous les clients l'utilisent : Application Patient, Médecin, Infirmier, Laboratoire, Pharmacie, Assurance, Ministère, services IA, partenaires externes.

### 3.3 OpenID Connect
Ajoute l'identité et l'authentification (« qui est connecté ? »). L'**ID Token signé** fournit : identifiant utilisateur, nom, prénom, email, spécialité, hôpital, rôle, photo, identifiant national.

### 3.4 JWT
Passeport numérique contenant : `id`, `nom`, `role`, `hopital`, `permissions`, `exp`, plus `pays_code` et `session_id`. Durée de vie courte (access token), refresh token à rotation. Signature par clés protégées en **HSM**. Blacklist des tokens révoqués en Redis.

### 3.5 MFA (Multi-Factor Authentication)
Combinaisons : mot de passe + code SMS + empreinte digitale ; ou mot de passe + application Authenticator (TOTP) + reconnaissance faciale.

**Obligatoire pour** : médecins, administrateurs, ministère, assurances, super administrateurs.
**Fortement recommandé pour** : patients.

### 3.6 RBAC (Role-Based Access Control)
Rôles : Patient, Médecin, Infirmier, Secrétaire/Accueil, Pharmacien, Laborantin, Radiologue, Administrateur d'établissement, Super Administrateur, Ministère, Assurance, Service technique/IA.

Exemples de droits :
| Rôle | Lecture dossier | Créer ordonnance | Modifier diagnostic |
|------|-----------------|------------------|---------------------|
| Patient | Oui (le sien) | Non | Non |
| Médecin | Oui (ses patients) | Oui | Oui |
| Pharmacien | Prescription uniquement | Non | Non |
| Administrateur | Non (données médicales) | Non | Non |

Le principe **ISP** s'applique : chaque rôle ne dispose que des capacités nécessaires (interfaces séparées : `AuthenticationInterface`, `PatientManagementInterface`, `PaymentInterface`, `ReportingInterface` — jamais une interface `UserActions` monolithique).

### 3.7 ABAC (Attribute-Based Access Control)
Le RBAC ne suffit pas. L'accès dépend en plus : du rôle, de l'hôpital, du service, de l'heure, du pays, du type de patient, du **contexte d'urgence**, et du **lien de prise en charge**.

```
SI Médecin ET Même établissement ET Patient suivi
ALORS Autoriser
SINON Refuser
```
**Exemple imposé** : deux cardiologues, l'un à Abidjan, l'autre à Korhogo. Même rôle. Seul celui qui suit le patient peut ouvrir son dossier.

### 3.8 Bris de glace (break-glass)
En situation d'urgence vitale, un professionnel peut accéder à un dossier hors de son périmètre habituel, **sous conditions strictes** : justification obligatoire, alerte immédiate, notification au patient et au responsable de la structure, revue systématique a posteriori, journalisation renforcée.

### 3.9 Gestion du cycle de vie des accès
Création, modification, suspension, révocation immédiate (départ, perte d'autorisation d'exercer, incident), revues d'accès périodiques, expiration automatique des comptes inactifs, séparation des comptes nominatifs et des comptes de service.

---

## 4. Cryptographie

### 4.1 PKI (Public Key Infrastructure)
Autorité de certification (CA), certificats **X.509**, paires de clés publique/privée. Usages : authentification des serveurs, **signature électronique**, chiffrement des échanges, **authentification mutuelle entre microservices (mTLS)**.

### 4.2 TLS
**HTTPS/TLS 1.3 exclusivement** pour tous les échanges externes. mTLS pour les communications internes via le Service Mesh (Istio/Envoy). Versions antérieures et suites cryptographiques faibles désactivées. HSTS activé.

### 4.3 HSM (Hardware Security Module)
Équipement matériel dédié à la protection des clés : génération, stockage sécurisé, signature, opérations cryptographiques **sans jamais exposer les clés**. Protège : clés de signature des JWT, certificats de la PKI, signatures électroniques, clés de chiffrement des bases de données.

### 4.4 Chiffrement
- **En transit** : TLS 1.3.
- **Au repos** : **AES-256** pour les bases de données, sauvegardes et stockage objet.
- **Au niveau colonne** pour les données ultra-sensibles (biométrie, identifiants d'assurance, données financières).
- Gestion centralisée des clés via HSM/KMS, **rotation planifiée**, séparation des clés par environnement et par domaine.

### 4.5 Signature électronique
Garantit **authenticité** du signataire, **intégrité** du document et **non-répudiation**.
Appliquée à : ordonnances électroniques, comptes rendus médicaux, certificats médicaux, prescriptions biologiques, rapports de radiologie, documents administratifs, factures.
Repose sur un certificat numérique délivré par la PKI et une clé privée protégée. **Vérification avant chaque signature** : identité, certificat, autorisation d'exercer, expiration, révocation (CDC_09 §5.4).

---

## 5. Sécurité applicative (OWASP)

Protections obligatoires :
- **Injection SQL** : requêtes préparées / ORM exclusivement, jamais de concaténation.
- **XSS** : encodage systématique, CSP stricte, aucun rendu de HTML non filtré.
- **CSRF** : jetons anti-CSRF, cookies `SameSite`.
- **IDOR / Broken Access Control** : vérification systématique de la propriété de la ressource (jamais « l'ID est dans l'URL donc c'est bon »).
- **SSRF** : liste blanche des destinations sortantes.
- **Mass assignment** : DTO explicites, jamais de binding automatique de la requête vers l'entité.
- **Upload malveillant** : contrôle du type MIME réel, de la taille, antivirus, stockage hors racine web, URLs signées à durée limitée.
- **Désérialisation non sécurisée**, **composants vulnérables** (scan de dépendances en CI), **secrets exposés** (scan de secrets, aucun secret dans le dépôt).
- **Rate limiting** et anti-bruteforce (Gateway + applicatif), verrouillage progressif, CAPTCHA sur les formulaires publics.
- **Validation stricte de toutes les entrées** (Form Requests, DTO, Pydantic, Zod).
- Messages d'erreur non bavards : aucune donnée médicale ni détail technique exposé.

### 5.1 Sécurité côté client
- **Mobile** (CDC_01 §13) : tokens en Secure Store, biométrie, chiffrement local, verrouillage après inactivité, masquage du contenu sensible dans le multitâche, certificate pinning.
- **Web** (CDC_02 §12) : cookies HTTPOnly/Secure/SameSite (jamais localStorage pour les tokens), CSP, en-têtes de sécurité, permissions appliquées au rendu.

---

## 6. Audit et traçabilité

### 6.1 Actions systématiquement journalisées
Connexion, déconnexion, échec de connexion, **consultation d'un dossier patient**, création/modification/suppression d'une ordonnance, suppression d'un document, export de données, téléchargement de document, paiement, modification de rôle ou de permission, accès administrateur, modification d'un référentiel ou d'un protocole, exécution d'un bris de glace, décision assistée par IA.

### 6.2 Contenu d'une entrée d'audit
Acteur (identifiant, rôle, établissement), action, ressource concernée, horodatage (UTC), adresse IP, appareil/agent, contexte (motif, `request_id`, `correlationId`), résultat (succès/échec), version du protocole ou du modèle IA le cas échéant.

### 6.3 Propriétés obligatoires
Le journal d'audit est **horodaté**, **inaltérable** (append-only, hachage chaîné ; blockchain privée en option) et **traçable**. Il est stocké séparément des données métier, avec des droits d'accès restreints et une conservation longue durée conforme à la réglementation.

### 6.4 Table dédiée aux accès aux dossiers
`acces_dossiers` (CDC_04) : qui a consulté quel dossier, quand, depuis où, pour quel motif. Le patient peut consulter l'historique des accès à son propre dossier.

---

## 7. Détection et réponse

### 7.1 SIEM
Collecte des événements de sécurité de tout le système : API Gateway, IAM, serveurs, bases de données, pare-feu, applications, équipements réseau. Corrélation pour détecter : plusieurs échecs de connexion, accès inhabituels (heure, localisation, volume), escalade de privilèges, tentatives d'exfiltration de données, comportements anormaux.

### 7.2 SOC
Équipe et organisation surveillant la sécurité **24 h/24 et 7 j/7** : surveillance des alertes SIEM, qualification des incidents, réponse aux attaques, coordination de la remédiation, production de rapports de sécurité.

### 7.3 Réponse à incident
Procédure documentée : détection → qualification (gravité) → confinement → éradication → restauration → post-mortem sans recherche de faute (blameless). Communication de crise définie. Rôles nommés (RSSI, CTO, responsable métier, communication).

---

## 8. Sécurité de l'infrastructure

- **Segmentation réseau** : zones séparées (public, applicatif, données, administration), pare-feu, aucune base exposée sur Internet.
- **Service Mesh** (Istio/Envoy) : mTLS obligatoire entre microservices, politiques d'autorisation service-à-service, contrôle du trafic, observabilité.
- **Kubernetes** : politiques réseau, `PodSecurity`, conteneurs non-root, images signées et scannées, secrets via Kubernetes Secrets/Vault, quotas de ressources.
- **Twelve-Factor facteur 3** : aucune configuration ni secret dans le code ; variables d'environnement, Vault ou gestionnaire de secrets cloud.
- **Durcissement** : mises à jour de sécurité régulières, suppression des services inutiles, comptes par défaut désactivés.
- **Souveraineté** : hébergement sur cloud privé gouvernemental, hybrid cloud ou datacenters nationaux ; localisation contrôlée des données ; accès réglementé.

---

## 9. Protection des données personnelles (Privacy by Design)

- **Minimisation** : ne collecter et ne transmettre que le strictement nécessaire (y compris dans les événements — règle EDA n°8, et dans les prompts IA — CDC_07).
- **Consentement** explicite, tracé, révocable ; gestion des personnes autorisées à accéder au dossier d'un patient.
- **Droits** : accès, rectification, portabilité, effacement — encadrés par les obligations légales de conservation médicale.
- **Anonymisation / pseudonymisation** obligatoire avant tout usage en IA, recherche ou statistiques (CDC_05, CDC_13).
- **Durées de conservation** définies par type de donnée, archivage puis purge journalisée.
- Conformité RGPD et réglementation nationale ; registre des traitements tenu à jour.

---

## 10. Continuité et résilience (HA / DR)

### 10.1 Objectifs
- Disponibilité **99,99 % minimum**, **99,999 %** pour les services critiques.
- **RTO < 30 minutes** (services critiques), **RPO < 5 minutes**.
- Aucun **Single Point of Failure**.

### 10.2 Haute disponibilité
Clusters applicatifs et Kubernetes (réplicas, auto-healing, rolling updates), load balancing (NGINX/HAProxy/Ingress) avec **health checks `GET /health` toutes les 5 secondes** et retrait automatique des instances défaillantes, autoscaling horizontal (CPU > 70 % : +1 pod ; > 80 % : +2 pods) et vertical, base Primary/Replicas avec **failover et promotion automatique en quelques secondes**, partitionnement et réplication géographique **Abidjan → Yamoussoukro → Bouaké → San Pedro → sauvegarde cloud**.

### 10.3 Sauvegardes
Règle **3-2-1** : 3 copies, 2 supports différents (disque + stockage objet), 1 copie hors site. Types : complète, incrémentale, différentielle. **Sauvegardes immuables**, snapshots, séparation réseau et coffre-fort de sauvegarde contre les **ransomwares**.

### 10.4 PRA (Plan de Reprise d'Activité)
Responsabilités, priorités de restauration, délais, procédures de bascule, tests réguliers.
Scénario type :
```
1. Détection de la panne du datacenter principal (monitoring, alertes, SIEM)
2. Décision de déclenchement (CTO + responsable sécurité)
3. Activation automatique du site de secours
4. Promotion de la base de données secondaire
5. Redirection du trafic (Load Balancer + DNS)
6. Vérification des services critiques
7. Reprise progressive des services non critiques
```
**Ordre de restauration imposé** : IAM → base patients → API médicales → paiements → applications utilisateurs.

### 10.5 PCA (Plan de Continuité d'Activité)
Maintien pendant la crise, sans attendre la restauration complète : urgences, accès aux dossiers patients, prescription électronique, accès aux résultats de laboratoire, authentification des professionnels. Moyens : fonctionnement sur site secondaire, **mode dégradé** de certaines fonctionnalités, synchronisation différée si la connexion est interrompue, équipes d'astreinte 24 h/24, procédures de communication de crise.

### 10.6 Tolérance aux pannes applicative
Une panne du module SMS ne bloque pas les consultations ; une panne du moteur IA n'empêche pas les médecins de travailler (retour aux protocoles seuls) ; une panne d'un prestataire de paiement permet de basculer vers un autre. Moyens : circuit breakers, timeouts, retries avec backoff, bulkheads, Dead Letter Queues, dégradation gracieuse annoncée à l'utilisateur.

### 10.7 Tests de résilience (obligatoires et réguliers)
Simulation de panne d'un serveur, arrêt volontaire d'un nœud Kubernetes, coupure réseau, perte d'une base répliquée, restauration à partir d'une sauvegarde, **bascule complète vers le site de secours**. Ces exercices valident les objectifs de disponibilité, mesurent les temps de reprise et améliorent en continu le PRA et le PCA.

---

## 11. Observabilité de sécurité

- **Metrics** (Prometheus/Grafana) : taux d'échec d'authentification, latence, erreurs 4xx/5xx, saturation, disponibilité.
- **Logs** structurés JSON vers stdout, centralisés (ELK/Loki), **sans donnée médicale en clair**, corrélés par `request_id`.
- **Tracing** distribué (OpenTelemetry) de bout en bout.
- **Alerting** : seuils définis, escalade vers le SOC, alertes avant dégradation perçue par l'utilisateur.

---

## 12. Sécurité de l'IA (Rule-005)

- Aucune décision médicale automatique sans validation humaine.
- Chaque prédiction journalisée avec la **version du modèle**, les protocoles appliqués, le score de confiance et les limites.
- Explicabilité obligatoire (SHAP ou équivalent — CDC_05).
- Données d'entraînement **anonymisées** et validées ; interdiction d'entraîner sur les données de production brutes.
- LLM **privé et auto-hébergé** ; aucune donnée patient envoyée à un service tiers (CDC_07).
- Filtrage RBAC/ABAC **avant** la récupération documentaire du RAG ; protection contre l'injection de prompt et l'exfiltration.
- Surveillance de la dérive et évaluation d'équité (biais) sur différentes populations.

---

## 13. Sécurité des paiements (voir CDC_06 §9)

PCI DSS, tokenisation (aucun PAN stocké), 3D Secure 2, webhooks signés (HMAC) avec anti-replay, idempotence anti-double-paiement, journal financier immuable, double écriture du Wallet, détection de fraude par IA, surveillance temps réel, séparation des environnements.

---

## 14. Gouvernance et conformité

- **ADR** pour toute décision ayant un impact sur la sécurité (Rule-003).
- Politique de sécurité documentée dans l'**Architecture Handbook** ; retours d'expérience dans le **Knowledge Book**.
- **Revue de code obligatoire** ; scan statique (SonarQube, PHPStan, ESLint, Pylint) et scan de dépendances/secrets en CI ; build bloqué en cas de vulnérabilité critique.
- **Tests d'intrusion** périodiques par un tiers ; programme de divulgation responsable des vulnérabilités.
- Formation et sensibilisation des équipes ; procédures d'habilitation des personnels de santé.
- Revue annuelle complète de l'architecture de sécurité.

---

## 15. Ordre de construction recommandé

1. IAM (Keycloak), OAuth2/OIDC, JWT, RBAC — **avant toute fonctionnalité métier**.
2. TLS 1.3 partout, en-têtes de sécurité, CSP, secrets externalisés.
3. Audit immuable + journalisation des accès aux dossiers.
4. ABAC (contexte, lien de prise en charge) + bris de glace.
5. MFA (professionnels d'abord), biométrie mobile.
6. PKI + signature électronique + vérification des autorisations d'exercer.
7. Chiffrement au repos AES-256 + HSM/KMS + rotation des clés.
8. Service Mesh + mTLS + segmentation réseau + durcissement Kubernetes.
9. SIEM, alerting, puis SOC.
10. HA/DR : clusters, réplication multi-sites, sauvegardes 3-2-1 immuables, PRA/PCA.
11. Tests d'intrusion, tests de résilience, exercices de bascule, revue de conformité.

Chaque étape est testée et validée avant de passer à la suivante ; en cas de problème, correction ciblée de la seule partie fautive.

---

*Fin du CDC_10 — Architecture Sécurité. Ce document prévaut sur tous les autres en cas de contradiction.*
