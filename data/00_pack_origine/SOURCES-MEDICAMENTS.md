# Référentiel médicaments — sources accessibles depuis la Côte d'Ivoire

## Pourquoi la BDPM vous a refusé l'accès

Le site `bdpm.ansm.sante.fr` filtre une partie du trafic non français (pare-feu applicatif +
restriction géographique sur l'espace de téléchargement). Le message « non autorisé » ne veut pas
dire que les données sont fermées : la BDPM est publiée sous **Licence Ouverte / Open Licence**,
donc librement réutilisable. C'est seulement le chemin d'accès qui bloque.

### Trois contournements légitimes

**1. Passer par data.gouv.fr (recommandé).**
Le même jeu de données y est republié par l'ANSM, sans le filtrage du site d'origine :

| Fichier | Contenu | URL |
|---|---|---|
| `CIS_bdpm_officielle.zip` (3,3 Mo) | Le cœur de la base : spécialités, présentations, compositions, conditions de délivrance | `https://www.data.gouv.fr/api/1/datasets/r/fecf69dd-ca9f-4902-95dd-4e0ec6ab92f0` |
| `CIS_Pathologie.csv` (394 Ko) | Correspondance spécialité ↔ pathologie | `https://www.data.gouv.fr/api/1/datasets/r/f549a488-d0fb-4ded-8fe0-1bbd6f5653bd` |
| `BDPM_Pathologies.csv` (2,6 Ko) | Nomenclature des pathologies | `https://www.data.gouv.fr/api/1/datasets/r/99db70f3-24cb-45e4-8ee3-374398626807` |
| `CIS_RCP.zip` (153 Mo) | Résumés des caractéristiques du produit — utile pour le RAG, pas pour le référentiel | `https://www.data.gouv.fr/api/1/datasets/r/bdbe2367-1898-4848-ac85-6fe58a1bdf68` |

Page du jeu de données : https://www.data.gouv.fr/datasets/base-de-donnees-publique-des-medicaments-base-officielle

**2. Une API tierce qui réexpose la BDPM.**
`api-bdpm-graphql` (open source) interroge la base en GraphQL, ce qui évite de gérer les fichiers
plats à structure ancienne : https://github.com/axel-op/api-bdpm-graphql

**3. Un VPN / proxy sortant en France** — solution de dernier recours, correcte juridiquement
puisque la licence autorise la réutilisation, mais fragile pour un système en production.

---

## Mais la BDPM n'est probablement pas votre référentiel principal

C'est une base **française** : AMM françaises, prix français, marques françaises. Pour MaSanté,
elle est utile comme *source de structure* (DCI, formes, dosages, interactions) et comme jeu de test,
mais le référentiel national doit venir de sources ivoiriennes. Par ordre de pertinence :

### Source n°1 — Liste Nationale des Médicaments Essentiels de Côte d'Ivoire (2024)

99 pages, publiée par le Ministère de la Santé et hébergée par l'OMS. C'est la référence officielle
la plus récente, et c'est exactement le périmètre de votre référentiel national (CDC_09 / Chapitre 14).

Téléchargement direct :
`https://cdn.who.int/media/docs/default-source/essential-medicines/national-essential-medicines-lists-(neml)/afro_neml/cote-d-ivoire_neml_2024_compressed.pdf?sfvrsn=1ca7d1f2_1&download=true`

Page OMS : https://www.who.int/publications/m/item/cote-d-ivoire--liste-des-m-dicaments-pris-en-charge-par-la-cmu-(french)

Le PDF n'est pas structuré : il faudra une extraction de tableaux (`pdfplumber` ou `camelot` en
Python) puis une relecture. C'est du travail, mais c'est *la* bonne donnée.

### Source n°2 — Liste des médicaments pris en charge par la CMU (Côte d'Ivoire, 2022)

Version antérieure du même dépôt OMS, centrée sur la couverture CMU. Directement pertinente pour
votre module carte CMU numérique : elle vous donne le périmètre remboursable.

### Source n°3 — Répertoire mondial des LNME (OMS)

Les listes nationales de tous les pays, au même format. C'est ce qui rendra votre extension
multi-pays possible « par ajout de données » comme prévu au CDC_08 §13.10.
https://www.who.int/teams/health-product-policy-and-standards/assistive-and-medical-technology/essential-medicines/national-emls

### Source n°4 — Liste modèle OMS des médicaments essentiels

Le socle international. Utile pour compléter les champs manquants et pour valider vos classes
thérapeutiques.

---

## Pour les champs que la LNME ne couvre pas

| Besoin | Source | Accès |
|---|---|---|
| Classe thérapeutique normalisée | Index ATC/DDD du WHO Collaborating Centre | Consultation libre en ligne |
| Interactions médicamenteuses, codes normalisés | **RxNorm** (US National Library of Medicine) | API REST publique, sans clé |
| Effets indésirables, rappels de lots | **openFDA** | API publique, clé gratuite |
| Codes de diagnostic (CIM-11 / CIM-10) | **ICD-API** de l'OMS | Inscription gratuite, ou conteneur Docker à héberger chez vous |

---

## Recommandation d'architecture

Ne mélangez pas les sources dans une table unique. Prévoyez, comme le décrit le Chapitre 14 :

```
medicament_national          ← LNME Côte d'Ivoire (source de vérité, statut VALIDE)
  ├── code_national          (MED000458…)
  ├── dci                    ← normalisée via RxNorm / ATC
  ├── classe_atc             ← ATC/DDD
  ├── prix_homologue         ← LNME / CMU
  └── pris_en_charge_cmu     ← liste CMU

medicament_source_externe    ← BDPM, RxNorm (statut SOURCE_EXTERNE)
  └── sert uniquement à pré-remplir et à enrichir, jamais à prescrire
```

La règle du CDC_00 s'applique : **CDC_09 est la source unique de vérité des référentiels**. Tout ce
qui vient d'ailleurs entre avec le statut `source_externe_non_validee` et ne devient officiel
qu'après validation.
