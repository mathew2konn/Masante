# CAHIER DES CHARGES N°5 — ARCHITECTURE INTELLIGENCE ARTIFICIELLE
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Ce document couvre l'**IA prédictive et analytique** (ML, Deep Learning, vision, OCR, voix, triage, scoring, recommandations). L'**IA générative** (LLM, RAG, embeddings, prompts, fine-tuning) fait l'objet du **CDC_07**. Le moteur de **protocoles médicaux** qui encadre l'IA est décrit dans le **CDC_08**.

Dépendances : CDC_03 (intégration Laravel ↔ FastAPI), CDC_04 (données sources), CDC_08 (protocoles prioritaires sur l'IA), CDC_09 (référentiels médicaments/maladies/analyses), CDC_10 (sécurité, anonymisation), CDC_13 (Data Lake, pipelines, gouvernance des données).

---

## 1. Principes non négociables

1. **Rule-005** : aucune IA ne prend de décision médicale sans expliquer son raisonnement. Chaque recommandation précise : les données utilisées, les protocoles appliqués, le score de confiance et les limites.
2. **L'IA est un outil d'aide à la décision, jamais un substitut au jugement clinique.** La décision finale appartient toujours au professionnel de santé.
3. **Les protocoles priment sur l'IA** : si un protocole officiel existe, il s'applique. L'IA complète, elle ne remplace pas (ordre de priorité complet en CDC_08 §3).
4. **Le triage n'est jamais un diagnostic** : il oriente vers le niveau de soins approprié.
5. **L'IA n'apprend jamais librement sur les données de production.** Pipeline obligatoire : anonymisation → validation par des médecins → jeu d'entraînement → entraînement → tests → déploiement.
6. **Approche hybride imposée** : moteur de règles médicales + modèle de Machine Learning + module d'explicabilité.
7. **Service indépendant** : l'IA est un ensemble de microservices autonomes. Une panne du moteur IA n'empêche pas les médecins de travailler (dégradation gracieuse vers les protocoles seuls).
8. **Contexte africain et ivoirien** : maladies fréquentes selon les régions (paludisme…), médicaments réellement disponibles dans le pays, structures accessibles, recommandations officielles du Ministère concerné, langues locales et français.

---

## 2. Stack technique imposée

| Domaine | Technologie |
|---------|-------------|
| Langage | **Python** |
| Serveur d'API | **FastAPI** + **Uvicorn** |
| Validation | **Pydantic** |
| ML tabulaire | **XGBoost** (principal), LightGBM, CatBoost, Random Forest, Régression logistique, SVM, Naive Bayes |
| Manipulation de données | **Pandas**, **NumPy** |
| Prétraitement / métriques | **Scikit-learn** |
| Explicabilité | **SHAP** (indispensable) |
| Suivi des modèles | **MLflow** |
| Deep Learning | **PyTorch** / **TensorFlow** — CNN, ResNet, EfficientNet, DenseNet, Vision Transformer (ViT), UNet, YOLO, Faster R-CNN |
| OCR | PaddleOCR, Tesseract, EasyOCR, TrOCR (Google Vision OCR en option) |
| Voix | Speech-to-Text et Text-to-Speech (moteurs open source privilégiés, hébergement privé) |
| Stockage | PostgreSQL (métadonnées, prédictions), MongoDB (traces), MinIO (modèles, images), Data Lake (CDC_13) |
| Déploiement | Docker + Kubernetes, autoscaling, GPU pour le Deep Learning |

---

## 3. Architecture générale

```
Applications MASANTÉ (Mobile, Web, Backend Laravel)
        │
   API Gateway  →  AI Gateway
        │
 ┌──────┴───────────────────────────────┐
 │ IA Temps réel │ IA Batch │ IA Streaming │
 └──────┬───────────────────────────────┘
        │
 Machine Learning │ Deep Learning │ Vision │ OCR │ Speech │ NLP médical
        │
 Moteur de règles (CDC_08) ── Explicabilité (SHAP) ── Registre de modèles (MLflow)
        │
 Données : PostgreSQL / MongoDB / Data Lake / MinIO
```

### 3.1 Pipeline standard de traitement
```
Collecte des données → Prétraitement → Nettoyage → Normalisation
→ Feature Engineering → Choix du modèle → Prédiction → Scoring
→ Explication → Retour vers le professionnel de santé
```

### 3.2 Intégration avec le backend
```
Laravel → REST (timeout court + circuit breaker) → FastAPI → Modèle → JSON → Laravel → Frontend
```
Laravel n'implémente aucune logique IA ; il appelle les endpoints, stocke la réponse (avec version de modèle et explication) et publie les événements correspondants.

---

## 4. Microservices IA à implémenter

| Service | Responsabilité |
|---------|----------------|
| `ai-gateway` | Point d'entrée unique, authentification, quotas, routage, journalisation |
| `triage-service` | Triage intelligent hybride (règles + XGBoost + SHAP) |
| `risk-prediction-service` | Prédiction de risques cliniques |
| `clinical-scoring-service` | Scores cliniques dynamiques (NEWS2, qSOFA, Glasgow…) |
| `early-warning-service` | Détection d'urgences et alertes sur constantes vitales |
| `vision-service` | Analyse d'images médicales (radio, scanner, IRM, écho, dermatologie, ophtalmologie) |
| `ocr-service` | Lecture automatique de documents papier |
| `speech-service` | STT (dictée médicale) et TTS |
| `recommendation-service` | Recommandations cliniques explicables |
| `interaction-service` | Interactions médicamenteuses, contre-indications, adaptation de doses |
| `hospital-optimization-service` | Prévision d'affluence, gestion des lits, planning, temps d'attente |
| `fraud-detection-service` | Détection de facturations suspectes et comportements anormaux |
| `epidemiology-service` | Surveillance épidémiologique, détection de zones à risque et tendances |
| `mlops-service` | Entraînement, versionnage, évaluation, déploiement, surveillance de dérive |

---

## 5. Moteur de triage intelligent (fonction centrale)

### 5.1 Fonctionnement hybride obligatoire
1. **Moteur de protocoles médicaux** (CDC_08) : règles officielles validées, stockées en base, jamais codées en dur.
   Exemple de règle : `SI difficulté respiratoire sévère → Urgence ; SINON SI fièvre + toux + douleur thoracique → Consultation rapide ; SINON → continuer les questions`.
2. **Modèle XGBoost** : estimation de la priorité à partir de données tabulaires (âge, sexe, poids, température, tension artérielle, fréquence cardiaque, saturation en oxygène, douleur, symptômes, antécédents, allergies, grossesse, médicaments en cours).
3. **Explicabilité SHAP** : justification systématique.

### 5.2 Contrat d'API
```
POST /api/v1/triage
{
  "pays_code": "CI",
  "age": 35, "sexe": "F", "grossesse": false,
  "temperature": 39.1, "frequence_cardiaque": 120, "tension": "13/8",
  "spo2": 91, "douleur": 8,
  "symptomes": ["fievre", "toux", "dyspnee"],
  "duree_symptomes_heures": 48, "evolution": "aggravation",
  "antecedents": ["asthme"], "allergies": ["penicilline"],
  "medicaments_en_cours": []
}
→
{
  "priorite": "Orange",
  "score": 0.94,
  "explication": ["Température élevée", "Saturation faible", "Tachycardie"],
  "protocoles_appliques": [{"id": "PROT-CI-PALU-2026.2", "version": "2026.2"}],
  "service_recommande": "Urgences / Pneumologie",
  "limites": "Résultat d'orientation, ne remplace pas un diagnostic médical.",
  "model_version": "triage-xgb-2.3.1",
  "confiance": "elevee"
}
```

### 5.3 Niveaux de priorité
**Côté patient (application)** — 4 niveaux :
- 🟢 Faible priorité : surveillance à domicile, conseils généraux, orientation possible vers un pharmacien.
- 🟡 Consultation recommandée : rendez-vous avec un généraliste ou un spécialiste, proposition d'établissements disponibles.
- 🟠 Consultation rapide : dans les 24 heures.
- 🔴 Urgence : se rendre immédiatement aux urgences ou appeler les services d'urgence.

**Côté hospitalier (professionnels)** — 5 niveaux, inspirés du Manchester Triage System et de l'Emergency Severity Index, **paramétrables selon les protocoles nationaux** :

| Niveau | Signification | Délai maximal recommandé |
|--------|---------------|--------------------------|
| Rouge | Urgence vitale | Immédiat |
| Orange | Très urgent | < 10 min |
| Jaune | Urgent | < 60 min |
| Vert | Peu urgent | < 120 min |
| Bleu | Non urgent | Consultation programmée |

### 5.4 Fiche de triage (livrable obligatoire)
Numéro de triage, date et heure, symptômes déclarés, réponses au questionnaire, niveau de priorité, recommandation, service recommandé, hôpitaux proches proposant ce service, **QR Code** permettant au médecin d'accéder au triage, et la mention obligatoire :
> *« Ce document constitue une aide à l'orientation et ne remplace pas un diagnostic médical. »*

Téléchargeable en PDF, partageable, transmissible au médecin avant le rendez-vous.

### 5.5 Rôles de l'IA dans le triage
1. **Comprendre le langage naturel** : « J'ai très mal au ventre depuis hier » → douleur abdominale, durée 24 h, intensité inconnue → poser les bonnes questions.
2. **Poser les questions les plus pertinentes** (questionnaire adaptatif, éviter 100 questions inutiles).
3. **Calculer le niveau de risque** avec une formulation prudente : jamais « Vous avez une pneumonie », mais « Les informations fournies suggèrent qu'une consultation médicale rapide est recommandée ».
4. **Apprendre** : enregistrement du triage réalisé, du diagnostic final posé par le médecin et du traitement prescrit — constitution progressive d'une base de données africaine, exploitée uniquement après anonymisation et validation.

---

## 6. Autres moteurs IA

### 6.1 Machine Learning (données tabulaires)
Prédictions de risque : cardiovasculaire, diabétique, réhospitalisation, infection, obstétrical, AVC, infarctus, septicémie, chutes, escarres, aggravation respiratoire, complications post-opératoires. Autres usages : estimation des temps d'attente, analyse des rendez-vous non honorés, prévision des stocks de médicaments, prévision d'affluence.

Pipeline : `Historique → Machine Learning → Probabilité → Score → Alertes → Recommandations`.

### 6.2 Deep Learning et Computer Vision
Sources : radiographie, IRM, scanner, échographie, photographie dermatologique, ophtalmologique, buccale, ECG, EEG.
Détections : fracture, tumeur, pneumonie, AVC, lésions, cancers cutanés, rétinopathie, hémorragie, anomalies ECG.
Pipeline : `Image → Prétraitement → Segmentation → Classification → Détection → Mesure → Explication → Rapport`.
Sortie type : `{"suspicion": "anomalie pulmonaire", "confiance": 0.86, "zones": [...], "model_version": "...", "validation_requise": true}` — **validation obligatoire par le radiologue** avant intégration au compte rendu.

### 6.3 OCR médical
Documents : ordonnances, résultats de laboratoire, cartes d'assurance, carnets de vaccination, certificats, anciens dossiers médicaux.
Pipeline : `Photo → Correction → Suppression du bruit → Détection du texte → Reconnaissance OCR → Correction automatique → Extraction structurée → Insertion dans le dossier patient (après validation humaine)`.

### 6.4 Speech-to-Text / Text-to-Speech
STT : consultation, dictée médicale, compte rendu opératoire, urgences, télémédecine. Pipeline : `Voix → Réduction du bruit → Reconnaissance → Ponctuation → Correction médicale → Texte`.
TTS : assistance aux personnes malvoyantes, lecture d'ordonnances et de résultats, chatbot vocal, bornes interactives, centre d'appel médical.

### 6.5 Scoring clinique dynamique
Scores calculés et **recalculés automatiquement après chaque nouvelle donnée clinique pertinente** : NEWS2, qSOFA, Glasgow Coma Scale, score de risque cardiovasculaire, score obstétrical, score pédiatrique. Historisés dans `scores_cliniques` (CDC_04).

### 6.6 Détection d'urgences (surveillance continue)
Déclencheurs : chute brutale de la saturation, tachycardie sévère, hypotension, hyperthermie importante, convulsions, arrêt cardiaque détecté, détresse respiratoire, anomalies ECG.
Chaîne : `Détection → Validation → Notification → Médecin → Infirmier → Urgences → Traçabilité`.
**Seuils configurables** (par établissement et par protocole national) et **mécanismes de réduction des faux positifs** obligatoires pour éviter la fatigue d'alerte.

### 6.7 Moteur de recommandations explicables
Recommandations : examens complémentaires, surveillance renforcée, rappels vaccinaux, dépistages, protocoles thérapeutiques, conseils de prévention, orientation vers un spécialiste, programmes d'éducation thérapeutique.
Entrées prises en compte : profil du patient, antécédents, allergies, traitements en cours, résultats biologiques, recommandations médicales en vigueur, interactions médicamenteuses potentielles.
**Toute recommandation est explicable** : l'utilisateur peut consulter les principaux facteurs qui l'ont produite.

### 6.8 Aide au diagnostic
Hypothèses diagnostiques classées par probabilité, accompagnées des éléments qui soutiennent ou affaiblissent chaque hypothèse (exemple : Paludisme 72 %, Dengue 18 %, Grippe 10 %). Décision finale toujours au médecin. Aucune formulation affirmative de diagnostic.

### 6.9 Optimisation hospitalière et santé publique
Prévision d'affluence, gestion des lits, planning des médecins, priorisation dynamique, optimisation du dispatch d'ambulances (disponibilité, trafic, hôpital adapté), détection de fraude (facturations suspectes, comportements anormaux), surveillance épidémiologique (épidémies, zones à risque, tendances sanitaires).

---

## 7. Données et pipeline d'apprentissage

### 7.1 Trois bases de connaissances (imposées)
1. **Base médicale internationale** : maladies (**CIM-11**), symptômes (**SNOMED CT**), anatomie, spécialités médicales, terminologie médicale.
2. **Base nationale** (par pays) : médicaments autorisés, protocoles nationaux, vaccins disponibles, liste officielle des établissements, assurances (CNAM et privées), langues, tarifs réglementés le cas échéant.
3. **Base d'apprentissage IA** : alimentée progressivement avec des données **anonymisées** et validées par des professionnels — symptômes déclarés, réponses au questionnaire, niveau de triage proposé, diagnostic final, traitements prescrits, résultats d'examens.

### 7.2 Pipeline ML obligatoire
```
Application → Base de données → Anonymisation → Validation par les médecins
→ Jeu de données d'entraînement → Entraînement (XGBoost/DL) → Tests
→ Validation clinique → Déploiement du nouveau modèle
```
**Interdiction absolue d'entraîner directement sur les données de production.**

### 7.3 Évolution progressive (4 phases)
- **Phase 1** : protocoles médicaux, arbres de décision, questions dynamiques.
- **Phase 2** : IA pour personnaliser les questions et améliorer l'orientation.
- **Phase 3** : modèles ML entraînés sur données validées et anonymisées (risque d'hospitalisation, risque de détérioration, estimation du temps d'attente, priorisation dynamique).
- **Phase 4** : amélioration continue sous supervision médicale.

---

## 8. MLOps

- **MLflow** : suivi des expériences, versionnage des modèles, comparaison des performances (ex. modèle 1 : 89 %, modèle 2 : 92 %, modèle 3 : 95 %), possibilité de revenir à une version antérieure.
- **Registre de modèles** : chaque modèle possède identifiant, version, jeu de données d'entraînement, métriques, date de validation clinique, responsable, statut (candidat / validé / actif / archivé).
- **Métriques suivies** : exactitude, précision, rappel, F1-score, AUC, matrice de confusion, calibration, latence d'inférence.
- **Surveillance de la dérive (model drift)** : suivi des distributions d'entrée et des performances en production, alertes automatiques, recalibrage périodique.
- **Équité et biais** : évaluation des modèles sur différentes populations (âge, sexe, région, milieu urbain/rural) pour limiter les biais algorithmiques.
- **Déploiement** : conteneurs versionnés, déploiement progressif (canary/shadow), possibilité de rollback immédiat, événement `ModelTrainingFinished` publié sur le bus (CDC_03 §8).
- **Reproductibilité** : jeux de données versionnés, seeds fixés, environnement figé (requirements.txt), traçabilité complète de l'entraînement.

---

## 9. Gouvernance et IA responsable

1. **Supervision humaine** : aucune décision médicale critique n'est prise automatiquement sans validation d'un professionnel.
2. **Traçabilité** : chaque prédiction, score ou recommandation est journalisé avec la version du modèle, les données d'entrée (référencées, non dupliquées en clair), les protocoles appliqués et l'explication produite.
3. **Explicabilité (Explainable AI)** : SHAP ou méthode équivalente pour tout modèle influençant une décision clinique. Exemple de sortie attendue : `Priorité Rouge — Facteurs principaux : Saturation 86 %, Température 40 °C, Douleur 9/10, Fréquence cardiaque 145 bpm`.
4. **Protection des données** : chiffrement (AES-256 au repos, TLS 1.3 en transit), anonymisation pour l'entraînement, minimisation des données transmises aux services IA, conformité RGPD et réglementation nationale.
5. **Surveillance des performances** et recalibrage périodique.
6. **Validation clinique** avant toute mise en production d'un modèle influençant une décision de soins.
7. **Limites explicites** : chaque réponse IA indique ses limites et son niveau de confiance.

---

## 10. Sécurité et exploitation

- Authentification des appels via l'AI Gateway (JWT de service, mTLS interne via Service Mesh — CDC_10).
- Quotas et rate limiting par appelant ; timeouts stricts ; circuit breaker côté Laravel.
- Journalisation structurée vers stdout (Twelve-Factor), traces OpenTelemetry, métriques Prometheus (latence P95/P99, taux d'erreur, taux d'utilisation GPU).
- Health checks `/health` et `/ready` ; autoscaling Kubernetes ; isolation des charges GPU.
- Stockage des modèles dans MinIO avec contrôle d'intégrité ; sauvegarde et versionnage (restauration possible — exigence HA/DR).
- **Dégradation gracieuse** : si un service IA est indisponible, le backend renvoie le résultat des protocoles seuls avec mention explicite que l'assistance IA est momentanément indisponible.

---

## 11. Tests

- Tests unitaires (PyTest) : prétraitement, feature engineering, règles d'intégration, sérialisation.
- Tests de contrat d'API (schémas Pydantic + OpenAPI).
- Tests de modèle : jeux de validation, cas cliniques de référence, cas limites (nourrissons, femmes enceintes, insuffisants rénaux, polymédication).
- Tests de non-régression clinique à chaque nouvelle version de modèle.
- Tests de charge et de latence.
- Tests d'explicabilité : toute réponse doit contenir une explication non vide et cohérente.

---

## 12. Ordre de construction recommandé

1. Socle FastAPI + Pydantic + Docker + observabilité + AI Gateway.
2. Intégration du **moteur de règles** (CDC_08) — le triage doit fonctionner **sans ML** en premier (Phase 1).
3. Triage XGBoost + SHAP + MLflow (Phase 2/3), avec fiche de triage PDF/QR.
4. Interactions médicamenteuses et contre-indications (référentiel CDC_09).
5. Scoring clinique + détection d'urgences sur constantes vitales.
6. Prédiction de risques cliniques.
7. OCR médical, puis STT/TTS.
8. Vision par ordinateur (imagerie) avec validation radiologue.
9. Recommandations explicables, aide au diagnostic.
10. Optimisation hospitalière, détection de fraude, surveillance épidémiologique.
11. MLOps complet : drift, équité, canary, rollback.

Chaque service est testé et validé isolément avant intégration ; en cas de problème, correction ciblée de la seule partie fautive.

---

*Fin du CDC_05 — Architecture Intelligence Artificielle.*
