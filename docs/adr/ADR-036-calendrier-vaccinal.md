# ADR-036 — Vaccins et calendrier vaccinal national (P6.8b)

- **Statut** : Accepté (2026-08-15)
- **Corpus** : CDC_09 §8 « Référentiels transverses » — **étape 8** de l'ordre §14
- **Se rattache à** : ADR-024 (référentiels additifs), ADR-025 (socle de gouvernance + L1/L2),
  ADR-034 (le précédent des strates : une réponse qui dépend de la personne), ADR-035 (P6.8a)
- **Referme** : le constat **T2** du G0 de P6.8 — « `statut` et `obligatoire` cessent d'être
  déclarés par le client »

---

## 1. Contexte — le G0 a trouvé plus grave que ce que le plan de P6.8 annonçait

Le plan de P6.8 décrivait T2 comme une entorse à la frontière : deux colonnes d'état déclarées par
le client. C'est vrai, et c'est insuffisant.

**`obligatoire` et `statut` étaient les deux seules colonnes de `vaccinations` remplies librement
par le client, et elles étaient exactement — et uniquement — les deux que lisait la fiche vitale
d'urgence.**

```php
// FicheVitaleService, avant P6.8b
$membre->vaccinations->where('obligatoire', true)->where('statut', 'fait')
```

Cet écran est montré **sans authentification**, à un secouriste qui tient le téléphone, sous un bloc
« Vaccinations essentielles » et une icône de bouclier coché. La conséquence joue dans les deux
sens : n'importe qui cochant les deux cases faisait apparaître une vaccination **présentée comme
attestée** ; et un BCG réellement administré, saisi sans cocher « obligatoire », en était **absent**.
*Le bouclier coché couvrait une case cochée par l'intéressé lui-même.*

Trois autres constats du même G0 :

| # | Constat |
|---|---|
| **U2** | `statut` était écrit **une fois**, à la saisie, et rien ne repassait jamais dessus : un « à faire » dont le rappel était dépassé depuis six mois restait « à faire », avec une pastille de couleur qui lui donnait l'autorité d'un calcul. **Périmé par construction.** |
| **U3** | Aucun calendrier, et **pas même la notion de dose** : « 2ᵉ dose de Pentavalent » n'était pas représentable. |
| **U5** | Les libellés de statut vivaient **en dur côté mobile** — 3ᵉ récidive du constat G-a de P6.4b. |

---

## 2. Le point de conception

> Un calendrier vaccinal ne répond pas à « ce vaccin est-il fait ? » mais à
> **« qu'est-ce qui est dû, pour cette personne-là, aujourd'hui ? »**.

C'est la bascule d'ADR-034, où une plage biologique s'est révélée dépendre de la personne. Ici la
variable n'est pas le sexe mais **l'âge en jours** : à six semaines une échéance existe, à cinq
semaines elle n'existe pas encore.

Deux conséquences structurelles en découlent :

1. **L'échéance ne peut pas être une colonne du vaccin.** Le Pentavalent a trois doses ; une colonne
   n'en porterait qu'une. Il faut **une ligne par (vaccin, dose)**, comme `analyse_references` porte
   une ligne par strate.
2. **L'âge se compte en JOURS, pas en mois.** Un calendrier exprimé en mois ne sait pas dire « six
   semaines », qui est l'échéance la plus dense du calendrier PEV.

---

## 3. Décisions

### 3.1 Deux tables, un seul instantané gouverné

| Table | Ce qu'elle porte |
|---|---|
| `vaccins` | l'**antigène** : code national, libellé, maladies évitées, nombre de doses, voie, statut marché |
| `calendrier_vaccinal` | une ligne par **(vaccin, dose)** : âge dû en **jours**, tolérance, borne de rattrapage, `obligatoire`, `source` |

Les deux entrent dans `RegistreReferentiels` sous **un instantané unique** : les publier séparément
laisserait une échéance désigner un vaccin absent de la version en vigueur, donc une **référence
irrésoluble**. Motif exact des interactions de P6.6a et des strates de P6.7a. Les échéances sont
portées **par code national**, jamais par identifiant technique.

Code national **`VAC` + 6 chiffres, littéral et sans clé** — 5ᵉ application du précédent
`ETS` / `PRO` / `MED` / `ANA`. Un vaccin est une **instance** (ce produit-là), pas un terme de
nomenclature : il se numérote, à la différence d'une spécialité (ADR-035) ou d'une région (ADR-026).
`UNIQUE(pays_code, code)`, hors `$fillable`.

**La tolérance et la borne de rattrapage sont des DONNÉES**, pas des constantes : « on considère
qu'une dose est en retard au-delà de N jours » est une politique de santé publique, pas une règle de
code.

### 3.2 `statut` devient un calcul, `obligatoire` une lecture — et la colonne ne bouge pas

Frontière CDC_01 §0.1 : un état métier est **fourni** par le backend, jamais déclaré ni déduit.

- Le statut est dérivé par une **classe pure** `ReglesCalendrierVaccinal` (motif `ReglesReversement`,
  `ReglesIntervalleReference`) : aucune base, aucune horloge — la date est un **paramètre**.
- **U2 est refermé par construction** : il n'y a plus de valeur à rafraîchir, puisqu'il n'y a plus de
  valeur stockée qui fasse autorité.
- `statut` **cesse d'être accepté du client** sur les **trois** chemins d'écriture (patient, délégué
  P7-C, soignant P7-D0), via le point d'accroche `preparerDonnees()` de P6.6b.
- `obligatoire` cesse d'être une déclaration et devient une **lecture du calendrier publié** : un
  fait de politique nationale n'est pas une case que l'intéressé coche.

**La colonne est conservée** (ADR-024, additif) — elle est lue par le cache hors ligne de P2 et
sérialisée par des modules validés G5 — mais elle devient **nullable**, ce qui est l'énoncé honnête
de la situation : *plus personne ne l'écrit*. Un `NOT NULL` avec valeur par défaut aurait laissé
croire à une donnée maintenue.

### 3.3 L'accesseur ne lit que sa propre ligne — et pourquoi c'est délibéré

`Vaccination::statut()` ne consulte **ni le référentiel, ni la date de naissance du membre**. S'il le
faisait, la réponse dépendrait de ce qui se trouve chargé au moment de l'appel : la même ligne
répondrait `a_faire` dans un endpoint et `en_retard` dans un autre, selon l'eager loading.

> **Une valeur qui change avec la façon dont on la demande n'est pas un calcul, c'est un hasard.**

L'échéance issue du calendrier est donc **résolue et inscrite dans `date_rappel` au moment de
l'écriture**, par le service de lien : une seule vérité, posée une fois, au moment où le serveur la
connaît. Motif P6.6b — ce que le serveur peut vérifier n'a pas à être cru, et ce qu'il a vérifié doit
rester **stable**.

Corollaire technique : `protected $appends = ['statut']` — sans lui, une ligne fraîchement créée
serait renvoyée **sans statut**, l'accesseur ne s'appliquant d'office qu'aux attributs déjà chargés.
Le contrat de lecture devait rester exactement celui d'avant la bascule.

### 3.4 Deux ordres de règles, tous deux délibérés

- **L'administration l'emporte sur l'échéance** : une dose administrée est `fait`, quelle que soit la
  date prévue. Répondre « en retard » pour une dose déjà reçue serait une information fausse.
- **La fenêtre de rattrapage est examinée AVANT le retard** : une dose hors borne est `hors_delai`,
  pas `en_retard` — les deux appellent des conduites différentes (rattraper vs. reprendre un schéma),
  et l'ordre inverse aurait masqué la seconde.

Deux vocabulaires distincts, jamais mélangés : `fait` / `a_faire` / `en_retard` qualifient une
**ligne du carnet** ; `a_venir` et `hors_delai` n'ont de sens que pour une **échéance** — une
échéance à venir n'est pas une ligne « en retard » qu'on n'aurait pas encore écrite.

### 3.5 Ce que le calendrier fait, et ce qu'il n'écrit pas *(décision propriétaire W3)*

Il **répond** — `GET /membres/{membre}/calendrier-vaccinal` — et il **prévient** par une
**notification P7-D1** à l'échéance atteinte. Il **n'écrit rien dans le carnet** : ni dans
`vaccinations`, ni dans `rappels`. Générer des lignes ouvrirait un **quatrième chemin d'écriture**
dans une table du carnet, avec la question du rejeu et de la suppression par le patient ; la
notification obtient le même résultat sans ouvrir cette porte.

**La règle inviolable de D1 mord ici** : le message dit qu'une vaccination est due, **jamais
laquelle**. Un push s'affiche sur un écran verrouillé et transite par un tiers ; *le nom d'un vaccin
désigne une pathologie*. Une notification par membre et par jour, jamais une par échéance.

L'idempotence est **de construction** — la commande ne se déclenche que le jour exact où l'âge du
membre atteint l'échéance, ou le lendemain de l'expiration de la tolérance — doublée d'une garde de
rejeu le jour même.

### 3.6 La fiche vitale change de critère *(décision propriétaire W2-bis)*

Correction chirurgicale d'un module validé G5. Elle cesse de croire la case « obligatoire », **parce
que la table porte déjà un signal que le serveur garantit et qu'elle n'utilisait pas** : `source`,
écrite par le serveur depuis P7-D0 et infalsifiable par le client.

Le critère de sélection devient donc **le seul fait qui compte en urgence — la dose a-t-elle été
administrée ?** — et l'attestation devient une information **jointe** plutôt qu'un filtre. Rien ne
disparaît de l'écran, et ce qui s'y affiche cesse de prétendre plus qu'il ne sait.

### 3.7 Le nouveau consommateur lit la version PUBLIÉE, pas la table

Le plan annonçait comme limite 5 que « la fiche vitale et le carnet lisent les tables en direct »
(L1/L2 d'ADR-025). C'est vrai des tables du **carnet**, qui ne sont pas des données gouvernées.

Mais `ServiceCalendrierVaccinal` est un consommateur **neuf** : aucun module G5 ne l'appelle. Le
faire lire la table aurait livré **dès le premier jour** exactement le défaut qu'un incrément entier
a dû refermer pour `seuils_mesure`. Il lit donc l'instantané publié, avec **refus bruyant** (503)
avant la première publication — *un repli sur la table laisserait un oubli de publication invisible :
tout fonctionnerait, et personne ne saurait la garantie inactive.*

### 3.8 Honnêteté sur le contenu — 3ᵉ application du motif `loinc` / `demonstration`

**Ni l'arrêté du PEV ivoirien ni le calendrier officiel du Ministère n'ont été vus.** Le calendrier
élargi de l'OMS est public et largement standard, mais l'inscrire comme « calendrier national
ivoirien » sans l'avoir vu serait précisément *la liste inventée qui a l'air juste, et qui pour cette
raison ne se fait jamais corriger* (raisonnement d'ADR-026 sur les 33 régions).

Donc, motif d'ADR-034 repris à l'identique : colonne `source` **obligatoire en base**, contrôle
qualité qui **refuse de publier** une échéance sans provenance, et affichage du **compte exact** des
échéances encore issues de la démonstration — API, écran mobile et portail le disent tous les trois.
Charger le calendrier officiel sera **de la donnée, zéro code**, et tant que ce n'est pas fait,
**ce n'est pas un calendrier national**.

### 3.9 `vaccin.referentiel`, portée par aucun rôle métier — 9ᵉ occurrence

Un centre de vaccination ne fixe pas le calendrier national : il serait juge et partie sur ce qu'il
administre. Vérifiée **en service** et non par le middleware spatie (routes Sanctum, permissions
guard `web` — piège P4 `rdv.validate`).

---

## 4. Les gardes

| Garde | Où | Pourquoi elle ne peut pas vivre ailleurs |
|---|---|---|
| Borne de rattrapage ≥ âge dû | **triggers** MySQL + SQLite | Le `CHECK` est impossible : `vaccin_id` est `cascadeOnDelete`, **erreur 3823** — le mur de P6.3, cousin du 1215 de P6.1. `COALESCE(cond,1)=0` et non `NOT(cond)` : une comparaison NULL ne déclencherait rien et la violation passerait sans bruit. |
| Unicité `(pays_code, code)` et `(vaccin_id, numero_dose)` | index uniques | Déclaratif : le moteur refuse, pas le code. |
| `statut` / `vaccin_code` jamais du client | service de lien (`unset`) **et** règles de validation | Deux couches, **deux vecteurs distincts** — un vecteur passant par HTTP seul prouverait le validateur, pas le service (leçon de P6.6b). |
| `obligatoire` relu au calendrier publié | service de lien | Reste `$fillable` : le retirer ferait **silencieusement écarter** la valeur lue au calendrier — piège de P6.7b. |
| Cohérence de l'instantané | contrôle qualité | Refuse un code manquant, un doublon **par pays**, un décompte de doses incohérent, une provenance absente, des doses non contiguës. |

Le contrôle de doublon intègre **le pays dans la clé** : c'est le défaut que le G2 de P6.5a avait
attrapé, où un contrôle plus strict que le moteur rendait le référentiel impubliable dès le 2ᵉ pays.

---

## 5. Défaut préexistant trouvé en passant

`CarnetSectionController::update()` n'appelait **pas** `preparerDonnees()`. Un `PUT` pouvait donc
changer `laboratoire_id` **sans repasser** par le contrôle « cet établissement n'est pas un
laboratoire » d'ADR-034 §8. La garantie ne valait qu'à la création. Corrigé pour **toutes** les
sections, pas seulement les vaccinations.

---

## 6. Vérification par mutation

Cinq gardes neutralisées → **exactement cinq vecteurs morts**, chaque mutation **assertée appliquée
avant d'être interprétée** (piège d'ADR-034 §8.5 : *une mutation qui ne s'applique pas ressemble
exactement à un vecteur qui survit*).

Un vecteur hérité — `test_vaccination_crud_basique`, qui affirmait qu'un `PUT {statut}` changeait le
statut — a été **réécrit pour énoncer la garantie neuve**, pas corrigé pour passer (précédent
ADR-029).

---

## 7. Limites

1. **Contenu = jeu de démonstration** (9 vaccins, 17 échéances, toutes `source='demonstration'`).
   Tant que le calendrier officiel n'est pas chargé, **ce n'est pas un calendrier national**.
2. **Aucun rappel automatique écrit** dans le carnet (§3.5) — `rappels.type = 'vaccin'` reste saisi à
   la main, et le constat U4 du G0 reste ouvert.
3. Le **lien est facultatif** : une ligne libre ne bénéficie d'aucune garantie de vocabulaire.
4. **Aucun rattrapage rétroactif** des lignes existantes : elles restent non liées, donc sans code
   national — *leur en inventer un serait un mensonge d'archive* (précédent L2 d'ADR-025).
5. **Aucun écran de gouvernance mobile**, comme tous les référentiels depuis P6.3 — la publication
   reste une procédure faite par deux agents habilités depuis le portail.
6. La **notification n'est pas prouvée au G4 par un push réel** : la dette de P7-D1 subsiste (Expo Go
   n'a plus le push distant depuis le SDK 53) ; la commande, la garde de rejeu et l'absence de fuite
   médicale le sont, en base.
7. La tâche planifiée est déclarée « **prête à activer** » : aucun cron n'existe dans cet
   environnement, et le dire vaut mieux que laisser croire qu'elle tourne.
