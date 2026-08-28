# PLAN G1 — P10c-2-i : la boucle d'apprentissage §5.5.4 + le socle d'intégration IA

**Statut : G1 VALIDÉ (2026-08-28)** — F1→F11 confirmés inchangés par le propriétaire depuis l'arbitrage
du 2026-08-22 ; aucune décision rouverte. **Découpage d'exécution retenu à la validation** : **partie A**
(F1+F2+F3 — le lien triage→consultation et le retour structuré du soignant) livrée et close en premier,
le reste (F4→F10, le microservice) suit sous ce même G1.
Corpus : CDC_05 §1/§2/§3.2/§5.5.4/§7.2/§7.3/§9/§10/§11 · CDC_08 §3/§10/§13 · CDC_03 §10.1 ·
CDC_04 §115/§123 · CDC_00 §4.
Précédents : ADR-017 (le microservice Python passif), ADR-019/020 (le backend orchestre, l'IA reste
passive ; premier appel sortant), ADR-041 (§B2 : le journal d'exécution §10 et ses colonnes vides),
ADR-042 (un identifiant de journal n'est pas une relation vivante), ADR-043 (les constantes).

---

## 0. Ce que cet incrément est, après l'arbitrage du 2026-08-22

Le plan G1 de P10c annonçait P10c-2 comme « le microservice + l'intégration + la traçabilité ».
Trois arbitrages du propriétaire l'ont déplacé, et il faut le dire plutôt que de le glisser :

1. **Audience** : l'IA parlera **au citoyen**, en formulation prudente (§5.5.3) — et au médecin par la
   fiche §5.4. L'affichage est **P10c-2-ii**.
2. **Cible du modèle** : le label viendra des **issues réelles** (§5.5.4), **pas** d'une cible
   synthétique. Le propriétaire a écarté le régime d'ADR-017.
3. **Découpage** : deux incréments, **i** le socle, **ii** l'affichage.

La décision 2 est celle qui commande tout : **aucune issue n'est collectée aujourd'hui**, donc aucun
modèle ne peut être entraîné le jour de la livraison. Ce n'est pas un retard, c'est l'ordre que la
décision impose — et c'est le §7.3 littéral (Phase 1 protocoles ✓ → Phase 3 « modèles ML entraînés
sur données validées et anonymisées »).

**P10c-2-i livre donc ce qui rend l'IA possible, et la plomberie qui l'accueillera** :
la boucle §5.5.4, le jeu d'apprentissage §7.2, le microservice, le client, le disjoncteur, la
dégradation gracieuse et la traçabilité §9.2. **Le service n'a pas de modèle, et il le dit.**

**Ce qui rend cet incrément honnête** : la dégradation gracieuse n'est pas une promesse écrite dans
un commentaire, c'est le **comportement nominal** du jour de la livraison, prouvé par vecteur.
Précédent exact : la décision N3 de P10b-1, où les protocoles thérapeutiques restent en brouillon et
où le refus du moteur de les appliquer devient *un comportement prouvé au lieu d'une promesse*.

---

## 1. G0 — ce qui a été lu, et ce qui a été trouvé

Lecture réelle : `TriageService`, `TriageController`, `FaitsTriage`, `RegistreFaitsProtocole`,
`EcritureSoignantService`, `RegistreSectionsCarnet`, `ServiceFicheTriage`, `ProtocoleSeeder`,
`services/fraud-detection` en entier, `config/services.php`, tous les sites `Http::` de Laravel,
les migrations `triages` / `triage_constantes` / `protocole_applications`, CDC_05 en entier, et les
extraits pertinents de CDC_03/CDC_04/CDC_08. Schémas `triages` et `protocole_applications` lus **en
base MySQL**, pas dans les migrations.

### Y1 — L'audience n'était tranchée nulle part, et le code disait déjà le problème

CDC_05 §1.2 (« la décision finale appartient toujours au professionnel ») et §9.1 (« aucune décision
médicale critique n'est prise automatiquement sans validation d'un professionnel ») supposent un
professionnel. Le triage citoyen n'en a aucun — et `TriageController` l'écrivait déjà :

> *« `professionnel_id`, `decision_finale` et `ecart_justification` restent NULS : le triage citoyen
> n'a pas de soignant dans la boucle. Le §10 les nomme, ils existent, et le fait qu'ils soient vides
> est une limite écrite — pas un oubli. »*

**La boucle demandée par le propriétaire fait entrer ce professionnel.** Ces trois colonnes ne sont
donc pas à créer : elles attendent leur consommateur depuis P10b-2.

### Y2 — Aucune plomberie sortante (A5 confirmé en réel)

Deux sites `Http::` dans tout Laravel : le push Expo et le contrôle de mot de passe (HIBP).
`config/services.php` ne contient que les stubs livrés par Laravel. **Aucun disjoncteur nulle part.**
Le §3.2 (« REST, timeout court + circuit breaker ») est entièrement neuf.

### Y3 — Aucune traçabilité IA (A7 confirmé en base)

`triages` porte 21 colonnes, dont les estampilles `referentiel_version`, `protocole_code`,
`protocole_version` — et **pas** `modele_version`, que CDC_04 §115 exige. Aucune des sept tables de
§123 (`predictions_ia`, `versions_modeles`, `jeux_donnees_entrainement`, `validations_medecins`…)
n'existe.

### Y4 — Le vecteur non tautologique existe désormais, et il est mesurable — mais son périmètre est republiable sans code

`ProtocoleSeeder` ne référence qu'**une seule** constante : `constante.temperature >= 39.5`. Les six
autres collectées par P10c-1 (pouls, saturation, tension ×2, glycémie, poids) **n'entrent dans aucune
règle**, donc dans aucun score. C'est exactement l'information dont le protocole ne dispose pas :
P10c-1 a bien désamorcé le constat A3.

**Ce que le plan n'avait pas vu** : ce périmètre est une propriété d'une **version de protocole**,
republiable sans une ligne de code. Le jour où un protocole utilise `constante.saturation_o2`, cette
feature devient partiellement tautologique **sans que rien ne le signale**. C'est le constat A4
(décalage train/serve) sous un angle nouveau, et il devra être traité au moment de l'entraînement
(P10c-3), pas ici — mais il est écrit maintenant pour ne pas être découvert trop tard.

### Y5 — `triage-service` ne peut pas être une copie de `fraud-detection`

Le service de fraude porte un moteur de règles Python qui **fait autorité** (`domain/regles.py`) et
une `domain/fusion.py` qui combine règles et ML. L'engagement §6 du plan P10c interdit les deux ici :
les règles de triage vivent dans les protocoles, sous quatre validations, et les redoubler en Python
ferait **deux vérités sur le niveau d'urgence d'un citoyen**.

Conséquence concrète, et elle n'est pas cosmétique : **la fraude dégrade en « règles seules » quand
le modèle manque ; le `triage-service` n'a aucun repli.** Sans modèle il n'a rien à dire. Sa
dégradation est donc le **refus honnête**, et c'est Laravel qui absorbe (A6).

### Y6 — Le chaînon §5.5.4 existe à moitié, et la moitié qui manque est celle qui compte

Quatre tables du carnet portent déjà `triage_id` : `ordonnances`, `rendez_vous`,
`documents_medicaux`, `notes_observations`. Mais :

- il est **déclaré par le client** (`nullable|exists:triages,id`) sur les chemins citoyens ;
- il n'est **jamais posé sur le chemin du soignant** — aucune occurrence dans
  `EcritureSoignantService` ni dans `app/Http/Controllers/Portail/`.

Autrement dit : **quand le médecin écrit l'ordonnance qui EST l'issue du triage, rien ne la relie à
ce triage.** La structure est là, l'alimentation manque précisément là où elle a un sens.

### Y7 — Ce projet ne peut pas observer l'aggravation, et il faut le dire avant de choisir un label

Le §5.5.4 veut « le diagnostic final posé par le médecin et le traitement prescrit ». Ce projet peut
observer :

| §5.5.4 attend | ce qui existe ici |
|---|---|
| traitement prescrit | ✅ `ordonnances`, avec le lien au référentiel médicaments (P6.6b) |
| diagnostic final | ~ `antecedents.maladie_id` (P6.8c) — le plus proche, mais c'est un antécédent, pas un diagnostic d'épisode |
| résultats d'examens | ✅ `resultats_analyses`, liés au catalogue (P6.7a) |
| hospitalisation, aggravation, issue à 48 h | ❌ **rien**, et aucune table ne s'en approche |

Il n'existe **aucune entité « consultation »** ni « diagnostic d'épisode » dans ce projet. Un label de
type « risque d'aggravation » serait donc **inobservable même une fois la boucle en place** — c'est
le point que le choix de la cible doit intégrer (voir F3).

### Y8 — Le journal §10 est append-only, ce qui interdit de « compléter » une ligne

`protocole_applications` porte `empreinte`/`empreinte_precedente` et des déclencheurs qui refusent
`UPDATE` et `DELETE` (prouvé au G2 de P10b-2). On **ne peut donc pas** remplir `decision_finale` sur
la ligne écrite au moment du triage — et c'est voulu : P10b-2 l'a écrit, *« une décision prise plus
tard produit une nouvelle entrée, jamais une réécriture »*.

Le retour du médecin sera donc **une entrée neuve**, ce que la conception du journal prévoyait déjà.

---

## 2. Décisions

### F1 — Le lien triage → acte est DÉCLARÉ par le soignant, jamais deviné

Tentation immédiate : rattacher automatiquement l'écriture au triage le plus récent du membre. **Non.**

Le précédent est constant dans ce projet : P6.8c refuse de rapprocher une maladie d'un texte libre
*même quand il est identique au libellé officiel* (« rapprocher serait un diagnostic posé par une
machine ») ; P6.7b a dû **retirer** une réécriture qui inscrivait le nom du mauvais médecin. Un
patient peut avoir fait trois triages en une semaine et consulter pour le premier ; affirmer le lien
par proximité temporelle produirait une **base d'apprentissage fausse**, c'est-à-dire le pire des
résultats possibles — un modèle qui apprend d'un lien inventé.

Donc : le soignant **désigne** le triage auquel son acte répond, parmi ceux du membre dont le dossier
est ouvert. Le serveur **vérifie** que ce triage appartient bien à ce membre (anti-IDOR par
construction, comme tout le chemin soignant : aucun `membre_id` dans l'URL, on écrit dans le dossier
que porte la session — règle héritée du Module 4).

**Le lien reste facultatif** (comme P6.6b, P6.7b, P6.8c) : un médecin qui ne relie rien n'est pas
bloqué, et son acte de soin est enregistré comme aujourd'hui.

### F2 — Le retour du médecin est une NOUVELLE entrée du journal §10, pas une table de plus

Y8 l'impose et Y1 le rend possible. Le retour remplit `professionnel_id`, `decision_finale` et
`ecart_justification` — **les colonnes que le §10 nomme et que P10b-2 a laissées vides en le disant**.

On ne crée pas une table pour ce que le corpus a déjà placé. Et la chaîne d'audit s'applique : le
retour d'un médecin sur une orientation est une pièce médico-légale au même titre que la décision
elle-même.

### F3 — Le label est l'appréciation du soignant sur l'ORIENTATION, pas une issue clinique inobservable

C'est la décision la plus importante, et elle découle de Y7.

Le propriétaire veut « une meilleure orientation ». Or l'orientation est précisément ce qu'un médecin
peut juger **au moment où il voit le patient**, sans suivi à 48 h et sans dossier hospitalier :
*ce patient relevait-il bien du niveau que le triage a rendu ?*

Trois valeurs, et elles ne sont pas symétriques :

- **adaptée** — l'orientation correspondait à l'état réel ;
- **sur-triage** — le triage a envoyé trop haut (coût : encombrement des urgences) ;
- **sous-triage** — le triage a envoyé trop bas (**c'est le seul dangereux**, et c'est celui qu'un
  futur modèle devra apprendre à réduire).

Ce label est direct, produit par un professionnel, **non dérivable des bandes du protocole** (donc il
échappe à la tautologie A3), et il correspond exactement à l'objectif énoncé. Le diagnostic et le
traitement sont **enregistrés à côté** (par F1) comme contexte, mais ils ne sont pas le label : ils
serviront plus tard, quand un référentiel de diagnostics d'épisode existera.

**Ce que cela n'est pas** : une évaluation du médecin, ni une note de performance. C'est une
appréciation portée sur une **décision de machine**, ce que le §9.1 appelle la supervision humaine.

### F4 — Le jeu d'apprentissage est PSEUDONYMISÉ, et on l'appelle par son nom

§7.2 impose : `Anonymisation → Validation par les médecins → Jeu d'entraînement`. L'ordre est celui
du corpus, et il est bien pensé : le validateur juge un cas clinique **sans voir de qui il s'agit**.

Table `jeux_donnees_entrainement` (nom du §123, adopté et non réinventé — principe P6.8a). Une ligne
porte : les features cliniques (âge, sexe, symptômes par code, constantes, réponses), le label F3, le
contexte d'issue, et **aucune identité** : ni `patient_nom`, ni `membre_id`, ni `user_id`, ni NIS, ni
date de naissance.

**Point d'honnêteté, écrit avant de coder** : la ligne conserve `triage_id` pour l'idempotence et la
traçabilité §9.2. Tant qu'elle le porte, c'est une **pseudonymisation, pas une anonymisation** — quiconque
a la base peut remonter au patient. Prétendre le contraire serait le genre d'affirmation que ce
projet refuse ailleurs (le « validé cliniquement » d'ADR-017, la source `demonstration` de P6.7a).
L'anonymisation devient effective à **l'export vers l'entraînement**, qui retire ce lien — et l'export
est en P10c-3, avec le modèle. On livre donc la **pseudonymisation**, on la nomme, et le §7.2 n'est
tenu qu'à moitié tant que l'export n'existe pas.

`validations_medecins` (nom du §123) porte la validation : **une ligne non validée n'entre jamais
dans un export**. Ce n'est pas un socle à vide (refusé par P6.3-D3) puisque son consommateur est le
contrôle qui filtre l'export, prouvable par vecteur dès maintenant.

### F5 — Le microservice n'embarque NI XGBoost, NI SHAP, NI MLflow

La stack du §2 les impose *pour un modèle*. Il n'y en a pas. Installer XGBoost, SHAP et MLflow dans
un service qui n'a aucun artefact à charger serait de la **mise en scène** — et une infraction à
§2.6 (« aucune dépendance sans accord écrit »), pour des paquets lourds (l'image ML de la fraude pèse
~600 s d'export, piège déjà documenté).

`triage-service` livre donc : **FastAPI + Pydantic + Uvicorn**, `/health`, `/ready`, et le contrat
`POST /api/v1/triage/score`. L'interface du modèle existe (`disponible = False`), la librairie non.
Les dépendances ML arriveront **avec le modèle**, en P10c-3, sous accord écrit.

**Ce que cela prouve quand même** : le contrat d'API, la validation Pydantic, la dégradation, le
disjoncteur, la minimisation, et l'aller-retour Laravel ↔ FastAPI (§3.2) — c'est-à-dire tout ce qui
casserait en production, et rien de ce qui relève du modèle.

### F6 — Le service REFUSE, Laravel dégrade et le dit — à un seul endroit

Sans modèle, `/api/v1/triage/score` répond **503** avec un motif explicite (`modele_indisponible`).
Jamais de score inventé — engagement §6 du plan P10c, régime ADR-019.

Laravel absorbe (A6 : le corpus impose ici la dégradation gracieuse, pas le refus bruyant) : le
triage est rendu **normalement, complet**, avec la mention du §10. Cette mention vit **à un seul
endroit** — précédent `MENTION_PROVENANCE` de P6.8d : *une phrase recopiée trois fois finit par
diverger deux fois*.

Le motif du refus bruyant n'est pas abandonné, il est **déplacé** (précédent P6.8e) : le service est
honnête envers l'exploitant, Laravel est disponible envers le patient.

### F7 — L'appel est gaté OFF, hors transaction, après la décision du protocole

- **Après** la décision : le protocole prime (CDC_08 §3, priorité 6 sur 6). Le §9 étape 1 vise l'IA
  qui *structure l'entrée* (NLP, hors périmètre), pas celle qui décide (A10).
- **Hors transaction** : précédent double et net — ADR-020 (« scoring hors tx paiement ») et P7-D1
  (*« un tiers n'a jamais le droit de mettre en péril l'écriture d'un dossier médical »*). Un
  `exp.host` lent tenant des verrous MySQL avait déjà été refusé.
- **Gaté OFF** (`TRIAGE_IA_ENABLED=false` par défaut) : appeler à chaque triage un service qui
  répondra toujours « pas de modèle » ajouterait une latence pour rien. Régime « prêt à activer » du
  projet (cashback P5.3b-3, push P7-D1). Le G2 prouve les **trois** états : OFF (aucun appel), ON +
  service debout (503 → dégradation), ON + service à terre (timeout → disjoncteur → dégradation).

### F8 — Le disjoncteur est un état PARTAGÉ, pas une variable de processus

Un compteur d'échecs en mémoire PHP ne survit pas à la requête : chaque requête rouvrirait le
circuit et retomberait dans le timeout. Le disjoncteur vit donc dans le **cache** (store `database`
ici — F5 de P6.3), avec seuil d'échecs et durée d'ouverture **en configuration, jamais en dur**.

Trois états classiques (fermé / ouvert / demi-ouvert). Circuit ouvert → **aucun appel réseau**, la
dégradation est immédiate.

### F9 — La minimisation §9.4 est un vecteur, pas une intention

Ce qui sort de Laravel : une **référence** (`triage:1234`), l'âge, le sexe, les codes de symptômes,
les constantes, les réponses. **Jamais** `patient_nom`, `membre_id`, `user_id`, le NIS, ni un
identifiant de compte.

Vecteur dédié qui **cherche ces champs dans la charge utile sortante et casse le build** — précédent
exact : le test de P7-D1 qui cherche la donnée clinique dans toute la charge d'une notification.

### F10 — La traçabilité §9.2 : `predictions_ia` + `triages.modele_version`

Noms du §123 et du §115, adoptés. `predictions_ia` porte `triage_id`, `modele_version`, le mode
(`hybride` / `degrade`), le motif de dégradation, la latence, et — quand un modèle existera — la
probabilité, les facteurs SHAP, l'explication, la confiance et les limites.

**Point d'honnêteté déjà écrit dans le plan P10c (D5), et maintenu** : cette table **contiendra du
contenu clinique** le jour où l'explication existera, parce qu'une explication SHAP nomme les valeurs
qui l'ont produite. Elle sera dans le régime de `protocole_applications` — la seule chaîne du projet
qui porte du clinique, *parce que le §10 l'exige*. On ne peut pas à la fois exiger l'explicabilité et
prétendre ne rien stocker.

`triages.modele_version` est **nullable et jamais rétroactive** : les triages antérieurs n'ont été vus
par aucun modèle, leur en attribuer un serait un mensonge d'archive (précédent L2, et
`protocole_code` en P10b-1).

### F11 — L'écran du portail soignant est DANS l'incrément

Sans écran, aucun médecin ne peut déclarer quoi que ce soit : le lien F1 et le retour F2 resteraient
des colonnes vides, c'est-à-dire le socle à vide refusé par P6.3-D3. Précédent explicite : la
décision W7 de P6.8b (« l'écran mobile est DANS l'incrément »).

Il sera en **Blade**, dans le portail existant, **sans investissement de design** — décision K1 de
P6.4d, et la migration du portail vers Next reste le module identifié qu'ADR-011 appelle.

---

## 3. Ce qui ne change pas

- **`TriageService` ne bouge pas.** Il assemble et remet au protocole ; l'IA est branchée **après**,
  dans le contrôleur, hors transaction. Si ce service devait changer, c'est que l'IA serait entrée
  dans la décision — le test de la conception, comme en P10b-3-i.
- **`MoteurProtocole` ne bouge pas.** Aucun fait `ia.*`, aucune règle ne consulte l'IA : ce serait
  faire dépendre une décision signée par quatre validateurs d'un service tiers.
- **Aucun écran citoyen n'est touché** (c'est P10c-2-ii).
- **`mesures_sante` reste hors du chemin** (E4 d'ADR-043).
- Les chemins d'écriture citoyens gardent leur contrat : `triage_id` reste facultatif là où il
  existait déjà.

---

## 4. Preuves prévues

**G3** — vecteurs dédiés écrits dans les deux sens ; **campagne de mutation** suivant les six règles
de `harnais-mutation-lecons` : vert vérifié **avant** de muter, chaque mutation **assertée appliquée
et sur le bon site**, ancre tenant sur **une seule ligne** et jamais préfixe du remplacement,
restauration vérifiée par `diff` contre la copie pré-mutation. Côté Python : `ruff`, `mypy`, `pytest`.

**G2 live MySQL** — au minimum :

- lien déclaré par le soignant → posé ; **triage d'un autre membre → refusé** ;
- **aucun rattachement automatique** : deux triages récents, aucun lien sans déclaration ;
- retour du médecin → **nouvelle entrée** du journal §10 portant `professionnel_id` et
  `decision_finale` ; l'entrée initiale **inchangée** (append-only prouvé) ; chaîne intacte ;
- ligne du jeu d'apprentissage **sans aucune identité** (vérifié colonne par colonne en SQL) ;
- ligne non validée → **absente** de l'export ;
- `TRIAGE_IA_ENABLED=false` → **aucun appel réseau** (prouvé par l'absence de trace) ;
- ON + service debout → **503** du service, triage **rendu complet** avec la mention de dégradation ;
- ON + service à terre → timeout puis **disjoncteur ouvert**, et les appels suivants **ne partent
  pas** (latence effondrée, prouvée) ;
- charge utile sortante **capturée** : ni nom, ni `membre_id`, ni NIS ;
- `triages.modele_version` **NULL** partout tant qu'aucun modèle n'existe ;
- **base restaurée compte pour compte**.

**Guide** : `GUIDE_TEST_TRIAGE.md` **partie 7** (règle propriétaire : un domaine à incréments
successifs ajoute une partie).

---

## 5. Limites que P10c-2-i annoncera au G5

- **Aucun modèle, donc aucune prédiction.** C'est le régime nominal de cet incrément, pas une panne.
- **Aucune anonymisation complète** : la pseudonymisation est livrée et **nommée** ; l'export
  anonymisant est en P10c-3. Le §7.2 n'est tenu qu'à moitié.
- **Aucune donnée d'apprentissage réelle le jour du G5** : la base se constituera par l'usage, sur
  des mois. C'est le §5.5.4 (« constitution progressive ») et cela ne peut pas être accéléré.
- **Le label est déclaratif** : il vaut ce que vaut l'appréciation du médecin qui le pose, et il n'est
  confronté à aucune issue clinique — ce projet n'observe ni hospitalisation ni aggravation (Y7).
- **Aucun `ai-gateway`** (§4, §12 étape 1) : appel direct, comme la fraude.
- **Aucun MLflow, aucune métrique, aucune dérive, aucune équité** (§8) : rien à suivre sans modèle.
- **Aucun NLP** (§5.5.1) et **aucun questionnaire personnalisé** (§5.5.2, différé à l'arbitrage) —
  la fonction « poser les bonnes questions » est **déjà rendue par le protocole** depuis P10b-3-i,
  sans IA.
- **Le décalage train/serve reste ouvert** (Y4) : le périmètre non tautologique dépend d'une version
  de protocole republiable sans code. À traiter en P10c-3, avant tout entraînement.
- **Niveaux hospitaliers toujours dormants** (A11), **aucune entité consultation ou diagnostic
  d'épisode** (Y7), **pas d'allergies structurées**.
- **La vision à 10 ans du propriétaire** (assistance au geste chirurgical, images et vidéos) relève
  du **CDC_07** (IA générative) et du §6.2 (vision) : elle n'est pas dans ce cahier des charges et
  n'est promise par aucun incrément de P10c.
