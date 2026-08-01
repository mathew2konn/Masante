# CAHIER DES CHARGES N°8 — ARCHITECTURE DES PROTOCOLES MÉDICAUX
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Le moteur de protocoles est le **cerveau décisionnel clinique** de MASANTÉ : il encadre l'IA (CDC_05, CDC_07), alimente le triage (CDC_01, CDC_05), s'appuie sur les référentiels nationaux (CDC_09), stocke ses règles en base (CDC_04), est exposé par le backend (CDC_03) et journalisé selon CDC_10.

---

## 1. Vision et principes non négociables

1. Le moteur **ne remplace pas le médecin**. Il standardise les prises en charge, diminue les erreurs médicales, harmonise les décisions, assiste les professionnels et maintient les recommandations à jour.
2. **Aucune règle médicale codée en dur.** Toutes les règles sont **stockées dans la base de données**. Interdit : `if temperature > 39: urgence = True`. Obligatoire : un moteur de règles interprétant des protocoles versionnés.
3. **Priorité absolue sur l'IA** : si un protocole officiel existe, il s'applique. L'IA n'invente jamais un traitement.
4. **Traçabilité médico-légale** : chaque décision conserve la **version exacte** du protocole utilisé.
5. **Multi-pays sans modification de code** : ajouter un pays = ajouter des protocoles et des référentiels.
6. **Aucun protocole utilisable sans validation** (clinique, réglementaire, scientifique, technique).
7. La **décision finale appartient toujours au professionnel de santé**.

---

## 2. Architecture générale

```
Médecin / Application / Triage
        │
   Moteur de Protocoles
        │
 ┌──────┴───────────────────────┐
 │ Recherche du contexte patient │
 └──────┬───────────────────────┘
        │
 ┌──────┴───────────────────────────┐
 │ Sélection des protocoles applicables │
 └──────┬───────────────────────────┘
        │
 Application de l'ordre de priorité
        │
 Évaluation des règles (moteur d'inférence)
        │
 Génération des recommandations (+ niveau de preuve + références)
        │
 Validation par le médecin
        │
 Dossier médical patient + Journal d'audit
```

### 2.1 Composants
- `protocol-registry` : catalogue, métadonnées, versions, cycle de vie.
- `rules-engine` : moteur d'inférence (règles + graphes décisionnels).
- `protocol-selector` : sélection contextuelle et résolution de priorité/conflits.
- `questionnaire-engine` : génération du questionnaire dynamique de triage (questions adaptatives).
- `protocol-authoring` : interface de rédaction, relecture, validation, publication.
- `protocol-audit` : traçabilité des décisions et des écarts.

---

## 3. Ordre de priorité entre référentiels (imposé)

```
1. Protocoles ivoiriens (Ministère de la Santé, programmes nationaux)
2. Protocoles ministériels régionaux
3. OMS
4. Sociétés savantes internationales
5. Protocoles hospitaliers (spécifiques à l'établissement)
6. Raisonnement IA
```
Le moteur applique automatiquement cet ordre. **L'IA n'intervient qu'en dernier recours**, et uniquement pour compléter (questions pertinentes, hypothèses probabilisées, orientation), jamais pour contredire un protocole officiel.

---

## 4. Structure d'un protocole

### 4.1 Métadonnées obligatoires
Identifiant, titre, version, date de publication, auteur, organisme, statut, date d'expiration, références bibliographiques, documents PDF associés, **niveau de preuve**, population concernée, conditions d'utilisation, pays (`pays_code`), spécialité, mots-clés, langue.

### 4.2 Exemple imposé
```
PROTOCOLE : Paludisme simple
Version : 2026.2
Auteur : Programme National de Lutte contre le Paludisme
Population : Adultes
Critères : Fièvre + TDR positif + absence de signes de gravité
Traitement : ACT, hydratation, contrôle à J3
```

### 4.3 Représentation des règles
Deux représentations complémentaires, toutes deux stockées en base :

**a) Règles métier déclaratives**
```
SI Patient < 5 ans
ET Fièvre
ET Convulsions
ET Paludisme positif
ALORS Urgence
     Hospitalisation
     Traitement injectable
     Surveillance neurologique
```
```
SI Âge > 60 ans ET Diabète ET HTA
ALORS Risque élevé
     Consultation cardiologue
     ECG
     Bilan biologique
```

**b) Graphe décisionnel**
```
Début → Patient → Analyse clinique → Diagnostic → Choix du protocole
→ Traitement → Contrôle → Fin
```
Exemple de questionnaire arborescent : `Fièvre → Durée ? → Âge ? → Difficulté respiratoire ? → Maladies chroniques ? → Grossesse ? → …`

### 4.4 Modèle de stockage (voir CDC_04)
Tables : `protocoles`, `protocole_versions`, `protocole_regles`, `protocole_conditions`, `protocole_actions`, `protocole_questions`, `protocole_reponses`, `protocole_references`, `protocole_validations`, `protocole_conflits`, `protocole_applications` (journal d'exécution). Les conditions et actions utilisent les codes des référentiels nationaux (CIM-10/CIM-11, SNOMED CT, codes médicaments, codes actes — CDC_09) plutôt que du texte libre.

---

## 5. Domaines couverts

### 5.1 Protocoles ivoiriens (prioritaires)
- **Maladies infectieuses** : paludisme, tuberculose, VIH, hépatites, choléra, fièvre jaune, dengue.
- **Maladies chroniques** : HTA, diabète, asthme, insuffisance cardiaque, AVC.
- **Santé maternelle** : consultation prénatale, accouchement, pré-éclampsie, hémorragie, césarienne, suivi post-partum.
- **Santé infantile** : vaccination, croissance, nutrition, déshydratation, diarrhée, pneumonie.
- **Urgences** : traumatisme, AVC, infarctus, convulsions, brûlures, choc septique.

### 5.2 Protocoles OMS
Utilisés lorsque les recommandations nationales sont absentes ou incomplètes : santé mondiale, pandémies, vaccination, tuberculose, VIH, paludisme, santé mentale, nutrition, maternité, urgences. Particulièrement utiles pour les centres de santé ruraux, les ONG, la médecine humanitaire, la télémédecine et les missions internationales.

### 5.3 Protocoles spécialisés (un moteur de décision par spécialité)
- **Cardiologie** : insuffisance cardiaque, fibrillation atriale, infarctus, hypertension.
- **Neurologie** : AVC, épilepsie, Parkinson, Alzheimer.
- **Oncologie** : dépistage, chimiothérapie, radiothérapie, suivi.
- **Gynécologie** : grossesse, infertilité, cancer du col, ménopause.
- **Pédiatrie** : vaccination, croissance, nutrition, prématurité.
- **Psychiatrie** : dépression, anxiété, bipolarité, schizophrénie.
- **Dermatologie** : eczéma, psoriasis, ulcères, brûlures.

### 5.4 Protocoles de triage
Alimentent le questionnaire dynamique et le calcul de priorité (CDC_05 §5), avec les niveaux patient (4) et hospitaliers (5, inspirés du Manchester Triage System / ESI, **paramétrables par pays**).

---

## 6. Versionnage et cycle de vie

### 6.1 Versionnage
Chaque protocole possède : version, date, auteur, historique, validation, commentaires, état (**Actif**, **Archivé**, **Brouillon**).

| Version | Date | État | Modifications |
|---------|------|------|---------------|
| 1.0 | Janvier 2025 | Archivée | Création |
| 1.1 | Septembre 2025 | Archivée | Nouvelle posologie |
| 2.0 | Mars 2026 | Active | Mise à jour complète |
| 2.1 | Juin 2026 | Active | Ajout des contre-indications |

**Chaque décision clinique conserve la version exacte du protocole utilisée** — exigence médico-légale non négociable. Un protocole archivé reste consultable indéfiniment.

### 6.2 Cycle de vie
```
Rédaction → Relecture scientifique → Validation → Publication
→ Déploiement → Utilisation → Surveillance → Révision → Nouvelle version
```
Le déploiement d'une nouvelle version publie l'événement `ProtocolVersionPublished` (CDC_03 §8), ce qui déclenche la réindexation RAG (CDC_07) et l'invalidation des caches.

---

## 7. Validation scientifique (aucun protocole utilisable sans validation)

1. **Validation clinique** : médecins spécialistes, experts hospitaliers, sociétés savantes.
2. **Validation réglementaire** : Ministère de la Santé, programmes nationaux, autorités sanitaires compétentes.
3. **Validation scientifique** : publications revues par les pairs, essais cliniques, méta-analyses, recommandations internationales.
4. **Validation technique** : cohérence des règles, **absence de conflits entre protocoles**, tests automatiques, simulations sur cas cliniques.

Chaque validation est enregistrée (validateur, rôle, date, avis, commentaires) et opposable.

---

## 8. Gestion des conflits entre protocoles

Lorsque deux recommandations divergent, le moteur applique la stratégie de résolution suivante :
1. **Protocole national en priorité**
2. **Protocole le plus récent**
3. **Niveau de preuve scientifique le plus élevé**
4. **Avis de la spécialité concernée**
5. **Validation finale par le médecin**

Toutes les divergences sont consignées dans un **journal d'audit** afin de garantir la transparence des décisions. Un conflit non résolu automatiquement est présenté au médecin avec les deux recommandations et leurs sources.

---

## 9. Intégration avec le moteur d'IA

```
1. L'IA analyse les données du patient (symptômes, examens, antécédents).
2. Le moteur sélectionne les protocoles applicables.
3. Les règles sont évaluées.
4. L'IA propose une ou plusieurs prises en charge compatibles avec les protocoles.
5. Le médecin visualise les recommandations, leur niveau de preuve et les références utilisées.
6. La décision finale appartient toujours au professionnel de santé.
```
Cette architecture garantit que **les capacités de l'IA restent encadrées par des référentiels médicaux validés** (Rule-005).

### 9.1 Contrat d'API (exemples)
```
POST /api/v1/protocoles/evaluer
{ "pays_code": "CI", "patient": {...}, "contexte": "triage|consultation|urgence", "donnees_cliniques": {...} }
→ {
  "recommandations": [
    { "action": "HOSPITALISATION", "justification": "...", "niveau_preuve": "A",
      "protocole": { "id": "PROT-CI-PALU-GRAVE", "version": "2026.2" } }
  ],
  "questions_suivantes": [ ... ],
  "conflits": [],
  "trace_id": "eval-77213"
}

GET  /api/v1/protocoles?pays=CI&specialite=cardiologie&statut=actif
GET  /api/v1/protocoles/{id}/versions/{version}
POST /api/v1/protocoles/{id}/versions          (création de brouillon)
POST /api/v1/protocoles/{id}/versions/{v}/valider
POST /api/v1/protocoles/{id}/versions/{v}/publier
```

---

## 10. Sécurité et traçabilité

Chaque recommandation est historisée avec :
- identifiant du patient ;
- identifiant du professionnel de santé ;
- protocole utilisé ;
- **version exacte** du protocole ;
- date et heure d'exécution ;
- recommandations affichées ;
- décision finale du médecin ;
- **justification en cas d'écart avec le protocole**.

Cette traçabilité facilite les audits de qualité, les analyses de pratiques cliniques et l'amélioration continue. Journal immuable (CDC_10). Accès à l'édition des protocoles strictement réservé aux rôles habilités (comité scientifique, autorités), avec MFA obligatoire et double validation pour la publication.

---

## 11. Performance

- Évaluation d'un protocole < **100 ms** (P95).
- Protocoles actifs mis en cache (Redis) avec invalidation par événement lors d'une publication de version.
- Compilation des règles en structure exécutable en mémoire ; rechargement à chaud lors d'une mise à jour.
- Pas d'appel réseau externe pendant l'évaluation (les référentiels nécessaires sont préchargés).

---

## 12. Tests

- Tests unitaires du moteur d'inférence (conditions, opérateurs, priorités).
- **Simulations sur cas cliniques de référence** : chaque protocole publié doit passer une batterie de cas types validés par des médecins.
- Tests de détection de conflits entre protocoles.
- Tests de non-régression lors d'un changement de version (comparaison des sorties sur le corpus de cas).
- Tests des cas limites : nourrissons, femmes enceintes, insuffisants rénaux, polymédication, allergies, comorbidités multiples.
- Tests multi-pays : le même moteur, avec des référentiels différents, produit les recommandations propres à chaque pays.

---

## 13. Ordre de construction recommandé

1. Modèle de données des protocoles (CDC_04) + registre + versionnage + cycle de vie.
2. Moteur de règles (conditions, actions, opérateurs) + compilation + cache.
3. Sélecteur de protocoles + ordre de priorité + résolution de conflits + journal.
4. Questionnaire dynamique de triage (questions adaptatives) — permet le triage **sans IA** (Phase 1 du CDC_05).
5. Chargement des protocoles ivoiriens prioritaires : paludisme, urgences, santé maternelle, santé infantile.
6. Interface d'authoring + workflow de validation multicouche (clinique, réglementaire, scientifique, technique).
7. Intégration avec l'IA (CDC_05) puis avec le RAG (CDC_07).
8. Protocoles OMS, sociétés savantes, protocoles hospitaliers.
9. Protocoles spécialisés par discipline.
10. Extension multi-pays (Sénégal, Bénin, Mali…) : uniquement par ajout de données.

Chaque étape est testée et validée avant de passer à la suivante ; en cas de problème, correction ciblée de la seule partie fautive.

---

*Fin du CDC_08 — Architecture des Protocoles Médicaux.*
