# PLAN G1 — P10c-3-i : export anonymisant + entraînement réel + registre de gouvernance

**Statut : PLAN CLOS — incrément VALIDÉ G5 le 2026-08-29** (G4 propriétaire OK). Voir ADR-045 et
`GUIDE_TEST_TRIAGE.md` partie 8 pour ce qui a réellement été livré et les trois défauts trouvés.

**Statut d'origine : G1 VALIDÉ (2026-08-29)** — découpage i/ii et périmètre des features validés le 2026-08-28 ;
plan détaillé (décisions F12→F21, dépendances) validé sans amendement le 2026-08-29.

**Arbitrages déjà rendus :**
1. **Découpage** — scinder sur la charnière littérale du §7.2 : **P10c-3-i** (ce document) livre
   export anonymisant + entraînement réel + registre, **rien branché sur le flux vivant** ;
   **P10c-3-ii** livrera la promotion validé→actif, le câblage de `ClientTriageIa`/`predictions_ia`,
   et le durcissement en chaîne.
2. **Features** — les antécédents entrent dans le vecteur (déjà structurés, gouvernés, coût faible) ;
   allergies et médicaments en cours restent hors périmètre, nommés comme dette groupée.

Corpus : CDC_05 §2/§5.1/§5.5 pt.4/§7.1-§7.3/§8/§9/§11/§12 · CDC_13 §7.3/§10/§12/§17/§18 ·
CDC_04 §5.2/§9/§115/§123 · CDC_00 §4 · CDC_08 §9 · CDC_10 §9/§12.
Précédents : ADR-017 (stack imposée = application, pas dérogation ; hybride ; honnêteté données) ·
ADR-019 (frontière HTTP, « celui qui expose n'est pas celui qui consomme ») ·
ADR-042 (D1 : un identifiant de journal n'est pas une relation vivante) ·
ADR-043 (bornes/refus/plausibilité des constantes) ·
`docs/PLAN_G1_P10c2i_Boucle_Apprentissage_Service.md` (F3/F4/F10/F11, Y4/Y5/Y7/Y13 — repris ici).

---

## 0. Ce que cet incrément est, et ce qu'il n'est pas

P10c-3-i ferme la première moitié de la chaîne littérale du §7.2 :
*Anonymisation → Validation par les médecins (faite, P10c-2-i) → Jeu de données d'entraînement →
Entraînement → Tests → Validation clinique.* Il s'arrête **avant** « Déploiement du nouveau modèle » —
`/api/v1/triage/score` continue de répondre 503 dans le flux vivant, exactement comme aujourd'hui.

**Pourquoi s'arrêter là plutôt que tout livrer d'un bloc** : ça permet de prouver tout le pipeline
d'apprentissage en G2 live, isolément, avant de risquer quoi que ce soit sur le chemin qui sert un
vrai triage à un vrai patient. Précédent exact : l'incrément 1 de `fraud-detection` (ADR-017) a été
prouvé en G2 par Swagger+curl sur le service **isolé**, et ce n'est qu'ADR-020, un incrément entier
plus tard, qui l'a raccordé au flux vivant du paiement.

**Ce que ça livre concrètement** : un export anonymisé versionné, un microservice qui sait
véritablement entraîner un XGBoost et l'expliquer par SHAP, un registre de gouvernance
(candidat → validé) côté Laravel, la stack ML enfin installée (sous l'accord que ce document
constitue). **Ce que ça ne livre pas** : un triage réel influencé par l'IA — c'est P10c-3-ii.

---

## 1. G0 — suite directe de Y1→Y8 (P10c2i)

### Y9 — Le corpus ne fixe aucun paramètre chiffré, ici comme ailleurs

Vérifié sur les 14 CDC : aucune valeur de *k* pour le k-anonymat (CDC_13 §12 le nomme sans le
chiffrer), aucun seuil d'AUC/F1 pour promouvoir candidat→validé, aucun seuil de volume minimal avant
entraînement. Ce sont donc des paramètres **à motiver ici**, pas des oublis de lecture — même statut
que les 210 000 itérations PBKDF2 de P6.5b ou le `k=5` qu'aucune ADR n'a encore posé.

### Y10 — `ClientTriageIa` traite déjà tout succès comme une anomalie, et c'est voulu jusqu'ici

Lu en entier : `ClientTriageIa::scorer()` a cinq branches, et la cinquième (réponse 2xx) produit
`degrade('reponse_inattendue_200', ...)` — jamais un résultat exploité. `ResultatTriageIa` n'a qu'une
factory `degrade()`, constructeur privé. **Rien dans ce document ne touche à ces deux classes.** Même
si P10c-3-i donne au service quelque chose de réel à répondre, Laravel continuera de le traiter comme
une anomalie tant que P10c-3-ii n'aura pas ajouté la branche `hybride()`. C'est la vérification
concrète que « rien branché sur le flux vivant » n'est pas qu'une intention.

### Y11 — Le jeu d'apprentissage porte déjà les 6 constantes + `poids`, sans lacune

Vérifié en lisant `TriageController::appelerAssistanceIa()` et
`ServiceRetourTriage::alimenterJeuApprentissage()` : `poids` est déjà envoyé au service IA et déjà
écrit dans `jeux_donnees_entrainement` — ce n'est pas un 8ᵉ type de `referentiels_mesure` à créer
(il existe depuis le Module 5), P10c-1 l'a simplement réutilisé comme les cinq autres. **Aucune
migration de constante n'est nécessaire ici.**

L'écart réel avec CDC_05 §5.1 porte sur trois features nommées par le corpus et absentes de la charge
envoyée : antécédents, allergies, médicaments en cours. Décision propriétaire déjà rendue (voir
préambule) : seuls les antécédents entrent, sous F14.

### Y12 — `jeux_donnees_entrainement.triage_id` reste réversible tant qu'aucun export ne l'a retiré

C'est exactement ce que F4 (P10c2i) avait annoncé : la table porte une **pseudonymisation**, pas une
anonymisation, parce que quiconque a la base peut remonter au patient via `triage_id`. L'export de cet
incrément est le mécanisme qui rend l'anonymisation **effective** (§7.2 n'était tenu qu'à moitié).

`ServiceValidationApprentissage::pretsPourExport(): Builder` existe déjà et filtre aux lignes
`statut='valide'` — c'est le filtre d'entrée de l'export, pas à réécrire.

### Y13 — Plusieurs lignes peuvent partager un `triage_id`, et personne n'a encore tranché laquelle compte

Le commentaire de `alimenterJeuApprentissage()` le dit lui-même : *« Choisir laquelle fait foi à
l'entraînement est une question d'EXPORT (P10c-3), pas de cet incrément. »* C'est cet incrément.
Décision : **F13 ci-dessous.**

### Y14 — `triage-service` n'a pas de générateur synthétique, et ne doit pas en avoir

Y5 (P10c2i) l'avait déjà écarté au niveau du principe (« `triage-service` ne peut pas être une copie
de `fraud-detection` », les règles ne se redoublent jamais en Python). Ça vaut aussi pour
l'entraînement : `fraud-detection` s'entraîne sur une population synthétique **par construction**
(ADR-017, jamais caché) ; ici, F3/F4 ont déjà fixé que le label vient d'un **vrai** retour médecin.
Aucun générateur, donc aucune donnée à entraîner tant qu'aucun retour réel validé n'existe — voir F15.

---

## 2. Décisions

### F12 — Le découpage suit la lettre du §7.2, pas une coupure arbitraire

*« Anonymisation → Validation par les médecins → Jeu de données d'entraînement → Entraînement →
Tests → Validation clinique → Déploiement du nouveau modèle. »* P10c-3-i couvre tout jusqu'à
« Validation clinique » inclus ; P10c-3-ii couvre « Déploiement ». La charnière n'est pas choisie pour
équilibrer la charge de travail, elle est **celle que le corpus a déjà posée**.

### F13 — Toutes les lignes validées entrent à l'export, y compris les retours révisés sur un même triage

Un médecin qui se ravise (retour B après retour A sur le même triage — les deux peuvent être validés
indépendamment) produit **deux exemples d'entraînement distincts**, jamais un seul « qui fait foi ».
Écarter silencieusement l'un des deux serait choisir à la place du médecin qui l'a validé — précédent
direct : P10b-1, *« un relecteur corrigeant son avis voyait le précédent faire autorité, faute d'ordre
total »* ; ici l'ordre total existe (`cree_le` puis `id`, déjà posé par P10c2i) mais il sert à
l'affichage, pas à trancher un entraînement. Un classifieur tolère le bruit d'étiquette ; inventer un
critère d'arbitrage ne le ferait pas.

### F14 — Les antécédents entrent comme la valeur DÉJÀ gouvernée, jamais comme une liste brute recalculée

Pas de nouvelle logique : la feature envoyée est `score_antecedents`, la même quantité bornée à 20 que
`ServicePlafondAntecedents` calcule déjà sous quatre validations (P10b-3-ii). Réutiliser un calcul
gouverné plutôt qu'en dériver un second évite exactement le défaut que P6.8a a nommé (« deux
vocabulaires pour le même fait finissent par diverger »).

**Coût assumé et dit** : SHAP ne pourra jamais nommer *quel* antécédent pèse — seulement que « les
antécédents » pèsent, en bloc. Nommer un antécédent précis exigerait un encodage catégoriel stable par
maladie, hors périmètre de cet incrément.

Colonne ajoutée : `jeux_donnees_entrainement.score_antecedents` (`unsignedTinyInteger nullable`,
migration additive). `alimenterJeuApprentissage()` et `appelerAssistanceIa()` sont étendus d'un champ
chacun — aucune autre classe ne bouge.

### F15 — L'entraînement REFUSE bruyamment sous un seuil minimal, en double garde

Sans générateur synthétique (Y14), le volume réel dépendra de l'usage. Refuser plutôt que d'entraîner
sur trop peu et de le taire est le seul choix cohérent avec tout ce que ce projet a déjà fait (P6.3 :
aucune publication sans quorum ; P10b-1 : un brouillon non validé n'est jamais appliqué).

Seuil proposé : **30 lignes validées au total** dans l'export (paramètre déclaré en config, pas en
dur — `config('masante.triage_ia.seuil_min_entrainement')`, défaut 30). C'est un choix arbitraire et
je le dis : le corpus n'en fixe aucun (Y9). Le propriétaire peut l'ajuster sans que rien d'autre ne
change.

**Double garde, deux vecteurs** (motif P6.6b — « dédoublé, une couche un vecteur ») : Laravel refuse
*avant* d'appeler le service (évite l'aller-retour réseau) ; `triage-service` refuse aussi,
indépendamment, s'il reçoit moins de lignes que son propre seuil — défense en profondeur, jamais de
confiance aveugle dans l'appelant.

**Conséquence assumée pour le G4/G5** : au jour de la validation, le volume réel de retours médecins
sera presque certainement sous ce seuil. Le G2 live devra donc **semer** des retours réels via le vrai
chemin HTTP (`POST dossier/triage/{triage}/retour`, en boucle scriptée, pas un par un à la main) pour
prouver le pipeline mécaniquement. Le modèle qui en sortira sera réel dans son **mécanisme**
(vraies données, vrai pipeline, vraie explication) et **non validé statistiquement** faute de volume —
exactement l'honnêteté déjà tenue pour `fraud-detection` (« conçu, jamais validé cliniquement »).

### F16 — Entraînement XGBoost multiclasse + SHAP, features fixes et indépendantes du protocole

Le label a trois valeurs non symétriques (F3, P10c2i) : `adaptee` / `sur_triage` / `sous_triage`.
Le modèle est un `XGBClassifier` multiclasse — jamais un binaire « écart oui/non » qui laisserait un
sur-triage compenser numériquement un sous-triage, ce que le projet a déjà refusé d'admettre ailleurs
(*« le sous-triage est le seul dangereux, distingué en donnée pour qu'un futur modèle ne le compense
pas avec le sur-triage »*, P10c-2-i partie A).

**Ça répond directement à Y4 (le décalage train/serve republiable sans code, signalé par P10c2i)** :
le vecteur de features du modèle est **fixe et indépendant de ce qu'une version de protocole utilise
aujourd'hui** — âge, sexe, symptômes, 6 constantes, durée, intensité, grossesse, `score_antecedents`.
Le modèle peut apprendre à corréler ces valeurs avec le label (normal pour tout signal prédictif), mais
il ne peut structurellement pas « redire ce que le protocole vient de calculer », parce que sa cible
(l'appréciation a posteriori d'un médecin) est catégoriquement différente de la cible du protocole
(un niveau déterministe issu de règles gouvernées). Mécanisme identique à `fraud-detection` :
`NOMS_FEATURES` unique, partagé entraînement/service, jamais recalculé séparément.

**Métrique nommée explicitement, au-delà de la liste du §8** : `rappel_sous_triage` (rappel calculé
sur la seule classe `sous_triage`), loggée à part dans MLflow. La métrique agrégée peut être haute
pendant que le modèle rate systématiquement le cas dangereux — l'agrégat seul ne le dirait jamais.

Hyperparamètres de départ (mêmes défauts que `fraud-detection`, réduits pour un volume faible) :
`n_estimators=100, max_depth=3, learning_rate=0.1`, `eval_metric="mlogloss"` (multiclasse). Split
stratifié par label. Sur un volume de quelques dizaines de lignes, aucun réglage fin n'aurait de sens
avant qu'un vrai volume existe — ce n'est pas différé par facilité, c'est que la question n'a pas de
réponse mesurable avant.

### F17 — Le registre de gouvernance vit en Laravel ; MLflow reste le tracking d'expérience côté Python

Noms adoptés du §123 (CDC_04), pas réinventés :

- **`versions_modeles`** (Laravel) : `id`, `pays_code`, `numero_version` (entier, auto-incrémenté par
  `pays_code` — motif `protocole_versions`/`referentiel_versions`), `export_id` (FK vers
  `exports_jeu_entrainement`), `statut` ENUM(`candidat`,`valide`,`actif`,`archive` — vocabulaire
  littéral CDC_05 §8), `mlflow_run_id` (string), `entraine_par_id` (nullable, `nullOnDelete` —
  identifiant de journal, pas relation vivante, ADR-042 D1), `valide_par_id` (idem),
  `date_validation_clinique` (nullable), `cree_le`.
- **`metriques_modeles`** (Laravel) : `version_id` (FK), `cle` (string — `exactitude`, `precision`,
  `rappel`, `f1`, `auc`, `rappel_sous_triage`, `latence_entrainement_ms`), `valeur` (decimal),
  `mesure_le`. Table clé-valeur plutôt que colonnes larges et creuses — une métrique de plus n'est
  jamais une migration.
- **`exports_jeu_entrainement`** (Laravel) : `id`, `pays_code`, `numero_export` (auto-incrémenté),
  `instantane_json` (les lignes anonymisées — motif P6.3-D1, « version + instantané JSON »),
  `nb_lignes`, `k_estime`, `cree_par_id` (nullable), `cree_le`. **Aucun `triage_id` dans
  `instantane_json`** — vecteur dédié qui grep la charge entière et casse le build (précédent F9).
- **`alertes_drift`** : nommée par le corpus, **pas créée** — rien ne l'alimenterait avant P10c-3-ii
  au plus tôt, et une table à vide est le socle refusé par P6.3-D3.
- **`explications_ia`** : déjà couverte par les colonnes existantes de `predictions_ia`
  (`facteurs_json`/`explication_json`) — pas de table séparée, elles resteront NULL jusqu'à ce que
  P10c-3-ii les peuple.

**Un seul modèle `actif` à la fois** sera une règle de P10c-3-ii (c'est là que « actif » prend un
sens) — non pertinente ici puisqu'aucun modèle n'atteint ce statut dans cet incrément.

### F18 — Promotion candidat → validé, à QUATRE YEUX, permission portée par aucun rôle

`candidat` est posé automatiquement à la fin d'un entraînement réussi (aucun jugement humain requis
pour ça — c'est un fait mécanique, pas une décision). `candidat → valide` est en revanche la
« validation clinique » que CDC_05 §9 exige *« avant toute mise en production d'un modèle influençant
une décision de soins »* — même poids que la publication d'un protocole ou d'un référentiel, donc même
garde : **quatre yeux** (celui qui valide ≠ celui qui a déclenché l'entraînement), 409 nommant le motif
en cas de contournement — motif `protocole_versions`/`referentiel_versions`, pas une invention propre
à ce document.

Permission neuve `ia_triage.valider`, **portée par aucun rôle métier** — **14ᵉ occurrence** de ce
motif dans le projet (la 13ᵉ était `apprentissage.valider`, P10c-2-i partie B) : un statisticien qui a
entraîné le modèle ne se valide pas lui-même, et aucun rôle existant (médecin, agent, gestionnaire
d'établissement) n'a vocation à porter une décision de gouvernance nationale sur un modèle IA.

### F19 — Déclenchement synchrone (Artisan + écran), notification de candidature réutilise l'outbox existante

Deux commandes Artisan, motif `masante:nis:backfill` (idempotentes, dry-run possible) :
`triage:jeu-entrainement:exporter` (produit une ligne `exports_jeu_entrainement`) et
`triage:modele:entrainer {export}` (appelle `triage-service`, écrit `versions_modeles`+
`metriques_modeles`). Synchrone — au volume de cet incrément (dizaines de lignes), un entraînement se
compte en secondes ; passer par une file d'attente serait une abstraction sans consommateur.

Écran portail Blade (motif F11/K1 — sans investissement de design) : liste des versions, leurs
métriques, bouton « Lancer un entraînement » (exécute les deux commandes), action « Valider » gardée
par `ia_triage.valider` et le quatre-yeux de F18.

**`ModelTrainingFinished` (§8) devient une notification réelle, pas un événement de bus inventé** :
à la création d'un `candidat`, une notification est émise via le **même** port `NotificationSysteme`/
Outbox que P5.4c/P7-D1 (réutilisation, pas une seconde infrastructure) vers quiconque porte
`ia_triage.valider` — *« un candidat attend une revue »*, jamais le détail des métriques (même
minimisation que toutes les notifications de ce projet).

### F20 — L'export généralise deux quasi-identifiants ; les constantes cliniques restent granulaires, et c'est dit

CDC_13 §12 : *« suppression des identifiants directs, généralisation des quasi-identifiants, contrôle
du risque de réidentification (k-anonymat ou équivalent) »*.

- **Identifiants directs** : `triage_id` retiré à l'export (F17) — binaire, vérifiable par grep.
- **Quasi-identifiants généralisés** : l'**âge**, en bandes cliniquement usuelles
  (0-1, 1-4, 5-14, 15-24, 25-44, 45-64, 65+ — config, pas en dur, pas un référentiel gouverné : c'est
  un paramètre de confidentialité, pas une règle clinique) ; et `cree_le`, réduit à `annee_mois`.
- **Ce qui reste granulaire, et pourquoi** : constantes (température, pouls…) et symptômes restent à
  leur précision clinique. Les généraliser détruirait le signal que le modèle doit apprendre, et ce ne
  sont pas, seuls, des quasi-identifiants au sens usuel (contrairement à âge+date, ils ne se recoupent
  pas directement avec une source externe pour ré-identifier quelqu'un).
- **k estimé, jamais un seuil bloquant** : `k_estime` = la taille du plus petit groupe
  (bande d'âge, sexe, année-mois) dans l'export. Il est **calculé et affiché**, jamais utilisé pour
  bloquer l'export — sur un volume de dizaines de lignes, un seuil de k bloquant rendrait l'export
  perpétuellement impossible, ce qui ne protégerait personne, seulement le pipeline. Même raisonnement
  que P6.7a sur les codes LOINC : *« un contrôle qu'on ne peut pas satisfaire n'est pas une exigence,
  c'est un mur »*. Nommé comme limite en §5, pas déguisé en garantie.

### F21 — `POST /api/v1/triage/entrainement` (nouveau), sans authentification renforcée — posture identique à `/score`

Le service reçoit les lignes déjà anonymisées (Laravel a déjà retiré `triage_id`), entraîne, répond
`{numero_version, statut: "candidat", metriques, mlflow_run_id}`. Même posture réseau que `/score`
aujourd'hui — atteignable uniquement en interne (venv local / réseau Docker), sans principal signé.
**Si le propriétaire veut un principal signé ici** (le motif existe déjà, P5.5b-1/P5.6a), c'est un
ajout localisé, à trancher en revue — je ne l'ajoute pas par défaut pour ne pas dupliquer une machinerie
sans qu'un besoin concret l'exige (le service n'est, à ce stade, joignable que depuis Laravel).

### Dépendances — l'accord que ce document constitue

`services/triage-service/requirements.txt` gagne `xgboost`, `shap`, `mlflow`, `scikit-learn`, `pandas`,
`numpy` — exactement la liste de `fraud-detection`. Le Dockerfile gagne `libgomp1` (précédent exact,
déjà résolu). **Ce n'est pas une dérogation à « aucune dépendance sans accord écrit » (§2.6) : c'est
l'application de la stack que CDC_05 §2 impose depuis le début**, différée jusqu'ici parce qu'un
modèle qui n'existe pas ne justifie pas une image de ~600 s à construire (F5, P10c2i). La validation de
ce plan **est** cet accord écrit — même formule qu'ADR-017.

---

## 3. Ce qui ne change pas

- **`TriageService`, `MoteurProtocole`, `TriageController` (chemin de décision)** ne bougent pas.
  Le protocole reste l'unique décideur du niveau.
- **`ClientTriageIa`, `ResultatTriageIa`, `DisjoncteurTriageIa`** ne bougent pas (Y10) — aucune branche
  succès, `/score` répond toujours 503 dans le flux vivant.
- **`PredictionIa`, `predictions_ia`** ne reçoivent aucune nouvelle donnée ici — toujours seulement
  mode/motif/latence. Le durcissement en chaîne (F10 de P10c2i) reste en P10c-3-ii.
- **`triage_id` reste sur `jeux_donnees_entrainement`** — seul l'**export** (une copie séparée) en est
  dépourvu. La table source demeure pseudonymisée par conception (F4, P10c2i), pas anonymisée.
- **`TRIAGE_IA_ENABLED` reste `false` par défaut** — rien dans cet incrément ne dépend de ce
  drapeau, puisque rien n'est branché sur le chemin qu'il gate.
- **Allergies, médicaments en cours** restent hors du vecteur de features (décision propriétaire).

---

## 4. Preuves prévues

**G3 Python** (`triage-service`) — ruff + mypy + pytest, config reprise de `fraud-detection/pyproject.toml`
sauf divergence justifiée. Tests purs : bornes de généralisation d'âge (si portées côté Python en
double garde), la fonction d'entraînement sur un jeu fixture minuscule (produit modèle + métriques +
run MLflow), SHAP multiclasse (mirroir du garde-fou `ndim==3` de `fraud-detection`), refus sous le
seuil minimal (vecteur dédié, indépendant du refus Laravel — F15).

**G3 Laravel** — vecteurs dédiés, écrits dans les deux sens, pour : généralisation d'âge (bornes de
bandes), calcul de `k_estime`, absence totale de `triage_id`/identité dans `instantane_json` (grep sur
la charge entière, précédent F9), toutes les lignes validées incluses y compris les retours révisés
(F13), refus sous le seuil minimal (couche Laravel), habilitation `ia_triage.valider` portée par aucun
rôle (vecteur de mutation), quatre-yeux créateur≠validateur (409 par son motif), `score_antecedents`
correctement lu et transmis. **Campagne de mutation** suivant les six règles de
`harnais-mutation-lecons` : vert vérifié avant de muter, chaque mutation assertée appliquée et sur le
bon site, ancre tenant sur une seule ligne, restauration vérifiée par `diff`.

**G2 live MySQL + triage-service réel** :
- ≥ 30 retours réels semés via le **vrai** chemin HTTP (`POST dossier/triage/{triage}/retour`, boucle
  scriptée), incluant des exemples `sous_triage` explicitement ;
- export → `exports_jeu_entrainement` : `nb_lignes` correct, `k_estime` cohérent, **`triage_id` absent**
  de `instantane_json` (grep), âge vérifié en bande sur un cas connu ;
- entraînement réel → `versions_modeles` en `candidat`, `metriques_modeles` peuplée,
  `mlflow_run_id` consultable (dossier `mlruns/` réellement présent, run inspectable) ;
- promotion par le créateur lui-même → **refusée, 409 nommant le motif** ;
- promotion par un second compte habilité → `valide`, `date_validation_clinique` posée ;
- notification de candidature reçue par le bon destinataire, **sans métrique dans le contenu** ;
- export sous le seuil minimal (base fraîche, peu de lignes) → **refusé bruyamment**, aux deux couches ;
- `/api/v1/triage/score` en flux vivant → **toujours 503**, malgré un modèle `valide` existant (preuve
  empirique que rien n'est branché, pas seulement une lecture de code) ;
- base restaurée compte pour compte.

**Guide** : `GUIDE_TEST_TRIAGE.md` **partie 8** (règle propriétaire : un domaine à incréments
successifs ajoute une partie).

---

## 5. Limites que P10c-3-i annoncera au G5

- **Aucun modèle `actif`, aucune influence sur un triage réel** — c'est le régime nominal de cet
  incrément (P10c-3-ii), pas un oubli.
- **Volume réel probablement sous le seuil minimal au jour du G5** : le pipeline est prouvé
  mécaniquement (G2 live avec des lignes réelles semées via le vrai chemin HTTP), **pas validé
  statistiquement** sur un vrai volume d'usage. Même honnêteté qu'ADR-017 pour `fraud-detection`.
- **k-anonymat estimé sur les seuls quasi-identifiants généralisés** (bande d'âge, sexe, mois-année) —
  pas sur la combinaison complète avec les constantes/symptômes, qui restent à précision clinique.
  Nommé, pas caché (F20).
- **Allergies et médicaments en cours restent hors du vecteur de features** — dette groupée, nommée,
  décision propriétaire de cette session.
- **Drift, canary, équité (§8) non traités** — cohérent avec l'ordre de construction du corpus
  lui-même, qui les place en tout dernier (§12, étape 11/11), après plusieurs domaines IA que ce
  projet n'a pas construits. Dette nommée, mirroir exact de `fraud-detection/DETTE_TECHNIQUE.md`
  entrée 5.
- **`predictions_ia`/`explications_ia` non peuplées, aucun durcissement en chaîne** — P10c-3-ii.
- **`alertes_drift` nommée par le corpus, non créée** — rien ne l'alimenterait avant que le drift soit
  traité.
- **Aucune règle de « meilleur modèle » entre plusieurs candidats** — la comparaison reste manuelle
  (l'écran affiche les métriques ; le choix reste humain), cohérent avec l'exemple du §8
  (« modèle 1 : 89 %, modèle 2 : 92 % ») qui ne prescrit pas non plus de règle automatique.
- **`/api/v1/triage/entrainement` sans principal signé** — posture réseau identique à `/score`
  aujourd'hui ; à durcir sur demande explicite.
- **§5.5.2 (questionnaire personnalisé par IA) reste sans porteur numéroté** — ni ici ni en P10c-3-ii,
  toujours en attente d'arbitrage (déjà noté par P10c2i, non résolu par ce document).
- **Le générateur d'âge en bandes est un paramètre de confidentialité en configuration, pas un
  référentiel gouverné** — le distinguer explicitement d'une donnée clinique (P6.x) est volontaire :
  ce n'est pas une règle médicale.
