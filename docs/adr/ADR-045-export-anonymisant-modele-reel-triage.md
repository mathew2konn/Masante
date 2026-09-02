# ADR-045 — Export anonymisant + entraînement réel + registre de gouvernance (P10c-3-i)

- **Statut** : **Accepté — P10c-3-i VALIDÉ (G5, 2026-08-29).** G4 propriétaire OK. Une reprise de
  vérification menée le même jour a trouvé un défaut réel de plus (une classe à un seul exemplaire
  faisait rendre un **500 opaque** au lieu d'un refus motivé) — corrigé, voir §7.
- **Date** : 2026-08-29
- **Corpus** : CDC_05 §2 (stack imposée), §5.1 (vecteur), §5.5 pt.4 (« apprendre »), §7.1-§7.3
  (bases de connaissance, pipeline, phases), §8 (MLOps/registre/métriques/drift), §9 (gouvernance,
  traçabilité, explicabilité), §11 (tests), §12 (ordre de construction) · CDC_13 §7.3/§10/§12/§17
  (anonymisation, k-anonymat) · CDC_04 §5.2/§9/§115/§123 · CDC_00 §4 · CDC_08 §9.
- **Lié à** : [[ADR-017]] (stack imposée = application, pas dérogation ; hybride ; honnêteté
  données synthétiques), [[ADR-019]] (frontière HTTP « celui qui expose n'est pas celui qui
  consomme »), [[ADR-041]] (protocoles médicaux, quatre-yeux `ServiceGouvernanceProtocole`),
  [[ADR-042]] (un identifiant de journal n'est pas une relation vivante), [[ADR-043]] (constantes
  cliniques). Plan : `docs/PLAN_G1_P10c3i_Export_Anonymisant_Modele_Reel.md` (F12→F21).

---

## 1. Contexte

P10c-2-i avait livré le socle (client, disjoncteur, minimisation §9.4, boucle de retour §5.5.4) et
nommé sans les fermer deux dettes : la table `jeux_donnees_entrainement` reste **pseudonymisée**
(`triage_id` conservé, quiconque a la base remonte au patient) ; le service Python n'embarque aucune
dépendance ML (« installer XGBoost/SHAP/MLflow pour un modèle qui n'existe pas serait de la mise en
scène »). Cet incrément ferme la **première moitié** de la chaîne littérale du §7.2 :

> *Anonymisation → Validation par les médecins (fait) → Jeu de données d'entraînement →
> Entraînement → Tests → Validation clinique → Déploiement du nouveau modèle.*

Il s'arrête **avant** « Déploiement » — décision de découpage du propriétaire, ci-dessous.

---

## 2. Décision — le découpage suit la lettre du §7.2

Deux arbitrages rendus en session (2026-08-28) : (1) scinder sur la charnière du §7.2 —
**P10c-3-i** couvre tout jusqu'à « Validation clinique » inclus, rien branché sur le flux vivant ;
**P10c-3-ii** couvrira « Déploiement » (validé→actif, câblage de `ClientTriageIa`/`predictions_ia`,
durcissement en chaîne). (2) les antécédents entrent dans le vecteur de features (déjà structurés et
gouvernés par P10b-3-ii, coût faible) ; allergies et médicaments en cours restent hors périmètre,
dette groupée et nommée.

La charnière n'est pas choisie pour équilibrer une charge de travail : elle est celle que le corpus
a déjà posée. Précédent exact : l'incrément 1 de `fraud-detection` (ADR-017), prouvé isolément en
G2 avant qu'ADR-020, un incrément entier plus tard, ne le raccorde au flux vivant.

---

## 3. L'export anonymisant — là où la pseudonymisation devient effective

`ServiceExportJeuEntrainement::exporter()` construit une ligne **neuve** par liste blanche explicite
(jamais `$ligne->toArray()` moins quelques clés — une future colonne fuiterait silencieusement dans
un export qu'on croit anonyme). Ce qui sort : `triage_id`. Ce qui est **généralisé** : l'âge, en
bandes cliniquement usuelles (config `masante.triage_ia.bandes_age`, un paramètre de
**confidentialité**, pas une donnée clinique — délibérément pas un référentiel gouverné) ; la date,
réduite au mois. Ce qui **reste à précision clinique** : constantes et symptômes — les généraliser
détruirait le signal que le modèle doit apprendre, et ce ne sont pas, seuls, des quasi-identifiants
au sens usuel.

**`k_estime`** (taille du plus petit groupe bande-d'âge/sexe/mois) est **calculé et affiché, jamais
bloquant** : sur un volume de dizaines de lignes, un seuil de k-anonymat bloquant rendrait l'export
perpétuellement impossible sans protéger personne de plus — motif P6.7a (« un contrôle qu'on ne peut
pas satisfaire n'est pas une exigence, c'est un mur »).

**Toutes les lignes validées entrent, y compris les retours révisés sur un même triage** : le
commentaire de P10c-2-i laissait la question ouverte (« question d'EXPORT, pas de cet incrément »).
Écarter l'une des deux lignes reviendrait à choisir à la place du médecin qui l'a validée — un
classifieur tolère le bruit d'étiquette, inventer un critère d'arbitrage ne le ferait pas.

**Habilitation `ia_triage.valider`** (même permission que la promotion, voir §5) — l'écran de
gouvernance est une seule surface, la fractionner en plusieurs permissions pour ses trois actions
n'a été demandé par aucune décision (motif `apprentissage.valider`, qui garde de la même façon un
contrôleur entier).

---

## 4. L'entraînement réel — sans générateur synthétique, jamais

`triage-service` gagne la stack imposée par CDC_05 §2 (xgboost/shap/mlflow/scikit-learn/pandas/
numpy) — différée depuis P10c-2-i, la validation du plan G1 constitue l'accord écrit §2.6, même
formule qu'ADR-017 : *« ce n'est pas une dérogation, c'est l'application de la stack imposée »*.

**Aucun générateur synthétique** (contrairement à `fraud-detection`, ADR-017 §4) : le label vient
d'un **vrai** retour médecin (F3, P10c-2-i), jamais fabriqué.

**Multiclasse, jamais binaire** : `adaptee`/`sur_triage`/`sous_triage`, `XGBClassifier` à trois
classes. Un binaire « écart oui/non » laisserait un sur-triage compenser numériquement un
sous-triage — refusé déjà en P10c-2-i (« distingué en donnée pour qu'un futur modèle ne le compense
pas »). **`rappel_sous_triage` est loggé à part** de la moyenne macro : un modèle peut afficher un
bon score agrégé en ratant systématiquement le seul cas dangereux.

**Vecteur de features fixe et indépendant de toute version de protocole publiée** (`NOMS_FEATURES`,
motif exact `fraud-detection/domain/features.py`) — répond directement à Y4 (décalage train/serve
signalé par P10c-2-i) : le modèle peut apprendre à corréler des valeurs avec le label (normal pour
tout signal prédictif), mais ne peut structurellement pas « redire ce que le protocole vient de
calculer », parce que sa cible (appréciation a posteriori d'un médecin) est catégoriquement
différente de la cible du protocole (niveau déterministe, règles gouvernées). `nb_symptomes`
remplace la liste des symptômes — un XGBoost prend des colonnes stables, pas une liste de longueur
variable ; encoder chaque symptôme figerait un vocabulaire versionné, un second problème de
gouvernance que cet incrément n'ouvre pas. Coût dit : SHAP dira que « les symptômes » pèsent, jamais
lequel — même reduction, même raison, que `score_antecedents` pour les antécédents.

**Refus en double garde sous le seuil minimal** (30, arbitraire et dit comme tel — le corpus n'en
fixe aucun) : Laravel refuse avant tout appel réseau, `triage-service` refuse aussi,
indépendamment — motif « dédoublé, une couche un vecteur » de P6.6b.

---

## 5. Le registre de gouvernance — séparé du tracking MLflow

MLflow (`file:./mlruns`, motif ADR-017 §6) trace les **expériences** — params, métriques, artefacts
d'un run. `versions_modeles`/`metriques_modeles` (Laravel, noms **adoptés** du §123, principe P6.8a)
portent la **gouvernance** — qui a entraîné, qui a validé, quand, avec quel statut. Les deux ne se
confondent pas : MLflow n'a aucune notion de permission ni de quatre-yeux.

`statut` : vocabulaire adopté du §8 (`candidat`/`valide`/`actif`/`archive`). `candidat` est posé
**automatiquement** à la fin d'un entraînement réussi — un fait mécanique, aucun jugement humain
requis pour ça. `candidat → valide` exige le **quatre-yeux** du §9 (« validation clinique... avant
toute mise en production d'un modèle influençant une décision de soins ») — motif exact
`ServiceGouvernanceProtocole::publier()` : celui qui a déclenché l'entraînement ne peut pas valider
son propre candidat. `actif`/`archive` existent dans l'ENUM sans être atteignables dans cet
incrément (P10c-3-ii) — même motif que `predictions_ia.mode` portant `hybride` avant P10c-2-i.

Une notification `MODELE_IA_CANDIDAT` (canal existant `ServiceNotification`/`TypeNotification`,
mirroir `@masante/shared` tenu) prévient les détenteurs de `ia_triage.valider`, sauf l'auteur —
corps sans métrique (même prudence que §2.7 pour la facturation, transposée à une donnée de
gouvernance IA plutôt qu'à un contenu clinique).

---

## 6. `score_antecedents` entre dans le vecteur — persisté, jamais recalculé

Décision propriétaire de session : la valeur **déjà gouvernée** par P10b-3-ii
(`ServicePlafondAntecedents`, bornée à 20, sous quatre validations), jamais une liste brute
recalculée. `TriageService::analyser()` la calculait déjà (`details_score.antecedents`) et la
renvoyait — mais rien ne la conservait. Persistée sur `triages.score_antecedents` à l'écriture
(même motif que `score_severite`/`niveau`/`protocole_version` : ne jamais recalculer
rétroactivement une décision), reprise telle quelle par `ServiceRetourTriage::
alimenterJeuApprentissage()`, qui peut tourner des jours plus tard.

---

## 7. Ce que le G2 live a établi (2026-08-29)

Base MySQL réelle, migrations appliquées sans incident. 35 lignes réelles semées par service direct
(`ServiceRetourTriage`/`ServiceValidationApprentissage`, mécanisme déjà prouvé par HTTP en
Partie 7). Export → 35 lignes, `k_estime=5`, `instantane_json` vérifié sans `triage_id`/`membre_id`.
Entraînement réel contre le **vrai** `triage-service` (stack ML réelle) → run MLflow
`fc4b731ea19a46a882d9d9885fa5bc3d`, métriques réelles proches du hasard (**honnêtement** : la
fixture n'avait aucun signal à apprendre — exactement la limite que ce document annonce). Quatre-yeux
prouvé par service direct **et** par le vrai écran (deux comptes, deux sessions HTTP authentifiées
avec CSRF). Notification reçue par les détenteurs réels de la permission, jamais par l'auteur, sans
métrique. **Boundary Y10/F18 vérifié empiriquement** : avec un modèle `valide` en base et
`TRIAGE_IA_ENABLED=true`, un vrai triage a bien fait partir un vrai appel à `/score` (vu dans le log
`uvicorn`) — réponse encore 503, `predictions_ia.mode=degrade`. Base restaurée compte pour compte ;
`protocole_applications` délibérément non touchée (append-only).

**Deux défauts réels trouvés par le harnais de test, avant MySQL** : `score_antecedents` manquait du
`$fillable` de **`Triage`** et de **`JeuDonneesEntrainement`** — Eloquent l'écartait silencieusement
à chaque assignation de masse. Un troisième, plus mineur, corrigé avant même d'atteindre MySQL : la
bande d'âge `1-4` du plan portait en réalité `min:2` — l'étiquette mentait sur sa propre borne.

**Piège d'environnement, pas un défaut de code** : le tout premier appel réel à `triage-service`
fraîchement démarré a expiré sans qu'aucune ligne n'apparaisse dans le log du service — rejoué sans
rien changer, il a réussi. Même famille que le cold-start `host.docker.internal` déjà documenté pour
le paiement.

**Découverte, pas un défaut** : un compte ne portant que `ia_triage.valider` (sans rôle) est refusé
au **login** du portail lui-même (`AuthController::ROLES_PORTAIL`, Module 4) — politique
transversale préexistante, pas propre à cet incrément, qui vaut pour toutes les permissions
orphelines du projet. Un compte réel doit porter l'un des quatre rôles du portail **et**, en plus,
la permission nominative.

### Reprise de vérification après le G4 (même jour) — un défaut réel de plus

**Une classe à un seul exemplaire faisait rendre un 500 opaque.** `train_test_split(stratify=…)`
exige **au moins deux exemplaires de chaque classe présente** ; en dessous il lève un `ValueError`
nu, que FastAPI rendait tel quel. Aucun des 17 vecteurs ne l'exerçait — ils construisaient tous des
fixtures équilibrées. Et le cas n'est pas théorique : **sur les premiers retours réels la classe
rare sera `sous_triage`**, c'est-à-dire précisément la seule dangereuse, celle que le §4 fait
suivre à part par `rappel_sous_triage` parce qu'un agrégat ne la rattrape jamais. Trente lignes dont
une seule en `sous_triage` est exactement ce qu'on attend des premiers mois d'usage — le premier
entraînement réel aurait donc eu de bonnes chances d'échouer **sans un mot exploitable**.

Corrigé par un **refus motivé qui NOMME la classe**, sur le contrat 422 `volume_insuffisant`
existant plutôt qu'un second motif : c'est bien un problème de volume, mais *de cette classe-là*, et
le dire est le motif des quatre validations de P10b-1 (« le refus nomme celle qui manque »).
Deux vecteurs, **une couche chacun** (le service en direct, puis HTTP en vérifiant qu'on obtient un
**422 et non un 500**) — parade P6.6b.

*Ce que ça dit de la campagne initiale* : le harnais couvrait les gardes **décidées**, pas les
défaillances de la **bibliothèque appelée sous des données réalistes**. La fixture équilibrée était
commode, et c'est ce confort qui a masqué le cas.

**L'image Docker est construite (972 Mo), et l'étape de qualité a tourné dans l'image cible** :
`ruff` propre puis **19 vecteurs verts** sous `python:3.11-slim` avec la résolution figée du build
(`mlflow-2.16.2`, `xgboost-2.1.4`, `shap-0.46.0`, `scikit-learn-1.5.2`, `numpy-2.0.2`). C'est une
preuve plus forte qu'un run local : les deux vecteurs neufs de la classe rare passent dans
l'environnement **réellement livré**, pas dans un venv de poste.

Il a fallu **quatre tentatives**, les trois premières tombant sur le réseau — d'où un piège qui
vaut d'être retenu : **un build Docker peut mentir sur sa cause.** Le premier échec fut un
`ResolutionImpossible` accusant `mlflow 2.14.0 depends on sqlalchemy<3` ; la quatrième tentative a
réussi **sans qu'une ligne de `requirements.txt` ne bouge**, ce qui règle la question. Un aléa
réseau pendant la récupération des métadonnées fait rétrograder pip jusqu'à la plus vieille version
candidate, puis il impute l'échec **à celle-là**. Les deux tentatives intermédiaires donnaient
d'ailleurs la vraie cause en clair (`ReadTimeoutError` sur un wheel de 342 Mo, puis blocage sur les
223 Mo d'`xgboost`), et `pip install --dry-run` résolvait proprement **dès la première fois**.
*Avant de desserrer une borne de version sur la foi d'un message de résolution, rejouer et
confronter au dry-run.* Note d'exploitation : l'image tire près de 600 Mo de wheels dont un
`nvidia_nccl_cu12` que le service n'utilisera jamais (dépendance transitive GPU d'`xgboost`) — pas
un défaut de cet incrément, `fraud-detection` a la même, mais c'est ce qui rend la construction
fragile sur une connexion moyenne.

Guide `GUIDE_TEST_TRIAGE.md` **partie 8**.

---

## 8. Conséquences / limites

- **Aucun modèle `actif`, aucune influence sur un triage réel** — régime nominal, P10c-3-ii.
- **k-anonymat estimé sur les seuls quasi-identifiants généralisés**, pas sur la combinaison
  complète avec les constantes/symptômes — nommé, pas caché.
- **Volume réel probablement sous le seuil minimal au jour du G5** : le pipeline est prouvé
  mécaniquement, pas validé statistiquement sur un vrai volume d'usage — même honnêteté qu'ADR-017.
- **Allergies et médicaments en cours hors du vecteur** — dette groupée, nommée.
- **Drift, canary, équité (§8) non traités** — cohérents avec l'ordre de construction du corpus
  lui-même (§12, étape 11/11, après huit domaines IA non construits) — dette nommée, mirroir exact
  de `fraud-detection/DETTE_TECHNIQUE.md` entrée 5.
- **`predictions_ia`/`explications_ia` non peuplées, aucun durcissement en chaîne** — P10c-3-ii.
- **`alertes_drift` nommée par le corpus, non créée** — rien ne l'alimenterait avant que le drift
  soit traité (socle à vide refusé par P6.3-D3).
- **`/api/v1/triage/entrainement` sans principal signé** — posture réseau identique à `/score`
  aujourd'hui ; à durcir sur demande explicite.
- **§5.5.2 (questionnaire personnalisé par IA) reste sans porteur numéroté.**
- **Aucune dépendance nouvelle hors la stack ML déjà approuvée par ADR-017** pour un service frère.
