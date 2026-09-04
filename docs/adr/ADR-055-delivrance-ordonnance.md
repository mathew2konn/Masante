# ADR-055 — La pharmacie : servir une ordonnance, puis tenir son stock (B3-a, B3-b)

**Statut : Accepté.** **B3-a et B3-b VALIDÉS (G5, 2026-09-03)**, **B3-c VALIDÉ (G5, 2026-09-04)** —
G4 propriétaire OK (B3-b : §9 ; B3-c : §10). **Lot B3 (Pharmacie) COMPLET (a, b, c).**
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
| Disponibilité | ✅ | Depuis `stocks_officine` — refermée par **B3-b** |
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


---

## 9. B3-b — le stock réel de l'officine (✅ VALIDÉ G5, 2026-09-03)

**Referme cinq des neuf manques du G0** : `stocks_pharmacie` et `lots_medicaments` (**P1**), le
relevé pris pour un stock (**P2**), l'écran dont le nom mentait (**P3**), la fiche médicament de
l'officine (**P4**), et le mode 1 du §7.7 (**P8**).

### 9.1 Une table à part, et `prix_pharmacie` ne change pas de nature

`prix_pharmacie` mélange déjà deux sources : le relevé d'un patient et la déclaration de l'officine.
Y ajouter lots et péremption ferait porter à une table **déclarative** des données dont **l'officine
seule répond** — la ligne que ce projet trace depuis P6.4a.

| | |
|---|---|
| `prix_pharmacie` | le **relevé public**, que lit le comparateur. Contrat inchangé (module G5) |
| `stocks_officine` | l'**inventaire** tenu par la pharmacie (§7.5) |

**L'inventaire ALIMENTE le relevé, il ne le double pas** : une seule valeur publique de prix et de
disponibilité, écrite par le service qui l'écrit déjà (`PrixMedicamentService`, jamais réécrit).
Sans cela, le comparateur et la fiche officine pourraient se contredire, et *le patient ne saurait
pas laquelle croire* (motif P6.7b).

### 9.2 Le stock est une SOMME, jamais une colonne

Aucune colonne `quantite` sur l'article : une entrée, une sortie, une péremption sont des **faits
datés**, et le stock courant en est la somme. C'est la partie double du wallet (P5.3a) — *une valeur
stockée recalculable finit par diverger de ce qu'elle résume*, et l'écart ne se voit qu'au moment où
il coûte cher.

`quantite` est **signée**, comme les contributions du grand livre de P5.5b-1 (« Σ = 0 par
contributions signées, aucun `abs()` »). **Le signe est déduit du TYPE**, jamais demandé à
l'appelant : une « entrée de −5 » n'a pas de sens, et laisser l'appelant choisir ferait dépendre
l'intégrité du stock de la discipline de chaque site d'appel. Le moteur refuse de toute façon un
signe contraire au type — les deux gardes ne se rattrapent pas, elles se confirment.

### 9.3 Append-only, à deux niveaux

Un mouvement ne se modifie ni ne s'efface : une erreur se corrige par un **ajustement**, qui la
laisse visible. Refusé par le modèle **et** par le moteur, comme `protocole_applications` (P10b-2) —
le second tient même face à un accès direct.

### 9.4 La délivrance sort du stock — mais ne s'y heurte pas

B3-a enregistrait ce que le pharmacien **déclarait** avoir servi. Désormais, une délivrance
**décrémente** le stock — **si l'officine tient son inventaire**. Sinon elle passe sans rien
décrémenter, et c'est délibéré : *refuser de servir parce qu'une pharmacie ne tient pas son stock
dans notre application priverait un patient de son traitement pour une raison qui ne le concerne
pas* (même esprit qu'en P7-D0, où un échec de signature ne défait pas l'écriture).

### 9.5 Renommer ce qui mentait

`StockPharmacieController` **ne gérait aucun stock** : il déclarait un prix. Le garder ainsi à côté
d'un vrai stock aurait fait chercher l'inventaire au mauvais endroit. Renommé
`PrixOfficineController` (routes `prix-officine.*`), **comportement strictement inchangé** — c'est
un renommage, pas une refonte (précédent P11.0, qui a renommé trois rôles). Déplacé par `git mv`
pour que l'historique suive.

### 9.6 La fiche officine (§7.4), et le critère d'ADR-026 appliqué tel quel

Quatre champs manquaient, et ils ne sont pas de même nature :

| Champ | Dans la projection gouvernée ? |
|---|---|
| `pharmacien_responsable`, `numero_licence` | **Oui** — ils ENGAGENT une autorité, comme un numéro d'autorisation. **Cela fait diverger le référentiel** jusqu'à la publication suivante : ce n'est pas une dérive, c'est ce que la projection est censée porter (précédent `forme_juridique`, P6.4d) |
| `livraison_disponible`, `rayon_livraison_km` | **Non** — opérationnels, comme les horaires. Les gouverner ferait d'un changement de zone de livraison un acte soumis au quatre-yeux |

### 9.7 Ce qui a été prouvé

**G3** — 22 vecteurs dédiés ; **mutation 6 tueuses + 1 témoin volontairement vert**, toutes
conformes, arbre restauré et vérifié par `diff`. **Baseline Pint établie avant de toucher au
formatage** : le contrôleur renommé échouait **déjà**, il n'est pas reformaté.

**Défaut trouvé par les vecteurs** : `firstOrCreate()` pose `structure_id` et `medicament_id` par
**assignation de masse** — hors `$fillable`, elles auraient été écartées. Ici la contrainte NOT NULL
a levé plutôt que de laisser passer en silence, ce qui vaut mieux ; c'est le piège de P6.7b, revu en
B2-c, rencontré une troisième fois.

**Erreur de diagnostic, dite plutôt que tue** : après avoir branché la délivrance sur le stock, un
test est passé de 11 s à plus de 4 minutes, et j'ai d'abord accusé ce branchement. Bissection faite,
**le code n'était pas en cause** : plusieurs exécutions de PHPUnit tournaient en parallèle et se
concurrençaient. Le même test, seul, prend 3,2 s. *Une mesure prise pendant qu'autre chose tourne ne
mesure pas ce qu'on croit.*

**CONSTAT DU G0 FAUX, CORRIGÉ PAR LA SUITE COMPLÈTE.** Le G0 affirmait qu'« aucun test ne couvre cet
écran », sur la foi d'une recherche du NOM DE ROUTE (`portail.stock`). C'était faux :
`PrixMedicamentTest::test_le_pharmacien_fait_autorite_sur_sa_pharmacie` le couvrait bel et bien —
par l'**URL littérale** `/portail/stock/{id}`, qu'aucune recherche sur le nom de route ne pouvait
trouver. Le renommage l'a cassé, et **c'est la suite complète qui l'a rattrapé**, pas la relecture.

Deux choses à en retenir. D'abord : *chercher le nom d'une route ne suffit jamais avant de la
renommer — il faut chercher aussi son adresse écrite en toutes lettres.* Ensuite : le test hérité a
été **mis à jour pour suivre la nouvelle adresse**, son comportement restant inchangé — il n'a pas
été affaibli pour passer (précédent P6.4d).

**DEUXIÈME DÉFAUT TROUVÉ PAR LE G2 LIVE, INVISIBLE AUX 22 VECTEURS.** Le message du déclencheur de
suppression disait « *un mouvement de stock ne se **efface** pas* » — une faute de français, corrigée
en « ne s'efface pas ». Cette correction a rendu **la migration impossible à rejouer** : le texte
part dans une chaîne SQL délimitée par des apostrophes, et l'apostrophe de « s'efface » y refermait
la chaîne. Doublée (`''`), elle passe.

Rien de tout cela n'était visible en test : SQLite reçoit le même texte par un chemin différent, et
aucun vecteur ne relit le **message** d'un refus du moteur. *Une chaîne qu'un moteur assemble à
partir d'un texte français est une chaîne qu'il faut échapper* — et c'est le G2 qui le montre, en
refusant de dérouler.

*Corollaire de méthode* : une migration ne se déclare pas bonne parce qu'elle est passée **une**
fois. Elle doit se **rejouer**.

**G2 live** — base MySQL de développement réelle, sauvegardée puis **restaurée compte pour compte**,
`php artisan serve` réel, trois comptes de portail réels (un pharmacien de l'officine 9, un confrère
de l'officine 10, un personnel d'accueil **rattaché à l'officine 9 mais non habilité**).

*Schéma* — `stocks_officine` 7 colonnes, `mouvements_stock` 11, **3 déclencheurs**, l'unicité
`uq_stock_officine_produit`, et les 4 colonnes de fiche officine sur `structures_sanitaires`.

*Les gardes du moteur, éprouvées en SQL direct* — entrée négative → `ERROR 1644` « Une entrée de
stock est positive » ; quantité nulle → `1644` ; **modification** d'un mouvement → `1644` ;
**suppression** → `1644` (message corrigé, apostrophe comprise), le stock restant à 30.

*Le parcours réel, par le vrai portail* — un produit ajouté naît **à 0** ; entrée de 40 → 40 ;
sortie saisie **`10` et non `-10`** → stock 30 et **`-10` enregistré** (le signe est déduit du type),
les **deux** mouvements conservés ; sortie de 100 → refus **nommant le produit et le stock réel**
(« Le stock de « Paracétamol 500 mg » est de 30 »), stock inchangé ; prix 1500 + seuil 20 →
`prix_pharmacie` porte **1500, disponible, source `pharmacie_portail`** ; descendre à 15 → bandeau
**« Sous le seuil d'alerte »** ; **vider le stock → le relevé public passe en rupture sans qu'on
l'ait déclaré**, et une nouvelle entrée le **remet à disponible avec son prix** ; entrée avec lot →
`LOT-G2-441` sous « proches de la péremption ».

*Anti-IDOR, contre un article RÉEL de confrère* (pas un identifiant inventé) — mouvement sur
l'article de l'officine 10 → **404**, son stock inchangé, et cet article **n'apparaît pas** dans mon
inventaire.

*Habilitation* — un compte **rattaché à l'officine** mais sans `medicament.manage` reçoit **403** sur
l'inventaire *et* sur la délivrance.

*La jonction avec B3-a* — une ordonnance de deux lignes servie en une fois : le Paracétamol **tenu**
en rayon sort tout seul (stock **25 → 19**, mouvement portant `delivrance_id` et le motif
« Délivrance d'ordonnance »), l'Ibuprofène **absent de l'inventaire** est servi **quand même** et
**aucun article n'est fabriqué** pour lui. C'est la décision de §9.4, vérifiée en direct.

### 9.8 Limites

- **Aucun code-barres, aucune traçabilité nationale** (§7.6) — c'est **B3-c**.
- **Ni photo, ni TVA** sur l'article : le §7.5 les nomme, mais la TVA n'a aucun consommateur (il
  n'existe pas de facturation en officine) et la photo appartiendrait au produit national. Les
  créer serait le « socle à vide » refusé par P6.3-D3.
- **Aucun panier ni commande** (§7.7, CDC_01 §17 module 7) — c'est **B3-d**.
- **Le stock n'est pas suivi par lot** : les lots sont enregistrés sur les mouvements d'entrée et
  servent aux alertes de péremption, mais le stock courant reste global. Un vrai suivi FEFO
  (premier périmé, premier sorti) supposerait d'imputer chaque sortie à un lot — non fait, et dit.
- **La borne « pas de stock négatif » est applicative** : elle dépend d'une somme, qu'un déclencheur
  ne peut pas calculer sans lire la table qu'il garde (erreur 1442, précédent P6.4c).

## 10. B3-c — code-barres et traçabilité nationale (✅ VALIDÉ G5, 2026-09-04)

**Dernier sous-incrément du lot → B3 (Pharmacie) est COMPLET (a, b, c).** Referme **P5** et **P7**
du G0 du lot (code-barres, traçabilité nationale §7.6).

### 10.1 Le §7.6 tient en une phrase, et c'est le constat qui commande tout

> « Lutte contre les médicaments falsifiés, suivi de consommation, statistiques nationales. »

Trois finalités, aucun mécanisme. Contrairement à tous les incréments précédents où le corpus
décrivait un cycle, des états, des champs, ici il fallait **concevoir**, pas transcrire — d'où une
règle de méthode propre à ce lot : *chaque élément livré doit être justifié par celle des trois
finalités qu'il sert, et ce qui n'en sert aucune n'entre pas*.

### 10.2 Le G0 corrige trois affirmations du plan G1 du lot

- **Une trace locale partielle existait déjà**, et le plan ne le disait pas : `mouvements_stock.
  delivrance_id` (B3-b) survit à la suppression d'une ordonnance. Mais elle est **conditionnelle**
  (seulement si l'officine tient l'article) et **locale** (elle cascade vers `medicaments`) : *elle
  disparaît avec le produit dont elle devait suivre la consommation* — elle ne peut pas devenir le
  registre national par extension.
- **`lots_medicaments` ne devait pas être créée** : B3-b a délibérément fait du lot une colonne de
  `mouvements_stock`. La créer aurait été un second endroit où dire la même chose (refus P6.6a).
- **Les « statistiques nationales » n'avaient aucun consommateur** : `StatistiqueController::
  global()` comptait établissements, RDV, triages, avis — rien sur les médicaments.

### 10.3 La décision centrale : le registre ne porte AUCUNE donnée nominative

Ni patient, ni prescripteur, ni ordonnance, ni posologie, ni instructions. C'est ce qui rend sa
survie acceptable : autrement on aurait construit un dossier médical qui survit à la suppression du
dossier médical — l'inverse exact de la loi 2013-450. Il porte le **quoi** (identité de produit
figée), le **combien**, le **quand**, le **où** (officine).

`delivrances` (B3-a) **cascade sur l'ordonnance**, parce que le patient est maître de son carnet ;
son droit de supprimer ne doit pas être empêché par un besoin statistique. Or le registre national
doit survivre **exactement à ce que `delivrances` ne survit pas** → deux tables, deux natures :
`delivrances` est l'**acte**, rattaché au dossier ; `traces_dispensation` est le **fait national**,
détaché.

La ligne est construite **par liste blanche explicite**, jamais `toArray()` moins quelques clés :
*une future colonne fuiterait silencieusement dans un registre qu'on croit dénominalisé* (motif
exact de l'export anonymisant, P10c-3-i).

### 10.4 Dénominalisé n'est pas anonyme, et c'est dit avant de coder

`delivrance_ligne_id` reste un **identifiant sans clé étrangère** (ADR-042 D1) : il sert la
réconciliation et l'idempotence. Conséquence énoncée : *tant que la délivrance existe, qui tient la
base peut remonter au patient* ; une fois l'ordonnance supprimée, la trace devient réellement
orpheline. Même formulation qu'en P10c-2-i.

### 10.5 `code_barres` sur `medicaments`, jamais sur l'article d'officine

Un EAN/GTIN identifie un **produit du fabricant** : deux officines qui vendent la même boîte
scannent le même code. Il entre dans la projection gouvernée (`SourceMedicaments::extraire()`) —
*l'empreinte du référentiel change, ce n'est pas une dérive* (précédent `forme_juridique`, P6.4d).
Il reste **vide** à la naissance, et l'absence est **comptée et affichée** : 5ᵉ application du motif
`loinc`/CIM/`numero_agrement`.

### 10.6 Ce que le code-barres prouve, et ce qu'il ne prouve pas

Un falsificateur **recopie** un code-barres. Le scan permet de dire « **ce code n'est pas au
référentiel** » — **jamais** « cette boîte est authentique ». Ce qui prouverait l'authenticité est
la **sérialisation unitaire**, écartée par le plan G1 (elle suppose un dispositif national).

> *Un écran qui afficherait « authentique » sur la foi d'un EAN mentirait à un pharmacien sur le
> seul point où cela compte.*

L'écran dit **« connu du référentiel »** ou **« inconnu »**, et un code inconnu **signale sans
bloquer** — même raisonnement que B3-b : refuser priverait le patient de son traitement.

### 10.7 Le champ de saisie EST le scanner

Un lecteur de codes-barres USB de comptoir **se comporte comme un clavier** : il tape le code puis
un retour chariot. Un simple champ texte le reçoit, **sans aucune dépendance et sans internet**. La
caméra reste un confort, jamais le mécanisme — *faire dépendre une fonction de pharmacie d'un CDN
reproduirait le défaut que K4 de P6.4d a corrigé pour Bootstrap*.

### 10.8 Une dispensation non rattachée au référentiel entre quand même, et se compte

Le lien ordonnance → référentiel est **facultatif** (B3-a) : une ligne servie peut n'avoir aucun
`medicament_id`. Ne rien écrire rendrait la consommation nationale **fausse en silence** — la panne
muette que ce projet refuse partout. `medicament_code IS NULL` **est** le marqueur, aucune colonne
supplémentaire : l'écran de statistiques compte et affiche « N dispensations non rattachées ».

### 10.9 Append-only à deux niveaux, mais PAS une septième chaîne de hachage

L'append-only (modèle + déclencheurs, motif `mouvements_stock`) empêche une officine de réécrire son
historique. Une septième chaîne d'audit (ADR-042) protégerait contre un **autre** risque (qui tient
la base), et ADR-042 vient de montrer ce que coûte une chaîne : déclaration d'origine, ancrage de
tête, procédure de scellement, piège des identifiants pris dans l'empreinte. *On ne durcit pas par
symétrie décorative* — précédent P6.4a, qui a refusé le journal de non-réutilisation pour les
établissements.

### 10.10 Défaut trouvé par un VECTEUR de G3, corrigé pendant l'écriture

Le plan prévoyait `medicament_id` en **clé étrangère `nullOnDelete`**, comme sur
`ordonnance_lignes`. Un vecteur a montré que c'était faux pour ce cas précis : supprimer le
médicament parent fait exécuter par le moteur un **UPDATE** (mise à NULL) sur la trace, et le
déclencheur append-only bloquant tout **refuse cette mise à NULL elle-même** — empêchant purement
et simplement de retirer un produit du référentiel.

Corrigé en `unsignedBigInteger` **sans contrainte**, même famille que `structure_id` et
`delivrance_ligne_id` dans la même table : un **identifiant**, jamais une relation vivante
(ADR-042 D1). Les colonnes figées (`medicament_code`/`nom`/`dci`/`dosage`) portent déjà tout ce qui
doit survivre — un `medicament_id` devenu orphelin après suppression du produit est **inoffensif**,
il n'est jamais rejoint après coup.

### 10.11 Ce qui a été prouvé

**G3** — **38 vecteurs dédiés** (24 dans `TracabiliteMedicamentsTest`, 14 dans
`ReglesCodeBarresTest` en isolation totale, `PHPUnit\Framework\TestCase` et non le TestCase Laravel)
; suite complète **1676/1676, 17 821 assertions, 0 échec**. **Mutation : 7 tueuses + 1 témoin
volontairement vert** (réordonnancement de deux affectations indépendantes, sans effet observable —
*un harnais qui ne prévoit que des tueuses ne se teste jamais lui-même*), chaque mutation **assertée
appliquée**, arbre **restauré et vérifié par `diff`** après chacune. Baseline Pint établie **avant**
tout formatage : les fichiers modifiés qui échouaient déjà (alignement `=>`) n'ont pas été
reformatés, seules les violations réellement neuves l'ont été.

**Un vecteur mal choisi, corrigé pendant l'écriture** : le premier test de la garde de longueur GTIN
utilisait `'123456789'` (9 chiffres) — mais sans la garde de longueur, ce nombre précis échoue quand
même sur la clé de contrôle, par pure coïncidence. La mutation qui neutralisait la garde ne tuait
donc rien : *le vecteur prouvait autre chose*. Remplacé par `'963850742'`, construit pour que sa clé
de contrôle soit cohérente sur ses 8 premiers chiffres — la seule façon d'exercer réellement la
garde de longueur elle-même.

**G2 live** — base MySQL de développement réelle, sauvegardée puis **restaurée compte pour compte**,
`php artisan serve` réel, trois comptes de portail réels (un agent référentiel, un pharmacien, un
compte à `stats.global`), une chaîne HTTP réelle avec session et jeton CSRF.

*Schéma* — colonne `code_barres` sur `medicaments`, table `traces_dispensation` (`medicament_id`
**sans** contrainte, vérifié directement par `SHOW COLUMNS`), l'unicité `(pays_code, code_barres)`,
**3 déclencheurs** posés.

*Les gardes du moteur, éprouvées en SQL direct* — `1644` sur `UPDATE`, sur `DELETE`, sur quantité
nulle, chacun avec son message exact ; `1062` sur un doublon de code-barres dans le même pays.

*Le parcours réel, par le vrai portail* — GTIN à clé fausse → refus **affiché à l'écran**, rien
enregistré ; GTIN valide → enregistré, **empreinte du référentiel changée** (vérifiée avant/après,
deux valeurs distinctes) ; scan d'un code connu → produit nommé ; scan d'un code inconnu →
« inconnu du référentiel », **sans bloquer** ; délivrance réelle de deux lignes (une rattachée, une
non) → **deux traces**, vérifiées colonne par colonne (`medicament_id`, `medicament_code`,
`medicament_nom`, `medicament_dci`, `quantite`, `structure_id`, `delivrance_ligne_id`) ; **le
vecteur central vérifié en réel** : zéro ligne créée dans `acces_dossier` par cette délivrance.

*La suppression réelle de l'ordonnance* — ordonnance et délivrance disparues (cascade réelle),
**les deux traces restées identiques**, colonne par colonne.

*L'écran de statistiques, par le vrai portail* — consommation par produit exacte, compteur des
non-rattachées exact, couverture code-barres du référentiel exacte (« 2 / 18 »).

*Restauration* — base restaurée depuis la sauvegarde `mysqldump`, vérifiée par comptage
(`users`, `medicaments`, `traces_dispensation`, `ordonnances`, `structures`) et par relecture
directe des deux fiches produit modifiées pendant le G2, revenues à `code_barres NULL`.

Aucune dépendance nouvelle.

### 10.12 Limites

- **La lutte contre les médicaments falsifiés n'est qu'à moitié servie** : on détecte l'inconnu, on
  ne prouve pas l'authentique (§10.6). La sérialisation unitaire suppose un dispositif national.
- **Aucun code-barres réel** : la colonne naît vide, et c'est compté et affiché. Les charger est de
  la donnée, zéro code — et tant que ce n'est pas fait, le scan ne reconnaît rien.
- **Aucun scan mobile** : dépendance §2.6, non demandée.
- **La caméra du portail reste sur un CDN** (`html5-qrcode`) — B3-c ne la corrige pas, il **ne s'y
  appuie pas** (§10.7). La limite de P6.4d reste ouverte, avec son porteur.
- **Pas de lot sur la trace** : le stock de B3-b n'est pas suivi lot par lot → un rappel de lot
  national n'est pas réalisable, et le dire vaut mieux que le suggérer.
- **Dénominalisé, pas anonyme** (§10.4).
- **Asymétrie gouvernance / lecture** : `code_barres` entre dans la projection gouvernée, mais le
  scan lit la table (une colonne neuve n'est dans aucune version publiée ; tout le domaine
  pharmacie lit déjà la table ; un refus bruyant devant un comptoir bloquerait une dispensation).
  Même famille que `poids_severite` en P10b-3-ii — porteur : l'élévation de la gouvernance du socle
  P6.3, déjà nommée par P10b-3-ii (*une dette sans porteur ne se fait jamais*, leçon L1+L2).
- **`disponibilité des médicaments` du §8.5** (portail Ministère) reste hors périmètre : c'est une
  autre section du corpus, et B3-c sert le §7.6.
