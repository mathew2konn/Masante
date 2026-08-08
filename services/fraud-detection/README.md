# fraud-detection-service (CDC_05)

Microservice IA autonome (§1.7) de **détection de facturations suspectes et comportements anormaux**
(§6.9) sur les **signaux paiement internes** (factures, prise en charge, wallet, remboursements).

## Principes tenus
- **Approche hybride imposée (§1.6)** : moteur de règles déterministe + modèle **XGBoost** + explicabilité **SHAP**.
- **Détection SEULE (§9.1, CDC_00 §4)** : le service note et explique ; il ne gèle rien, ne corrige rien.
  La garde temps-réel qui bloque/gèle reste côté Java (P5.3b-2). Ligne rouge : l'IA ne décide jamais seule.
- **Rule-005** : chaque réponse porte données utilisées, score, confiance, **limites** ; explication jamais vide.
- **Dégradation gracieuse (§1.7/§10)** : modèle indisponible → réponse en **règles seules** (mention explicite).
- **Seuils = données** (config `FRAUD_*`), jamais du métier codé en dur.

## ⚠️ Honnêteté (décision G1)
Le modèle est entraîné sur des **données SYNTHÉTIQUES de démonstration** — CDC_05 §7.2 interdit
d'entraîner sur la production et aucun jeu anonymisé + validé médecin n'existe encore. Le modèle
**démontre la mécanique** ML/SHAP ; il n'est **jamais validé cliniquement**. L'extraction depuis la
base payment réelle est un **adaptateur différé** (le contrat de features en tient déjà la forme).

## Lancer
```bash
docker compose up --build           # http://localhost:8090/docs
docker compose up fraud-degrade --build   # port 8091, mode règles seules (démo dégradation)
```

## API
- `POST /api/v1/fraud/score` — score un signalement, résultat explicable.
- `POST /api/v1/fraud/scan` — score un lot, avec résumé.
- `GET /health`, `GET /ready` (expose `mode` : hybride | regles_seules).

## Entraînement / MLflow
`python -m app.ml.entrainement` : entraîne XGBoost, journalise params + métriques dans **MLflow
fichier local** (`file:./mlruns`, aucun serveur), écrit `models/modele_fraude.json` +
`models/metriques.json`. Voir les runs à la demande : `mlflow ui` (pointé sur `./mlruns`).

## Dettes assumées
Modèle synthétique (non validé clinique) · extraction base payment différée · multi-comptes
(device/IP indisponibles) différé · MLflow avancé (drift/canary/équité §8) différé · pas d'écran
(un portail admin Next consommera les alertes plus tard). Détails : plan G1 / `DETTE_TECHNIQUE.md`.
