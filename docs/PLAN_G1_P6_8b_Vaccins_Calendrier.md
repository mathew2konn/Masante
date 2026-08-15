# Plan G1 — P6.8b : Vaccins disponibles + calendrier vaccinal national (CDC_09 §8)

> Deuxième incrément de **P6.8 Référentiels transverses** (étape 8 de l'ordre §14).
> Referme **T2** du G0 de P6.8 : « le statut d'une vaccination est déclaré par le client ».
>
> Statut : **G1 VALIDÉ par le propriétaire (2026-08-14).** Trois décisions prises après échange :
> **W2-bis** le critère de la fiche vitale est refait sur la **provenance** · **W3** le calendrier
> **émet une notification P7-D1** à l'échéance · **W7** l'incrément comprend **l'écran mobile**.
> Le G0 ci-dessous a été conduit en lisant les migrations, le modèle, les trois chemins d'écriture,
> les consommateurs et le client mobile — il corrige et **aggrave** le constat T2.

---

## 1. G0 — ce que le code dit réellement

### U1 — Les deux colonnes déclarées par le client sont **exactement** celles que lit l'écran d'urgence

C'est le constat qui change la nature de cet incrément. `FicheVitaleService::vaccinationsEssentielles()`
fait, ligne 118 :

```php
$membre->vaccinations
    ->where('obligatoire', true)
    ->where('statut', 'fait')
```

Cette fiche est lue **sans authentification** : carte vitale montrée à un secouriste (§5.1), corps du
SMS du bouton SOS (§5.2), voie « bris de glace » d'un service d'urgences (§5.3). Or `obligatoire` et
`statut` sont les **seules deux colonnes de la table que le client déclare librement** — et ce sont
**les deux seules que ce filtre utilise**. Aucune autre fonction du projet ne les lit.

Conséquence en deux sens, dont aucun n'était écrit jusqu'ici :

- **Faux positif** — n'importe qui cochant « obligatoire » + « fait » sur une ligne libre fait
  apparaître au secouriste une vaccination essentielle **présentée comme attestée** ;
- **Faux négatif** — un BCG réellement administré, saisi sans cocher « obligatoire », est
  **invisible** du secouriste, alors qu'il est dans le carnet.

Le G1 de P6.8 classait T2 comme un défaut de propreté du modèle. Il ne l'est pas : c'est le seul
endroit du projet où une valeur auto-déclarée est présentée sans réserve à un tiers en situation
d'urgence. *Ce n'est pas la vaccination qui est mal typée, c'est la fiche vitale qui croit le carnet.*

### U2 — `en_retard` est **figé au jour de la saisie**, et rien ne le rafraîchit jamais

`statut` est écrit une fois, à la création, par les trois chemins d'écriture (patient,
délégué via `ContributionCarnetService`, soignant via `EcritureSoignantService`). **Aucun code de ce
projet ne repasse jamais sur ces lignes.** Un « à faire » dont la `date_rappel` est dépassée depuis
six mois reste éternellement « à faire ».

Donc le défaut n'est pas seulement que le statut est *déclaré* : c'est qu'il est **périmé par
construction**. Un statut qui ne peut pas changer avec le temps n'est pas un statut, c'est un
souvenir. Et il est affiché au carnet avec une pastille de couleur (`VACCIN_STATUT_TON`), ce qui lui
donne l'autorité d'un calcul.

### U3 — Aucun calendrier, et pas même la notion de dose

`vaccin_nom` est `required|string|max:200`. « BCG », « bcg » et « B.C.G. » sont trois vaccins
différents pour ce système — 4ᵉ instance de la famille `medecin_nom` / `medicaments_json.*.nom` /
`resultats_json` que P6.5, P6.6b et P6.7 ont refermées une à une.

Et il n'y a **aucune notion de dose** : le Pentavalent saisi trois fois produit trois lignes
indiscernables, sans qu'on puisse savoir s'il s'agit des doses 1, 2 et 3 ou de trois saisies en
double. Rien, nulle part, ne sait qu'un nourrisson de six semaines a un rendez-vous vaccinal.

### U4 — `rappels.type = 'vaccin'` existe depuis le Module 2 et n'a **jamais eu de source**

La table `rappels` porte un type `vaccin` depuis juin. Ces rappels sont saisis **à la main** par le
patient. Un calendrier vaccinal est exactement ce qui pourrait les produire — d'où une question de
périmètre à trancher (décision **W3**), et non une évidence à trancher seul.

### U5 — Le mobile porte les libellés de statut **en dur** — 3ᵉ récidive du constat G-a de P6.4b

`apps/mobile/src/carnet/registre.ts:23` fige `VACCIN_STATUT` et `VACCIN_STATUT_TON`, et la ligne 140
en fait un `select` **obligatoire** du formulaire. Si le statut devient calculé côté serveur et que
ce champ reste, on demanderait au patient de déclarer ce que le serveur sait — la contradiction
serait visible à l'écran.

### U6 — Un vaccin est-il un médicament ? La question est posée, pas supposée

`medicaments` (P6.6a) existe, avec `forme`, `dosage`, `voie_administration`. Un vaccin **est** un
médicament au sens réglementaire, et la tentation de l'y ranger est réelle.

**Réponse : non, et la raison est fonctionnelle, pas administrative.** Ce que le §8 demande, c'est
« vaccins disponibles **et calendrier vaccinal national** ». Le calendrier porte sur l'**antigène**
(BCG, Pentavalent, Rougeole), jamais sur la présentation commerciale : deux lots de Pentavalent de
deux fabricants ne créent pas deux échéances à six semaines. Accrocher le calendrier national à une
ligne de `medicaments` ferait changer l'échéance de porteur le jour où le fabricant change.

En sens inverse, **le produit réellement injecté reste un produit** : `vaccinations.numero_lot`
existe déjà et ne bouge pas. Si un jour le lot doit désigner un produit commercial, `medicaments`
est là et le lien sera additif (ADR-024).

### U7 — Ce qui est déjà là et ne doit pas être refait

`source` / `added_by` existent sur `vaccinations` depuis P7-D0, la section est ouverte aux trois
chemins d'écriture, `preparerDonnees()` est le point d'accroche serveur posé par P6.6b et déjà
utilisé par les ordonnances et les analyses. **Cet incrément n'a aucune plomberie à inventer.**

---

## 2. Le point de conception

> **Un calendrier vaccinal ne répond pas à « ce vaccin est-il fait ? » mais à
> « qu'est-ce qui est dû, pour cette personne-là, aujourd'hui ? ».**

C'est la même bascule qu'en P6.7a, où une plage biologique s'est révélée dépendre de la personne
(11 g/dL bas chez l'homme, normal chez la femme enceinte). Ici la variable n'est pas le sexe mais
**l'âge en jours** : à six semaines une échéance existe, à cinq semaines elle n'existe pas encore.

D'où la conséquence de structure : **l'échéance ne peut pas être une colonne du vaccin.** Le
Pentavalent a trois doses à 6, 10 et 14 semaines — une colonne n'en porterait qu'une. Il faut
**une ligne par (vaccin, dose)**, exactement comme `analyse_references` porte une ligne par strate.

Et une conséquence de frontière : **l'âge se compte en JOURS, pas en mois.** Un calendrier exprimé
en mois ne sait pas dire « six semaines », qui est l'échéance la plus dense du calendrier PEV.

---

## 3. Décisions proposées

### W1 — Deux tables, un seul instantané gouverné

| Table | Ce qu'elle porte |
|---|---|
| `vaccins` | l'**antigène** : code national, libellé, maladies évitées, nombre de doses, voie, statut |
| `calendrier_vaccinal` | une ligne par **(vaccin, dose)** : âge dû en **jours**, obligatoire ou non, `source` |

Les deux entrent dans `RegistreReferentiels` sous **un instantané unique** : les publier séparément
laisserait une échéance désigner un vaccin absent de la version en vigueur, donc une référence
irrésoluble. C'est le motif exact des interactions de P6.6a et des strates de P6.7a.

Code national **`VAC` + 6 chiffres, littéral et sans clé** — 5ᵉ application du précédent
`ETS`/`PRO`/`MED`/`ANA`. Un vaccin est une **instance** (ce produit-là), pas un terme de
nomenclature : il se numérote, à la différence d'une spécialité (P6.8a) ou d'une région (P6.4a).
`UNIQUE(pays_code, code)`, hors `$fillable`.

### W2 — `statut` devient un **calcul**, `obligatoire` une **lecture** — et la colonne ne bouge pas

Frontière CDC_01 §0.1 : un état métier est **fourni** par le backend, jamais déclaré ni déduit.

- Le `statut` est **dérivé à la lecture** par une classe **pure** `ReglesCalendrierVaccinal`
  (motif `ReglesReversement`, `ReglesIntervalleReference`), exposé par un **accesseur** du modèle.
  U2 est refermé **par construction** : il n'y a plus de valeur à rafraîchir, puisqu'il n'y a plus
  de valeur stockée qui fasse autorité.
- **Les colonnes sont conservées** (ADR-024, additif) : `vaccinations.statut` est lue par le cache
  hors ligne de P2 et sérialisée par des modules **validés G5**. Le contrat de lecture ne change
  pas d'un octet — c'est ce qui rend la bascule chirurgicale, exactement comme `statutPour()` en
  L1/L2.
- `statut` **cesse d'être accepté du client** sur les trois chemins d'écriture (`preparerDonnees()`).

### W3 — Ce que le calendrier fait, et ce qu'il ne fait pas *(décision propriétaire)*

Il **répond** à « qu'est-ce qui est dû pour cette personne ? » via un endpoint de lecture
`GET /membres/{membre}/calendrier-vaccinal` : pour chaque échéance du référentiel applicable à
l'âge du membre, ce qui est fait, ce qui est dû, ce qui est en retard.

Il **prévient** : une échéance atteinte émet une **notification P7-D1** au responsable du carnet
(décision propriétaire). Le canal existe, il est prouvé, et il porte déjà la règle inviolable
« aucun contenu médical dans une notification » — *ici elle mord* : le message dira qu'une
vaccination est due, **jamais laquelle**. Un push s'affiche sur un écran verrouillé et transite par
un tiers ; le nom d'un vaccin est une information de santé.

Il **n'écrit rien dans le carnet** — ni dans `vaccinations`, ni dans `rappels` (U4). Générer des
lignes serait un quatrième chemin d'écriture dans une table du carnet, avec la question du rejeu et
de la suppression par le patient ; la notification obtient le même résultat sans ouvrir cette porte.
`rappels.type = 'vaccin'` reste donc **saisi à la main**, et c'est dit comme limite.

### W2-bis — La fiche vitale change de critère *(décision propriétaire)*

Correction chirurgicale de `FicheVitaleService`, module validé G5. Elle cesse de croire la case
« obligatoire », **parce que la table porte déjà un signal que le serveur garantit et qu'elle
n'utilise pas** : `source` / `added_by`, écrits par le serveur depuis P7-D0 et infalsifiables par le
client (contribution → `patient`, écriture soignant → `medecin`).

La fiche montre donc les vaccinations **faites** — statut désormais calculé (W2) — en **séparant ce
qui est attesté par un soignant de ce qui est auto-déclaré**. Rien ne disparaît de l'écran
d'urgence, et ce qui s'y affiche cesse de prétendre plus qu'il ne sait. *Le bouclier coché ne
couvrira plus une case cochée par l'intéressé lui-même.*

### W7 — L'écran mobile fait partie de l'incrément *(décision propriétaire)*

Conforme à la décision de P6.5 (« les écrans ne sont plus reportés à la fin ») après les deux
reports de P6.4 (M1 puis O1). Le calendrier vaccinal est le seul référentiel de P6 qui s'adresse
**directement au citoyen** : un calendrier que le parent ne voit pas ne sert qu'aux statistiques.
Referme U5 au passage — le `select` de statut disparaît du formulaire, puisqu'on ne demande pas à
quelqu'un de déclarer ce que le serveur calcule.

### W4 — Le lien au référentiel est **facultatif**, mais ce qui est lié est **relu et figé**

Motif P6.6b / P6.7b, pour la même raison qu'alors : un patient qui recopie un carnet papier n'a pas
la liste sous les yeux, et le référentiel est incomplet — imposer le lien ferait de ses **lacunes**
un blocage. Quand `vaccin_id` est fourni, le code national, le libellé et la dose sont **relus au
référentiel et figés**, jamais crus du client.

### W5 — Honnêteté sur le contenu, 3ᵉ application du motif `loinc` / `demonstration`

**Je n'ai vu ni l'arrêté du PEV ivoirien, ni le calendrier officiel publié par le Ministère.** Le
calendrier élargi de vaccination de l'OMS est public et largement standard, mais l'inscrire comme
« calendrier national ivoirien » sans l'avoir vu serait précisément *la liste inventée qui a l'air
juste, et qui pour cette raison ne se fait jamais corriger* (raisonnement P6.4a sur les 33 régions).

Donc, motif P6.7a repris à l'identique : colonne `source` **obligatoire en base**, contrôle qualité
qui **refuse de publier** une échéance sans provenance, et l'écran affiche le **compte exact** des
échéances encore issues de la démonstration. Charger le calendrier officiel sera **de la donnée,
zéro code** — et tant que ce n'est pas fait, ce n'est pas un calendrier national.

### W6 — `vaccin.referentiel`, portée par **aucun rôle métier** — 9ᵉ occurrence

Un centre de vaccination ne fixe pas le calendrier national : il serait juge et partie sur ce
qu'il administre. Vérifiée **en service** et non par le middleware spatie (routes Sanctum,
permissions guard `web` — piège P4 `rdv.validate`).

---

## 4. Vecteurs en miroir exigés

1. Un vaccin **retiré du marché** → l'empreinte du référentiel **change** (c'est une donnée gouvernée).
2. Une **vaccination inscrite au carnet d'un patient** → l'empreinte **ne change pas**
   (rappel du critère d'ADR-026 : le référentiel gouverne le vocabulaire, pas les actes).
3. Un membre de **5 semaines** et un de **7 semaines** → **deux réponses différentes** au même
   endpoint, l'échéance à 6 semaines n'apparaissant que pour le second.
4. Une ligne saisie « à faire » **il y a un an** → répond **`en_retard`** aujourd'hui sans qu'aucune
   écriture ait eu lieu (U2 refermé).
5. `statut` et le code national envoyés par le client → **ignorés**, sur les **trois** chemins d'écriture.

---

## 5. Preuves attendues

- **G3** — vecteurs dédiés écrits dans les deux sens ; suite complète verte ; typecheck ×3 ;
  `expo-doctor` ; **mutation obligatoire, chaque mutation assertée appliquée avant d'être
  interprétée** (piège de P6.7b : une mutation qui ne s'applique pas ressemble exactement à un
  vecteur qui survit).
- **G2 live MySQL** — schéma et contraintes ; backfill dry-run → réel → rejeu ; doublon refusé par
  le moteur ; borne d'âge incohérente refusée par le **trigger** (le `CHECK` est impossible :
  colonnes `cascadeOnDelete`, erreur **3823**, mur de P6.3) ; gouvernance à deux agents ;
  `UPDATE` direct sans effet sur le diffusé ; les cinq vecteurs du §4 ; **base restaurée compte
  par compte**.
- **G4** — `GUIDE_TEST_TRANSVERSES.md` **partie 2**, écrite avant le G4.

---

## 6. Limites qui seront annoncées

1. **Contenu = jeu de démonstration** tant que le calendrier officiel n'est pas chargé (W5).
2. **Aucun rappel automatique** (W3) — sous réserve de la décision propriétaire.
3. Le lien reste **facultatif** : une ligne libre ne bénéficie d'aucune garantie.
4. **Aucun écran de gouvernance mobile**, comme tous les référentiels depuis P6.3.
5. **L1/L2 d'ADR-025 s'appliquent** : la fiche vitale et le carnet lisent les tables en direct.
6. Aucun rattrapage rétroactif des lignes existantes : elles restent non liées, donc sans code
   national — *leur en inventer un serait un mensonge d'archive* (précédent L2).
