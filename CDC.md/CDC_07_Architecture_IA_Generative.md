# CAHIER DES CHARGES N°7 — ARCHITECTURE IA GÉNÉRATIVE
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Ce document couvre le **LLM privé, le RAG, la base vectorielle, les embeddings, le prompt engineering et le fine-tuning**. L'IA prédictive (ML, vision, OCR, triage) est traitée dans le **CDC_05** ; les protocoles médicaux qui encadrent toute réponse dans le **CDC_08**.

Dépendances : CDC_03 (intégration backend), CDC_04 (sources documentaires), CDC_05 (moteurs IA complémentaires), CDC_08 (protocoles prioritaires), CDC_09 (référentiels nationaux), CDC_10 (confidentialité, audit), CDC_13 (gouvernance des données).

---

## 1. Vision et principes non négociables

1. **L'IA générative n'est pas un médecin** : elle assiste patients, médecins, infirmiers, pharmaciens et administrations. Elle agit comme un **copilote**.
2. **Rule-005** : aucune réponse influençant une décision médicale sans explication, sources, score de confiance et limites.
3. **Confidentialité absolue** : les données médicales ne quittent **jamais** les serveurs MASANTÉ. Le LLM est **privé et auto-hébergé**.
4. **Les protocoles priment** : toute réponse clinique s'appuie d'abord sur les protocoles officiels (CDC_08). Le LLM ne « décide » jamais d'un traitement.
5. **Jamais de diagnostic définitif** : formulation prudente imposée.
6. **RAG obligatoire** pour toute réponse à contenu médical ou institutionnel : pas de réponse « de mémoire » du modèle sur des sujets où une source MASANTÉ existe.
7. **Traçabilité complète** : chaque réponse est journalisée avec le prompt (hors données sensibles en clair), les sources récupérées, la version du modèle et l'identifiant de l'utilisateur.

---

## 2. Architecture générale

```
Utilisateur (patient, médecin, infirmier, pharmacien, agent)
        │
   Question / Document
        │
   API IA Générative MASANTÉ (FastAPI)
        │
 ┌──────┴────────────────────────────┐
 │ Prompt Builder │ Vérification Sécurité │
 └──────┬────────────────────────────┘
        │
    Moteur RAG
 ┌──────┼───────────────────────┐
 │ Base Vectorielle (Qdrant) │ LLM Privé │ Sources externes (contrôlées) │
 └──────┼───────────────────────┘
        │
  Réponse contextualisée + sources + confiance + limites
        │
  Journalisation / Audit  →  Interface utilisateur
```

Architecture **hybride** : IA privée hébergée par MASANTÉ (principale) + IA cloud **optionnelle** (jamais pour des données patient), base documentaire privée, RAG, base vectorielle, moteur de prompts, fine-tuning spécialisé santé ivoirienne.

---

## 3. LLM privé

### 3.1 Pourquoi privé
Les données manipulées (dossiers patients, radios, IRM, analyses biologiques, comptes rendus opératoires, données CNAM, historiques médicaux) sont extrêmement sensibles. Le LLM privé garantit : aucune fuite de données, contrôle total, audit complet, chiffrement, journalisation, conformité réglementaire, rapidité, personnalisation, indépendance.

### 3.2 Modèles candidats

| Modèle | Usage cible |
|--------|-------------|
| **Llama 3** | Assistant médical (usage principal) |
| **Mistral** | Chat intelligent |
| **Qwen** | Multilingue |
| **DeepSeek** | Raisonnement |
| **Gemma** | Déploiement mobile / léger |
| **Phi** | Appareils peu puissants |

Le choix définitif fait l'objet d'un **ADR** (Rule-003). Plusieurs modèles peuvent coexister derrière une abstraction commune (OCP) :
```python
class LLMProvider(Protocol):
    def generate(self, prompt: Prompt, context: list[Document]) -> LLMResponse: ...
```

### 3.3 Déploiement
Serveur d'inférence conteneurisé (Docker/Kubernetes), GPU dédiés, autoscaling, quotas par appelant, timeouts stricts, file d'attente pour les requêtes longues, streaming de la réponse token par token vers le client.

---

## 4. RAG (Retrieval Augmented Generation)

### 4.1 Problème résolu
Un LLM ne connaît ni le dossier du patient, ni les protocoles internes, ni les guides du ministère, ni les médicaments disponibles, ni les décisions de l'hôpital. Le RAG recherche les informations pertinentes **avant** de générer la réponse.

### 4.2 Fonctionnement
```
Question utilisateur → Contrôle de sécurité et d'autorisation
→ Recherche documentaire (base vectorielle + filtres d'accès)
→ Documents pertinents (Top K) → Construction du prompt
→ LLM → Réponse + citation des sources
```

### 4.3 Exemple imposé
Patient : « J'ai une glycémie de 2,4 g/L. Est-ce dangereux ? »
Le RAG recherche : recommandations officielles, protocole diabète national, dossier patient, antécédents. Le LLM répond en tenant compte de ces éléments, cite ses sources, et oriente sans poser de diagnostic.

### 4.4 Avantages attendus
Informations à jour, réduction des hallucinations, réponses personnalisées, traçabilité des sources, amélioration de la précision clinique.

### 4.5 Filtrage d'accès (critique)
La recherche vectorielle applique **systématiquement** les règles RBAC/ABAC (CDC_10) : un utilisateur ne peut jamais récupérer un document auquel il n'a pas droit. Le filtrage se fait **avant** l'envoi au LLM (filtrage au niveau de la requête vectorielle, jamais en post-traitement).

---

## 5. Base vectorielle

### 5.1 Choix retenu
**Qdrant** (rapide, open source, déployable sur infrastructure privée). Milvus est l'alternative pour très grande échelle. pgvector, Weaviate, Chroma et Pinecone sont écartés du choix principal (Pinecone étant cloud managé, incompatible avec l'exigence de souveraineté pour les données patient).

### 5.2 Documents indexés
Dossiers médicaux, prescriptions, comptes rendus, examens biologiques, protocoles, guides thérapeutiques, textes réglementaires, recommandations OMS, recommandations nationales, FAQ patients, documentation interne, catalogues (médicaments, analyses, actes), documents de formation.

### 5.3 Organisation
Collections séparées par sensibilité et par domaine (`protocoles`, `referentiels`, `faq_publique`, `documents_etablissement`, `dossiers_patients`), avec métadonnées obligatoires par vecteur : `document_id`, `type`, `pays_code`, `etablissement_id`, `patient_id` (si applicable), `niveau_confidentialite`, `version`, `date_publication`, `source`, `langue`. Ces métadonnées servent au filtrage d'accès et à la citation.

### 5.4 Cycle de vie
Indexation lors de la création/mise à jour du document (déclenchée par événement — CDC_03 §8), réindexation lors d'un changement de version de protocole, suppression du vecteur lors de la suppression/anonymisation du document source.

---

## 6. Embeddings

### 6.1 Principe
Transformation d'un texte en vecteur numérique représentant son **sens**, permettant une recherche par similarité sémantique : « Le patient est diabétique » et « Cette personne présente un diabète » sont proches malgré des formulations différentes.

### 6.2 Pipeline
```
Texte → Tokenizer → Embedding Model → Vecteur → Base vectorielle (Qdrant)
```

### 6.3 Modèles candidats
BAAI BGE, E5, Instructor, Jina Embeddings, Nomic Embed, Sentence Transformers. Le modèle retenu est **versionné** : tout changement impose une **réindexation complète** (les vecteurs de modèles différents ne sont pas comparables).

### 6.4 Usages
Recherche documentaire, recherche de symptômes, recherche de médicaments, rapprochement de dossiers similaires, détection de doublons (appui au MPI — CDC_09), aide au diagnostic, recommandations personnalisées.

---

## 7. Prompt Engineering

### 7.1 Construction automatique
Chaque prompt est construit **automatiquement** par le `Prompt Builder` selon : le profil de l'utilisateur (rôle, spécialité, établissement), le contexte clinique, la langue, le pays et les règles de sécurité. Les prompts sont **versionnés** et stockés (jamais en dur dans le code applicatif dispersé).

### 7.2 Structure imposée
```
SYSTEM
  Tu es un assistant médical.
  Tu aides les professionnels de santé.
  Tu ne poses jamais un diagnostic définitif.
  Tu cites systématiquement tes sources.
  Tu respectes les protocoles ivoiriens (ou du pays de l'établissement).
  Si l'information n'est pas dans les sources fournies, tu le dis explicitement.
------------------------------
CONTEXTE
  Patient : 45 ans, diabétique, HTA, derniers examens...
  Documents récupérés : [source 1], [source 2], [source 3]
------------------------------
QUESTION
  Quels sont les risques ?
```

### 7.3 Types de prompts
- **Prompt système** : définit le rôle (ex. « assistant clinique spécialisé en médecine interne »).
- **Prompt utilisateur** : la demande.
- **Prompt contextuel** : antécédents, traitements, résultats biologiques, allergies, âge, poids, sexe, grossesse, examens.
- **Prompt de sécurité** : interdit explicitement les diagnostics définitifs sans validation médicale, les prescriptions dangereuses, la divulgation de données personnelles, les recommandations contraires aux protocoles.

### 7.4 Bonnes pratiques obligatoires
Instructions explicites ; ne fournir que le contexte nécessaire (minimisation des données) ; définir le format attendu (tableau, résumé, liste) ; exiger la citation des sources quand le RAG est utilisé ; tester les prompts sur différents cas cliniques pour vérifier leur robustesse.

### 7.5 Garde-fous techniques
- **Filtrage d'entrée** : détection d'injection de prompt, de tentative d'exfiltration de données, de demande hors périmètre.
- **Filtrage de sortie** : détection de diagnostic affirmatif, de posologie non validée, de données personnelles d'un tiers, de contenu contraire aux protocoles. Toute sortie non conforme est bloquée et journalisée.
- **Refus contrôlé** : si l'information n'est pas disponible dans les sources, la réponse doit l'indiquer plutôt qu'inventer.

---

## 8. Cas d'usage à implémenter

| Utilisateur | Fonctionnalité |
|-------------|----------------|
| Patient | Réponses vulgarisées à ses questions, explication de résultats biologiques, explication d'une ordonnance, rappels et conseils de prévention, chatbot vocal (TTS/STT — CDC_05) |
| Médecin | Recherche clinique en langage naturel avec réponse argumentée et sources ; résumé automatique d'un dossier volumineux (diagnostics, allergies, traitements, examens, interventions, événements importants) ; génération documentaire (compte rendu de consultation, compte rendu opératoire, lettre de sortie, certificat médical, ordonnance préremplie **à valider**, synthèse d'hospitalisation) ; traduction de documents |
| Infirmier | Synthèse de l'état du patient, aide à la rédaction des transmissions |
| Pharmacien | Explication d'interactions, alternatives et génériques disponibles (référentiel CDC_09) |
| Administration | Rédaction de documents administratifs, synthèses de rapports |

**Toute génération documentaire est un brouillon** : validation et signature électronique par le professionnel obligatoires avant intégration au dossier (CDC_09 §signature, CDC_10).

---

## 9. Fine-Tuning

### 9.1 Objectifs
Adapter un LLM généraliste aux réalités du système de santé ivoirien : terminologie médicale locale, protocoles nationaux, maladies les plus fréquentes, formulaires administratifs, précision de l'assistance aux professionnels.

### 9.2 Jeux de données autorisés
Comptes rendus médicaux, protocoles thérapeutiques, recommandations nationales, documents de formation, FAQ médicales, échanges validés entre médecins et assistants IA. **Toutes les données doivent être anonymisées** et conformes aux exigences de confidentialité avant utilisation.

### 9.3 Pipeline imposé
```
Collecte des données → Nettoyage → Anonymisation → Annotation
→ Jeu d'entraînement → Fine-Tuning → Validation clinique
→ Déploiement du nouveau modèle
```

### 9.4 Évaluation avant mise en production
Exactitude des réponses, **taux d'hallucination**, conformité aux protocoles, pertinence des sources RAG, temps de réponse, satisfaction des professionnels de santé, robustesse face aux cas complexes. Un modèle qui régresse sur l'un de ces critères n'est pas déployé.

### 9.5 Versionnage
Chaque modèle (base, fine-tuné) est enregistré dans **MLflow** avec version, jeu de données, métriques, date de validation clinique, responsable et statut. Rollback immédiat possible.

---

## 10. Sécurité, confidentialité et gouvernance

- **Hébergement privé** : LLM, base vectorielle et embeddings sur l'infrastructure MASANTÉ (cloud privé gouvernemental / datacenters nationaux). Aucune donnée patient envoyée à un service tiers.
- **Chiffrement** : TLS 1.3 en transit, AES-256 au repos (index vectoriels, documents, journaux), clés en HSM/KMS.
- **Authentification et autorisation** : appels via l'AI Gateway avec JWT, mTLS interne (Service Mesh), RBAC/ABAC appliqués au filtrage documentaire.
- **Minimisation** : ne transmettre au LLM que le contexte strictement nécessaire ; pseudonymisation des identifiants dans les prompts quand c'est possible.
- **Journalisation et audit** : chaque interaction tracée (utilisateur, horodatage, sources utilisées, version du modèle et du prompt, décision finale) dans un journal immuable (CDC_10).
- **Rétention** : durée de conservation des conversations définie et documentée ; purge automatique.
- **Supervision humaine** : aucune sortie de l'IA générative n'est intégrée au dossier médical sans validation d'un professionnel.
- **Détection d'abus** : quotas, rate limiting, détection d'usage anormal, blocage des tentatives d'extraction massive de données.

---

## 11. Performance et exploitation

- Latence cible : première réponse (streaming) < 2 s ; réponse complète courte < 5 s.
- Cache sémantique des questions fréquentes (FAQ, explications standards) avec invalidation lors d'un changement de protocole.
- Observabilité : métriques Prometheus (latence, tokens/s, taux d'erreur, utilisation GPU, taux de refus, taux de citation de sources), traces OpenTelemetry, logs structurés vers stdout.
- Health checks `/health` et `/ready` ; autoscaling ; isolation des charges GPU.
- **Dégradation gracieuse** : si le LLM est indisponible, le système renvoie les documents pertinents trouvés par le RAG (recherche seule) avec mention explicite que la synthèse IA est momentanément indisponible.

---

## 12. Tests

- Tests unitaires : Prompt Builder, filtres de sécurité, découpage documentaire (chunking), pipeline d'embedding.
- Tests d'intégration RAG : la réponse cite-t-elle des sources ? les sources sont-elles autorisées pour cet utilisateur ?
- **Tests d'autorisation croisée** : un médecin ne doit jamais récupérer le document d'un patient qu'il ne suit pas.
- Tests d'injection de prompt et d'exfiltration.
- Tests de non-régression clinique sur un corpus de cas de référence (avant/après changement de modèle ou de prompt).
- Mesure du taux d'hallucination sur un jeu de questions à réponse connue.
- Tests de charge et de latence.

---

## 13. Ordre de construction recommandé

1. Socle FastAPI + AI Gateway + observabilité + abstraction `LLMProvider`.
2. Ingestion documentaire : chunking, métadonnées, pipeline d'embedding, indexation Qdrant (commencer par les documents publics : FAQ, protocoles, référentiels).
3. RAG en lecture seule sur documents non sensibles + citation des sources.
4. Prompt Builder + prompts système/sécurité versionnés + filtres d'entrée/sortie.
5. LLM privé déployé (Llama 3 ou modèle retenu par ADR) avec streaming.
6. Extension du RAG aux documents d'établissement, puis aux dossiers patients **avec filtrage RBAC/ABAC strict** (étape la plus sensible : tests d'autorisation exhaustifs avant activation).
7. Cas d'usage patient (explications vulgarisées), puis médecin (résumé de dossier, génération documentaire en brouillon).
8. Fine-tuning sur données anonymisées + validation clinique.
9. Cache sémantique, optimisation de latence, durcissement, tests de charge.

Chaque étape est testée et validée avant de passer à la suivante ; en cas de problème, correction ciblée de la seule partie fautive.

---

*Fin du CDC_07 — Architecture IA Générative.*
