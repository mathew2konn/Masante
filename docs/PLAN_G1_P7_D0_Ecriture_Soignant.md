# Plan G1 — P7 / Incrément D0 : écriture du soignant au carnet

> Statut : **G1 VALIDÉ par le propriétaire le 2026-08-12**.
> Prérequis : A, B, C et D1 validés G5.
>
> **Décisions du propriétaire, au G1 :**
> 1. **Ouvrir d'abord l'écriture soignant**, puis livrer D2 (fiche de parcours) — d'où **D0**,
>    qui s'intercale avant D2.
> 2. Sections ouvertes : **antécédents, ordonnances, résultats + vaccinations** (migration additive
>    sur `vaccinations`).
> 3. **Lecture seule en bris de glace** : l'écriture n'est autorisée que sur les voies consenties.

---

## 1. Pourquoi cet incrément existe

Le G0 de D2 a trouvé un trou que rien ne laissait voir depuis l'extérieur :

> **Aucun soignant ne peut écrire dans le carnet aujourd'hui.**
> `Portail\DossierController` n'expose que `show`, `section`, `fermer` — lecture seule. Et
> `CarnetSectionTest` le dit en clair : le chemin d'écriture médecin/structure est
> « *futur, M3/M4* ».

Conséquence directe : la fiche de parcours promise en D2 — « la trace du médecin, l'hôpital,
**l'ordonnance** » — aurait affiché une section vide, structurellement. Le propriétaire a tranché
au G1 : on ouvre le chemin d'écriture d'abord.

Deux autres trouvailles du même G0, qui pèsent sur la conception :

| Trouvaille | Conséquence |
|---|---|
| `acces_dossier.donnees_ajoutees` est déclarée depuis le Module 2 mais **jamais écrite** — colonne morte | D0 lui donne enfin son sens : elle enregistrera ce qui a été écrit pendant la session |
| `source`/`added_by` n'existent que sur `antecedents`, `ordonnances`, `resultats_analyses` (+ `mesures_sante`) | `vaccinations` n'a aucune traçabilité d'origine — la garantie de l'incrément C y est **sans effet** (Eloquent écarte les clés hors `$fillable`, sans erreur) |

---

## 2. La règle structurante, héritée de la lecture

`DossierController` n'accepte **aucun identifiant de membre dans l'URL** : le dossier consulté est
celui que porte la session ouverte au scan. L'agent ne peut pas atteindre un autre dossier en
changeant l'adresse — **l'anti-IDOR est par construction**, pas par contrôle.

**L'écriture suit la même règle, sans exception.** `POST /portail/dossier/{section}` écrit dans le
dossier de la session. Il n'existe aucun chemin permettant de désigner un membre.

C'est la décision la plus importante de cet incrément : elle rend une classe entière d'attaques
impossible plutôt que détectable.

---

## 3. Décisions d'architecture

### D0.1 — La permission n'est attribuée à aucun rôle

Nouvelle permission `dossier.ecrire`, créée mais **rattachée à aucun rôle par défaut** — exactement
comme `urgence.bris_de_glace` (Note_Continuite §5.3, déjà validé). C'est le gestionnaire
d'établissement qui l'accorde individuellement, aux soignants habilités.

Pourquoi pas au rôle `agent_garde` : il porte `qr.scan` et sert l'accueil. **Un agent d'accueil ne
rédige pas une ordonnance.** Et le portail n'a que trois rôles (`admin_ivoirsante`,
`gestionnaire_etablissement`, `agent_garde`) — inventer un rôle `medecin` côté web déborderait de
cet incrément.

### D0.2 — `source` et `added_by` sont réécrits par le serveur

Miroir exact de l'incrément C, dans l'autre sens :

| Qui écrit | `source` imposée | `added_by` imposé |
|---|---|---|
| délégué (contribution, C) | `patient` | `patient` |
| **soignant (D0)** | **`medecin`** | **nom du soignant + établissement** |

Le client ne décide **jamais** de ces deux champs. Un soignant ne peut pas faire passer son
écriture pour une déclaration du patient, ni l'inverse. C'est ce qui donnera sa valeur à la fiche
de parcours : « ceci vient d'un soignant » sera une **garantie du serveur**, pas une déclaration.

### D0.3 — Écriture interdite en bris de glace

Le bris de glace ouvre le **vital minimal**, 15 minutes, **sans consentement** du patient (voie 4,
Sécurité §4.4). Son périmètre est défini en lecture. Y autoriser l'écriture ferait d'un accès
d'exception non consenti un droit de modifier le dossier.

**Voies autorisées à écrire : `qr_scan` (le patient a présenté son QR) et `referent` (le patient a
désigné ce médecin).** `bris_de_glace` et `admin` : lecture seule, garde explicite et testée.

### D0.4 — Les règles de validation ne sont pas réécrites

`RegistreSectionsCarnet` (incrément C) est déjà la source unique des règles par section, extraite
des contrôleurs de l'API. D0 le réutilise tel quel. Les règles d'une ordonnance seront donc écrites
**une seule fois**, et serviront trois chemins : le patient (API Sanctum), le délégué (contribution
au brouillon), le soignant (portail).

Une liste distincte y est ajoutée pour le soignant : `rappels` en est exclu (un rappel est un outil
du patient, pas un acte médical).

### D0.5 — `donnees_ajoutees` enfin utilisée, sans contenu clinique

À la clôture de session, la ligne d'audit portera ce qui a été écrit : **section, identifiant de
l'entrée, horodatage — jamais le contenu médical**. Minimisation (loi 2013-450), et pas de
duplication d'une donnée de santé dans le journal. D2 relira l'entrée réelle par son identifiant.

### D0.6 — La famille est prévenue (canal D1)

Nouveau type `CARNET_ENRICHI` : « Un soignant a ajouté une ordonnance au carnet de X. » Destinataires
identiques à `DOSSIER_CONSULTE` — le propriétaire **et** les délégués en lecture. **Aucun contenu
médical** : la règle inviolable de D1 s'applique sans changement.

### D0.7 — Frontière

Le serveur décide : le membre (session), `source`, `added_by`, la validité des données (registre),
le droit d'écrire (permission + voie d'accès), la notification. La vue Blade affiche un formulaire
et poste. **Quelles règles métier ce module calcule-t-il côté front ? → aucune.**

---

## 4. Périmètre

**Livré** :
- `POST /portail/dossier/{section}` — écriture dans le dossier de la session, gardée.
- Formulaire Blade par section ouverte, dans l'écran de dossier existant.
- Permission `dossier.ecrire` + garde de voie d'accès.
- `source`/`added_by` imposés serveur ; `donnees_ajoutees` renseignée à la clôture.
- Notification `CARNET_ENRICHI` à la famille.
- Migration additive : `added_by` + `source` sur `vaccinations` (défaut `patient` → l'existant ne
  bouge pas), ce qui répare au passage la garantie vide de l'incrément C sur cette section.

**Non livré** (à dire, pas à découvrir) :
- Ce n'est **pas** le DMEN complet ni P6.4 : pas de modification ni de suppression par le soignant,
  pas de signature, pas de workflow de contre-signature.
- Pas d'écriture sur `documents`, `mesures`, `grossesse`, `notes`, `contacts-urgence` — chacune a
  sa logique propre ; ouverture ultérieure purement additive.
- Le soignant n'écrit que **pendant sa fenêtre de session** ; rien de différé.

---

## 5. Preuves prévues

- **G2 live (MySQL)** : écriture par un compte habilité → entrée créée avec `source=medecin` et
  `added_by` nominatif ; **client envoyant `source=patient` → stocké `medecin`** ; compte sans la
  permission → 403 ; **session de bris de glace → 403 en écriture mais 200 en lecture** ; session
  expirée → refus ; `donnees_ajoutees` renseignée à la clôture, **sans contenu clinique** (vérifié
  au `SELECT`) ; notification reçue par le propriétaire et les délégués en lecture, sans détail
  médical ; aucun chemin ne permet de viser un autre membre.
- **G3** : suite complète verte (387 tests aujourd'hui) + tests dédiés `EcritureSoignantTest`
  écrits **dans les deux sens** ; typecheck ×3.
- **G4** : portail Chrome/Firefox, compte agent habilité + compte agent non habilité.
- Guide `GUIDE_TEST_CARNET_FAMILIAL.md` **partie D0**, écrit avant le G4.
