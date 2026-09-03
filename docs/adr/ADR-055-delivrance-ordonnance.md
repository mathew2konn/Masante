# ADR-055 — Servir une ordonnance sans ouvrir le dossier (B3-a)

**Statut : Accepté — B3-a VALIDÉ (G5, 2026-09-03).** G4 propriétaire OK.
Suite complète **1616/1616**, 17 717 assertions ; mutation 7/7.
Contexte : CDC_11 §7.1 et §7.2 (étape 7 de §12), CDC_04 §105 · Plan G1 :
[`docs/PLAN_G1_B3_Pharmacie.md`](../PLAN_G1_B3_Pharmacie.md) · **Lève le report de
[ADR-054 §9](ADR-054-consultation-acte-de-soin.md)** en nommant le consommateur qui manquait.

---

## 1. Contexte

Le §5.4 décrit `Médecin → Patient → Pharmacie`. B2-c n'en avait livré que le premier tronçon :
l'ordonnance désigne son prescripteur, mais **rien ne permettait à un pharmacien de la recevoir**.

Le G0 a relevé neuf manques (P1→P9). Ce lot en referme trois : **P1** (la table `delivrances`),
**P6** (la réception d'une ordonnance) et **P9** (les lignes, dont B2-c disait qu'elles n'avaient
« aucun consommateur »).

---

## 2. Décision centrale — le pharmacien ne voit que l'ordonnance

Le seul mécanisme existant, `qr.scan`, ouvre une **session de dossier** : antécédents,
vaccinations, résultats d'analyses. **Un pharmacien n'a pas à lire les antécédents pour servir une
boîte de paracétamol.**

C'est le principe de minimisation de la loi 2013-450, que ce projet applique déjà explicitement
(P7-D2 : les documents sont listés, jamais téléchargeables depuis le portail).

| Option | Écartée parce que |
|---|---|
| 7<sup>e</sup> voie d'accès au dossier | Élargissement durable pour un besoin ponctuel — ce que P7-D0 a refusé au bris de glace en écriture |
| Le QR patient existant | Le plus court, et le plus disproportionné |

**Retenu : un jeton porté par l'ordonnance**, patron repris de la fiche de triage (P10a) et non
réinventé — 48 caractères, hors `$fillable` (un client qui choisirait son jeton pourrait le
deviner), `$hidden`, comparaison en **temps constant**, et **404 jamais 403** : un 403 confirmerait
qu'une ordonnance existe là et permettrait de balayer l'espace des jetons.

**Le vecteur central de ce lot est une absence** : servir une ordonnance ne crée **aucune ligne de
journal d'accès**, parce qu'aucun accès n'a lieu. *Ce n'est pas une garde qu'on vérifie, c'est une
porte qui n'existe pas.*

---

## 3. Les lignes d'ordonnance — le report de B2-c est levé

B2-c écartait `ordonnance_lignes` en écrivant : « elle n'a **aucun consommateur** aujourd'hui ; sa
raison d'être est la délivrance en pharmacie, qui n'existe pas ». Ce lot livre ce consommateur.

### 3.1 La décision que B2-c avait renvoyée est prise ici

B2-c : *« l'interrogeabilité ne s'obtient qu'en cessant de chiffrer — une décision qui mérite d'être
prise pour elle-même »*. La voici, alignée sur la ligne déjà tranchée du projet (constat Y9 de B2,
où `resultats_analyses` laisse `intitule` en clair et ne chiffre que les valeurs mesurées) :

| En clair | Chiffré |
|---|---|
| `nom`, `medicament_id`, `code_national`, `dci`, `dosage` | `posologie`, `duree`, `instructions` |

*Ce qui identifie un produit n'est pas ce qui décrit un traitement.* Sans les identifiants en clair,
ni la délivrance, ni la vérification d'interactions, ni le §7.6 ne sont possibles.

### 3.2 Les lignes sont une PROJECTION, pas une seconde saisie

`medicaments_json` reste le contrat d'écriture des trois chemins, validé et résolu au référentiel
par `ServiceLienMedicament` (P6.6b). Demander en plus des lignes à l'appelant créerait **deux
endroits où dire la même chose** — refus de P6.6a.

Un **seul crochet** (`saved`, et non `created`) les dérive : le `PUT` est couvert au même titre que
la création, sinon la garantie ne vaudrait que sur l'un des chemins (leçon P6.8b, où `update()`
avait été oublié).

### 3.3 Une prescription servie ne se réécrit pas

La reprojection **se refuse d'elle-même** dès qu'une délivrance existe : régénérer les lignes
changerait ce à quoi une délivrance se rattache — au mieux on perdrait la trace de ce qui a été
servi, au pire on l'attacherait à un autre médicament. Si une prescription est fausse, elle se
corrige par une nouvelle ordonnance, comme une facture par un avoir (P5.2b).

La sauvegarde de l'ordonnance, elle, **reste permise** (le patient peut ajouter une photo du
papier) : c'est la reprojection qui n'a pas lieu, pas l'écriture — même esprit qu'en P7-D0, où un
échec de signature ne défait pas l'écriture.

---

## 4. La délivrance : un en-tête, des lignes, aucun statut stocké

Une délivrance **partielle** est le cas normal : la pharmacie a deux médicaments sur trois. D'où des
lignes, et non un booléen.

**Aucune colonne « statut »** : « cette ordonnance est-elle entièrement servie ? » se **déduit** des
lignes. Une valeur stockée recalculable finit par diverger de ce qu'elle résume — leçon du wallet
(P5.3a), où le solde est une somme et jamais une colonne.

**`resteAServir()` rend `null`** quand le médecin n'a pas précisé de quantité : on ne borne pas ce
qu'on ne sait pas, et `null` n'est pas zéro (précédent P10c-3-ii).

---

## 5. Une permission neuve, et c'est justifié

`ordonnance.delivrer` est créée plutôt que de réutiliser `medicament.manage`. Ce n'est pas de la
prudence décorative : **`medicament.manage` appartient aussi au gestionnaire d'établissement**
(P6.6a le dit pour « les prix et les ruptures de SA pharmacie »). La réutiliser laisserait un
gestionnaire de CHU servir des ordonnances.

**La différence avec P11.1-D5 tient au fait que ce n'est pas le même acte** : là-bas, « approuver
une candidature, c'est créer un établissement » désignait littéralement la même chose ; ici, tenir
un prix et dispenser un médicament sont deux gestes distincts.

Elle est donnée au **seul** rôle `pharmacien`, et déclarée **des deux côtés** — sans quoi la garde
anti-divergence de P11.0 casse le build.

---

## 6. Ce que le §7.2 vérifie vraiment, dit sans embellir

| Le §7.2 demande | Livré | |
|---|---|---|
| Authenticité | ✅ | Le jeton prouve l'origine, la signature (P6.5b) l'intégrité |
| Disponibilité | ⏳ | Depuis `stocks_pharmacie` — **B3-b** |
| Interactions | ✅ | **Consultation explicite**, jamais un calcul : le choix de P6.6b n'est pas rouvert, calculer rapprocherait le module d'une aide à la décision (CDC_05/CDC_08) |
| Contre-indications | ❌ | **Impossible** : les allergies sont du texte libre chiffré (constat Y4 de B2). Une vérification partielle afficherait « aucune contre-indication » sur un patient qui en a une — *plus dangereux que pas de vérification du tout* |

**Le §7.2 est donc atteint à trois quarts, et le lot le dit.** Porteur du quart manquant : le
référentiel d'allergènes, déjà nommé par B2-a.

---

## 7. Ce qui a été prouvé

**G3** — suite complète **1616/1616, 17 717 assertions, 0 échec** ; 21 vecteurs dédiés ;
**mutation 6 tueuses + 1 témoin volontairement vert**, toutes conformes, arbre restauré et vérifié
par `diff`.

**Baseline Pint établie AVANT de toucher au formatage** (leçon B2-b, appliquée d'emblée) :
`Ordonnance.php` et `PrixMedicamentService.php` échouaient **déjà** — ils ne sont pas reformatés.
Le seul changement de `PrixMedicamentService` est **une ligne** : `$structure->type !== 'pharmacie'`
devient `! $structure->estPharmacie()`, pour que les deux services lisent la même source.

**G2 live MySQL** — jeton inventé → **404** ; vrai jeton → l'ordonnance et ses deux médicaments,
avec la mention de minimisation à l'écran ; délivrance partielle (8 sur 20) ; tentative de 13
**refusée avec un message qui nomme le médicament et le reste** ; complément de 12 accepté ; total
en base **20 sur 20**, **deux** délivrances et non trois. Et surtout : **zéro ligne dans
`acces_dossier`** — le vecteur central vérifié en réel. Base restaurée compte pour compte.

---

## 8. Limites

- **Aucune gestion de stock** : la disponibilité du §7.2 attend **B3-b**. Rien n'empêche
  aujourd'hui de servir un médicament que l'officine n'a pas — le système enregistre ce que le
  pharmacien déclare avoir servi, il ne le vérifie pas contre un inventaire.
- **Aucune vérification de contre-indications** (§6).
- **Les ordonnances antérieures à ce lot ne sont pas servables** : elles n'ont pas de lignes, et on
  n'en fabrique pas rétroactivement depuis un JSON saisi librement — ce seraient des lignes que
  personne n'a vérifiées, sur un document parfois signé. Elles restent **consultables**, et l'écran
  le dit.
- **Aucune traçabilité nationale** (§7.6) : la trace de délivrance suit le sort de l'ordonnance
  (`cascadeOnDelete`), et le patient reste maître de son carnet. Le registre qui doit **survivre**
  est celui du §7.6 — c'est **B3-c**, et le dire ici évite de faire porter à cette table une
  promesse qu'elle ne tient pas.
- **La borne de quantité est une garde applicative**, annoncée comme telle : elle dépend d'une
  somme sur les délivrances antérieures, qu'un déclencheur ne peut pas calculer sans lire la table
  qu'il garde (erreur 1442, précédent P6.4c). Le moteur garantit deux choses seulement, et il les
  garantit vraiment : qu'une ligne servie appartient à l'ordonnance de sa délivrance, et qu'une
  quantité servie n'est pas nulle.
- **Aucun écran mobile** : le patient présente son code, il ne sert pas lui-même.
