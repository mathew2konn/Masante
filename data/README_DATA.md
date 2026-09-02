# README_DATA — corpus de données nationales MaSanté

**Produit à la phase 2 du brief du 22 août 2026.** Chaque fichier de `data/` a été ouvert et analysé
par script (bibliothèque standard Python — aucune dépendance ajoutée pour un travail d'inventaire).
Les volumétries ci-dessous sont **mesurées**, jamais déduites d'un nom de fichier.

**Statut de tout ce corpus : `source_externe_non_validee`.** Rien n'en sort sans la validation en
quatre volets du CDC_08 §7 (clinique, réglementaire, scientifique, technique).

---

## 1. Inventaire

### 01_referentiels/lnme — Liste Nationale des Médicaments Essentiels

| Fichier | Format | Volumétrie | Source | Usage prévu |
|---|---|---|---|---|
| `LNME_CI_2024.csv` | CSV UTF-8, 14 colonnes | **3 873 lignes**, 842 Ko | LNME Côte d'Ivoire 2024 (extraction PDF) | Alimente le référentiel des médicaments + le matériel biomédical |
| `LNME_CI_2024.json` | JSON | 1,8 Mo | idem | Même contenu, forme imbriquée |
| `extraire_lnme.py` | Python | script | — | **Traçabilité de l'extraction — à conserver** |

**Colonnes** : `annexe`, `liste`, `section_code`, `section`, `sous_section_code`, `sous_section`,
`designation`, `dosage`, `voie`, `forme`, `niveau`, `categorisation`, `usage_pediatrique`, `page_pdf`.

**Remplissage mesuré** : `designation`, `niveau`, `annexe`, `liste`, `section` à 100 % ·
`dosage` 99,3 % · `voie` 99,8 % · `forme` 99,9 % · `sous_section` 63,6 % ·
**`categorisation` 2,9 % seulement (111 lignes)**.

**Cardinalités** : 3 199 désignations distinctes · 529 dosages · **24 voies** · **139 formes** ·
5 valeurs de niveau · 62 sections · 3 annexes.

**Répartition par annexe** : Annexe 2 (Matériel Bio-Médical) 2 488 · Annexe 1 (Médicaments
Essentiels) 1 254 · Annexe 3 (Liste Pédiatrique) 131.

**`categorisation`** ne prend que 3 valeurs — `Accès` (60), `Surveillance` (45), `Réserve` (6) :
c'est la classification **AWaRe de l'OMS** pour les antibiotiques, pas une catégorie générale.

### 01_referentiels/prix — prix homologués

| Fichier | Format | Volumétrie | Source |
|---|---|---|---|
| `referentiel_prix_homologue.csv` | CSV, 22 colonnes | **177 lignes** (117 HTA + 60 DIABETE) | CNAM/CMU — **Décision n° 0016/AIRP/DG du 29/09/2025** |
| `V1__referentiel_prix.sql` | SQL **PostgreSQL** | schéma `referentiel` | — |
| `V2__seed_prix_homologue_cmu.sql` | SQL | seed des 177 lignes | — |
| `V3__sources_prix_complementaires.sql` | SQL | sources complémentaires | — |
| `verify.sql` | SQL | **8 contrôles** exécutables | — |

**Mesuré** : `date_effet` = 2025-09-29 sur les 177 lignes · `date_fin` vide partout (aucune clôture) ·
devise `XOF` unique · prix de **871 à 16 723 XOF** (moyenne 5 838), **aucun prix ≤ 0** ·
`niveau_confiance` = 3 partout · `statut_validation` = `source_externe_non_validee` partout ·
`source_code` = `CNAM_CMU` unique.

**Dialecte SQL** : ces migrations sont du **PostgreSQL** — `CREATE SCHEMA`, `uuid-ossp`, `plpgsql`,
`RETURNS trigger` et **`pg_trgm`** y sont utilisés. Décision propriétaire du 22/08/2026 : le
référentiel devient un **microservice PostgreSQL** dédié.

### 02_protocoles

| Emplacement | Contenu | Volumétrie |
|---|---|---|
| `ci/PROT-CI-PALU-2022.json` | Paludisme, PNLP · version **2022.1** · état **BROUILLON**, `validations: []` | 14 variables d'entrée, **10 règles**, 4 traitements, 2 arbres de décision |
| `who-smart-anc/ANC.DT.*.json` | OMS SMART ANC — **16 tables**, **385 règles** (comptées) | 17 fichiers JSON |
| `who-smart-anc/sources/cql-l3/` | **63 fichiers CQL** (HL7 Clinical Quality Language) | 552 Ko — sources de référence, non exécutables ici |
| `who-smart-anc/sources/ANC Test Cases.xlsx` | **Cas de test officiels OMS** | 83 Ko |
| `who-smart-anc/sources/WHO-SRH-21.2-eng.xlsx` | Document source OMS | 621 Ko |
| `who-smart-anc/sources/LICENCE-WHO-SMART-ANC.md` | **CC BY-NC-SA 3.0 IGO** | — |
| `moteur/` | Moteur TypeScript de référence | **685 lignes** (`moteur.ts` 242, `conditions.ts` 224, `posologie.ts` 104, `types.ts` 115) |

Structure d'une table ANC : `id`, `titre`, `source`, `statut`, `version`, `pays_applicable`,
`declencheur`, `regle_metier`, `regles`. Contenu **en anglais**.

### 03_jeux_de_test/synthea

30 patients, **tous du Massachusetts (États-Unis)**. 5 736 observations · 1 217 consultations ·
740 médicaments · 785 actes · 332 vaccinations · 216 diagnostics · 104 plans de soins ·
30 allergies · 19 imageries.

Pathologies dominantes : sinusite virale (43), pharyngite aiguë (14), bronchite aiguë (14),
grossesse normale (12), obésité (11), prédiabète (8), anémie (7). **Occurrences de paludisme : 0.**

### 00_pack_origine, 04_specifications, 05_pharmacies, terminologies

- `00_pack_origine/` — `README.md` et `SOURCES-MEDICAMENTS.md` du pack livré. **Hors de
  l'arborescence prévue par le brief** : conservés tels quels, sans renommage, plutôt que supprimés.
- `04_specifications/` — **délibérément vide.** Les 14 cahiers des charges vivent déjà dans
  `CDC.md/` à la racine, et les documents de conception dans `docs/`. Les y recopier créerait deux
  exemplaires d'un même cahier des charges, donc deux vérités qui finiraient par diverger. *Le brief
  déclare CDC_03 à CDC_07, CDC_09 et CDC_10 « absents du corpus » : c'est exact du pack livré, mais
  faux du dépôt — les 14 y sont.*
- `05_pharmacies/` et `01_referentiels/terminologies/` — vides, en attente (voir `TODO-DONNEES.md`).

---

## 2. Les pièges annoncés par le brief : ce que la mesure confirme, et ce qu'elle corrige

| # | Piège annoncé | Vérification |
|---|---|---|
| 1 | Ligature `Ǫ` au lieu de `Q` | ❌ **Aucune occurrence dans le CSV.** Le piège est **déjà traité en amont** : `extraire_lnme.py` porte `REMPLACEMENTS = {'Ǫ': 'Q', 'ǫ': 'q'}`. Une normalisation NFKD de plus serait inutile — **mais une ré-extraction sans ce script le ferait revenir.** |
| 2 | 2 487 entrées à voie `---` = consommables | ✅ **Exactement 2 487.** ⚠️ Mais c'est un **critère faux** : 6 produits de l'**annexe 1** (donc des médicaments) portent `---` — Ammoniaque, Glutaraldéhyde, Dichloro-isocyanate de sodium, Trioxyméthylène, Sulfadiazine argentique, un lubrifiant. **Le critère fiable est `annexe`, jamais `voie`.** |
| 3 | Niveau A–E, 466 en A, 120 en E | ✅ Chiffres exacts. ⚠️ **Structure différente de ce que le brief laisse entendre** : `niveau` n'est pas une lettre mais une **chaîne cumulative** — 5 valeurs seulement (`A` 466, `AB` 1 688, `ABC` 1 008, `ABCD` 591, `ABCDE` 120), toujours un **préfixe contigu depuis A**. Se modélise par « échelon le plus bas autorisé ». |
| 4 | `Catégorisation` décale la colonne Niveau en annexe 3 | ✅ **Aucun décalage constaté** : les 131 lignes de l'annexe 3 portent des niveaux plausibles (`ABCDE` 19, `ABCD` 65, `ABC` 22, `AB` 16, `A` 9). Le script d'extraction a absorbé la divergence d'en-tête. |
| 5 | 37 libellés incomplets sans conditionnement | ✅ **Exactement 37**, et **`conditionnement_qte` est vide sur les 37**. Ces prix ne peuvent pas s'afficher comme prix fermes. |
| 6 | Les 177 lignes ont `medicament_id` à NULL | ✅ **0/177 renseignés.** ⚠️ **Et `dci_code` est vide lui aussi (0/177)** — le brief ne le signalait pas. Le rapprochement avec la LNME n'a donc **aucun point d'accroche structuré** : il devra se faire sur le libellé. |
| 7 | Synthea sans paludisme | ✅ **0 occurrence**, 30 patients du Massachusetts. |
| 8 | Protocoles OMS en anglais | ✅ Confirmé. Traduction et validation médicale requises avant tout usage. |

### Trois anomalies que le brief ne mentionnait pas

1. **`usage_pediatrique` ne dit pas ce que son nom promet.** Les 131 valeurs `True` sont dans
   l'**annexe 1** ; les 131 lignes de l'**annexe 3 — Liste Pédiatrique** portent toutes `False`.
   Les mêmes produits figurent donc **deux fois** dans le fichier, sous deux annexes différentes.
   → Charger 3 873 lignes comme 3 873 produits distincts serait une erreur.
2. **105 clés `designation + dosage + forme` en double** — conséquence directe du point précédent,
   plus des répétitions internes aux annexes.
3. **Marqueurs de valeur absente hétérogènes** : `''`, `---` (voie) et `----` (dosage) coexistent
   dans le même fichier. Une normalisation unique est nécessaire à l'ingestion.

---

## 3. Ce que le corpus permet, et ce qu'il ne permet pas

**Il permet** : un vrai référentiel de médicaments (3 873 entrées, contre 18 de démonstration
aujourd'hui) · un référentiel de prix opposable avec sa référence réglementaire · un protocole
ivoirien réel et deux corpus OMS · un jeu de test structurellement complet, avec des cas de test
officiels.

**Il ne permet pas :**

- **d'entraîner un modèle de triage.** Synthea, ce sont 30 patients américains sans paludisme, et
  **aucun fichier ne relie un triage MaSanté à son issue clinique**. Le label retenu par le
  propriétaire (issues réelles) n'existe donc pas encore ; il se constituera par l'usage, via la
  boucle ouverte en P10c-2-i partie A. La décision **F5 reste en vigueur**.
- **de publier le protocole PALU en l'état.** Son `cycle_de_vie` est `BROUILLON` avec
  `validations: []` — exactement le régime que la décision N3 de P10b-1 impose. Il s'importe, il ne
  se publie pas sans les quatre validations du §7. De plus, ses `condition` sont des **expressions
  JavaScript** (`signes_gravite.length > 0`), que le moteur de P10b refuse au profit d'une liste
  blanche d'opérateurs : une **transposition** est nécessaire, ce n'est pas un import direct.
- **de produire des statistiques épidémiologiques ivoiriennes** à partir de Synthea.

---

## 4. Voir aussi

- `MODELE_DONNEES_CIBLE.md` — les entités qui découlent de cet inventaire.
- `TODO-DONNEES.md` — ce qui manque, et ce que cela bloque.
