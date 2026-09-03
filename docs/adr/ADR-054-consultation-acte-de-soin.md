# ADR-054 — La consultation : un acte de soin distinct du journal d'accès (B2-a)

**Statut : Accepté — B2-a, B2-b et B2-c VALIDÉS (G5, 2026-09-03).** G4 propriétaire OK sur les
trois. **Le lot B2 est COMPLET.** Suite complète **1595/1595**, 17 668 assertions.
Contexte : CDC_11 §5.2 (étape 5 de §12), CDC_04 §12 étape 7 · Plan G1 :
[`docs/PLAN_G1_B2_Consultation_Diagnostic_Prescription.md`](../PLAN_G1_B2_Consultation_Diagnostic_Prescription.md)
· Suite de [ADR-053](ADR-053-rdv-cloture-verification-notification.md) (lot B1).

---

## 1. Contexte

Trois cahiers désignent cette étape, elle n'a pas été choisie : CDC_11 §12 (étape 5,
« Consultation + diagnostic + prescription électronique »), CDC_04 §12 (étape 7, « Dossier
médical : consultations, diagnostics, observations ») et CDC_01 §17 (module 8, « Espace
Médecin »).

Et **le code du projet la nommait lui-même** : `RegistreRetourTriage.php`, écrit le 2026-08-28,
porte la phrase « *Le diagnostic codé a un porteur nommé : le Module 8 (Espace Médecin)* ».

Le G0 a vérifié en base, pas de mémoire :

| Constat | Fait |
|---|---|
| **Y1** | **Une seule** des quinze tables médicales du CDC_04 §103/§105 existe (`signatures_electroniques`) |
| **Y2** | `ordonnances` porte `medecin_nom` **en chaîne** ; aucun `medecin_id` — « toutes les ordonnances du D<sup>r</sup> X » est insoluble |
| **Y3** | Les médicaments sont un blob chiffré ; la structure existe, l'interrogeabilité est perdue par le chiffrement |
| **Y4** | Les allergies sont du texte libre chiffré → la vérification du §5.4 est **structurellement impossible** |
| **Y5** | `acces_dossier` porte déjà presque tout, et son propre commentaire l'appelle « consultation » |
| **Y6** | `RegistreContextesProtocole::CONSULTATION` existe depuis P10b, sans émetteur |
| **Y7** | Aucun support pour la transmission en pharmacie |
| **Y8** | Le rôle `medecin` a déjà toutes les permissions nécessaires |
| **Y9** | `resultats_analyses` **mélange déjà** colonnes en clair et colonne chiffrée — la ligne est tranchée |
| **Y10** | Écart CDC_04 §101 / B1-a sur le vocabulaire des états de rendez-vous — **signalé, non corrigé** |

---

## 2. Décisions

### D1 — La consultation est une entité distincte, pas `acces_dossier` enrichi

Ajouter trois colonnes à `acces_dossier` aurait été beaucoup plus court. Quatre raisons de ne pas
le faire, dont deux sont des décisions déjà prises par ce projet :

1. **Deux natures de vérité dans la même table.** `acces_dossier` est un journal d'accès régi par
   la loi 2013-450 ; P7-D2 a décidé qu'il reste réservé au propriétaire du dossier, parce qu'il
   porte l'adresse IP et **toutes** les lectures familiales. Y verser le contenu clinique mêlerait
   un registre de surveillance et un acte de soin — le refus que P6.6a a opposé aux interactions.
2. **Un journal est immuable, une consultation se rédige.** Rendre éditable une ligne d'audit
   détruirait ce que ce journal existe pour prouver.
3. **Les cardinalités ne coïncident pas.** Un accès existe sans consultation (lecture familiale,
   bris de glace, un médecin qui ouvre puis referme sans acte).
4. **Précédent direct, pris cinq jours plus tôt.** P10c-3-ii a créé `retours_cliniques_triage` en
   table séparée plutôt que d'ajouter trois colonnes à `protocole_applications`.

Le lien est un **identifiant sans clé étrangère** (`acces_dossier_id`, patron ADR-042 D1), avec
`UNIQUE` : une session d'accès porte **au plus une** consultation. Plusieurs écritures dans une
même session sont plusieurs actes de la **même** consultation.

### D2 — Le diagnostic : lien facultatif au référentiel (reporté à B2-b)

Décision prise au G1, **non implémentée dans B2-a** : `maladie_id` + `maladie_code` +
`maladie_libelle` figés, patron exact de P6.8c sur `antecedents`, avec le texte du médecin
**jamais réécrit**. Point structurant retenu : **un diagnostic de consultation n'est PAS un
antécédent** — `RegistreRetourTriage` l'a déjà écrit (« y consigner chaque grippe la
transformerait en antécédent permanent pesant sur toutes les orientations futures »).

### D3 — L'ordonnance est enrichie, pas restructurée (reporté à B2-c)

`ordonnance_lignes` n'a **aucun consommateur** aujourd'hui : sa raison d'être est la délivrance en
pharmacie, qui n'existe pas (Y7). La créer serait le « socle à vide » refusé par P6.3-D3. Et
l'interrogeabilité ne s'obtient qu'**en cessant de chiffrer** — une décision qui mérite d'être
prise pour elle-même. Y2 se refermera par `medecin_id`/`structure_id`/`consultation_id`, geste
exact de P6.7b sur `resultats_analyses`.

### D4 — Les allergies restent hors périmètre, et c'est une décision de sécurité

Une vérification qui ne couvrirait que les allergies saisies **après** ce lot afficherait « aucune
allergie signalée » sur un patient dont l'allergie est écrite en prose depuis des mois. **C'est
plus dangereux que pas de vérification du tout** : le médecin cesserait de demander, croyant que
la machine a regardé. Raisonnement de P6.8e, mot pour mot (*un numéro d'urgence faux est plus
dangereux qu'un numéro absent, parce qu'il sera composé*).

Structurer une allergie suppose un **référentiel d'allergènes** — un module CDC_09 de plein droit.
**Le §5.4 est donc partiellement atteint, et le lot le dit.**

### D5 — Trois sous-incréments ; B2-a seul est livré

**B2-a** l'entité et les observations · **B2-b** le diagnostic · **B2-c** la prescription
rattachée. B2-a a un **consommateur immédiat** : les écritures du soignant, qui existent depuis
P7-D0 et se rattachent enfin à un acte identifié.

---

## 3. Ce que l'implémentation a changé au plan

### Z-a — La table des observations existait déjà, et le plan l'ignorait

Le G0 d'implémentation a trouvé `notes_observations`, créée le **2026-07-02** : contenu chiffré,
append-only, auteur, lien triage — c'est **exactement** les `observations` du CDC_04 §103. Son
propre commentaire annonçait le rattachement au praticien comme « différé aux Modules 3/4 ».

**Aucune table `observations` n'est créée.** `notes_observations` reçoit `consultation_id`
(additif, ADR-024).

### Z-b — L'observation passe par la consultation, jamais par le registre générique

Le registre des sections laisse `notes-observations` « réservée au propriétaire », en notant que
l'ouvrir serait additif. L'ouvrir par le registre laisserait un soignant écrire une note
**flottante**, rattachée à aucun acte — alors que le §5.2 place l'observation *dans* la
consultation. Ici, une observation de soignant appartient toujours à un acte identifié.

### Z-c — Aucune permission neuve

Mener une consultation, c'est consigner un acte dans le carnet : `dossier.ecrire` le dit déjà. En
inventer une seconde donnerait **deux clés pour une seule porte** — refus opposé par P11.1-D5 à
`demande.traiter`. La liste des voies consenties vient de
`EcritureSoignantService::VOIES_ECRITURE`, **jamais recopiée** : une seconde liste aurait pu
diverger sans que rien ne le dise.

---

## 4. Les gardes, et aucune ne rattrape les autres

| # | Garde | Où |
|---|---|---|
| 1 | Habilitation `dossier.ecrire` | route **et** service (piège P4 : guard `web`) |
| 2 | Voie consentie — le bris de glace est exclu (P7-D0) | service |
| 3 | Un seul acte par accès | `UNIQUE` en base + verrou pessimiste pour un message utile |
| 4 | L'auteur seul poursuit son acte | service |
| 5 | Une consultation clôturée est terminale | service |
| 6 | `statut = 'cloturee'` ⟺ `cloturee_le IS NOT NULL` | **déclencheurs dans les deux dialectes** |

La clôture **n'exige pas** la voie consentie, à la différence de l'ouverture : refermer un acte
n'ajoute rien au dossier, et laisser une consultation « en cours » indéfiniment parce que le
consentement a expiré serait pire.

---

## 5. Défauts réels trouvés

1. **Le refus était muet à l'écran** — trouvé **au G2 live**, invisible aux vecteurs de service :
   le service refusait correctement une observation vide, la base le confirmait (une seule ligne),
   et l'écran ne disait rien. Aucun vecteur n'exerçait le **rendu** — exactement le trou que B1-b
   avait trouvé dans B1-a. Corrigé, prouvé en direct (le message passe d'absent à présent).
2. **Le message était en anglais** — « The contenu field is required. » sur un portail
   entièrement francophone. Le projet n'a pas de répertoire `lang/` (défaut **transverse**
   préexistant, signalé et non corrigé), mais plusieurs `FormRequest` le corrigent déjà chez
   elles : on suit ce patron.
3. **Un vecteur qui prouvait sa propre existence** — trouvé **par la mutation**, dixième
   occurrence de cette famille dans le projet. Le vecteur de rendu fabriquait un état de session
   à la main, avec les seules clés qu'il croyait utiles ; il en manquait (`sections`), la page
   rendait un **500**, et `assertSee` passait quand même — parce qu'une page d'erreur Laravel
   affiche le code source de la pile, donc le texte cherché, **qui figure dans ce fichier même**.
   Deux leçons : `assertOk()` **avant** `assertSee`, et dériver la session de son vrai
   constructeur plutôt que de l'imiter.

---

## 6. Ce qui est prouvé, et ce qui ne l'est pas

**G3** — 21 vecteurs dédiés ; **mutation 6/6 conforme** dont un témoin volontairement vert
(*un harnais qui ne prévoit que des tueuses ne se teste jamais lui-même*), arbre restauré et
vérifié par `diff` ; Pint propre sur les fichiers neufs, **baseline établie contre `HEAD`** —
`DossierController` et `SessionDossierService` échouaient **déjà** Pint avec les mêmes fixers
avant modification, ils ne sont donc pas reformatés.

**G2 live MySQL** — schéma conforme (16 colonnes, 2 déclencheurs, `uq_consultation_acces`) ; les
quatre gardes du moteur répondent (`1644` ×2, insertion valide, `1062` sur doublon) ; parcours
réel au portail par la voie **référent** (jamais le bris de glace) : ouverture avec champs
interdits envoyés → **ignorés**, observation consignée, refus par leur motif exact, clôture,
seconde clôture refusée ; contenu **chiffré en base**, `auteur_type` réécrit par le serveur ;
**base restaurée compte pour compte** (133 tables, zéro résidu, migration revenue à `Pending`).

**Ce qui n'est PAS prouvé en test automatisé, et qui est dit** : l'**affichage** du refus ne l'est
qu'au G2 live. `withSession()` remplace la session à chaque requête, donc les erreurs flashées ne
survivent pas à un second appel, et `followingRedirects()` perd la session de dossier. Le vecteur
automatisé prouve que le refus **part** vers l'écran, sous la clé que la vue lit — pas qu'il s'y
affiche.

---

## 7. Limites

- **Aucun diagnostic** (B2-b) ni **prescription rattachée** (B2-c) : la chaîne du §5.2 est ouverte,
  pas parcourue.
- **§5.4 partiellement atteint** : les interactions restent consultables (P6.6b, choix
  propriétaire), les **allergies et contre-indications ne sont pas vérifiées**, l'adaptation de
  dose est absente. Porteur nommé : un référentiel d'allergènes (CDC_09).
- **Aucune transmission en pharmacie**, `ordonnance_lignes` et `delivrances` non livrées (lot
  pharmacie, CDC_11 §7).
- **Aucune aide au diagnostic IA** (§5.3) : CDC_05, et CDC_08 §3 classe le raisonnement IA
  dernier ; CDC_00 §4 interdit qu'une IA décide seule.
- **Le contexte `consultation` des protocoles n'est toujours émis par aucun écran** : B2-a crée
  l'objet, il ne branche pas encore le moteur de protocoles dessus.
- **Aucun écran mobile** : la consultation est un acte professionnel, elle vit au portail.
- **Messages de validation non traduits ailleurs dans le projet** — défaut transverse constaté,
  hors périmètre.


---

## 8. B2-b — le diagnostic (✅ VALIDÉ G5, 2026-09-03)

### 8.1 La décision, et pourquoi la table courte était la mauvaise

`antecedents` porte déjà `maladie_id`, un libellé figé et une `description` : y écrire chaque
diagnostic aurait demandé zéro table. Le code du projet disait déjà pourquoi c'est faux —
`RegistreRetourTriage` (P10c-2-i) :

> « le seul endroit où une maladie codée se pose aujourd'hui est un ANTÉCÉDENT — or `antecedents`
> porte aussi `impact_triage`, qui alimente le score des triages suivants : y consigner chaque
> grippe la transformerait en antécédent permanent pesant sur toutes les orientations futures. On
> dégraderait l'orientation qu'on cherche à améliorer. »

**Un antécédent SUIT le patient ; un diagnostic DATE d'un épisode.** D'où une table à part, et une
garantie qui a son vecteur : **poser un diagnostic ne crée AUCUN antécédent**. L'inscription est un
acte séparé, et **le médecin en choisit le type** — décider qu'un diagnostic est « chronique » est
une affirmation clinique, que ce projet ne fabrique pas.

### 8.2 Le lien au référentiel : facultatif, figé, jamais deviné

Patron exact de P6.8c, via **le même service** (`ServiceLienMaladie`, où `resoudreDiagnostic()`
partage la mécanique de `resoudreAntecedent()` plutôt que de la recopier). Facultatif parce que le
référentiel livré est un jeu de démonstration et qu'une maladie émergente n'est dans aucune
nomenclature au moment où elle émerge. **Le serveur ne rapproche jamais** un libellé d'une entrée
du référentiel : ce serait un diagnostic posé par une machine (CDC_00 §4) — vecteur dédié, où un
libellé identique à une entrée publiée ne produit aucun rattachement.

La promotion passe par **le chemin d'écriture soignant existant** (`EcritureSoignantService`,
P7-D0) : ses trois gardes s'appliquent sans être réécrites, `source`/`added_by` sont réécrits par
le serveur, et la notification part comme pour toute autre écriture au carnet.

### 8.3 Pas de déclencheur de cohérence sur `maladie_*`, et c'est une décision

Exiger « `maladie_id` nul ⟺ `maladie_code` nul » serait le piège exact qu'ADR-042 a documenté :
`maladie_id` est une clé étrangère `nullOnDelete` (comme sur `antecedents`), donc supprimer une
maladie du référentiel la met à NULL **sans toucher** le code et le libellé figés — et le
déclencheur crierait alors sur une ligne que personne n'a modifiée. Les valeurs figées doivent
survivre à la disparition de leur source : c'est leur raison d'être. La seule garantie du moteur
est déclarative — `UNIQUE(antecedent_id)` — et on n'invente pas un déclencheur pour la forme.

### 8.4 Défaut réel trouvé au G0, et il datait de P6.8c

**Le rattachement d'un antécédent au référentiel était INOPÉRANT depuis le portail.** L'instantané
publié ne porte pas d'`id` (délibérément : un identifiant technique ne veut rien dire hors de cette
base), mais `formulaire.blade.php` écrivait `value="{{ $m['id'] }}"` sur une clé absente — **chaque
option valait la chaîne vide**. Défaut muet : rien ne cassait, la fonctionnalité ne marchait
simplement pas. Corrigé dans `ServiceMaladies::listePubliee()`, qui résout l'`id` depuis la table
par le code de la version publiée — les deux lectures gardent chacune leur rôle.

### 8.5 Ce qui a été prouvé

**G3** — suite complète **1584/1584, 17 636 assertions, 0 échec** ; 17 vecteurs dédiés (38 avec
B2-a) ; **mutation 6/6 conforme** dont un témoin volontairement vert, arbre restauré et vérifié
par `diff`.

**G2 live MySQL, en deux temps, et le contraste est le vecteur** : *avant* publication du
référentiel, zéro option proposée et le diagnostic **passe quand même en texte libre**, badge
« hors référentiel » — la décision « le lien est facultatif » prouvée en direct ; *après*
publication par un **quatre-yeux réel** (deux agents distincts), 21 options, diagnostic rattaché,
code et libellé figés en base (`MAL000002` / `Choléra`), promotion réussie puis **refusée par son
motif** à la seconde tentative. Base vérifiée : libellé **chiffré**, `source`/`added_by` réécrits
par le serveur, et **2 diagnostics pour 1 seul antécédent**. Base restaurée compte pour compte.

**Piège rencontré, et déjà connu du projet** : `MaladieSeeder` seul laisse les codes nationaux
nuls, donc le contrôle qualité refuse la publication — il faut `masante:maladies:backfill` ensuite.
C'est la conséquence de déploiement que P10c-3-ii avait relevée, et elle se manifeste à
l'identique.

**Faute évitée de justesse** : Pint a reformaté `ServiceLienMaladie` et `ServiceMaladies` — deux
fichiers de P6.8c qui **échouaient déjà** avec les mêmes fixers (style d'alignement délibéré du
dépôt). Le reformatage a été **annulé** et les modifications réappliquées seules : le diff final
est de **66 insertions, 0 suppression**.

### 8.6 Limites de B2-b

- **Aucune prescription rattachée à la consultation** (B2-c).
- **Ni « principal » ni « certitude »** sur un diagnostic : le §5.2 dit « poser un diagnostic » et
  le CDC_04 §103 nomme la table sans plus. Inventer une hiérarchie clinique serait une affirmation
  non sourcée — refus déjà opposé par P6.8c à une colonne `categorie`. Additif le jour où un
  consommateur réel l'exige.
- **Les codes CIM restent vides** : le diagnostic est « codé » au sens du référentiel national,
  pas au sens CIM. Les charger reste de la donnée, zéro code.
- **Le diagnostic de consultation et celui du retour de triage (P10c-3-ii) restent deux saisies
  distinctes.** Ce sont deux faits de nature différente — l'un dit ce qu'a le patient, l'autre juge
  une orientation de machine pour l'apprentissage — et P10c-3-ii a posé que ce jugement doit rester
  un acte délibéré. Les fondre déduirait un jugement ; les rapprocher à l'écran est possible, ce
  n'est pas fait ici.
- **Aucun écran mobile** : le diagnostic est un acte professionnel.


---

## 9. B2-c — l'ordonnance désigne son prescripteur (✅ VALIDÉ G5, 2026-09-03)

**Referme le constat Y2**, ouvert au G0 de B2 : `ordonnances.medecin_nom` est une CHAÎNE. Fiable
depuis P6.5a — le serveur la réécrit — mais **une valeur fiable n'est pas un lien** : « toutes les
ordonnances du D<sup>r</sup> X » et « ce prescripteur exerce-t-il encore ? » étaient insolubles.
Trois colonnes (`medecin_id`, `structure_id`, `consultation_id`), geste identique à celui de P6.7b
sur `resultats_analyses`.

### 9.1 Ce qui n'entre PAS dans la signature, et c'est le point le plus sensible

`DocumentOrdonnance::contenuCanonique()` signe « tout ce dont la modification changerait le sens de
la prescription ». Les trois colonnes sont des **rattachements**, au même titre que `triage_id` que
ce contenu exclut déjà en le nommant « un rattachement de navigation ». Les y ajouter ferait passer
« altérée » **chaque ordonnance signée avant ce jour** — *une signature qui casse toute seule ne
prouve plus rien, et pire, elle accuse* (P6.5b). Le contenu canonique n'est pas modifié.

### 9.2 La distinction de P6.7b passe du commentaire à une liste que le code lit

P6.7b avait établi que « pour une ordonnance, celui qui écrit EST le prescripteur ; pour un
résultat d'analyse, celui qui consigne est souvent quelqu'un d'autre ». Cette phrase vivait dans un
commentaire ; elle vit désormais dans `RegistreSectionsCarnet::SECTIONS_AUTEUR_EST_PRESCRIPTEUR`,
plutôt que dans un `if section === 'ordonnances'` disséminé.

### 9.3 Périmètre réduit, et la raison est écrite

Le plan G1 annonçait « prescription rattachée **+ demandes d'examens** ». Les demandes d'examens
sont **écartées** : `RegistreDocumentsSignables` dit déjà pourquoi — « ce n'est pas un document
mais une DEMANDE qui ouvre un circuit (médecin → laboratoire → résultat, §7.4) », et ce circuit est
un module que P6.7 avait explicitement mis à part. Les livrer ici serait ouvrir un module dans un
autre. `ordonnance_lignes` reste écartée pour la raison de D3 : aucun consommateur.

### 9.4 Trois défauts de méthode, tous trouvés par la mutation

1. **Un cycle de dépendances évité de justesse.** `ServiceConsultation` injecte
   `EcritureSoignantService` depuis B2-b ; l'injecter en retour aurait bouclé. La consultation est
   donc lue depuis la session que ce service porte déjà — une requête, pas un jugement dupliqué.
2. **Un vecteur qui prouvait la validation, pas le service** (onzième occurrence).
   `EcritureSoignantService::ecrire()` VALIDE en interne avec les règles de la section : les clés
   de liens n'atteignent jamais le code qui pose. Il n'y a donc **qu'une couche qui mord**, et le
   commentaire du modèle qui en promettait deux a été corrigé — *décrire une garde jamais
   sollicitée, c'est se raconter une protection*.
3. **LE VECTEUR OBLIGATOIRE NE REPRODUISAIT PAS SON PROPRE CAS.** Il signait une ordonnance qui
   portait **déjà** les liens (écrite par le chemin soignant), donc « reposer » la même valeur ne
   changeait rien : la mutation qui ajoutait `medecin_id` au contenu signé **survivait**. Une
   ordonnance d'avant B2-c n'a aucun lien — le vecteur part désormais d'une ordonnance sans lien,
   et la mutation meurt.

### 9.5 Ce qui a été prouvé

**G3** — suite complète **1595/1595, 17 668 assertions, 0 échec** ; 11 vecteurs dédiés ;
**mutation 4 tueuses + 1 témoin vert**, toutes conformes. Une
cinquième définition a été retirée : son ancre (`'ordonnances',`) n'est pas unique dans le fichier,
donc le harnais la déclarait « non appliquée » — défaut de définition, pas de code, et elle faisait
double emploi avec la mutation de la méthode elle-même.

**G2 live MySQL** — schéma et cinq clés étrangères vérifiés ; ordonnance écrite **par le vrai
portail** pendant une consultation réelle, avec `medecin_id`, `structure_id` et `consultation_id`
envoyés à `999999` par le client : **tous ignorés**, les liens posés désignent la bonne fiche, le
bon établissement et la bonne consultation, et `medecin_nom` porte « Dr Sekou Bamba » au lieu du
nom envoyé. Base restaurée compte pour compte (133 tables, 0 résidu).

**Baseline Pint respectée** : `EcritureSoignantService`, `RegistreSectionsCarnet` et `Ordonnance`
échouaient **déjà** — ils ne sont pas reformatés. Diff final sur ces trois fichiers : **98
insertions, 0 suppression**.

### 9.6 Limites

- **Demandes d'examens non livrées** (voir §9.3) : elles supposent le circuit §7.4.
- **`ordonnance_lignes` et `delivrances` non livrées** : aucun consommateur (lot pharmacie).
- **Les ordonnances antérieures gardent leurs liens nuls** : aucun rattrapage rétroactif n'est
  fait. Les rattacher supposerait de deviner quel praticien a écrit quoi à partir d'un nom — ce
  serait inventer une donnée. Le vecteur de signature prouve seulement qu'un tel rattrapage, s'il
  était fait un jour à partir d'une source sûre, ne casserait aucune signature.
- **Rien ne relie encore une ordonnance à sa délivrance** : le §5.4 décrit
  `Médecin → Patient → Pharmacie`, et seul le premier maillon existe.
