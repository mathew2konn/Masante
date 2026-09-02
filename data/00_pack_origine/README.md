# Paquet de données MaSanté — protocoles, référentiels et données de test

Assemblé le 22 août 2026. Tout ce qui suit est en accès libre, mais **rien n'a de valeur officielle
tant que le comité scientifique ne l'a pas validé** (CDC_08 §7). Chaque protocole porte le statut
`source_externe_non_validee`.

```
masante-pack/
├── protocoles/
│   ├── PROT-CI-PALU-2022.json          Paludisme — Côte d'Ivoire, structuré pour le moteur
│   ├── oms-anc/                        16 tables de décision OMS (soins prénatals) en JSON
│   │   ├── _index.json
│   │   └── ANC.DT.*.json               385 règles au total
│   └── sources/
│       ├── WHO-SRH-21.2-eng.xlsx       Le classeur L2 d'origine (tables de décision)
│       ├── ANC Test Cases.xlsx         Cas de test officiels OMS
│       ├── cql-l3/                     63 fichiers CQL — la logique L3 exécutable
│       └── LICENCE-WHO-SMART-ANC.md
├── referentiels/
│   └── SOURCES-MEDICAMENTS.md          Contournement BDPM + sources ivoiriennes
└── donnees-test/
    └── synthea/                        30 patients synthétiques, 9 fichiers CSV
```

---

## 1. Paludisme — Côte d'Ivoire

`protocoles/PROT-CI-PALU-2022.json`

Transcrit depuis le **Guide national de prise en charge du paludisme**, PNLP Côte d'Ivoire,
mars 2022. Le fichier suit la structure de votre CDC_08 : variables d'entrée typées, règles
priorisées avec conditions et actions, tables de posologie par tranche de poids, arbres de décision
et champs de traçabilité.

Ce qu'il contient : critères de gravité OMS (cliniques et biologiques), algorithme diagnostique
par niveau de structure (ASC / ESPC / structure de référence), les 4 CTA de première intention avec
posologies complètes, quinine orale, traitement du paludisme grave, traitement pré-transfert,
grossesse par trimestre, TPIg, interactions ARV, arbre d'échec thérapeutique, calendrier de suivi
J3/J7/J14.

> ⚠️ **Attention à un piège.** Le PDF qui circule le plus sur le web sous le nom « Directives de
> prise en charge du paludisme » est celui de la **République Centrafricaine (2013)**, pas de la
> Côte d'Ivoire. Les posologies diffèrent. Le document ivoirien correct est celui de mars 2022,
> hébergé par le PNLP : `https://www.pnlpcotedivoire.org/fichiers_uploades/2023/06/fichier_joint_152`

## 2. Soins prénatals — OMS SMART Guidelines

`protocoles/oms-anc/`

C'est la pièce la plus précieuse du lot. L'OMS publie ses recommandations de soins prénatals sous
forme de **tables de décision déjà formalisées** — 16 tables, 385 règles, chacune avec sa condition,
sa sortie, son action, son annotation clinique et sa référence bibliographique.

Contenu couvert : signes de danger, évaluation HEADSS, examen physique, examens de laboratoire et
imagerie, conseils comportementaux et diététiques, diagnostic et traitement, anémie et fer-folates,
nutrition (calcium, vitamine A), conseils sur les risques, vaccinations, violences conjugales (IPV),
déparasitage et paludisme, plus les calendriers de contacts et de prophylaxie antipaludique.

Les JSON sont directement chargeables par un *seeder*. Le classeur d'origine et les 63 fichiers CQL
sont dans `sources/` — la logique CQL vous servira de référence si vous formalisez plus tard votre
propre langage de règles.

**Le contenu est en anglais.** Il faudra le traduire avant usage clinique, et cette traduction devra
elle-même être validée médicalement.

## 3. Données de test — Synthea

`donnees-test/synthea/`

30 patients synthétiques complets, extraits de l'échantillon officiel MITRE :

| Fichier | Lignes |
|---|---|
| patients.csv | 30 |
| encounters.csv | 1 217 |
| observations.csv | 5 736 |
| procedures.csv | 785 |
| medications.csv | 740 |
| immunizations.csv | 332 |
| conditions.csv | 216 |
| careplans.csv | 104 |
| allergies.csv | 30 |

Aucune donnée réelle, aucune contrainte de confidentialité, codes SNOMED / LOINC / RxNorm
standards. Deux réserves : la population est américaine (noms, adresses, assureurs, épidémiologie),
et il n'y a pas de paludisme. Pour vos tests de triage il faudra soit générer un module Synthea
ivoirien, soit fabriquer une trentaine de cas cliniques locaux à la main — c'est de toute façon ce
que le CDC_08 §12 exige (« batterie de cas types validés par des médecins »).

L'échantillon complet — 1 171 patients, en CSV, FHIR R4/STU3/DSTU2 ou C-CDA — est là :
https://github.com/synthetichealth/synthea-sample-data

## 4. Référentiel médicaments

`referentiels/SOURCES-MEDICAMENTS.md` — pourquoi la BDPM vous refuse l'accès, trois contournements,
et surtout les sources ivoiriennes qui devraient être votre référentiel principal (LNME 2024,
liste CMU).

---

## Ce qui manque encore : la PCIME

Je n'ai pas pu récupérer le recueil de tableaux PCIME — les serveurs de l'OMS (`iris.who.int`,
`apps.who.int`) ont refusé la requête automatisée. Les liens sont valides depuis un navigateur :

- Recueil des tableaux, nourrisson 0–2 mois (2019) :
  https://apps.who.int/iris/bitstream/handle/10665/329450/9789242516364-fre.pdf
- Manuel PCIME complet, 172 pages (2005) :
  https://www.who.int/fr/publications/i/item/9241546441

Téléchargez-en un et déposez-le ici, je le convertirai au même format que le protocole paludisme.

---

## Licences

| Source | Licence | Conséquence |
|---|---|---|
| OMS (SMART Guidelines, PCIME) | CC BY-NC-SA 3.0 IGO | Attribution obligatoire, pas d'usage commercial sans autorisation |
| PNLP Côte d'Ivoire | Document public | Citer le PNLP et l'édition (mars 2022) |
| Synthea | Apache 2.0 | Libre |
| BDPM | Licence Ouverte | Libre avec attribution |

Le point de vigilance pour MaSanté : la clause **NC** de l'OMS. Si la plateforme devient un service
payant, il faudra une autorisation de l'OMS ou remplacer ces protocoles par des versions nationales.
À traiter dans un ADR avant la mise en production.

---

## Prochaine étape suggérée

1. Charger `PROT-CI-PALU-2022.json` dans le moteur de règles et le faire tourner sur 10 cas de test.
2. Vérifier chaque posologie ligne par ligne contre le PDF d'origine — une erreur de transcription
   sur un dosage pédiatrique n'est pas un bug ordinaire.
3. Faire relire le fichier par un médecin avant de passer l'état de `BROUILLON` à `ACTIF`.
