# ADR-017 — fraud-detection-service (CDC_05) : microservice IA Python, hybride règles+ML+SHAP, détection seule

- **Statut** : **Accepté — incrément 1 VALIDÉ G5** (2026-08-08). Détection SIMULÉE (modèle synthétique) ; routage/écran d'alerte différés (dette).
- **Date** : 2026-08-08
- **Corpus** : CDC_05 (Architecture IA) — §1.6 approche hybride imposée, §2 stack imposée, §6.9 détection de fraude, §7.2 interdiction d'entraîner sur la production, §9.1 supervision humaine, §1.7/§10 dégradation gracieuse, §8 MLOps/MLflow. CDC_00 §4 (interdit : IA décidant seule). CDC_01/02 §0.1 (frontière : détection backend).
- **Lié à** : [[ADR-013]] (microservice paiement Java), [[ADR-014]] (rapprochement 2 sources), P5.3b-2 (fraude wallet temps-réel Java), [[fraude-wallet-principes]].

## Contexte

CDC_05 liste un `fraud-detection-service` (§4, §6.9 : « facturations suspectes et comportements anormaux »).
Deux dettes explicites l'attendaient : la fraude IA + multi-comptes renvoyée depuis P5.3b-2. Trois tensions
devaient être tranchées **par le propriétaire** avant tout code (G1) ; elles le sont ci-dessous.

## Décision

### 1. Stack = Python/FastAPI (fidèle CDC_05 §2), microservice séparé

CDC_05 §2 **impose** Python + FastAPI + Pydantic + XGBoost + SHAP + scikit-learn + pandas/numpy + MLflow.
Tout le domaine paiement/fraude existant est en Java (`services/payment`). L'ordre de résolution des conflits
impose : dévier de la stack imposée = **ADR validé par le propriétaire**. Le propriétaire a choisi de **rester
fidèle** : nouveau service **`services/fraud-detection/`** en Python, et non une extension Java. Dépendances
approuvées **en bloc** au G1 (§2.6). Ce n'est **pas** une dérogation : c'est l'application de la stack imposée.

### 2. Séparation temps-réel (Java) / analytique (Python) — détection SEULE

Le service Java existant (`ServiceDetectionFraude`, P5.3b-2) reste la **garde temps-réel transactionnelle** qui
challenge/gèle **en ligne** une opération wallet. Le nouveau service Python est le **détecteur analytique
hybride** : il **note et explique** des signalements, **sans jamais agir** (pas de gel, pas de correction). Cette
séparation tient la ligne rouge **CDC_00 §4 + CDC_05 §9.1 : « l'IA ne décide jamais seule »**. Ce n'est pas un
report : le service **restera** détection seule ; l'action reste à un humain (ou à la garde déterministe Java).

### 3. Approche hybride imposée (§1.6) — les règles font autorité, le ML est indicatif

Trois composantes : (a) **moteur de règles déterministe** (`ReglesDetectionFraude` en Python pur, seuils =
**données** de config, couche « Phase 1 » §7.3 qui fonctionne **sans ML**) ; (b) **XGBoost** → probabilité ;
(c) **SHAP** → facteurs contributifs. **Fusion** : une incohérence **dure** (part couverte > TTC ; couvert +
reste ≠ TTC — invariant paiement rompu) **escalade** le niveau quel que soit le score ML (`force_escalade`) ;
le ML ne peut jamais **minorer** une certitude déterministe. Dégradation gracieuse (§1.7/§10) : modèle absent →
réponse **règles seules** avec mention explicite. **Rule-005** : chaque réponse porte données, score, confiance,
**limites** ; l'explication n'est **jamais vide**.

### 4. Honnêteté : modèle sur données SYNTHÉTIQUES, jamais « validé cliniquement »

§7.2 **interdit** d'entraîner sur la production ; aucun jeu anonymisé + validé médecin n'existe. Le propriétaire a
choisi d'entraîner XGBoost sur des **données synthétiques ouvertement étiquetées « demo »** (générateur documenté,
graine fixée, distributions chevauchantes). Le modèle **démontre la mécanique** ML/SHAP de bout en bout ; il est
classé **« conçu »**, **jamais « validé cliniquement »** (avertissement porté dans `metriques.json`, la réponse
API, le README et Swagger). Même discipline que « un run vert sur du vide ne prouve rien » ([[ADR-014]]).

### 5. Périmètre = signaux paiement INTERNES ; extraction réelle différée

Les features sont le **contrat des signaux internes** (factures, prise en charge, wallet, remboursements) déjà
présents dans le domaine paiement. Le générateur synthétique produit **exactement la même forme** de vecteur →
pas de décalage train/serve. **L'extraction depuis la base payment réelle est un adaptateur DIFFÉRÉ** : coupler
aveuglément le service Python au schéma de la base Java est précisément ce qu'on évite ([[ADR-014]]). Classé
« conçu », pas « prêt ».

### 6. MLflow en registre FICHIER local (§8), pas de serveur

`file:./mlruns` : versionnage des modèles, params, métriques (exactitude/précision/rappel/F1/AUC), retour à une
version antérieure — **sans serveur à héberger** (UI `mlflow ui` à la demande). Passer au serveur de tracking en
prod = **une ligne** (`tracking_uri`). MLflow avancé (drift, canary, équité §8) = différé.

### 7. Destinataire de l'alerte = contrôleur plateforme INDÉPENDANT, jamais la structure signalée

Décision de gouvernance figée à G5. La fraude à la **facturation** (sur-facturation, actes fantômes,
gonflement de la prise en charge) est fréquemment commise **par la structure elle-même**. Router une
alerte vers le **directeur de la structure signalée** reviendrait à **prévenir le fraudeur** (destruction
de preuves). Donc :

- L'alerte est destinée à un **contrôleur anti-fraude / conformité côté plateforme** (profil type
  `ADMIN_FINANCE`), **indépendant** de la structure contrôlée, qui reçoit le niveau, les règles
  déclenchées et les **facteurs SHAP**, puis **décide** (enquête, gel manuel, signalement CNAM…).
- **Jamais d'action automatique** (CDC_05 §9.1) : l'IA détecte et explique, **un humain tranche**.
- Le responsable de la structure n'est informé qu'**après** décision humaine, dans un cadre
  **contradictoire** (droit de réponse), et seulement si l'enquête l'exige.
- **État livré** : la détection est faite (API `/score`, `/scan`) ; le **routage/notification vers ce
  contrôleur et l'écran d'administration** ne sont **pas** construits (dette « pas d'écran »). Le portail
  futur (Next) affichera la file d'alertes sous **RBAC** (module P1) — chaque rôle ne voyant que ce qui
  le concerne, la structure n'ayant **pas** accès aux alertes qui la visent.

## Conséquences

- Service **sans état** (scoring pur) : ni Postgres ni Redis ; `docker compose up` léger. Port **8090**.
- Gates adaptés (backend seul) : G2 = FastAPI live (Swagger + curl : fraude/sain/modèle absent) ; G3 = ruff +
  pytest + build Docker verts ; G4 = propriétaire via Swagger ; G5 écrit.
- **Dettes assumées** (`services/fraud-detection` — voir plan G1) : modèle synthétique non validé clinique ;
  extraction base payment ; multi-comptes (device/IP indisponibles) ; MLflow avancé ; pas d'écran (un portail
  admin Next consommera les alertes plus tard). Enums d'alerte backend-only, à promouvoir dans `@masante/shared`
  quand un écran les consommera (même logique qu'ADR-014/015/016).
- La détection **IA + multi-comptes** renvoyée par P5.3b-2 est **partiellement** adressée : IA (hybride) livrée ;
  multi-comptes reste dû (signaux device/IP absents).
