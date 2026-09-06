# ADR-059 — Le circuit du laboratoire : résultats, automates, validation, publication (B5-c)

**Statut : Accepté — B5-c VALIDÉ (G5, 2026-09-06)** — G3/G2 menés le 2026-09-05, G4 propriétaire OK
et mot du G5 donnés le 2026-09-06. Suite complète **1868/1868**, 18 357
assertions. Contexte : CDC_11 §8.1 (étape 9 de §12), CDC_09 §7.4, CDC_04 §109 · Plan G1 :
[`plan.md` PLAN 4 §10/§13](../../plan.md) · Suite de [ADR-057](ADR-057-circuit-laboratoire.md)
(B5-a) et [ADR-058](ADR-058-circuit-prelevement-laboratoire.md) (B5-b).

**Le lot B5 (le circuit du laboratoire) est COMPLET (a, b, c) — l'étape 9 de l'ordre CDC_11 §12
est achevée.**

---

## 1. Contexte

B5-a a fermé la porte `source` (K5/K11) et livré la demande d'examen signable. B5-b a livré le
laboratoire, le prélèvement, son étiquette et son cycle jusqu'à `en_analyse` — sans jamais ouvrir
de session de dossier. Ce qui restait, dans l'ordre du §7.4 : la saisie et l'import des résultats
(étape 6), la validation biologique (étape 7), la transmission au dossier patient (étape 8), le
registre des automates, et une notification que le propriétaire a ajoutée au périmètre initial du
corpus (« on ne va rien abandonner »).

---

## 2. Décisions (B5-c)

### D1 (M1) — Le brouillon des résultats vit HORS du carnet, sur `prelevements` lui-même

`CarnetSectionController::index()` liste sans filtre de statut : le carnet ne connaît **aucune**
notion de brouillon. Créer une ligne `resultats_analyses` avant validation la rendrait visible au
patient avant que L7 ne l'autorise. `prelevements.resultats_bruts_json` (chiffré, même structure
que `resultats_analyses.resultats_json`) et `resultats_bruts_origine` (`saisie`|`automate`, décidée
par le SERVEUR) portent donc ce brouillon à part. Il **survit** à la publication (M8) : c'est la
pièce médico-légale de ce que le laboratoire a réellement validé et transmis, distincte de la copie
du carnet que le patient peut ensuite modifier — les deux peuvent un jour diverger, et c'est
**attendu**, au même titre qu'une signature qui casse dit qu'un document a été modifié (P6.5b).

### D2 (M2) — Saisie et import : un seul service, une garde applicative honnête

`ServiceValidationBiologique::saisir()` (laborantin, portail) et `::importer()` (automate, API)
écrivent le **même** couple de colonnes par la **même** méthode privée : seule diffère l'origine,
décidée ici, jamais déclarée par l'appelant. La garde d'état (`en_analyse` seulement) est
**applicative**, dite comme telle — jamais déguisée en garantie du moteur (précédent B3-b : une
garde qui dépend d'un texte, pas d'une somme, mais l'honnêteté est la même). Le lien au catalogue
est résolu par `ServiceLienAnalyse::resoudre()`, **réutilisé**, jamais réécrit.

### D3 (M4/M5) — Le rejet efface, journalise, exige son motif — en DOUBLE garde

Un prélèvement `en_analyse` sans brouillon ne peut pas être validé. Rejeter **efface** le brouillon
(`resultats_bruts_json`/`_origine` remis à `null`) et journalise (`validations_biologiques`,
append-only) SANS changer le statut — le prélèvement est déjà `en_analyse` au moment où rejeter a
un sens. Le motif est **obligatoire, sans défaut** (précédent commission P5.5a, révocation P11.2,
rejet d'onboarding P11.1), vérifié en DEUX couches indépendantes : le service ET un déclencheur du
moteur — **prouvé en mutation** : neutraliser la garde du service ne laisse pas passer le rejet
sans motif, la garde du moteur le rattrape (défense en profondeur observée, pas seulement conçue).

### D4 (L7) — La validation est le verrou, `analyse.valider` orpheline (15ᵉ occurrence)

`publier()` exige `statut === VALIDE` : un résultat non validé ne part **jamais** au dossier du
patient. `analyse.valider` (le verdict) n'est portée par **aucun rôle métier** — un résultat
biologique validé engage la responsabilité d'un biologiste **nommé**, jamais un rôle par défaut ;
elle s'accorde nominativement, comme `professionnel.habiliter` ou `document.signer`. Pas
d'interdiction de cumul avec `analyse.executer` (le corpus ne l'exige pas, et un garde-fou plus
strict que sa propre règle est un défaut, P6.8c) — mais chaque acte **nomme** la permission qui
l'autorise, et le service la revérifie, jamais seulement le middleware (piège P4).

### D5 (M10) — Le groupe de routes s'ouvre à DEUX permissions, la garde qui compte reste au service

`permission:analyse.executer|analyse.valider` (patron `rdv.prevalider|rdv.validate` de B1-a,
`protocole.valider.*` de P10b-1) : un biologiste qui n'exécute jamais de prélèvement doit pouvoir
ouvrir la fiche pour la valider. `ServiceValidationBiologique` revérifie l'habilitation exacte pour
chaque acte — `valider()`/`rejeter()`/`publier()` exigent `analyse.valider`,
`saisir()` exige `analyse.executer`, jamais l'inverse.

### D6 (M7) — La publication réutilise `ServiceLienResultat` DIRECTEMENT, jamais le composite du contrôleur

**Point le plus sensible du lot.** Le plan initial envisageait de faire passer la publication par
`RegistreSectionsCarnet::controleur('resultats-analyses')->preparerDonnees()` — le même point
d'accroche que les trois autres chemins d'écriture. **Écarté à l'implémentation** : ce point
d'accroche composite re-résoudrait *aussi* `resultats_json` via `ServiceLienAnalyse`, donc
re-interrogerait le catalogue sur des valeurs **déjà figées à la saisie**. Si le catalogue change
entre-temps (une unité corrigée sous quatre-yeux), le résultat publié changerait silencieusement
sans qu'aucun biologiste ne l'ait revu — exactement le risque que `ProjecteurLignesDemande` (B5-a)
existe pour fermer sur les lignes de la demande. `resultats_json` est donc copié **VERBATIM** depuis
le brouillon ; seuls `medecin_prescripteur_id`/`laboratoire_id` (des déclarations sur des TIERS,
jamais chiffrées, jamais figées avant cet instant) passent par `ServiceLienResultat`, appelé
**directement** — réutilisé, pas dupliqué, mais pas non plus composé avec un mécanisme qui aurait
réintroduit la re-résolution. **Prouvé par un vecteur dédié et par la mutation** : modifier le
catalogue après la saisie, avant la publication, ne change rien au résultat déjà publié ; forcer la
ré-résolution (mutation) fait échouer ce vecteur exact.

### D7 (M7) — `source='structure'` : LA valeur qui referme K5/K11, jamais un choix du client

Posée par `publier()` seul, **après** toute résolution — hors de la portée de tout composite
partagé avec les autres chemins d'écriture. C'est le fait qui distingue un résultat publié par ce
circuit d'un compte rendu recopié par le patient, et c'est exactement la valeur que K5/K11 (B5-a)
avaient fermée au client sans encore lui donner d'émetteur légitime. `origine`
(`saisie`|`automate`, nouvelle colonne sur `resultats_analyses`) l'accompagne, décidée de la même
façon.

### D8 (M6) — Une demande = un cycle : `estOuverte()` devient une garde réelle

`DemandeAnalyse::estOuverte()` existait depuis B5-a, jamais câblée. **Défaut réel laissé ouvert par
B5-b**, invisible tant que `servie` était inatteignable : `enregistrer()` ne vérifiait rien sur
l'état de la demande, et rien ne limitait le nombre de prélèvements qu'on pouvait y enregistrer.
Maintenant que B5-c peut publier (donc atteindre `servie`), la garde devient réelle :
`assertOuverte()` refuse un nouveau prélèvement contre une demande `servie` ou `annulee`, en
nommant l'état. Plusieurs prélèvements **avant** publication restent possibles (échantillon
insuffisant, reprise) — seule la publication ferme la porte, miroir du monde réel où une
ordonnance honorée ne se réutilise pas.

### D9 (M9, L10 réécrit) — Les automates sont déclarés PAR COMMANDE, jamais par écran

Même raisonnement qu'`EmettreClientApiCommand` (P11.2) : déclarer un appareil qui écrira dans des
dossiers patients est un acte d'exploitation vérifié hors du système, pas une saisie de routine.
`masante:laboratoire:automate` (déclarer/désactiver). `automates.client_api_id` (nullable,
`nullOnDelete`) **trace** sous quelle clé cet appareil pousse — il **n'authentifie rien lui-même** :
l'authentification reste entièrement portée par le HMAC (`AuthentificationClientApi`, inchangé,
P11.2). L'`automate_id` porté par la charge d'ingestion désigne, pour le journal, quel appareil
parle, et le serveur vérifie qu'il appartient à LA MÊME structure que le client authentifié —
anti-usurpation d'un automate par la clé d'un autre laboratoire.

### D10 (L10) — Un automate ne valide JAMAIS ; le serveur ne devine jamais un prélèvement

`ServiceValidationBiologique::importer()` n'écrit qu'un brouillon — le prélèvement reste
`en_analyse`. La validation biologique humaine reste, dans tous les cas, un acte séparé. Le
rattachement se fait par l'**identifiant du prélèvement** (l'étiquette du tube), jamais par un
rapprochement de nom de patient : *ce serait l'erreur d'identification que le §7.4 existe pour
supprimer*. Un identifiant inconnu est **refusé et nommé** (patron P11.2 : « le serveur ne devine
pas une référence produit »), jamais deviné.

### D11 (M9, D3 de P11.2 transposé) — L'ingestion réutilise le service, jamais un second chemin d'écriture

`IngestionResultatsLaboratoire` n'écrit rien lui-même : il résout l'automate et le prélèvement
désignés, puis appelle `ServiceValidationBiologique::importer()` — le même service que la saisie
manuelle. Acceptation partielle avec rapport nominatif (patron P11.2) : un lot dont une ligne
échoue écrit les autres et nomme la fautive avec son motif. Un `automate_id` invalide ou hors de la
structure du client authentifié fait échouer le **lot entier** (question d'autorisation, pas une
ligne à refuser parmi d'autres) — distinction trouvée en construisant, pas au G0 : `etablissementRef`
seul ne suffisait pas, il fallait vérifier que l'automate désigné appartient au client qui parle.

### D12 (L14/M11) — La notification dit l'événement, jamais le résultat

`RESULTAT_ANALYSE_PUBLIE`, émis **uniquement** par `publier()`. Même patron que
`carnetEnrichi()`/`rendezVousTermine()` : destinataires = titulaire + délégués en lecture. Un type
**dédié**, pas `CARNET_ENRICHI` (leçon B1-d, « le mot avant le mécanisme ») : un résultat d'analyse
est attendu, un enrichissement générique ne l'est pas. Contenu : « Un résultat d'analyse a été
déposé dans le carnet de {membre} par {laboratoire}. » — **ni l'intitulé de l'analyse, ni la
moindre valeur**. La règle inviolable de P7-D1 mord ici plus fort qu'ailleurs : un push s'affiche
sur un écran verrouillé, et « sérologie VIH » y serait une divulgation. Vérifié par un test dédié
ET en direct sur la base réelle (le corps du push ne contient que le nom du patient et du
laboratoire).

### D13 (M12) — Extension additive de `journal_laboratoire.action`, ENUM élargi jamais réécrit

Cinq actes de plus (`resultat_saisi`, `resultat_importe`, `validation`, `rejet`, `publication`) —
patron `triages.niveau` (P10b-1), `paiements.mode` (B4-b), `prix_pharmacie.source` (P11.2). Aucune
valeur retirée, aucune ligne existante réinterprétée.

---

## 3. Un défaut réel de migration, trouvé par la suite complète, pas par les 42 vecteurs dédiés

`Schema::table('journal_laboratoire', …)->enum('action', …)->change()` a fait échouer **deux**
vecteurs hérités de B5-b (`CircuitPrelevementTest::test_le_journal_refuse_…_au_niveau_du_moteur`),
invisibles à la suite de B5-c parce qu'elle ne les exerce jamais. **Sous SQLite** (le dialecte de
test), Laravel émule un `ALTER COLUMN` en reconstruisant toute la table : il crée une copie, copie
les lignes, **supprime l'originale**, renomme la copie. SQLite supprime automatiquement les
déclencheurs attachés à une table qu'on supprime — les deux gardes append-only de
`journal_laboratoire` (posées par la migration de B5-b) disparaissaient donc **silencieusement**,
sans qu'aucune erreur ne le signale : le journal redevenait modifiable.

**Sous MySQL**, `ALTER TABLE … MODIFY COLUMN` ne reconstruit pas la table par son nom : les
déclencheurs survivent — **vérifié au G2 live, pas supposé** (`SHOW TRIGGERS` confirme les deux
déclencheurs `trg_journal_labo_*` intacts après la migration réelle). Le défaut est donc propre à
SQLite, et c'est la direction la **plus traître** de la divergence P6.8c/P6.8e/P10c-3-ii/P11.2 :
ici la garantie qui manque est celle du dialecte de **test**, pas celle de production — un test qui
exercerait cette garde après coup l'aurait trouvée déjà rompue, silencieusement, sans lien apparent
avec cette migration. Corrigé par `reconstituerGardesJournalLaboratoire()` : ne fait **rien** sous
MySQL (les déclencheurs de B5-b y sont intacts), **recrée** les deux gardes sous SQLite — appelée
après chaque `->change()` sur cette table, dans `up()` et `down()`.

---

## 4. Preuves

**G3** : 42 vecteurs dédiés (`ResultatBiologiqueTest`), 94 assertions ; suite complète **1868/1868,
18 357 assertions, 0 échec** (deux exécutions indépendantes, avant et après la campagne de
mutation, toutes deux propres). **Campagne de mutation manuelle : 8 tueuses + 1 témoin
volontairement vert**, chacune assertée appliquée avant interprétation, chaque fichier restauré et
vérifié par `diff` :

- garde applicative `en_analyse` (`enregistrerBrouillon`) → tue 2 vecteurs (avant mise en analyse,
  après validation) ;
- brouillon requis pour valider (`assertPeutJugerLeBrouillon`) → tue 1 vecteur ;
- permission `analyse.valider` (`assertHabiliteValidation`) → tue 1 vecteur ;
- garde `VALIDE` de `publier()` → tue 1 vecteur ;
- `assertOuverte()` (M6, `ServiceCircuitPrelevement`) → tue 1 vecteur ;
- anti-IDOR (`assertAppartientAuLaboratoire`) → tue 2 vecteurs (404 direct, refus de publier) ;
- motif de rejet obligatoire → tue 1 vecteur **par la garde du MOTEUR**, le service neutralisé
  laissant la garde du moteur intervenir seule (`QueryException` au lieu de `ValidationException`
  — la mutation reste tuée, par une couche différente : défense en profondeur observée en
  pratique) ;
- anti-usurpation d'un automate d'un autre laboratoire (`importer()`) → tue 1 vecteur ;
- **le point le plus important** : forcer la ré-résolution de `resultats_json` via
  `ServiceLienAnalyse` au lieu de la copie verbatim → tue le vecteur qui prouve la décision D6 ;
- témoin : réordonnancement de deux affectations indépendantes dans `enregistrerBrouillon()` →
  **42/42 restent verts**.

Pint propre sur tous les fichiers touchés ; baseline établie avant tout formatage
(`ResultatAnalyse.php` gardait une seule ligne mal alignée, préexistante, non reformatée).

**G2 live MySQL réel**, en trois temps :

1. **Schéma et déclencheurs** : `mysqldump --routines --triggers` avant migration (stderr redirigé
   séparément), migration réelle, `PortailRolesSeeder` rejoué. Les deux colonnes de `prelevements`,
   la colonne `origine` de `resultats_analyses`, `validations_biologiques`, `automates` : tous
   conformes. `SHOW TRIGGERS` confirme les 7 déclencheurs attendus, y compris les deux
   `trg_journal_labo_*` de B5-b **intacts** malgré l'extension de l'ENUM (§3 ci-dessus). Un rejet
   sans motif en SQL direct → `ERROR 1644` ; `UPDATE`/`DELETE` sur `validations_biologiques` →
   `ERROR 1644` (append-only, les deux moments) ; `journal_laboratoire` toujours append-only.
2. **Cycle complet réel via le SERVICE** (script direct contre la vraie connexion MySQL) : demande
   → prélèvement → `en_analyse` → saisie refusée sans habilitation → saisie acceptée (brouillon
   chiffré en base, vérifié par requête SQL directe qu'aucun texte en clair n'y figure) →
   validation refusée à un laborantin sans `analyse.valider` → validation acceptée → **le
   catalogue est modifié entre-temps** → publication → **l'unité publiée reste celle de la saisie,
   pas celle du catalogue modifié** (preuve directe de D6) → `acces_dossier` reste à **0** du début
   à la fin → demande `servie` → un second prélèvement contre cette même demande est refusé, en
   nommant l'état → `travailPour()` inclut bien un prélèvement `valide` en attente de publication
   (correction du défaut hérité de B5-b, §5 ci-dessous).
3. **Cycle complet réel via HTTP, sessions et CSRF réels** (portail) : connexion réelle d'un
   laborantin, vue de la demande par jeton, enregistrement du prélèvement, réception, mise en
   analyse, **saisie du résultat par un vrai POST de formulaire** (`valeurs[0][…]`) ; connexion
   réelle d'un second compte portant `analyse.valider` (rôle `laborantin` + permission
   nominative — aucun rôle `biologiste` n'existe, conformément à D4), qui **voit le brouillon
   saisi**, valide, publie ; la page finale confirme la publication. **Anti-IDOR réel** : un
   troisième compte, laborantin d'un second laboratoire (même permission `analyse.valider`),
   reçoit **404** sur la fiche du prélèvement. **Ingestion automate réelle** : une clé `ClientApi`
   et un `Automate` réels, un prélèvement réel en `en_analyse`, un client PHP autonome (`curl`)
   signant comme le ferait un vrai middleware de laboratoire — envoi accepté (200), **rejeu refusé**
   (401, anti-rejeu réel), **identifiant de prélèvement inconnu refusé ET nommé** dans la réponse
   JSON réelle, **signature fausse refusée** (401). Vérifié en base réelle : le prélèvement importé
   reste `en_analyse`, `resultat_analyse_id` NULL — **un automate n'a jamais validé, en réel**.
   Notification vérifiée en base réelle : corps ne contenant que le nom du patient et du
   laboratoire, aucune analyse, aucune valeur.

**Base restaurée** : dump réimporté, les quatre tables neuves explicitement supprimées (le dump,
pris avant migration, ne les connaît pas — leçon retenue de B5-b), migrations revenues à
`Pending`, 145 tables, `.env` inchangé, comptes et structures vérifiés revenus à leurs effectifs
d'avant test (13 structures, 19 utilisateurs, 0 demande, 0 résultat).

**Vérification supplémentaire des deux `down()`, via le mécanisme natif de Laravel plutôt que par
restauration manuelle** : `migrate --force` (B5-b puis B5-c) puis `migrate:rollback --step=1` deux
fois de suite sur la vraie base — les deux migrations se défont proprement, sans erreur, 145 tables
et 13/19 structures/utilisateurs retrouvés à l'identique. Une preuve plus stricte que la
restauration par dump, puisqu'elle exerce le code `down()` lui-même plutôt que de le contourner.

---

## 5. Corrections apportées à l'existant, trouvées en construisant B5-c

- **`ServiceCircuitPrelevement::travailPour()` s'arrêtait à `en_analyse`** : un prélèvement
  **validé**, en attente de publication, disparaissait de « mon travail en cours » sans qu'aucun
  biologiste ne puisse le retrouver pour le publier. Invisible en B5-b faute d'état `valide`
  atteignable. Corrigé : la liste inclut désormais `valide`.
- **`assertOuverte()` sur `enregistrer()`** (D8 ci-dessus) : défaut réel de B5-b, non détectable à
  l'époque.

---

## 6. Limites annoncées

- **Le driver ASTM E1381/E1394 ou HL7 v2 côté automate n'est pas fourni** — notre moitié du
  contrat l'est (registre, authentification, ingestion, garde de non-validation) ; l'autre suppose
  un appareil physique que nous n'avons pas vu (position ADR-030, tenue par P11.2).
- **Aucun registre national de consommation d'analyses** (L11/L9) — les valeurs restent chiffrées,
  et le corpus ne le demande que pour les médicaments (§7.6).
- **Catalogue toujours un jeu de démonstration** (hérité P6.7a) — charger le catalogue national
  réel est une donnée, pas du code.
- **La validation biologique n'est pas signée cryptographiquement** — la PKI signe la *demande*
  (B5-a, L8), pas le verdict ; le journal nomme son auteur.
- **`journal_laboratoire`/`validations_biologiques` ne sont PAS des chaînes de hachage** —
  append-only sans empreinte chaînée (ADR-042 a montré ce que coûte une chaîne, précédent B3-c :
  *on ne durcit pas par symétrie décorative*).
- **Aucune radiologie** — DICOM/PACS hors périmètre (seconde moitié de l'étape 9 de §12).
- **Aucun écran mobile** — le patient voit son résultat publié dans son carnet (section
  `resultats-analyses`, inchangée) et reçoit la notification ; il ne suit pas le cycle du
  prélèvement.
- **`ReglesCode128` reste prouvée par auto-cohérence, jamais confrontée à une douchette physique**
  (limite héritée de B5-b, non aggravée).
- **Une demande = un cycle** (D8) : plusieurs examens exigeant des prélèvements physiquement
  distincts après une première publication supposent une **nouvelle** prescription, jamais un
  second résultat sur la même demande.
