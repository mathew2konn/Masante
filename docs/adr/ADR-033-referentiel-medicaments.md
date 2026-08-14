# ADR-033 — Référentiel National des Médicaments (P6.6a)

**Statut : Accepté** — 2026-08-14 · Contexte : CDC_09 §6 · Applique [ADR-024](ADR-024-referentiels-nationaux.md) et [ADR-025](ADR-025-socle-referentiel.md) · Suit [ADR-031](ADR-031-referentiel-professionnels.md).

---

## 1. Contexte

Étape **6** de l'ordre CDC_09 §14. L'audit G0 a établi quatre faits, tous vérifiés dans le code.

**Une ordonnance ne désigne aucun médicament du référentiel.** `OrdonnanceController` valide
`medicaments_json.*.nom` en `required|string|max:200` : du texte libre. C'est le **miroir exact** du
défaut que P6.5 vient de refermer sur `medecin_nom` — là, n'importe qui pouvait porter le nom de
n'importe quel médecin ; ici, une prescription peut nommer n'importe quelle molécule sous n'importe
quelle orthographe, alors que §6.1 annonce l'inverse. *Le lien est traité en P6.6b (décision du
propriétaire), pas ici.*

**L'exemple imposé du §6.3 n'était pas représentable.** `Code : MED000458 · Dosage : 500 mg ·
Voie : Orale` — aucune de ces trois données n'existait. La table avait 7 colonnes ; 8 des 15 données
du §6.2 manquaient, dont les **interactions**.

**`medicaments` n'était pas gouverné**, alors qu'un référentiel portant interactions,
contre-indications et prix homologué porte autant de règles que des seuils cliniques.

**Le moteur d'analyse ne relève pas de ce module** : CDC_05 §2 liste un microservice
`interaction-service` — « interactions médicamenteuses, contre-indications, adaptation de doses ».
Même partage qu'avec la fraude (ADR-017). P6.6 fournit la **donnée**, pas le jugement.

---

## 2. Décisions

### 2.1 La projection prend la ligne entière — et la question a été REPOSÉE

P6.4a avait exclu `note_moyenne` de la projection des établissements : recalculée à chaque avis, elle
aurait rendu l'instantané divergent en permanence. P6.5a avait refait le tri sur un autre critère.

Ici, **la vérification a donné une réponse opposée** : rien n'écrit automatiquement dans
`medicaments`. Les prix relevés par les citoyens et les ruptures signalées vivent dans
`prix_pharmacie`, une table séparée. Le prix homologué, lui, est une donnée d'autorité — §6.2
l'exige, et il ne bouge que si l'autorité le change.

**Deux vecteurs en miroir le prouvent, aucun ne suffisant seul** : un relevé de prix citoyen ne fait
**pas** diverger le référentiel · un dosage corrigé **si**.

### 2.2 Code national `MED` + 6 chiffres, littéral, sans clé de contrôle

Troisième application du raisonnement d'`ETS` (P6.4a) et `PRO` (P6.5a) — avec ici un argument
décisif de plus : l'exemple imposé est **`MED000458`**, et aucune convention de clé de contrôle ne le
laisserait valide. En ajouter une mettrait le corpus en défaut, ce qu'ADR-021 avait déjà dû corriger
dans l'autre sens en recalculant l'exemple de NIS d'ADR-001.

`UNIQUE(pays_code, code)` : le pays qualifie le code, il ne s'écrit pas dedans. Hors `$fillable`.

### 2.3 Les interactions sont une TABLE, pas une colonne JSON

Une interaction est une **relation entre deux DCI**, pas une propriété de l'une d'elles. Deux
colonnes JSON auraient dit deux fois la même chose (X→Y et Y→X) et auraient pu diverger sur le
niveau — deux vérités sur un même fait clinique.

**Le couple est ordonné** (`medicament_a_id < medicament_b_id`), ce qui rend l'unicité déclarative.

### 2.4 Un seul référentiel, un seul instantané

L'instantané porte les médicaments **et** leurs interactions. Les publier séparément permettrait
qu'une version d'interactions désigne un produit absent de la version de médicaments en vigueur : la
référence serait **irrésoluble**. Les interactions y figurent par **code national**, jamais par
identifiant technique — un instantané doit rester rejouable sans la base qui l'a produit.

### 2.5 Une permission neuve, `medicament.referentiel`

`medicament.manage` existait — mais elle appartient au **gestionnaire d'établissement**, et son
commentaire au seeder dit ce qu'elle couvre : « prix et ruptures de SA pharmacie ». La réutiliser
aurait donné à toute officine partenaire le droit d'écrire les indications, les contre-indications et
les **interactions** du catalogue national ; un laboratoire fabricant serait devenu juge et partie
sur son propre produit.

`medicament.referentiel` n'est portée par **aucun rôle** — sixième occurrence du précédent
`urgence.bris_de_glace`. À dire honnêtement, comme le seeder le fait déjà pour les permissions de
gouvernance : `admin_ivoirsante` la reçoit quand même par `syncPermissions(Permission::all())`. La
séparation réelle est celle qui compte ici — **une officine n'écrit pas le catalogue national** — et
elle est prouvée en live.

### 2.6 Un produit retiré est signalé, jamais bloqué

`statut_marche` (`autorise` / `suspendu` / `retire`) est une **donnée**, pas un filtre. Un produit
retiré reste visible au catalogue : le masquer empêcherait un pharmacien de comprendre pourquoi il ne
doit plus le délivrer. Et refuser une prescription serait une **décision médicale prise par une
machine** (CDC_00 §4).

---

## 3. Ce que le G2 a corrigé

**L'index unique ne protégeait que le couple déjà ordonné.** Une insertion SQL directe écrivant
(B, A) alors que (A, B) existait était acceptée par le moteur. `ServiceInteractions` ordonnait bien —
mais *une garantie qui ne tient qu'au chemin applicatif n'en est pas une* : un import, un seeder ou
une correction à la main la contourne.

Un `CHECK` était le premier choix. **MySQL le refuse** : les deux colonnes sont `cascadeOnDelete`,
donc soumises à une action référentielle — **erreur 3823**, exactement le mur de P6.3 (cousin du 1215
de P6.1). D'où des **triggers dans les deux dialectes** (`SIGNAL 45000` / `RAISE(ABORT)`), le recours
que CDC_04 §139 prévoit. Prouvé live : `ERROR 1644 ck_interaction_couple_ordonne`.

**Un contrôle de doublon naïf aurait rendu le référentiel impubliable.** Le jeu de développement
contient « Amoxicilline 500 mg » deux fois : la ligne générique avec sa référence CENAME, et
« Clamoxyl ». Ce sont **deux produits distincts**, et c'est le fonctionnement normal d'un référentiel.
Un contrôle sur la seule DCI les aurait signalés comme doublons. La clé retenue est le **produit
complet** : molécule, dosage, marque, fabricant — avec deux vecteurs en miroir, le couple
générique/marque qui passe et le vrai doublon qui échoue.

---

## 4. Conséquences

- Ajouter un référentiel reste **une classe et une ligne** : le moteur de gouvernance n'a pas bougé.
- Le catalogue enrichi sert **immédiatement** la recherche publique, le comparateur de prix et le
  stock des pharmacies — il n'attend pas P6.6b, donc le découpage ne reproduit pas le « socle à
  vide » refusé en P6.3 (D3).
- Les énumérations sont servies par l'API (`GET /medicaments`) : aucun client ne les recopie, ce qui
  évite le défaut de P6.4b où sept libellés vivaient en dur côté mobile.

---

## 5. Limites

1. **Le lien ordonnance → référentiel n'est pas fait** (P6.6b). Le défaut central du G0 reste ouvert.
2. **Aucun moteur d'interactions** : P6.6 rapporte ce que le référentiel déclare. Pas d'analyse, pas
   d'alternative thérapeutique, pas d'adaptation de dose — `interaction-service`, CDC_05.
3. **La pharmacovigilance §6.5 n'est pas livrée** : le statut `retire` existe, sa **propagation** aux
   pharmacies et aux prescripteurs est un incrément séparé (décision du propriétaire).
4. **Les allergies restent du texte libre** (`antecedents.description`) : §6.4 « signalement des
   allergies connues » reste partiellement irréalisable tant qu'une allergie ne désigne pas une DCI.
5. **Le contenu est un jeu de démonstration** — 18 lignes. Charger la base réelle DPM/CENAME est de
   la **donnée, zéro code**, mais tant que ce n'est pas fait, ce n'est pas un référentiel national et
   il ne faut pas le présenter comme tel. Même situation que le découpage sanitaire partiel de P6.4a.
6. **Aucun écran mobile** : le catalogue enrichi est servi par l'API, les écrans citoyens restent
   ceux du module 5.
