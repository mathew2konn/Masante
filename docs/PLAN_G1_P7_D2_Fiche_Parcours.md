# Plan G1 — P7 / D2 : Fiche de parcours

> Dernier incrément du module **P7 Carnet familial partagé**. Précédents : A (partage en lecture),
> B (revendication), C (contributions au brouillon), D1 (notifications), D0 (écriture du soignant).

---

## 1. Le besoin, tel qu'il a été posé

Du plan G1 du module (§2) : les parents sont en voyage, un proche emmène l'enfant à l'hôpital et
dépose une contribution. Les responsables reçoivent la notification (D1), **consultent la fiche de
parcours — médecin, établissement, journal d'audit, ordonnance —, appellent la personne pour
vérifier, puis valident**.

La fiche sert donc d'abord à **décider en connaissance de cause**. Elle n'est pas un tableau de bord
médical : c'est le support d'un appel téléphonique.

---

## 2. Ce que le G0 a trouvé (2026-08-12)

| # | Constat | Conséquence |
|---|---------|-------------|
| **F1** | L'écran du journal existe depuis le Module 2 et affiche **« Agent #4 »**. Les libellés mobiles (`qr_scan`, `consultation`, `ajout`) ont divergé de l'ENUM réel (5 voies) : un parent lit **« bris_de_glace »** brut. | À réparer à la racine, pas à contourner. |
| **F2** | **Aucun établissement dans `acces_dossier`.** Il n'existe que sur `tokens_qr.used_by_etablissement`, voie QR uniquement. | Migration additive (décision 3.2). |
| **F3** | Le lien entrée ↔ visite n'existe que par `donnees_ajoutees`, donc **depuis D0**. | Deux blocs distincts (décision 3.3). |
| **F4** | Depuis A, chaque lecture d'un délégué écrit une ligne `delegation`, **une par section**. | Exclues de la fiche : une lecture familiale n'est pas un passage à l'hôpital. |
| **F5** | Une visite = 2 lignes (ouverture, clôture), reliées par `token_qr_id`… **NULL en référent et bris de glace**. Et si l'agent ferme son navigateur, la clôture **n'est jamais écrite**. | Colonne de corrélation (décision 3.2) + état « non clôturée » affiché honnêtement. |
| **F6** | `membres/[id].tsx` affiche le bloc « Partage sécurisé » **sans aucune garde de propriété** : un délégué tape « Journal d'accès » et reçoit un 403. | Correction chirurgicale incluse. |

---

## 3. Décisions du propriétaire (2026-08-12)

### 3.1 Audience — **toute la famille consulte, seuls les responsables décident**

Décision propriétaire du 2026-08-12, qui sépare nettement **voir** et **décider** :

| Qui | Consulter la fiche | Valider une contribution |
|---|---|---|
| Propriétaire du carnet | ✅ | ✅ (de droit) |
| Second responsable désigné | ✅ | ✅ |
| Délégué en lecture | ✅ | ❌ |
| Toute autre personne | ❌ | ❌ |

Nouvelle capacité `MembreFamillePolicy::viewParcours` = **peut lire le carnet** (`view`, donc
propriétaire ou délégué actif) **ou** figure parmi `ResponsableFamille::decideursPour()` — un second
responsable n'est pas nécessairement délégué sur ce membre précis.

**`view` et `viewAcces` ne sont pas modifiés.** La barrière la plus sensible du projet, élargie une
seule fois en A, garde sa définition ; la fiche s'y adosse au lieu de la déplacer. Et le journal
d'accès brut (`/acces`) reste propriétaire-seul : c'est son droit d'accès personnel §10.3, distinct
de la fiche.

**Ce que cet élargissement expose, et pourquoi il se tient.** Un délégué en lecture voit déjà
l'intégralité du carnet — antécédents, ordonnances, résultats. Apprendre *où* et *par qui* une
ordonnance a été écrite est moins sensible que l'ordonnance elle-même, qu'il peut déjà lire. La
fiche n'ouvre donc pas une catégorie de donnée nouvelle ; elle donne son contexte à ce qui est déjà
partagé.

**Deux réserves que je porte au plan plutôt qu'à l'implémentation :**
- `ip_address` **n'est jamais exposée** dans la fiche, à personne. Elle n'apprend rien à une famille
  et identifie un lieu de connexion. Elle reste dans le journal.
- `motif_urgence` d'un bris de glace est un **texte libre écrit par un agent dans l'urgence**
  (« patient inconscient, accompagné par… »). Il est montré à toute l'audience de la fiche, parce
  qu'un accès sans consentement doit rester explicable — mais c'est le seul champ de la fiche dont
  le contenu n'est pas maîtrisé par le produit.

### 3.1 bis La validation ne bouge pas — et devient visible de tous

La décision reste ce qu'elle est depuis C : `ResponsableFamille::decideursPour()`, l'auteur ne se
valide jamais lui-même. **Aucune ligne de ce mécanisme n'est touchée.**

Ce qui change est l'**annonce** : `ServiceNotification::contributionDecidee` ajoute
`Delegation::lecteursDe($membre->id)` à ses destinataires — méthode déjà écrite en D1 pour
`DOSSIER_CONSULTE`. Toute la famille qui a accès au carnet apprend donc la décision, sauf celui qui
vient de la prendre.

La règle inviolable de D1 s'applique sans changement : **aucun contenu médical** dans la notification
(« a validé l'ajout au carnet de X », jamais ce qui a été ajouté). Le motif de rejet reste repris —
c'est une justification de gouvernance familiale, pas une donnée clinique.

### 3.2 Établissement — instantané à l'écriture

Migration **additive** sur `acces_dossier` :

| Colonne | Rôle |
|---|---|
| `etablissement` (string 200, nullable) | Le nom **copié au moment de l'accès**, sur les cinq voies. |
| `acces_ouverture_id` (FK nullable vers `acces_dossier`) | La clôture désigne son ouverture. **Résout F5 exactement** : `SessionDossierService::fermer()` détient déjà `$etat['acces_id']`. |

Précédent du projet : `AlerteSos` dénormalise déjà le contact prévenu *« pour que la trace reste
exacte même si le contact est modifié ensuite »*. Même raison ici — **un agent change d'hôpital, ses
visites passées ne doivent pas changer d'établissement**.

Les lignes déjà écrites restent `NULL`. La fiche affichera **« établissement non enregistré »**
plutôt que d'inventer. C'est une limite datée, pas un défaut permanent.

### 3.3 Entrées médicales — deux blocs, un seul lien affirmé

| Bloc | Contenu | Statut |
|---|---|---|
| **« Écrit pendant cette consultation »** | Résolu depuis `donnees_ajoutees` : section + identifiant | **Fait.** Le journal l'atteste. |
| **« Autres entrées médicales de la période »** | `source ∈ (medecin, structure)` créées dans la fenêtre de la fiche et rattachées à aucune visite | **Rapprochement possible**, dit comme tel. |

Le second bloc est **au niveau de la fiche, pas de la visite** : le placer sous une visite
suggérerait le lien qu'on refuse d'affirmer.

---

## 4. Ce qui est construit

### 4.1 Backend — tout le jugement dans un service

`App\Services\ServiceFicheParcours` :

1. **Constitue les visites.** Voies retenues : `qr_scan`, `referent`, `bris_de_glace`, `admin`.
   `delegation` **exclue** (F4). Une visite = la ligne de **clôture** (elle porte durée, sections,
   données ajoutées), reliée à son ouverture par `acces_ouverture_id`. Une ouverture sans clôture
   devient une visite marquée **« consultation non clôturée »** — l'agent a fermé son navigateur,
   on ne sait pas combien de temps il est resté, et on le dit.
2. **Résout les identités** : nom de l'agent (`users`), établissement (colonne instantané, sinon
   `tokens_qr.used_by_etablissement`, sinon rien), motif d'urgence pour un bris de glace.
3. **Résout les entrées écrites** via `RegistreSectionsCarnet::controleur()->nomRelation()` —
   la même source unique qu'en C et D0.
4. **Rassemble les contributions** de la période avec leur statut (le cœur du scénario : le
   responsable voit ce qu'on lui demande de valider, à côté du passage à l'hôpital).

`GET /api/v1/membres/{membre}/parcours?depuis=` — gardé par `viewParcours`. La fenêtre par défaut
est une **donnée de configuration**, jamais une constante dispersée.

### 4.2 Source unique des libellés (répare F1)

`TypeAccesDossier` promu dans **`@masante/shared`** avec ses cinq valeurs et leurs libellés, miroir
PHP dans `App\Support` — exactement le motif de `TypeNotification` (D1). L'écran du journal existant
**et** la fiche le consomment. La divergence qui a produit « bris_de_glace » à l'écran ne peut plus
se reformer sans casser le typecheck.

**Libellés citoyens (décision propriétaire, 2026-08-12).** La valeur technique ne change nulle
part — elle est dans l'ENUM de `acces_dossier`, dans la permission `urgence.bris_de_glace` et dans
des modules validés G5. Seul l'affichage change :

| Valeur en base | Ce que lit le citoyen |
|---|---|
| `qr_scan` | Consultation après scan de votre QR |
| `referent` | Consultation par votre médecin référent |
| `delegation` | Consultation par un proche |
| `bris_de_glace` | **Accès d'urgence vitale** |
| `admin` | Accès administrateur MaSanté |

« Bris de glace » est un terme métier (*break the glass*) : juste entre professionnels, opaque pour
une famille. **« Urgence vitale » porte à lui seul la justification** de l'absence de consentement —
c'est ce que le lecteur doit comprendre en une ligne. Le portail professionnel peut conserver le
terme technique ; la source unique fournit le libellé citoyen.

### 4.3 Mobile

- Écran `app/(app)/membres/parcours/[id].tsx` : visites en ordre antéchronologique, chacune avec
  agent, établissement, voie, durée, sections consultées, ce qui a été écrit ; puis le bloc des
  autres entrées ; puis les contributions de la période.
- Accès depuis la fiche du membre **et** depuis la file « Ajouts à valider » (C) — c'est là que la
  fiche sert vraiment.
- **Correction F6** : sur un carnet partagé, le bloc « Partage sécurisé » disparaît — mais l'entrée
  « **Fiche de parcours** » reste, puisqu'un délégué y a désormais droit. C'est exactement la
  distinction demandée : ce qui relève de la **gouvernance** du carnet (journal brut, gestion des
  délégués) reste au propriétaire ; ce qui relève de l'**information** est partagé.
  `user_id` étant caché par le modèle, `GET /membres/{membre}` expose un booléen **calculé**
  `est_proprietaire`. Ajout **purement additif** au contrat P2 : rien n'est retiré ni renommé.

---

## 5. Frontière (CDC_01 §0.1)

**Quelles règles métier ce module calcule-t-il ? → aucune.**

Le regroupement en visites, l'éligibilité à la fiche, la résolution des entrées, la distinction
entre lien certain et rapprochement possible : **backend seul**. Le mobile affiche des objets déjà
constitués et déjà qualifiés. Aucun statut n'est déduit à l'écran.

---

## 6. Ce que D2 ne fait pas — à dire dans l'écran

- **Si l'hôpital n'a jamais scanné le QR, la fiche est vide.** Elle ne prouve pas qu'il ne s'est
  rien passé : elle prouve que rien n'a été tracé. C'est un support à l'appel téléphonique.
- Les lignes écrites avant cette migration n'ont **pas** d'établissement.
- Les lectures familiales (`delegation`) n'y figurent pas — elles restent dans le journal d'accès.
- Ni export PDF, ni impression, ni partage vers un tiers.
- **`ip_address` n'est jamais affichée** (§3.1).
- Aucun **type** de notification nouveau : D1 les couvre tous. Seule la **liste des destinataires**
  de la décision s'élargit à la famille (§3.1 bis).

---

## 7. Preuves attendues

**G3** — `FicheParcoursTest`, une garde et un cas par vecteur :

- **Audience** : propriétaire ✅ · second responsable **non délégué** ✅ · délégué en lecture ✅ ·
  délégué révoqué ❌ · tiers ❌ · délégué `qr_generation` seul ❌ (il n'ouvre pas le dossier).
- **Voir n'est pas décider** : un délégué en lecture consulte la fiche **et** reçoit 403 sur la
  validation d'une contribution. Le vecteur qui prouve la séparation demandée.
- `ip_address` **absente** de toute réponse, pour tous les rôles.
- Visite clôturée reconstituée ; ouverture sans clôture marquée non clôturée ; lignes `delegation`
  absentes ; entrée liée dans le bon bloc et entrée non liée dans l'autre ; établissement instantané
  présent, et absent sans mensonge sur les lignes anciennes.
- **Extension D1** : à la validation, l'auteur, les responsables **et les délégués en lecture** sont
  notifiés, le décideur ne l'est pas ; le test anti-fuite médicale de D1 est rejoué sur ces nouveaux
  destinataires (élargir l'audience, c'est élargir la surface de fuite).

Suite complète + typecheck ×3.

**G2 live MySQL** — parcours réel de bout en bout : scan QR → écriture soignant (D0) → clôture →
fiche restituant agent, établissement, durée, sections et l'ordonnance écrite ; contribution en
brouillon visible à côté ; base restaurée à l'identique.

**G4** — test propriétaire (mobile Expo Go), guide `GUIDE_TEST_CARNET_FAMILIAL.md` partie D2.
