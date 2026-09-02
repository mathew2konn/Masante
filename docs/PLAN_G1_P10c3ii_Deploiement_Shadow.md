# PLAN G1 — P10c-3-ii : déploiement en observation, captation des faits manquants, dérive

**Statut : PLAN CLOS — incrément VALIDÉ G5 le 2026-08-30** (G4 propriétaire OK), lots A et B.
Voir ADR-046 et `GUIDE_TEST_TRIAGE.md` partie 9 pour ce qui a réellement été livré, les six défauts
réels trouvés et les limites annoncées.

**Statut d'origine : G1 VALIDÉ (2026-08-29).** Périmètre élargi par le propriétaire le même jour —
la version initiale (shadow + comparaison) était validée « pour le reste » ; s'y sont ajoutées la
**captation de maladie / spécialité / priorité** et la **construction d'`alertes_drift`**.

**Arbitrages rendus en session (2026-08-29) :**

1. **Déploiement** — le modèle est **allumé pour de vrai** : il prédit à chaque triage, sa
   prédiction et son explication sont enregistrées, et **comparées ensuite au verdict du soignant**.
   Il n'apparaît **nulle part** dans le parcours de soin.
2. **Surface de lecture** — une fonctionnalité **« comparaison »** sur la surface **administrateur
   MaSanté**, « ce sont eux qui vérifient la comparaison vu qu'ils surveillent l'évolution de l'IA ».
3. **Tranches d'âge** — **Laravel convertit et envoie la tranche** ; le service ne reçoit plus
   jamais l'âge exact.
4. **Traçabilité** — **sceller `predictions_ia` telle quelle**, plutôt que créer `explications_ia`.
5. **Faits manquants** — **ajouter maladie, spécialité et priorité**, qui manquent pour l'image
   visée (une IA qui contribue à l'orientation).
6. **Dérive** — **construire `alertes_drift`**, au lieu de la nommer sans la bâtir.

Corpus : CDC_05 §1.1/§1.2/§1.3/§1.7 · §5.1/§5.2/§5.3/§5.4/§5.5 · §6.8 (aide au diagnostic) ·
§7.1/§7.2/§7.3 · §8 (registre, statuts, métriques, matrice de confusion, **dérive**, canary/shadow,
rollback) · §9.2/§9.3/§9.6/§9.7 · §10 · §11 · CDC_08 §3 et §9 · CDC_04 §115/§123 · CDC_00 §4 ·
CDC_13 §12.

Précédents : ADR-045 (P10c-3-i) · ADR-042 (chaînes d'audit) · ADR-041 (§10) · ADR-040 (l'orientation
est une agrégation gouvernée) · ADR-037 (référentiel des maladies) · ADR-035 (vocabulaire des
spécialités) · ADR-020 · ADR-017.

> **Numérotation.** Constats préfixés **Z** (présentés Y1→Y7 pendant l'échange de G0, renumérotés
> pour ne pas heurter la série Y de P10c-2-i, encore ouverte). Décisions : suite de la série **F**
> (P10c-2-i F1→F11, P10c-3-i F12→F21).

---

## 1. Ce que le G0 a établi

### Z1 — Le modèle entraîné ne prédit pas une priorité

CDC_05 §5.1 pt.2 décrit un XGBoost qui estime **la priorité**. Celui de P10c-3-i apprend
`adaptee` / `sur_triage` / `sous_triage` : **le jugement qu'un soignant porte sur l'orientation**.

Ce n'est pas un écart au corpus — c'est le seul label réel dont le projet disposait, celui que la
boucle du §5.5.4 produit depuis P10c-2-i. Conséquence : « brancher le modèle sur le flux vivant »
ne peut pas signifier « il influence le niveau », ni techniquement (il ne le prédit pas), ni
réglementairement (Z2). **C'est ce constat qui motive l'élargissement décidé au point 5.**

### Z2 — Le corpus interdit deux fois, indépendamment, que l'IA change la décision

CDC_08 §3 classe le « Raisonnement IA » **6ᵉ et dernier**, « uniquement pour compléter […] jamais
pour contredire un protocole officiel ». CDC_05 §1.3 dit la même chose.

### Z3 — Montrer la prédiction au soignant contaminerait l'étiquette

`ServiceRetourTriage::enregistrer()` appelle `alimenterJeuApprentissage()` **dans la même
transaction** : le verdict du soignant *devient* l'étiquette d'entraînement. Afficher la prédiction
à celui dont le verdict deviendra l'étiquette ferme la boucle — **et le défaut serait invisible dans
les métriques, elles s'amélioreraient.**

### Z4 — Aucun destinataire dans le parcours citoyen

Annoncer à un patient une probabilité de sous-triage est non actionnable, alarmant, et proche de
l'affirmation que §5.5.3 proscrit.

### Z5 — `predictions_ia` va porter du contenu clinique

Sa propre migration l'annonce. Une explication SHAP nomme les valeurs qui l'ont produite.

### Z6 — Le service Python n'a aucun chemin de chargement

`app/modele.py` est un stub (`disponible = False`), `app/service.py` lève `NotImplementedError`.

### Z7 — Deux schémas séparés, un seul vecteur : le décalage peut renaître ici

`LigneEntrainement` porte `bande_age`, `RequeteTriageScore` porte `age`. Rien de **structurel** ne
garantit qu'ils restent alignés — seulement la discipline.

### Z8 — Un décalage train/serve est DÉJÀ dans le code, vérifié

Laravel envoie `score_antecedents` (F14) ; `RequeteTriageScore` ne le déclare pas ; Pydantic écarte
les clés non déclarées :

```
champs reçus : age, constantes, duree_jours, grossesse, intensite,
               niveau_protocole, reference, sexe, symptomes
score_antecedents présent ? False
```

Sans effet tant que tout répond 503 ; le jour où le modèle tourne, la feature vaudrait `NaN` à
l'inférence alors qu'elle existait à l'entraînement.

### Z9 — L'état réel de la base, vérifié et non supposé

| Fait | Constat |
|---|---|
| `maladies` | **0 ligne** — `MaladieSeeder` n'a pas été rejoué depuis la restauration du G2 de P6.8c ; **0 code CIM** de toute façon |
| `specialites_medicales` | 14 lignes (vocabulaire adopté, P6.8a) — utilisable tel quel |
| `protocole_applications` avec `decision_finale` | **37 verdicts réels** déjà donnés |
| `jeux_donnees_entrainement` | 0 ligne (base restaurée après le G2 de P10c-3-i) |

Deux conséquences directes : la captation de la maladie s'appuiera sur un référentiel **vide dans
cet environnement** (mise en vigueur = rejouer un seeder, pas du code) et **sans aucun code CIM** ;
et il existe déjà 37 verdicts, donc la comparaison aura de la matière dès le premier modèle actif.

### Comment chaque constat est refermé

| Constat | Refermé par |
|---|---|
| Z1 | F32 (captation des trois faits manquants) — **outillé, pas comblé** : voir la limite en §6 |
| Z2 | F22 (`observation`, jamais `hybride`) |
| Z3 | F22 + F29 (la prédiction ne sort que sur la surface administrateur) |
| Z4 | F22 (rien sur la fiche patient) |
| Z5 | F28 (chaîne d'audit) |
| Z6 | F23 (chargement réel piloté par le registre) |
| Z7 | F25 (base commune : le décalage devient **inexprimable**) |
| Z8 | F25 (le champ est déclaré une fois) + vecteur dédié |
| Z9 | F34 (lien au référentiel) + étape de mise en vigueur, §7 |

---

## 2. Décisions — le déploiement en observation

### F22 — `mode` reçoit une valeur honnête : `observation`, jamais `hybride`

Appeler `hybride` un mode où les règles décident seules serait affirmer une participation de l'IA
qui n'a pas lieu — ce que Z2 interdit d'affirmer. Valeur neuve **`observation`**. `hybride` **reste
dans l'ENUM et reste inatteignable**, comme `actif`/`archive` l'étaient : le contrat existe avant
l'usage, aucune migration de donnée le jour où il le devient.

### F23 — Le registre de gouvernance est la seule source de « quel modèle répond »

Laravel envoie `modele_attendu` (le `mlflow_run_id` de la version `actif`). Le service charge **ce
modèle-là**. S'il ne l'a pas → **503 `modele_absent_du_service`**, motif distinct, jamais de repli
sur un autre artefact : la base dirait « l'actif est X » pendant que le service servirait Y, et
**deux vérités sur ce qui a produit une prédiction médicale** est ce que ce projet refuse depuis
P6.6a. Le motif distinct rend visible un vrai problème d'exploitation au lieu de le noyer.

### F24 — `valide → actif` : un seul actif par pays, rollback explicite, pas de quatre-yeux de plus

Au plus un `actif` par `pays_code` (sinon « lequel répond ? » est insoluble — résolution identique
à l'ambiguïté du §6.1 tranchée en P10b-1) ; activer archive le précédent ; un `archive` peut
redevenir `actif` (§8, rollback), donc `archive` **n'est pas terminal** et c'est dit. **Aucun
quatre-yeux supplémentaire** : la validation clinique du §9.6 a eu lieu au passage `candidat →
valide`, par quelqu'un ≠ l'entraîneur ; en exiger un troisième serait un garde-fou **plus strict que
sa propre règle**, refusé en P6.8c.

### F25 — Le vecteur est partagé par la STRUCTURE, pas par la discipline

Base Pydantic **`TraitsCliniques`** dont **héritent** `LigneEntrainement` et `RequeteTriageScore`.
Deux conséquences qui ne dépendent plus de personne : **Z8 devient inexprimable** (un champ du
vecteur est déclaré une fois) ; **`niveau_protocole` reste hors de la base**, donc il ne *peut* pas
devenir une feature — D3 de P10c-2-i tenu structurellement au lieu d'être promis par un commentaire.

### F26 — Laravel envoie la tranche, le service ne reçoit plus l'âge exact

Une seule définition des bornes (`config('masante.triage_ia.bandes_age')`, celle de l'export) ; la
table Python ne garde que l'**ordre** des étiquettes, qu'elle refuse déjà bruyamment si une
étiquette lui est inconnue. Gain non cherché : l'âge exact **cesse de sortir du backend** (§9.4).

### F27 — Explication obligatoire, jamais vide (Rule-005)

Classe prédite, trois probabilités, **facteurs SHAP de cette prédiction** (§9.3), **confiance** et
**limites** (§9.7). Seuils de confiance = **données**, côté service **uniquement** (Laravel
enregistre ce qu'il reçoit — une seule définition). **Vecteur obligatoire** : une réponse sans
explication, confiance ou limites fait échouer le build (§11).

### F28 — `predictions_ia` devient une chaîne d'audit

Inscription dans `ChaineAudit::JOURNAUX`, **origine déclarée** (ADR-042), append-only par
déclencheurs dans les deux dialectes, `triage_id`/`modele_version` en **identifiants et non
relations vivantes** (ADR-042 D1). L'empreinte couvre l'explication : hors d'elle, elle serait le
seul élément réécrivable de la ligne — et celui qu'un litige discuterait.

### F29 — L'écran « comparaison », sur la surface administrateur

Ajouté à `/portail/modeles-ia`, gardé par **`ia_triage.valider`** — portée par aucun rôle métier,
donc réservée aux contrôleurs plateforme, **jamais à l'établissement dont les triages sont
examinés** (ADR-017 §7). Contenu tiré du §8 : **matrice de confusion en production**, **rappel sur
`sous_triage` mesuré en production** à côté de celui du jeu de test, concordance, volume, latence.

**On ne départage jamais deux verdicts d'un même triage** : deux verdicts produisent deux couples,
tous deux comptés — cohérence stricte avec F13 (« écarter l'une reviendrait à choisir à la place du
médecin qui l'a validée »).

### F30 — Aucun re-scoring rétroactif, et c'est ce qui garantit l'honnêteté de la mesure

La prédiction n'a lieu **qu'au moment du triage**. C'est **la seule garantie disponible** qu'un
modèle n'est jamais évalué sur des triages de son propre entraînement : l'export ayant **retiré
`triage_id`** (F20), on ne *pourrait pas* le vérifier après coup — l'anonymisation qu'on a voulue
nous prive du contrôle, et la chronologie le fait à notre place. **Ajouter un rattrapage casserait
cette garantie en silence**, et le commentaire du code doit le dire.

### F31 — Le disjoncteur ne change pas de sémantique

`modele_absent_du_service` est un **refus honnête**, pas une panne : il n'ouvre pas le circuit,
comme `modele_indisponible` (raisonnement de F8).

---

## 3. Décisions — les trois faits manquants (maladie, spécialité, priorité)

### F32 — Ils sont CAPTÉS maintenant ; ils ne peuvent pas être PRÉDITS maintenant

Ce que cet incrément livre : **la captation**, au moment où le soignant donne son retour. C'est
exactement le §5.5.4 pt.4 (« enregistrement du triage réalisé, du **diagnostic final posé par le
médecin** et du traitement prescrit — constitution progressive d'une base de données africaine ») et
la 3ᵉ base de connaissances du §7.1.

Ce qu'il ne livre pas, et pourquoi : **un modèle ne peut apprendre que ce qu'on lui a montré.** Il y
a aujourd'hui **zéro** diagnostic enregistré (Z9), et la garde de volume refuse en dessous de 30
lignes — correctement. Entraîner une tête « maladie » sur zéro exemple ne produirait pas un mauvais
modèle : ça ne produirait rien.

**Ce n'est donc pas un refus, c'est un ordre.** La captation est le verrou ; une fois posée, la
prédiction devient *de la donnée plus une tête d'entraînement*, jamais un changement
d'architecture — et le plan le dit pour qu'on puisse le tenir.

**Contrainte qui survivra à l'arrivée du volume** : même entraînée, une tête « maladie » **ne
remontera pas dans la fiche de triage** — CDC_00 §4 (« triage présenté comme diagnostic ») est un
interdit absolu, et l'exemple de réponse du §5.2 ne comporte lui-même aucune maladie. Le diagnostic
capté sert la **base d'apprentissage** et le futur **§6.8** (`recommendation-service`, aide au
diagnostic, étape 9), pas le triage.

### F33 — `niveau_reel` s'ajoute à `decision_finale`, il ne la remplace pas — et un contrôle empêche les deux vérités

`decision_finale` (`adaptee`/`sur_triage`/`sous_triage`) vit dans une **chaîne immuable** validée
G5 : on n'y touche pas. On ajoute **`niveau_reel`** — le niveau que le soignant aurait retenu, dans
le vocabulaire à 4 niveaux du §5.3 (côté patient), déjà porté par l'ENUM de `triages.niveau`.

Les deux se recoupent : `niveau_reel` comparé à `niveau_protocole` **implique** un écart, que
`decision_finale` déclare aussi. **Deux façons de dire le même fait peuvent se contredire** — c'est
la « deux vérités » que ce projet refuse depuis P6.6a.

Parade : un **contrôle de cohérence** refuse le retour quand les deux se contredisent (dire
« adaptée » en donnant un niveau différent de celui du protocole, ou « sous-triage » en donnant un
niveau inférieur), **en nommant la contradiction**. On n'écrase jamais l'une par l'autre : le
soignant corrige, parce que lui seul sait laquelle il pensait.

`niveau_reel` est **facultatif** : le rendre obligatoire changerait le contrat d'un module G5 et
bloquerait un retour déjà valide.

### F34 — Le diagnostic est un LIEN au référentiel, jamais du texte libre

`maladie_id` vers le référentiel gouverné de P6.8c, **jamais** une chaîne saisie. Le motif est celui
d'ADR-037 : un texte libre rendrait insoluble « combien de paludismes parmi les triages sous-évalués
? », et une faute de frappe deviendrait une catégorie. **Le serveur ne devine jamais** une maladie
depuis les symptômes — ce serait le diagnostic posé par une machine que P6.8c a déjà refusé.

Le libellé est **figé** à l'enregistrement (motif P6.6b/P6.7b) : une correction ultérieure du
référentiel ne doit pas réécrire ce qu'un médecin a consigné.

Même forme pour la **spécialité** : `specialite_id` vers `specialites_medicales` (14 termes,
P6.8a). Elle répond à « quelle spécialité a **réellement** pris en charge », à distinguer de
l'orientation *proposée* par P10a — ce sont deux faits, et leur écart est précisément ce qu'on veut
pouvoir mesurer.

### F35 — Le diagnostic entre dans l'export, et il change ce que `k_estime` doit dire

Un label rare est **identifiant** : « femme, 25-44 ans, août 2026 » n'identifie personne ; « femme,
25-44 ans, août 2026, maladie X » peut n'être qu'une personne si X est rare.

Donc la clé de `k_estime` s'étend au diagnostic quand il est présent. **Le chiffre reste calculé et
affiché, jamais bloquant** (motif P6.7a inchangé) — mais il cesserait de dire la vérité si on le
laissait ignorer le label le plus discriminant de la ligne.

### F36 — Trois cibles, un seul vecteur d'entrée

Les nouvelles colonnes sont des **labels**, jamais des features : les mettre en entrée ferait
prédire le diagnostic à partir du diagnostic. `TraitsCliniques` (F25) ne bouge pas, et un vecteur
dédié le vérifie — même garde que `niveau_protocole`.

---

## 4. Décisions — la dérive (`alertes_drift`)

### F37 — La dérive d'entrée est RE-DÉRIVÉE des tables, jamais dupliquée

Pour comparer les distributions d'entrée, il faut les connaître. La tentation est de recopier le
vecteur de features à côté de chaque prédiction — **refusée** : ce serait une seconde copie de
données cliniques, et le §9.2 dit littéralement l'inverse (« les données d'entrée **référencées, non
dupliquées en clair** »).

Donc : la distribution de production est **recalculée depuis `triages` / `triage_constantes` /
`triage_reponses`** sur une fenêtre ; la distribution de référence vient de
`exports_jeu_entrainement.instantane_json` de l'export **sur lequel le modèle actif a été entraîné**
— elle est déjà là, déjà anonymisée, et c'est la seule qui décrive vraiment ce que le modèle a vu.

Indice : **PSI** (Population Stability Index) par feature, seuils **en données** (jamais codés en
dur — un seuil de dérive est un réglage d'exploitation, pas une constante).

### F38 — Deux natures de dérive, dites séparément

- **Dérive d'entrée** : la population change (PSI par feature).
- **Dérive de performance** : le rappel `sous_triage` mesuré en production s'écarte de celui du jeu
  de test.

Les fondre en un seul indicateur masquerait le cas le plus utile : *une population stable et une
performance qui chute* ne se soigne pas comme *une population qui change*. Chaque alerte porte sa
nature.

### F39 — Détection seule, jamais de désactivation automatique

Une alerte de dérive **ne retire jamais** un modèle du service. Retirer automatiquement un modèle
sur un indicateur statistique serait une décision d'exploitation prise par une machine — et le
projet a tenu cette ligne pour la fraude (ADR-017 : « détection seule, jamais de gel »). L'alerte
est journalisée, notifiée au **contrôleur plateforme** (canal Outbox existant, comme
`MODELE_IA_CANDIDAT`), et **un humain décide** — il dispose déjà du rollback de F24.

Job planifié quotidien **plus** endpoint manuel, idempotent par `UNIQUE(version_id, date_rapport,
nature)` — motif exact des rapprochements du paiement (P5.5c) et du routage de fraude (B1).

---

## 5. Séquencement proposé — deux lots

Le périmètre élargi est **large pour un seul G4**. Je propose de le livrer en deux lots, chacun
prouvé et présenté séparément, plutôt qu'en un bloc trop gros pour être éprouvé sérieusement (le
projet a déjà scindé i/ii sur cette raison) :

| Lot | Contenu | Pourquoi dans cet ordre |
|---|---|---|
| **ii-A** | shadow réel (F22→F28, F30, F31) + captation des trois faits (F32→F36) | La captation ne dépend de rien et commence à **accumuler du volume immédiatement** — chaque jour de retard est un jour de données perdues |
| **ii-B** | écran de comparaison (F29) + `alertes_drift` (F37→F39) | Les deux ont besoin de prédictions réelles et de verdicts pour montrer autre chose que des tableaux vides |

Si tu préfères un seul lot, dis-le et je livre d'un bloc — mais le G4 sera plus lourd, et le lot B
s'afficherait surtout vide au moment de le tester.

---

## 6. Limites que P10c-3-ii annoncera au G5

- **Aucune prédiction de maladie, de spécialité ou de priorité dans cet incrément** : le mécanisme
  de captation est posé, **le volume n'existe pas** (0 diagnostic aujourd'hui). Z1 est **outillé,
  pas comblé** — et la distinction est celle que ce projet fait depuis P6.8a.
- **Aucune IA sur la fiche du patient**, et ce n'est pas de la prudence : le modèle n'a rien à y
  écrire. Même avec du volume, la maladie restera hors du triage (CDC_00 §4, §6.8 étape 9).
- **Le référentiel des maladies est vide dans cet environnement et n'a aucun code CIM** : la
  captation renverra vers un référentiel de démonstration de 21 lignes une fois le seeder rejoué.
  Charger la CIM reste **de la donnée, zéro code** — et tant que ce n'est pas fait, ce n'est pas un
  référentiel national.
- **Équité et biais §8 non traités** ; calibration et latence P95/P99 non agrégées.
- **Pas de canary** (fraction de trafic) : le shadow est total, plus simple et plus sûr.
- **Artefacts sur le disque du service**, pas dans MinIO (§10) : `modele_absent_du_service` rend
  visible ce que le multi-instance provoquerait.
- **Le modèle reste entraîné sur un volume faible** : réel dans son mécanisme, pas validé
  statistiquement. La comparaison en production est ce qui dira quand ça change.
- **§5.5.1 (langage naturel) et §5.5.2 (questionnaire personnalisé)** toujours sans porteur numéroté.
- **La chaîne ne témoigne pas du passé** : les lignes `predictions_ia` antérieures ne sont pas
  scellées rétroactivement — leur inventer une empreinte serait un mensonge d'archive.
- **PSI est un indice, pas une preuve** : il dit qu'une distribution a bougé, jamais pourquoi.

---

## 7. Conséquences de déploiement

Deux étapes s'ajoutent, toutes deux **manuelles et nominatives** :

```
export anonymisé → entraînement → validation clinique (quatre-yeux) → ACTIVATION
```

et, préalable à la captation du diagnostic : **rejouer `MaladieSeeder`** (référentiel vide dans cet
environnement, Z9).

Tant qu'aucune version n'est `actif`, `/score` répond 503 et le triage reste rendu complet par les
protocoles seuls — **dégradation gracieuse, jamais refus bruyant** (§1.7, §10), posture déjà
argumentée en P10c-2-i : le résultat du protocole est complet sans l'IA.

---

## 8. Preuves prévues

**G3 — Python.** Vecteur identique entraînement/inférence sur la même ligne ; refus si l'artefact
demandé est absent ; explication/confiance/limites jamais vides (§11) ; `niveau_protocole` et les
trois nouveaux labels structurellement absents du vecteur ; bande inconnue → lève.

**G3 — Laravel.** `observation` écrit avec explication complète ; `hybride` jamais atteignable ;
`valide → actif` archive le précédent ; deux actifs impossibles ; rollback ; chaîne intacte puis
rompue par un `UPDATE` direct ; append-only refusé par le moteur ; `score_antecedents` réellement
transporté (Z8) ; diagnostic refusé en texte libre et **jamais deviné** ; libellés figés ;
**contradiction `niveau_reel` ⇄ `decision_finale` refusée en nommant la contradiction** ; `k_estime`
qui **baisse** quand un diagnostic rare entre dans l'export ; comparaison correcte sur des couples
connus, **y compris un triage à deux verdicts** ; PSI nul sur une population identique et non nul
sur une population décalée ; alerte idempotente ; **aucune désactivation automatique**.

**Campagne de mutation** : une garde neutralisée doit tuer ses vecteurs, ancrage sur une seule
ligne, restauration vérifiée par `diff`, vert constaté **avant** de muter (les six règles du
harnais).

**G2 live réel.** Base MySQL réelle, `triage-service` démarré, modèle entraîné puis activé : un vrai
triage produit une prédiction `observation` avec SHAP réel ; un retour est donné avec diagnostic,
spécialité et niveau réel ; la comparaison le montre à l'écran authentifié ; rollback ; artefact
retiré du disque → `modele_absent_du_service` sans ouverture du disjoncteur ; service coupé →
triage rendu complet ; chaîne vérifiée intacte, rompue, rétablie ; **base restaurée**, journaux
chaînés délibérément conservés.
