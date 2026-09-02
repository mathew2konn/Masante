# TODO-DONNEES — ce qui manque, et ce que cela bloque

**Livrable (c) de la phase 2.** Règle du brief : *« Tout ce que tu ne peux pas faire faute de
données part ici, jamais en valeur inventée dans le code. Un prix plausible écrit en dur dans une
application de santé est une faute, pas un raccourci. »*

Chaque manque porte son **impact fonctionnel** et son **niveau de blocage**.

---

## 1. Bloquants — le module Pharmacie ne peut pas fonctionner sans

| # | Donnée manquante | Ce que ça bloque | Nature |
|---|---|---|---|
| **D1** | **Officines** : raison sociale, adresse, **coordonnées GPS**, téléphone, commune | L'écran d'accueil Pharmacie (§4.1) : « liste triée par proximité » est **impossible** sans coordonnées. Aucune distance, aucun itinéraire, aucun tri. | Donnée terrain / DPM |
| **D2** | **Horaires d'ouverture** par officine | Le statut « ouvert / fermé » (§4.1) et le filtrage. | Donnée terrain |
| **D3** | **Calendrier de garde** officiel, par période et par commune | Le bouton Garde (§4.2). La règle week-end n'est qu'un **repli** : les tours réels changent chaque semaine et incluent des jours fériés. Sans calendrier, le bouton affiche une liste fausse au moment où elle compte le plus. | Ordre des pharmaciens / DPM |
| **D4** | **Stock / inventaire par officine**, avec date de mise à jour | Toute la recherche de disponibilité (§4.3a), **l'algorithme de répartition (§4.3c)** et le panier. C'est le cœur du besoin, et il est entièrement dépendant de cette donnée. | Déclaratif officine / interface SIH |
| **D5** | **Notices / RCP** : indications, posologie, contre-indications, conseils, effets indésirables | Le bouton Info (§4.4) **et** l'IA du §4.5, qui doit répondre *uniquement* à partir de documents récupérés. Sans base de notices, l'IA n'a aucune source et doit se taire. | BDPM/RCP + relecture pharmacien |

**Conséquence directe** : les critères d'acceptation §6 qui portent sur la proximité, la garde, la
disponibilité, la répartition et le panier **ne peuvent pas être satisfaits** tant que D1 à D4 ne
sont pas fournis. Un jeu de démonstration est possible, mais il devra vivre dans
`data/05_pharmacies/demo/`, être étiqueté `DEMO`, et un garde-fou devra empêcher son chargement en
production (règle transverse 1 du brief).

---

## 2. Bloquants partiels — dégradent une fonction sans l'empêcher

| # | Donnée manquante | Impact |
|---|---|---|
| **D6** | **Liste CMU des médicaments remboursables** (document distinct de la LNME) | Impossible d'indiquer au citoyen si son médicament est pris en charge. Le module paiement sait calculer une couverture (P5.1), mais rien ne dit quels produits y ouvrent droit. |
| **D7** | **Arrêté n° 249/MSHP/MCIPPME du 04/04/2019** — prix homologués AIRP | C'est la **seule source de niveau 5** (opposable au sens fort). Le corpus actuel porte la Décision n° 0016/AIRP/DG du 29/09/2025, en **niveau de confiance 3**. Les prix affichés resteront donc « indicatifs » au sens du brief tant que l'arrêté n'est pas intégré. |
| **D8** | **`dci_code` et `medicament_id`** du référentiel de prix | **0/177 renseignés.** Le rapprochement prix ↔ LNME n'a aucun point d'accroche : il devra se faire sur le libellé, produire un **taux de correspondance mesuré** et une liste de cas ambigus à trancher humainement. |
| **D9** | **Conditionnement** des 37 lignes `libelle_incomplet` | Ces 37 prix ne peuvent pas s'afficher comme prix fermes (4 132 F pour 30 comprimés ou pour 90 n'est pas le même produit). Ils doivent rester visibles mais marqués. |
| **D10** | **Terminologies** : ATC, RxNorm, CIM-11, LOINC | `data/01_referentiels/terminologies/` est vide. Aucune interopérabilité internationale, aucun rapprochement automatique par code. Le niveau A–E ivoirien, lui, n'existe dans **aucune** de ces terminologies et devra de toute façon vivre en propre. |

---

## 3. Manques cliniques et de gouvernance

| # | Manque | Impact |
|---|---|---|
| **D11** | **Les quatre validations du CDC_08 §7** pour `PROT-CI-PALU-2022` | Le protocole est en `BROUILLON` avec `validations: []`. Il **s'importe mais ne se publie pas**. Publier exigerait d'inscrire dans une chaîne immuable qu'un médecin spécialiste et le Ministère ont validé des posologies — c'est la pièce qu'on produirait devant un tribunal. La décision N3 de P10b-1 l'interdit sans validateurs réels et nommés. |
| **D12** | **Traduction française validée** des 16 tables ANC (385 règles) | Contenu OMS en anglais. Une traduction non relue médicalement ne peut pas gouverner une conduite à tenir. |
| **D13** | **Issues cliniques réelles** reliant un triage à son résultat | Aucun fichier du corpus ne les porte. C'est le label choisi pour le modèle de triage : **la décision F5 reste donc en vigueur**, et ces données se constitueront par l'usage via la boucle ouverte en P10c-2-i partie A. Synthea ne les remplace pas (30 patients américains, 0 paludisme). |
| **D14** | **PDF source de la LNME** | Absent du corpus : seuls le CSV, le JSON et `extraire_lnme.py` sont là. On ne peut donc **ni rejouer l'extraction, ni vérifier une ligne douteuse contre l'original**. À demander — c'est la pièce qui rend le reste vérifiable. |

---

## 4. Documents annoncés par le brief et introuvables

Vérifié fichier par fichier. Le brief les listait dans l'arborescence cible ; ils ne sont pas dans
le dépôt.

| Document | État |
|---|---|
| `Referentiel_Medicaments_LNME_2024.md` (note d'analyse : pièges, couverture, réserves) | **Introuvable** — c'est la note qui documentait les pièges ; ce `README_DATA.md` la remplace en partie, mesures à l'appui |
| `Referentiel_Prix_Medicaments_MaSante.md` | **Introuvable** |
| `Architecture URGENCE.docx` | **Introuvable** |
| `Prompt_Scalabilite_MaSante.md` | **Introuvable** |
| `Sources_Donnees_Protocoles_MaSante.md` | **Introuvable** |
| `Synthese_Modifications_a_valider_MaSante.md` | **Introuvable** |
| Mémoire, parties I / II / III | **Introuvable** |
| `Tome 1 Et 2.docx` | ✓ présent — `Nouvelle Architecture et plan de Conception du projet/` |

**Correction au brief** : il déclare « CDC_03 à CDC_07, CDC_09 et CDC_10 absents du corpus (CDC_02
y figure en double) ». C'est exact du **pack livré**, mais **faux du dépôt** : les **14** cahiers des
charges sont présents dans `CDC.md/` et font autorité. Ils n'ont donc pas été recopiés dans
`data/04_specifications/` — deux exemplaires d'un cahier des charges, ce sont deux vérités.

---

## 5. Décisions en attente (pas des données, mais bloquantes)

| # | Décision | Pourquoi elle bloque |
|---|---|---|
| **A1** | **Articulation entre `medicaments` (MySQL, gouverné, P6.6a/b) et le nouveau microservice PostgreSQL** | Sans elle, on créerait **deux catalogues nationaux de médicaments**. Voir `MODELE_DONNEES_CIBLE.md` §3 — trois options, recommandation (b). **Bloquant pour la phase 3.** |
| **A2** | **Actions de protocole nouvelles** (`CLASSIFICATION`, `ORIENTATION`, `TRAITEMENT_PRE_TRANSFERT`) | Absentes de la liste blanche de P10b. `TRAITEMENT` touche la posologie, donc au §1.3 de CDC_08. |
| **A3** | **Licence OMS CC BY-NC-SA 3.0 IGO** | Usage non commercial. Si MaSanté devient payant, ces protocoles devront être autorisés ou remplacés. À trancher avant, pas après. |
