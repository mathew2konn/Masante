# Dette technique — services/fraud-detection

Dettes assumées (décidées au G1, à traiter plus tard). Chaque entrée : quoi, pourquoi acceptable
maintenant, condition de levée. Voir ADR-017.

## Incrément 1 (CDC_05)

- **Modèle entraîné sur données SYNTHÉTIQUES.** CDC_05 §7.2 interdit d'entraîner sur la production ;
  aucun jeu anonymisé + validé médecin n'existe. Le modèle **démontre la mécanique** ML/SHAP ; il n'est
  **jamais validé cliniquement** (avertissement dans `metriques.json`, la réponse API, Swagger, le README).
  Les métriques quasi-parfaites (AUC ~1.0) reflètent des classes synthétiques séparables, **pas** une
  performance réelle. *Levée* : pipeline §7.2 (anonymisation → validation médecin → entraînement) avec un
  vrai jeu, puis promotion du modèle dans le registre (candidat → validé → actif).

- **Extraction depuis la base payment réelle — LEVÉE (2026-08-09, incrément A, ADR-019).** Le service
  paiement (propriétaire de son schéma) expose un **endpoint read-only** `GET /api/v1/fraud-signals/{ref}`
  (+ `/lot`) qui projette les agrégats en SQL ; la fraude le **consomme en HTTP** sous **principal signé
  (P5.5b-1) + ADMIN_FINANCE** et **normalise** camelCase→snake_case (frontière anti-corruption, ADR-014 :
  la fraude ne lit jamais la base paiement). Nouvelles routes `POST /score-ref` & `/scan-refs`. Additif pur
  (POST-signaux et modèle inchangés). Dégradation honnête : source injoignable → 502, jamais un score
  inventé. Aucune dépendance nouvelle (`httpx` promu test→runtime ; signature en stdlib). *Reste différé* :
  le **routage/notification** de l'alerte extraite vers `ADMIN_FINANCE` = incrément B (cf. entrée « écran ni
  routage »). En prod : secret partagé → compte de service Keycloak ; audit d'accès à envisager.

- **Multi-comptes (N wallets → même bénéficiaire/device/IP) non couvert.** Dette explicitement renvoyée
  ici depuis P5.3b-2. Les signaux device/IP ne sont pas disponibles dans le domaine paiement actuel.
  *Levée* : capter device/IP en amont, ajouter des règles + features de collusion.

- **SHAP dégénéré — LEVÉ (2026-08-08).** Le générateur a été revu : features tirées d'une population
  unique + label = combinaison pondérée de plusieurs features + bruit → le modèle combine plusieurs
  signaux, SHAP répartit ses contributions (5 features distinctes) et l'AUC redescend à ~0.86 (réaliste).
  Reste la dette de fond : modèle **synthétique**, non validé clinique (voir 1ʳᵉ entrée).

- **MLflow avancé (drift / canary / équité §8) non livré.** Registre FICHIER local (`file:./mlruns`) :
  versionnage + params + métriques + rollback. *Levée* : serveur de tracking (une ligne `tracking_uri`),
  surveillance de dérive, déploiement canary/shadow, évaluation d'équité par population.

- **Pas d'écran NI routage d'alerte.** Un microservice IA expose une API, pas d'UI. Le service **répond**
  au demandeur ; il n'**envoie** l'alerte à personne (pas de notification, pas de file). *Destinataire figé
  ADR-017 §7* : contrôleur anti-fraude / conformité plateforme (`ADMIN_FINANCE`), **indépendant** de la
  structure signalée — **jamais** le directeur de la structure visée (le prévenir = prévenir le fraudeur).
  *Levée* : portail d'administration Next consommant `/scan`, sous RBAC (P1), avec routage/notification ;
  les enums d'alerte seront alors promus dans `@masante/shared` (même logique qu'ADR-014/015/016).

- **Tests d'intégration (Testcontainers) absents.** G3 = règles/features/fusion purs + API en mode dégradé ;
  le mode hybride (modèle chargé + SHAP) est prouvé en **G2 live** (Swagger + curl). *Levée* : Testcontainers
  ou fixtures de modèle en CI (dépendance nouvelle → accord propriétaire, §2.6).
