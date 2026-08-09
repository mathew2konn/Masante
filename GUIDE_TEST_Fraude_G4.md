# Guide de test G4 — fraud-detection-service (CDC_05)

Service IA de **détection** de facturations suspectes / comportements anormaux. **Détection SEULE** :
il note et explique, il n'agit jamais (pas de gel, pas de correction). Modèle entraîné sur données
**synthétiques de démonstration** — il prouve la mécanique, **pas** une validité clinique.

## 0. Démarrer
```bash
cd services/fraud-detection
docker compose up -d --build fraud-detection
# Attendre ~15 s puis vérifier :
curl http://localhost:8090/ready        # {"status":"READY","modele_charge":true,"mode":"hybride"}
```
Swagger : **http://localhost:8090/docs** (tout se teste depuis « Try it out »).

## 1. Facture SAINE → NORMAL
`POST /api/v1/fraud/score`
```json
{"reference":"FACT-SAINE-1","etablissement_ref":"ETB-ABJ-001","montant_ttc":30000,
 "montant_couvert":21000,"reste_a_payer":9000,"montant_acte":30000,"montant_acte_reference":28000,
 "nb_factures_etablissement_30j":12,"nb_actes_identiques_jour":3,"heure_operation":10}
```
Attendu : `niveau=NORMAL`, `score=0`, `regles_declenchees=[]`, `probabilite_ml` très bas (~0.001),
`explication` non vide, `limites` présent, `action="AUCUNE…"`.

## 2. Incohérence DURE (part couverte > TTC) → TRES_SUSPECT, escaladé par les règles
```json
{"reference":"FACT-INCOH-1","etablissement_ref":"ETB-ABJ-001","montant_ttc":10000,
 "montant_couvert":15000,"reste_a_payer":0,"montant_acte":10000,"montant_acte_reference":9000}
```
Attendu : `niveau=TRES_SUSPECT`, `score≥70`, règles `INCOHERENCE_PRISE_EN_CHARGE` +
`DESEQUILIBRE_COMPTABLE`. **Point clé** : même si `probabilite_ml` est bas, le niveau est TRES_SUSPECT
→ **les règles font autorité**, le ML ne peut pas minorer une certitude déterministe. `confiance=moyenne`
(désaccord règles/ML).

## 3. Profil FRAUDE (actes gonflés + vélocités + horaire) → TRES_SUSPECT + SHAP
```json
{"reference":"FACT-FRAUDE-1","etablissement_ref":"ETB-ABJ-009","montant_ttc":900000,
 "montant_couvert":0,"reste_a_payer":900000,"montant_acte":300000,"montant_acte_reference":28000,
 "nb_factures_etablissement_30j":80,"nb_actes_identiques_jour":40,"nb_remboursements_carte_7j":9,
 "montant_cumule_wallet_24h":6000000,"nb_ops_wallet_1h":30,"heure_operation":3,
 "delai_facture_paiement_minutes":2}
```
Attendu : `niveau=TRES_SUSPECT`, `score=100`, `probabilite_ml`~0.999, plusieurs règles, et
`facteurs_ml` (valeurs **SHAP**) dans l'explication.

## 4. Lot → résumé
`POST /api/v1/fraud/scan` avec `{"signaux":[ …plusieurs objets… ]}` → `resume` (total/normal/suspect/
tres_suspect) + un résultat par signalement.

## 5. Dégradation gracieuse (modèle indisponible → règles seules)
```bash
docker compose --profile degrade up -d --build fraud-degrade   # port 8091
curl http://localhost:8091/ready     # {"modele_charge":false,"mode":"regles_seules"}
```
Rejouer le vecteur §2 sur le port **8091** : le service répond quand même (`mode=regles_seules`,
`probabilite_ml=null`, `facteurs_ml=[]`, `confiance=reduite`, `limites` mentionne « ML indisponible »)
et l'incohérence dure reste TRES_SUSPECT. Prouve que le service ne tombe pas si le ML manque.

## 6. Ce qu'il faut vérifier (Rule-005 / frontière)
- Chaque réponse porte **explication non vide**, **limites**, **confiance**, et `action="AUCUNE — …"`.
- Aucune réponse ne « décide » : pas de gel, pas de blocage. C'est un outil d'aide, revue humaine requise.
- Les `limites` rappellent toujours : modèle **synthétique de démonstration, non validé cliniquement**.

## 7. Extraction réelle depuis le paiement (incrément A, ADR-019)
Le service peut désormais **extraire les signaux d'une facture** directement du service paiement (au lieu
qu'on les fournisse), via l'endpoint read-only `/fraud-signals` gardé par **principal signé + ADMIN_FINANCE**.

Prérequis : la pile **paiement** tourne (`services/payment` : `docker compose up -d`), et la pile fraude a
`FRAUD_PAYMENT_BASE_URL` + `FRAUD_PRINCIPAL_SECRET` (déjà câblés dans `docker-compose.yml`).
```bash
cd services/fraud-detection && docker compose up -d --build fraud-detection
```

- **Score par référence** (`POST /api/v1/fraud/score-ref`) — extrait FCT-… du paiement puis score :
```json
{"reference":"FCT-TEST","as_of":"2026-08-09T12:00:00Z"}
```
Attendu : un `ResultatFraude` (mêmes champs qu'au §2) calculé sur les **signaux réels** de la facture.

- **Scan par références** (`POST /api/v1/fraud/scan-refs`) : `{"references":["FCT-TEST","FCT-A"]}` → résumé + un résultat par facture.

- **Dégradation HONNÊTE** (on n'invente pas ce qu'on ne peut pas lire) :
  - référence inconnue → **404** ;
  - paiement arrêté (`docker compose stop payment` côté paiement) → **502** (`SourceIndisponible`), **jamais** un score inventé.

Côté paiement, l'endpoint lui-même se teste (Swagger `http://localhost:8080/swagger-ui.html`, tag « Signaux
fraude ») mais exige les en-têtes `X-Principal`/`X-Principal-Sig` : en pratique on le prouve **via** les
routes fraude ci-dessus, qui signent pour vous.

## 8. Routage d'alerte vers l'admin plateforme (incrément B1, ADR-020)
Le **paiement orchestre** : il sélectionne les factures d'une journée, demande un score au
fraud-detection-service, **persiste les alertes ≥ SUSPECT** et **émet une notification** (Outbox P5.4c)
vers le contrôleur plateforme `ADMIN_FINANCE` — jamais la structure signalée. **Détection seule** : aucun
gel. Tout se passe **côté paiement** (Swagger `http://localhost:8080/swagger-ui.html`, tags « Alertes
fraude » et « Notifications »), endpoints gardés par **principal signé + ADMIN_FINANCE**.

Prérequis : les deux piles tournent (`services/payment` **et** `services/fraud-detection`).

1. **Scan** (`POST /api/v1/fraud-alertes/scan?journee=AAAA-MM-JJ`) → résumé
   `{nbEvaluees, nbSuspectes, nbNouvelles, nbNotifiees}`. Sur les données de démonstration, la facture au
   montant d'acte aberrant + horaire nocturne + vélocité franchit le seuil → **1 alerte**.
2. **Lister** (`GET /api/v1/fraud-alertes`) → l'alerte (facture, niveau, score, `notifiee=true`,
   snapshots règles/facteurs/signaux), statut `OUVERTE`.
3. **Notification émise** (`GET /api/v1/notifications`, en-tête `X-Utilisateur-Id: CTRL-FRAUDE-PLATEFORME`)
   → une ligne `FRAUDE_SUSPECTEE`, statut `EN_ATTENTE`.
4. **Relayer** (`POST /api/v1/notifications/relayer`) puis relister → statut `ENVOYEE` (canal simulé).
5. **Rejeu idempotent** : relancer le scan → `nbNouvelles=0`, `nbNotifiees=0`, toujours 1 alerte (pas de
   doublon, pas de nouvelle notification).
6. **Revue** (`POST /api/v1/fraud-alertes/{id}/revue`) → statut `REVUE` (trace ; aucune action automatique).
7. **Dégradation honnête** : arrêter la fraude (`docker compose stop fraud-detection`) puis relancer le
   scan → **502** (`fraud-detection-service injoignable`), **aucune alerte inventée**.

Les endpoints `/fraud-alertes` exigent les en-têtes `X-Principal`/`X-Principal-Sig` (principal signé
ADMIN_FINANCE) — en pratique on les teste avec un outil qui signe (cf. incrément A).

## Arrêter
```bash
docker compose --profile degrade down
```

## Registre de modèles (facultatif)
`services/fraud-detection/mlruns/` contient les runs MLflow (params + métriques). Pour visualiser :
`mlflow ui` dans le dossier du service, puis http://localhost:5000. Aucun serveur requis pour faire
tourner le service.
