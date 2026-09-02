# Modèle de données cible — issu de l'inventaire du corpus

**Livrable (b) de la phase 2.** Ce document ne décrit que ce que les données **mesurées** imposent.
Tout ce qui relève d'une décision non prise est signalé comme tel, jamais tranché ici.

Décisions propriétaire déjà actées (22/08/2026) : le référentiel devient un **microservice
PostgreSQL** dédié · le ML reste suspendu tant qu'il n'y a pas d'issues réelles (**F5 maintenue**).

---

## 1. Les entités que les données imposent

### 1.1 Deux entités distinctes, et le critère n'est pas celui qu'on croit

`medicament_national` (annexes 1 et 3 — **1 385 lignes**) et `materiel_biomedical`
(annexe 2 — **2 488 lignes**) sont deux entités séparées. Le brief le demandait, la mesure le
confirme : les fusionner produirait une table dont 64 % des lignes auraient dosage, voie et forme
vides.

**Le critère de partage est `annexe`, jamais `voie`.** Six médicaments de l'annexe 1 portent la voie
`---` (Ammoniaque, Glutaraldéhyde, Dichloro-isocyanate, Trioxyméthylène, Sulfadiazine argentique,
un lubrifiant) : trier sur la voie les classerait en consommables.

### 1.2 Le doublon annexe 1 / annexe 3 : `usage_pediatrique` est un attribut, pas une ligne

Les 131 lignes de l'annexe 3 (Liste Pédiatrique) **répètent** 131 produits de l'annexe 1, qui
portent déjà `usage_pediatrique = True`. Charger les 1 385 lignes telles quelles créerait **131
doublons** dans le catalogue national.

→ Une ligne par produit, avec un booléen `usage_pediatrique` et la trace de l'annexe d'origine.
La colonne source est conservée à côté, jamais écrasée.

### 1.3 Le niveau d'autorisation : un échelon, pas un ensemble arbitraire

`niveau` ne prend que 5 valeurs, toutes **préfixes contigus depuis A** : `A`, `AB`, `ABC`, `ABCD`,
`ABCDE`. Il se modélise donc par **l'échelon le plus bas autorisé** (`A` = réservé au sommet de la
pyramide sanitaire, `E` = accessible partout), avec le libellé source conservé.

C'est **une spécificité ivoirienne absente de tout référentiel international**, et elle conditionne
le **droit de prescrire** (règle transverse 7 du brief). Elle ne peut donc pas être dérivée d'ATC ou
de RxNorm : elle doit vivre en propre.

### 1.4 Normalisation : le libellé source est toujours conservé

139 formes galéniques et 24 voies à réduire à une nomenclature fermée ; dosage à parser en
`{valeur, unité}`. **Le libellé brut reste stocké à côté du champ normalisé** — on doit pouvoir
revenir à l'original (règle du brief §3.1, et précédent constant du projet).

Trois marqueurs de vide coexistent (`''`, `---`, `----`) : ils se normalisent en `NULL` unique à
l'ingestion, et l'absence se dit par l'absence, jamais par une valeur inventée.

### 1.5 Prix : deux natures qui ne se mélangent jamais

| Entité | Nature | Règle |
|---|---|---|
| `prix_homologue` | Référence **opposable** | **Immuable.** Un changement = clôture (`date_fin`) + nouvelle ligne. Le trigger `f_prix_immuable` en est le gardien. |
| `prix_pharmacie` | Prix réellement pratiqué | Optionnel, déclaré par l'officine, daté. |

- Les **37 lignes** à `conditionnement_qte` vide **ne peuvent pas s'afficher comme prix fermes** :
  4 132 F pour 30 comprimés ou pour 90, ce n'est pas le même produit.
- Sources non opposables → mention « prix indicatif » **non masquable**.
- Aucun prix connu → « prix non disponible ». **Jamais 0, jamais une estimation.**
- Écart > 2 % entre référence et prix officine → alerte.

Ce partage est déjà celui du projet : `prix_pharmacie` existe côté Laravel depuis le module
pharmacie initial, et P6.6a a posé que « les prix citoyens vivent dans `prix_pharmacie` » — c'est
précisément ce qui avait permis à la projection gouvernée de rester stable.

### 1.6 Rapprochement prix ↔ LNME : à mesurer, jamais à deviner

`medicament_id` **et** `dci_code` sont vides sur les 177 lignes. Il n'existe **aucun point
d'accroche structuré**. Le rapprochement devra donc se faire sur le libellé
(`nom_commercial_presume` + `dosage_presume` + `forme_normalisee`), et le brief impose la bonne
méthode : **proposer un algorithme, mesurer son taux de correspondance, montrer les cas ambigus** —
sans jamais écrire un lien deviné dans la base.

Précédent du projet à respecter : P6.8c refuse de rapprocher une maladie d'un texte libre *même
identique au libellé officiel*. Ici le rapprochement est nécessaire, mais il produit une
**proposition à valider**, pas un fait.

---

## 2. Répartition par service

Rappel du brief : « une base par service », aucune clé étrangère inter-services.

| Service | Base | Contenu |
|---|---|---|
| `referentiel` (**neuf**) | PostgreSQL | `medicament_national`, `materiel_biomedical`, `prix_homologue`, sources, terminologies |
| `pharmacie` (**neuf**) | PostgreSQL | officines, stocks, horaires, calendrier de garde, paniers, commandes |
| Cœur métier (existant) | MySQL | carnet, ordonnances, triage, protocoles, gouvernance des référentiels |
| `payment` (existant) | PostgreSQL | facturation, wallet, cartes |
| `fraud-detection` (existant) | sans état | scoring |

---

## 3. LA DÉCISION QUI RESTE OUVERTE, et je ne la prends pas seul

Le choix du microservice PostgreSQL règle **C1** (le dialecte). Il **ne règle pas C2** : il le
déplace, et il faut le dire.

Le projet possède déjà un référentiel de médicaments **gouverné** dans MySQL — `medicaments`
(P6.6a) : codes `MED000001`, publication à quatre yeux, contrôle qualité, anti-substitution. Et
**P6.6b a figé dans les ordonnances** le code national, la DCI et le dosage lus à ce référentiel :
ces valeurs sont inscrites dans des ordonnances **signées électroniquement** (P6.5b).

Créer `medicament_national` en PostgreSQL sans trancher l'articulation donnerait **deux catalogues
nationaux de médicaments**, chacun avec ses codes — exactement ce que ce projet refuse partout.

### Trois articulations possibles

| | Principe | Coût | Risque |
|---|---|---|---|
| **(a)** | Le microservice devient la source de vérité ; `medicaments` (MySQL) n'est plus qu'une projection | Migration des liens figés dans les ordonnances signées | **Élevé** : toucher des ordonnances signées invaliderait leurs signatures |
| **(b)** *(recommandé)* | `medicaments` (MySQL) reste la source pour **le soin** (ordonnances, carnet, triage) ; le microservice porte le **catalogue national étendu, les prix et la recherche floue** ; le pont se fait par le **code national** | Un import LNME → `medicaments` sous gouvernance, et un identifiant partagé | Faible : aucun module G5 n'est réécrit |
| **(c)** | La LNME alimente `medicaments` (MySQL) ; le microservice ne porte **que** les prix | Le « < 150 ms » avec `pg_trgm` ne couvre plus la recherche de médicaments | Moyen : on perd l'intérêt principal de PostgreSQL |

**Ma recommandation : (b).** Elle respecte le fait que le soin s'appuie sur un référentiel
**gouverné à quatre yeux**, et elle donne au microservice ce pour quoi PostgreSQL a été choisi :
volumétrie, prix, recherche floue. La LNME devient alors le **chargement réel** que P6.6a annonçait
comme sa limite principale — « charger la base DPM/CENAME est de la donnée, zéro code ; tant que ce
n'est pas fait, ce n'est pas un référentiel national ».

**Cette décision est bloquante pour la phase 3.** Je ne commence aucune migration avant qu'elle soit
prise.

---

## 4. Protocoles : ce qui s'importe, ce qui se transpose

| Source | Ce qu'on peut en faire |
|---|---|
| `PROT-CI-PALU-2022.json` | **Importable en brouillon** — son `cycle_de_vie` est déjà `BROUILLON` / `validations: []`, ce qui est exactement le régime N3 de P10b-1. **Non publiable** sans les quatre validations du §7. |
| Ses `condition` | **À transposer.** Ce sont des expressions JavaScript (`signes_gravite.length > 0`) ; le moteur de P10b n'évalue que des conditions déclaratives sur liste blanche d'opérateurs — délibérément, pour qu'aucune expression écrite en base ne s'exécute sur l'écran d'un patient. |
| Ses actions (`CLASSIFICATION`, `ORIENTATION`, `TRAITEMENT_PRE_TRANSFERT`) | **Absentes de `RegistreActionsProtocole`.** Les ajouter est une décision : `TRAITEMENT` touche la posologie, donc au §1.3 de CDC_08. |
| `moteur/` (685 lignes TS) | **Spécification de référence et source de cas de test.** Jamais du code exécuté : la règle de frontière interdit tout calcul médical dans le front, et P10b a placé le moteur dans Laravel (N1). |
| ANC (16 tables, 385 règles) | Corpus de référence **en anglais**, sous **CC BY-NC-SA 3.0 IGO**. Traduction et validation médicale requises. |
| `ANC Test Cases.xlsx` | **Cas de test officiels OMS** — la pièce la plus directement exploitable, pour éprouver un moteur sans rien affirmer cliniquement. |

---

## 5. Licence — une décision d'architecture à trancher, pas à enterrer

Les contenus OMS SMART ANC sont sous **CC BY-NC-SA 3.0 IGO** : attribution obligatoire, **usage non
commercial**. Si MaSanté devient payant, ces protocoles devront être autorisés ou remplacés.
C'est inscrit ici pour que la question se pose avant, et non après.
