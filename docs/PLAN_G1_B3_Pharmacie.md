# Plan G1 — B3 : Pharmacie (CDC_11 §7, étape 7 de §12)

**Statut : EN ATTENTE DE VALIDATION ÉCRITE DU PROPRIÉTAIRE.** Aucune ligne de code n'est écrite avant.

**Ce plan couvre les neuf manques relevés au G0**, sans en écarter aucun : chacun est rattaché à un
sous-incrément nommé, et ceux qui sortent du premier lot le font avec leur porteur et leur raison.

---

## 1. G0 — ce qui a été vérifié dans le code

### Ce qui existe

| | |
|---|---|
| `prix_pharmacie` | relevés de prix et de disponibilité (+ `quantite`, ajoutée par P11.2) |
| `pharmacies_garde` | pharmacies de garde |
| API d'ingestion (P11.2) | un logiciel partenaire pousse stock et prix — **le mode 2 du §7.7** |
| Mobile | recherche de médicaments, fiche, comparateur de prix, pharmacies de garde |
| Rôle `pharmacien` (P11.0) | `medicament.manage` + `qr.scan` |
| Référentiel national (P6.6a) | code, DCI, dosage, forme, voie, indications, contre-indications, statut |

### Les neuf constats

| # | Constat |
|---|---|
| **P1** | **Cinq tables sur sept manquent** : `stocks_pharmacie`, `delivrances`, `renouvellements`, `commandes`, `lots_medicaments`. `delivrances` et `renouvellements` sont pourtant nommées par CDC_04 §105 |
| **P2** | **`prix_pharmacie` est un RELEVÉ, pas un stock** : prix, disponibilité, quantité, source. Ni lot, ni péremption, ni entrée/sortie — là où le §7.3 demande les quatre |
| **P3** | **L'écran « stock » du portail ne gère pas de stock** : `StockPharmacieController` déclare un prix ou une rupture. Le nom est trompeur |
| **P4** | **Le §7.5 décrit la fiche de l'OFFICINE**, pas le référentiel national : code-barres, photo, TVA, stock, seuil, péremption sont des données propres à une pharmacie |
| **P5** | **Le mobile n'a ni panier ni commande**, alors que CDC_01 §17 module 7 les annonce |
| **P6** | **Rien ne permet à un pharmacien de recevoir une ordonnance** — et `qr.scan` ouvre une session sur **tout le carnet** : antécédents, vaccinations, résultats d'analyses |
| **P7** | **Aucune traçabilité §7.6, aucun code-barres** |
| **P8** | **Le mode 2 du §7.7 existe (P11.2), le mode 1 non** : la « gestion directe » se réduit à déclarer un prix |
| **P9** | **B2-c attend ce lot** : les lignes d'ordonnance ont été écartées faute de consommateur — la délivrance est ce consommateur |

---

## 2. Les décisions

### D1 — Comment le pharmacien reçoit l'ordonnance

**Le besoin.** §7.1 : `Médecin → Prescription numérique → Pharmacien`.

**Le problème, et c'est le constat qui commande tout le lot.** Le seul mécanisme existant (`qr.scan`)
ouvre une **session de dossier** : le pharmacien verrait les antécédents, les vaccinations, les
résultats d'analyses. **Un pharmacien n'a pas à lire les antécédents pour servir une boîte de
paracétamol.** C'est le principe de minimisation de la loi 2013-450, que ce projet applique déjà
explicitement (P7-D2 : les documents sont listés, jamais téléchargeables depuis le portail).

| Option | Ce que ça donne |
|---|---|
| **(a)** 7<sup>e</sup> voie d'accès au dossier (`pharmacie_partage`) | Réutilise tout le mécanisme B1-c… et ouvre le carnet entier pour un besoin étroit |
| **(b)** **Jeton d'ordonnance** — le patient partage UNE ordonnance | Le pharmacien lit l'ordonnance, et rien d'autre |
| **(c)** Le pharmacien scanne le QR patient existant | Le plus court, et le plus disproportionné |

**Ma décision : (b), un jeton d'ordonnance.** Trois raisons.

1. **La minimisation n'est pas négociable ici.** Le besoin du pharmacien est borné par le §7.2 :
   authenticité, disponibilité, interactions, contre-indications. Les trois premières se vérifient
   sur les **médicaments prescrits**, pas sur le dossier. (La quatrième est traitée en D6.)
2. **Le patron existe déjà et il est éprouvé** : le jeton de fiche de triage (P10a) — 48 caractères
   aléatoires, hors `$hidden`, comparaison en temps constant, **404 et jamais 403** (un 403
   confirmerait qu'une ordonnance existe là). On ne l'invente pas, on l'applique.
3. **Une 7<sup>e</sup> voie d'accès serait un élargissement durable pour un besoin ponctuel.** Ce
   projet a refusé exactement cela au bris de glace en écriture (P7-D0) : *un accès d'exception ne
   devient pas un droit général*.

**Ce que ça coûte, dit avant de coder** : le pharmacien ne voit **que** l'ordonnance. S'il lui faut
davantage, ce sera une décision séparée — pas un effet de bord de celle-ci.

---

### D2 — Le stock : une entité propre, et `prix_pharmacie` ne change pas de nature

**Le problème.** `prix_pharmacie` mélange déjà deux sources : le relevé d'un patient
(`crowdsource_patient`) et la déclaration de l'officine. Y ajouter lots et péremption ferait porter
à une table déclarative des données dont **l'officine seule répond**.

| Option | Ce que ça donne |
|---|---|
| **(a)** Étendre `prix_pharmacie` | Court ; mélange un relevé public et un inventaire professionnel |
| **(b)** **`stocks_pharmacie` + `mouvements_stock`** | Deux natures séparées ; l'inventaire alimente le relevé |
| **(c)** Ne rien faire | Le §7.3 reste entièrement absent |

**Ma décision : (b).** La ligne est celle que ce projet trace depuis P6.4a : *ce qu'un humain
identifié dépose délibérément* d'un côté, *ce qui est relevé ou recalculé* de l'autre.

- `prix_pharmacie` **reste ce qu'il est** : le relevé public, alimenté par les patients, l'officine
  ou son logiciel. C'est lui que lit le comparateur, et il ne change pas de contrat (module G5).
- `stocks_pharmacie` est **l'inventaire tenu par l'officine** (§7.5) : article, lot, péremption,
  quantité, seuil d'alerte.
- **L'inventaire ALIMENTE le relevé, il ne le double pas.** Une seule valeur publique de prix et de
  disponibilité — sinon le comparateur et la fiche officine pourraient se contredire, et *le patient
  ne saurait pas laquelle croire* (motif P6.7b sur le délai du laboratoire).

**`mouvements_stock` est append-only** : une entrée, une sortie, une péremption sont des **faits
datés**. Le stock courant est leur **somme**, jamais une valeur stockée qu'on corrige — c'est la
partie double du wallet (P5.3a), dont la leçon est exactement celle-ci.

---

### D3 — La délivrance porte sur des LIGNES, et c'est ce qui débloque B2-c

**Le problème.** Une délivrance partielle est le **cas normal** : la pharmacie a deux médicaments
sur trois. Une délivrance « en bloc » ne saurait pas le dire.

**Ma décision : créer `ordonnance_lignes`** — le report de B2-c-D3 est levé, **parce que son
consommateur existe enfin**. C'était la condition posée : « aucun consommateur aujourd'hui ; sa
raison d'être est la délivrance en pharmacie, qui n'existe pas ».

**ET LA DÉCISION QUE B2-c AVAIT RENVOYÉE À PLUS TARD DOIT ÊTRE PRISE ICI**, parce qu'elle ne peut
plus être différée : *« l'interrogeabilité ne s'obtient qu'en cessant de chiffrer — une décision qui
mérite d'être prise pour elle-même »*.

Partage proposé, aligné sur la ligne déjà tranchée du projet (constat Y9 de B2, `resultats_analyses`
laisse `intitule` en clair et ne chiffre que les valeurs mesurées) :

| En clair | Chiffré |
|---|---|
| `medicament_id`, `code_national`, `dci`, `dosage` — des **identifiants de produit** | `posologie`, `duree`, `instructions` — ce que le médecin a **prescrit à cette personne** |

*Ce qui identifie un produit n'est pas ce qui décrit un traitement.* Et sans les identifiants en
clair, ni la délivrance, ni la vérification d'interactions, ni le §7.6 ne sont possibles.

**`medicaments_json` n'est PAS supprimé** (ADR-024, enrichissement additif) : les ordonnances
antérieures le gardent, et il reste la source des chemins patient. Les lignes sont créées **à
l'écriture**, sur les trois chemins.

**Conséquence assumée** : les ordonnances **antérieures** n'ont pas de lignes, donc **ne sont pas
délivrables électroniquement**. Aucune rétro-génération : recréer des lignes depuis un JSON saisi
librement par un patient produirait des lignes que personne n'a vérifiées, sur un document
potentiellement signé. Le dire vaut mieux que le deviner.

---

### D4 — Le code-barres appartient au produit national, pas à l'officine

Un code-barres (EAN/GTIN) identifie un **produit du fabricant** : deux officines qui vendent la même
boîte scannent le même code. Le mettre sur l'article d'officine le ferait ressaisir par chacune, et
diverger.

**Ma décision : `code_barres` sur `medicaments`** (référentiel national, P6.6a), en sachant et en
disant que **cela fait diverger la projection gouvernée** — exactement comme `forme_juridique` en
P6.4d. Ce n'est pas une dérive : un code-barres engage l'identité du produit.

Il reste **vide**, et c'est compté et affiché — 5<sup>e</sup> application du motif `loinc`/CIM/
`numero_agrement` : *un champ vide qui s'annonce vaut mieux qu'un champ rempli au jugé*.

Le reste du §7.5 (photo, TVA, prix, stock, seuil, péremption) vit sur `stocks_pharmacie` : ce sont
des données d'officine.

---

### D5 — Renommer ce qui ment (P3)

`StockPharmacieController` ne gère pas de stock. Le laisser ainsi à côté d'un vrai stock rendrait le
code illisible et **ferait chercher la gestion de stock au mauvais endroit**.

**Ma décision : le renommer `PrixOfficineController`** (et sa route `stock` → `prix`), en gardant
son comportement **strictement inchangé** — c'est un renommage, pas une refonte. Précédent : P11.0
a renommé trois rôles pour la même raison, en transférant avant de supprimer.

---

### D6 — Ce que le §7.2 pourra vraiment vérifier, dit sans embellir

| Le §7.2 demande | Ce qu'on peut faire | Pourquoi |
|---|---|---|
| **Authenticité** | ✅ le jeton prouve que l'ordonnance vient du système, et la signature (P6.5b) qu'elle n'a pas été altérée | Déjà là |
| **Disponibilité** | ✅ depuis `stocks_pharmacie` (D2) | Livré par ce lot |
| **Interactions** | ✅ **consultation explicite**, jamais un calcul automatique | Choix propriétaire de P6.6b, non rouvert : calculer rapprocherait le module d'une aide à la décision (CDC_05/CDC_08) |
| **Contre-indications** | ❌ **impossible** | Les allergies sont du texte libre chiffré (constat Y4 de B2). Une vérification partielle afficherait « aucune contre-indication » sur un patient qui en a une — *plus dangereux que pas de vérification du tout* (B2-a D4, raisonnement P6.8e) |

**Le §7.2 sera donc atteint à trois quarts, et le lot doit le dire.** Porteur du quart manquant :
le **référentiel d'allergènes**, déjà nommé par B2-a.

---

### D7 — Découpage : quatre sous-incréments, et l'ordre n'est pas celui du corpus

| Sous-lot | Contenu | Manques refermés |
|---|---|---|
| **B3-a** | `ordonnance_lignes` + jeton d'ordonnance + réception par le pharmacien + **délivrance** (§7.1, §7.2 partiel) | **P1** (délivrances), **P6**, **P9** |
| **B3-b** | Fiche officine + stock réel + mouvements + seuils et péremption (§7.3, §7.5) + renommage (D5) | **P1** (stocks, lots), **P2**, **P3**, **P4**, **P8** |
| **B3-c** | Code-barres + traçabilité nationale (§7.6) | **P1** (traçabilité), **P7** |
| **B3-d** | Panier, commande, renouvellement côté mobile (CDC_01 §17 module 7) | **P1** (commandes, renouvellements), **P5** |

**Pourquoi B3-a avant B3-b, alors que le corpus décrit le stock d'abord.** La délivrance est le
**maillon manquant** : elle referme le §5.4 (`Médecin → Patient → Pharmacie`), dont B2-c n'a livré
que le premier tronçon, et elle donne enfin un consommateur aux lignes d'ordonnance. Le stock, lui,
a déjà un substitut fonctionnel — `prix_pharmacie.disponible`, qui alimente le comparateur depuis le
Module 5. **Ce qui manque entièrement passe avant ce qui existe imparfaitement.**

**Aucun sous-lot n'est un socle à vide** : B3-a a son consommateur (le pharmacien qui sert),
B3-b le sien (la délivrance qui vérifie la disponibilité), B3-c le sien (la délivrance qui scanne),
B3-d le sien (le patient).

---

## 3. Hors périmètre de B3, avec la raison

| Écarté | Raison |
|---|---|
| Vérification des contre-indications | Suppose un référentiel d'allergènes (D6) — module CDC_09 |
| Calcul automatique des interactions | Choix propriétaire de P6.6b : consultation explicite |
| Paiement d'une commande | CDC_06 / P5 ; une commande payée est un autre sujet qu'une commande passée |
| Livraison à domicile | §7.4 mentionne « livraison, rayon » comme **données de la fiche**, pas comme un service à construire |
| Sérialisation unitaire des boîtes | Le §7.6 vise la lutte contre les falsifiés ; la sérialisation par boîte suppose un dispositif national |

---

## 4. Ce qu'il faudra prouver

**G2 (live)** — base MySQL réelle sauvegardée puis restaurée : une ordonnance écrite par un médecin,
partagée par jeton, **lue par un pharmacien qui n'accède à aucune autre partie du dossier**,
délivrée partiellement, puis la seconde délivrance qui complète — et les refus, chacun **par son
motif exact**.

**G3** — suite complète verte (référence actuelle : **1595 tests**), Pint avec **baseline établie
contre `HEAD`**, campagne de mutation avec au moins un témoin volontairement vert.

**G4** — test réel par le propriétaire.

---

## 5. Pièges connus qui s'appliquent ici

- **Le jeton ne doit jamais répondre 403** : un 403 confirme l'existence (P10a).
- **`$fillable` et assignation de masse** : une colonne posée par le serveur mais absente de
  `$fillable` est écartée **en silence** (P6.7b, revu en B2-c).
- **Baseline Pint** : plusieurs fichiers du dépôt échouent déjà ; ne pas les reformater (leçon B2-b).
- **Ancre de mutation sur une seule ligne, et unique dans le fichier** (P6.8e, revu en B2-c).
- **Vérifier qu'un vecteur meurt pour la BONNE raison** : onze occurrences dans ce projet.
- **`CHECK` impossible sur une colonne à action référentielle** (erreur 3823) → déclencheurs dans
  les deux dialectes, ou rien.

---

## 6. ADR

Ce lot produira **ADR-055**, et lèvera explicitement le report de **ADR-054 §9** (les lignes
d'ordonnance) en nommant le consommateur qui manquait.
