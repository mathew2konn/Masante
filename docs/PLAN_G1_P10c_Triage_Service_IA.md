# PLAN G1 — P10c : `triage-service` (IA, CDC_05 §5)

**Statut : G0 fait, G1 EN ATTENTE D'ARBITRAGE DU PROPRIÉTAIRE.**
Corpus : CDC_05 §1/§2/§5/§7/§9/§10/§12 · CDC_08 §3/§9/§13 · CDC_03 §10.1 · CDC_04 §115/§123 · CDC_00 §4.
Précédents : ADR-017 (fraud-detection, le microservice Python), ADR-019/020 (extraction + routage,
le backend orchestre / l'IA reste passive), ADR-040 (orientation), ADR-041 (protocoles, N1 : le
moteur vit dans Laravel).

---

## 0. Ce que P10c doit être, et ce qu'il ne peut pas être

P10a a sorti l'**orientation** du code. P10b a sorti le **niveau**, le **questionnaire** et la
**borne des antécédents** du code, et les a mis sous quatre validations cliniques. Le triage
fonctionne aujourd'hui **entièrement sans IA** — c'est la Phase 1 de CDC_05 §7.3, et elle est
achevée.

P10c est l'étape **7** de l'ordre CDC_08 §13 (« intégration avec l'IA ») et l'étape **3** de l'ordre
CDC_05 §12. Le corpus est explicite sur la place de l'IA : **priorité 6 sur 6** (CDC_08 §3), elle
« complète, elle ne remplace pas » (CDC_05 §1.3), elle « n'invente jamais un traitement » (CDC_08
§1.3), et « le triage n'est jamais un diagnostic » (CDC_05 §1.4).

---

## 1. G0 — ce qui a été lu, et ce qui a été trouvé

Lecture réelle : `TriageService`, `TriageController`, `AnalyserTriageRequest`, `RegistreFaitsProtocole`,
`NiveauTriage`, `ReferentielMesure` + son seeder, les migrations `triages`, `services/fraud-detection`
en entier, `config/services.php`, l'ensemble des appels `Http::` de Laravel, CDC_05, CDC_08, et les
extraits pertinents de CDC_03/CDC_04.

### A1 — L'IA n'a presque rien à manger, et le code le dit déjà lui-même

CDC_05 §5.1.2 énumère le vecteur XGBoost : *âge, sexe, poids, température, tension artérielle,
fréquence cardiaque, saturation en oxygène, douleur, symptômes, antécédents, allergies, grossesse,
médicaments en cours*. §5.2 en donne le contrat d'API littéral.

Ce que le triage collecte réellement (`AnalyserTriageRequest`) :

| §5.2 attend | présent aujourd'hui |
|---|---|
| `symptomes` | ✅ (identifiants techniques, pas des codes) |
| `age`, `sexe` | ✅ (facultatifs) |
| `antecedents` | ✅ (membre rattaché seulement, sous forme d'un **impact déclaré**) |
| `temperature`, `frequence_cardiaque`, `tension`, `spo2` | ❌ **aucune** |
| `douleur` | ~ (une question `intensite` du protocole, pas un champ) |
| `duree_symptomes_heures`, `evolution` | ❌ |
| `grossesse` | ❌ sur ce chemin (existe au carnet) |
| `allergies` | ❌ **aucune table dans le projet** (vérifié) |
| `medicaments_en_cours` | ❌ sur ce chemin |

**Et le projet avait déjà nommé ce manque, avec sa condition de levée**, dans
`RegistreFaitsProtocole` :

> *« Déclarer `temperature` ou `spo2` — que CDC_05 §5.2 cite — alors qu'aucun écran ne les collecte
> permettrait d'écrire une règle qui ne se déclencherait jamais. Ce serait publier une garantie
> inerte. **Ces faits entreront quand leur collecte existera.** »*

P10c est le module où cette condition devient d'actualité.

### A2 — Mais les constantes vitales existent ailleurs, et elles sont déjà gouvernées

`referentiels_mesure` porte exactement `temperature`, `pouls`, `saturation_o2`,
`tension_systolique`, `tension_diastolique`, `glycemie`, `poids` — et depuis **L1+L2** c'est un
référentiel **publié sous quatre yeux**, dont `statutPour()` classe une valeur en
`critique | bas | eleve | normal`.

Autrement dit : le vocabulaire clinique des constantes du §5.2 est **déjà défini, déjà versionné et
déjà relu** dans ce projet. Il n'est simplement pas sur le chemin du triage — et il n'existe que
pour un **membre rattaché** (un triage anonyme n'a pas de carnet).

### A3 — Un modèle entraîné sur les features actuelles apprendrait à imiter le protocole

C'est le constat central, et il commande tout le reste.

Le niveau rendu par le triage est, depuis P10b-1, une **fonction déterministe** du score : bandes
publiées de `TRIAGE-NIVEAU` + planchers `DEFINIR_SCORE_MINIMUM`. Or les seules features disponibles
aujourd'hui sont précisément les termes de ce score (`score_symptomes`, `score_reponses`,
`score_antecedents`, `drapeau_rouge`) plus `age` et `sexe`.

Un XGBoost entraîné là-dessus atteindrait une exactitude quasi parfaite **et n'aurait rien appris
d'autre que la règle posée à côté de lui**. Il aurait l'apparence d'une IA et la valeur d'une
tautologie.

C'est le piège qu'ADR-017 a dû corriger pour la fraude (AUC ~1.0 sur des classes synthétiques
séparables). **Ici il est structurellement pire** : là-bas le label était mal construit ; ici le
label **est** la règle.

Pour apporter une information, un modèle a besoin soit **(a)** de features que le protocole n'utilise
pas (constantes vitales, durée, évolution), soit **(b)** d'un label que le protocole ne produit pas
(l'issue réelle : hospitalisation, diagnostic final, aggravation). **Les deux axes sont vides
aujourd'hui** : aucune consultation n'est reliée à un triage, et §5.5.4 décrit précisément cette
base d'apprentissage comme *« à constituer progressivement »*.

### A4 — Le décalage train/serve est ouvert par construction

Les clés de questionnaire (`reponse.intensite`, `reponse.duree_jours`…) vivent dans une **version de
protocole republiable sans une ligne de code** — c'est tout l'acquis de P10b-3-i.

Un modèle dont les features sont ces clés se dégrade **en silence** le jour où une version en retire
une. C'est la famille de panne muette que P10a (« orienter vers un terme désactivé ne fait aucun
bruit ») et P10b-1 (« un fait inconnu **lève**, il ne vaut pas faux ») ont passé deux incréments à
refermer. On ne la rouvre pas par la porte de derrière.

### A5 — Laravel n'a aucun appel sortant vers un service IA, ni l'infrastructure pour en faire

Deux sites `Http::` en tout : le push Expo et le contrôle de mot de passe. Aucune entrée dans
`config/services.php`. **Aucun disjoncteur nulle part.**

Or CDC_05 §3.2 et CDC_03 §10.1 imposent la même phrase : *« Laravel → REST (timeout court +
circuit breaker) → FastAPI »*. C'est de la plomberie neuve.

Le précédent existe côté paiement (ADR-020, `ClientFraudeDetection`) avec son piège documenté :
le client JDK négociait **HTTP/2 h2c**, uvicorn perdait le corps du POST → 422 « body required ».
Guzzle parle HTTP/1.1 par défaut, donc *ce* piège-là ne s'applique pas — mais la forme est
identique et mérite d'être vérifiée au G2 plutôt que supposée.

### A6 — Ici le corpus impose la DÉGRADATION GRACIEUSE, pas le refus bruyant

Trois fois, mot pour mot : CDC_05 §1.7, CDC_05 §10, CDC_03 §10.1 —
*« si un service IA est indisponible, le backend renvoie le résultat des protocoles seuls avec
mention explicite que l'assistance IA est momentanément indisponible »*.

C'est **l'inverse** du motif adopté huit fois dans ce projet (503 tant que rien n'est publié). Il
faut le dire plutôt que de l'appliquer par habitude : le refus bruyant protège une garantie **dont
le consommateur dépend** ; ici le résultat du protocole est **complet sans l'IA**, et refuser
priverait un patient de son triage pour une raison qui ne le concerne pas.

Le motif n'est donc pas abandonné, il est **déplacé** — exactement comme en P6.8e : le service IA,
lui, refusera honnêtement (jamais de score inventé), et c'est Laravel qui absorbe et **le dit**.

### A7 — `triages` doit porter la version du modèle IA, et ne la porte pas

CDC_04 §115 énumère pour `triages` : *« …version du protocole, **version du modèle IA** »*.
Présent : `referentiel_version`, `protocole_code`, `protocole_version`. **Absent** : la version du
modèle.

CDC_04 §123 nomme par ailleurs les tables IA — `predictions_ia`, `explications_ia`,
`versions_modeles`, `jeux_donnees_entrainement`, `validations_medecins`, `metriques_modeles`,
`alertes_drift`. **Aucune n'existe.**

### A8 — Qui persiste ? Le précédent du projet est net

ADR-020/B1 : le **paiement orchestre et persiste** (`ia_fraude_alertes`), le service de fraude reste
**passif et sans état**. Même forme ici : **Laravel persiste**, le `triage-service` reste sans état
(ni Postgres ni Redis — conséquence explicite d'ADR-017).

Cela sert aussi §9.2, qui exige que les données d'entrée soient *« référencées, non dupliquées en
clair »* : on référence `triage_id`, on ne recopie pas le contenu clinique.

### A9 — L'ordre du corpus est enjambé, et il faut l'écrire

CDC_08 §13 : **5** = charger les protocoles ivoiriens prioritaires · **6** = interface d'authoring +
workflow de validation · **7** = intégration avec l'IA.

P10c saute à 7 alors que 5 et 6 sont délibérément inachevés : **5** parce que la décision N3 refuse
de fabriquer des validations cliniques (« le §7 dit *opposable* : c'est la pièce qu'on produirait
devant un tribunal »), **6** parce que Q2 a choisi *lire et signer* plutôt qu'un éditeur de règles
en Blade dans un portail qu'ADR-011 condamne.

Les deux refus tiennent. La conséquence, elle, doit être dite : **P10c tournera sur un corpus de
démonstration**, comme la fraude.

### A10 — §9 met l'IA en étape 1, §3 la met en priorité 6 : ce n'est pas une contradiction

CDC_08 §9 étape 1 : « l'IA analyse les données du patient ». CDC_08 §3 rang 6 : « l'IA n'intervient
qu'en dernier recours ».

Deux choses différentes : l'IA qui **structure l'entrée** (le langage naturel du §5.5.1) est en
amont ; l'IA qui **décide** est en aval et en dernier. Cela détermine où l'appel se place dans
`TriageService::analyser()` — voir D6.

### A11 — Les niveaux hospitaliers dorment toujours

`TriageNiveauHospitalier` (les 5 Manchester/ESI) et leurs couleurs existent dans `@masante/shared`
depuis P0 et **ne sont consommés par personne** (constat W3 de P10b-1, N6 les avait différés faute
d'écran soignant). CDC_05 §5.3 les rattache au triage. Ils restent hors périmètre tant qu'aucun
écran professionnel n'existe — sinon c'est le « socle à vide » refusé par P6.3-D3.

---

## 2. La question qu'il faut trancher avant tout le reste

**Avec ce que le triage collecte aujourd'hui, aucun modèle ne peut ajouter d'information clinique.**
Ni par les entrées (A1), ni par les issues (A3). C'est vérifié, pas supposé.

Deux chemins seulement :

1. **Élargir l'entrée** au contrat §5.2 — et alors le modèle a quelque chose à traiter, **et le
   moteur de protocoles aussi** (une règle `SI température > 39 ET âge < 5` devient exprimable
   **en donnée relue et signée**, c'est-à-dire le contre-exemple du §1.2 retourné à l'endroit).
2. **Ne pas élargir** — et alors l'incrément livre la **mécanique** (service, hybride, SHAP,
   traçabilité, dégradation) sur un modèle de démonstration, en disant qu'il n'apporte pas
   d'information clinique. C'est honnête, c'est le régime d'ADR-017, mais c'est moins que ce que le
   §5 décrit.

**Ma recommandation : élargir d'abord, l'IA ensuite** — parce qu'un modèle entraîné sur le vecteur
étroit devrait de toute façon être réentraîné, et parce que l'élargissement profite au protocole
même si l'IA n'arrivait jamais.

---

## 3. Découpage proposé

| Incrément | Contenu | Pourquoi cet ordre |
|---|---|---|
| **P10c-1** | **Collecte clinique du §5.2** : constantes vitales, durée, évolution, grossesse — entrant comme **faits de protocole gouvernés**, lues au carnet pour un triage rattaché, saisies sur l'écran sinon. **Zéro IA.** | Lève la condition écrite dans `RegistreFaitsProtocole`. Rend le moteur P10b capable d'exprimer de vraies règles cliniques. Donne au modèle un vecteur non tautologique. |
| **P10c-2** | **Le microservice `triage-service`** : FastAPI/Pydantic/XGBoost/SHAP/MLflow, hybride, sans état. **Le socle d'intégration Laravel** : client, timeout, disjoncteur, dégradation gracieuse. **La traçabilité** : `predictions_ia` + `triages.modele_version`. | C'est le cœur de CDC_05 §5. Il n'a de sens qu'après P10c-1. |
| **P10c-3** *(optionnel)* | **Questionnaire personnalisé (Phase 2, §5.5.2)** : l'IA **ordonne** les questions que le protocole a débloquées. Elle n'en ajoute ni n'en retire aucune. | Le seul rôle du §5.5 qui n'exige pas de prédire une issue clinique. Peut être différé sans dette morale. |

Hors périmètre déclaré : `ai-gateway` (§4, §12 étape 1), NLP §5.5.1, les 5 niveaux hospitaliers,
`risk-prediction`/`clinical-scoring`/`early-warning` (§4).

---

## 4. Points de décision

### D1 — Élargit-on le contrat d'entrée aux constantes vitales du §5.2 ?

| Option | Ce que ça donne | Coût |
|---|---|---|
| **(a) Oui, saisie à l'écran + lecture du carnet** *(recommandé)* | Le protocole gagne de vrais faits cliniques ; le modèle gagne un vecteur non tautologique ; le §5.2 devient représentable | Un incrément de plus, un écran de triage plus long (champs **tous facultatifs**) |
| (b) Oui, carnet seulement | Aucun écran à toucher | Ne marche que pour un triage **rattaché** ; un triage anonyme reste aveugle |
| (c) Non | Rien à faire | Le modèle reste une tautologie (A3), et on le sait avant d'écrire la première ligne |

**Ma décision proposée : (a).** Avec une nuance qui n'est pas décorative : **une mesure du carnet
n'est pas une constante « maintenant »**. Une température prise il y a trois mois ne dit rien de
l'état actuel. C'est exactement le problème des « trois sources, trois phrases » de P6.4b
(*« vous êtes à X »* / *« ville choisie »* / *« dernière position connue »*) — la réponse y était de
**dire laquelle des trois on tient**, jamais de les confondre. Ici : `mesuree_maintenant` /
`rappelee_du_carnet` (avec sa date) / `non_fournie`. Et une valeur rappelée du carnet n'entre dans
aucune règle de protocole sans que sa fraîcheur soit un fait.

### D2 — L'IA rend-elle un niveau ?

**Ma décision proposée : NON, et structurellement pas.**

Si le service renvoyait un `niveau` parmi les quatre de §5.3, quelqu'un finirait par l'afficher, et
l'IA déciderait — interdit absolu, CDC_00 §4.

Le service parle donc un **vocabulaire délibérément différent** : un *risque estimé*
(`faible | modere | eleve`), qui ne peut pas être collé dans `triages.niveau` parce que ce ne sont
pas les mêmes valeurs. Précédent exact : P6.8b, où `fait/a_faire/en_retard` (une ligne) et
`a_venir/hors_delai` (une échéance) ne sont **jamais** mélangés.

Corollaire testable : un vecteur qui échoue si une valeur de `NiveauTriage` apparaît dans la réponse
du service.

### D3 — Le service reçoit-il la décision du protocole ?

**Ma décision proposée : oui, mais jamais comme feature.**

Pour : c'est ce qui permet le **second lecteur** — signaler que « le protocole conclut *consultation
recommandée* et le modèle estime un risque plus élevé, voici les facteurs ». Une **divergence** est
une information exploitable par un humain sans que le modèle ait besoin de faire autorité.

Contre : si le verdict entrait dans le vecteur d'entraînement, le modèle réapprendrait la règle (A3).

Résolution : `niveau_protocole` est **comparé après inférence**, jamais **inféré**. Vecteur dédié
asserting que le vecteur de features ne le contient pas.

### D4 — Sur quoi entraîne-t-on ?

§7.2 **interdit** d'entraîner sur la production. Aucun jeu anonymisé + validé par des médecins
n'existe. Donc : **données synthétiques ouvertement étiquetées « démonstration »**, générateur
documenté, graine fixée — régime d'ADR-017.

Avec une contrainte de plus, propre à P10c : **le label ne doit pas dériver des bandes du
protocole** (A3). Cible proposée : un « risque d'aggravation à 48 h » synthétique, combinaison
pondérée des constantes + bruit (le correctif qu'ADR-017 a dû appliquer pour sortir du SHAP
dégénéré).

**Jamais « validé cliniquement »**, et l'avertissement porté dans `metriques.json`, la réponse API,
Swagger, le README **et l'écran**.

### D5 — Où vit la traçabilité du §9.2 ?

**Laravel** (A8). `predictions_ia` : `triage_id`, `modele_version`, `probabilite`, facteurs SHAP,
explication, confiance, limites, mode (`hybride` / `degrade`), latence.
Plus `triages.modele_version` (A7, CDC_04 §115).

**Point d'honnêteté à écrire** : cette table **contiendra du contenu clinique**. Rule-005 et CDC_03
§10.1 exigent de stocker l'explication, et une explication SHAP nomme forcément les valeurs qui
l'ont produite (« spo2=91 augmente le risque »). Elle est donc dans le même régime que
`protocole_applications` de P10b-2 — la seule chaîne du projet qui porte du clinique, **parce que le
§10 l'exige**. On ne peut pas à la fois exiger l'explicabilité et prétendre ne rien stocker.

### D6 — Où se place l'appel, et que se passe-t-il quand il échoue ?

**Après** la décision du protocole et **hors** de la transaction qui écrit le triage.

Précédent double et net : ADR-020 (« scoring hors tx paiement ») et P7-D1 (« le push part APRÈS le
commit — *un tiers n'a jamais le droit de mettre en péril l'écriture d'un dossier médical* »).

En cas d'échec (timeout, disjoncteur ouvert, 5xx) : le triage est rendu **normalement**, avec la
mention du §10 — écrite **à un seul endroit** (précédent `MENTION_PROVENANCE` de P6.8d : *une phrase
recopiée trois fois finit par diverger deux fois*).

### D7 — Minimisation (§9.4)

Ce qui sort de Laravel : une **référence** (`triage:1234`), l'âge, le sexe, les codes de symptômes,
les constantes. **Jamais** le nom du patient, `membre_id`, le NIS, ni un identifiant de compte.

Vecteur dédié qui cherche ces champs dans la charge utile sortante et casse le build — précédent
exact : le test de P7-D1 qui cherche la donnée clinique dans toute la charge utile d'une
notification.

### D8 — Guide de test

Le sujet est le triage, et `GUIDE_TEST_TRIAGE.md` porte déjà 5 parties → **partie 6** (règle
propriétaire : un domaine à incréments successifs ajoute une partie).
À confirmer : la fraude, elle, a son guide propre parce qu'elle était un domaine neuf.

---

## 4bis. ARBITRAGE DU PROPRIÉTAIRE (2026-08-21)

- **D1 = option (a)** : l'entrée est élargie aux données cliniques du §5.2, **écran + carnet**.
- **§5.5.2 (questionnaire personnalisé par l'IA) = DIFFÉRÉ**, nommé comme limite avec son porteur.

Découpage retenu : **P10c-1** (collecte clinique) → **P10c-2** (microservice + intégration).

---

## 5. G1 DÉTAILLÉ — P10c-1 : collecte des données cliniques du §5.2

### 5.0 Ce que l'implémentation a fait tomber du périmètre annoncé

Le §3 annonçait « constantes vitales, **durée, évolution**, grossesse ». Vérification faite,
**durée et évolution n'ont besoin d'aucun code** : ce sont des **questions de protocole**, et le
mécanisme existe depuis P10b-3-i — `reponse.duree_jours` et `reponse.intensite` (échelle 1-10, soit
la `douleur` du §5.2) sont déjà dans le questionnaire de démonstration.

Les ajouter comme « champs du §5.2 » créerait un **second chemin** pour dire ce que le questionnaire
dit déjà, avec la divergence qui vient toujours avec (précédent X5 de P10b-3-i : deux listes du même
fait dans le même blob). **Durée, évolution et douleur = de la donnée, zéro code** : une nouvelle
version du protocole `TRIAGE-QUESTIONNAIRE`.

P10c-1 porte donc **uniquement ce que le questionnaire ne peut pas porter** : une valeur **mesurée**,
qui a une unité, des bornes de plausibilité et un nombre de décimales.

### 5.1 Décision E1 — le vocabulaire est ADOPTÉ, pas réinventé, et il diverge du §5.2

`referentiels_mesure` (gouverné, publié sous quatre yeux depuis L1+L2) porte déjà exactement les
constantes du §5.2 :

| §5.2 écrit | le projet dit | pourquoi on garde le projet |
|---|---|---|
| `frequence_cardiaque` | **`pouls`** | même fait clinique ; deux noms = deux vérités |
| `spo2` | **`saturation_o2`** | idem |
| `tension: "13/8"` | **`tension_systolique` + `tension_diastolique`, en mmHg** | une chaîne à parser, et une **conversion d'unité sur une tension artérielle** dont l'erreur ne se verrait pas |
| `temperature` | `temperature` | identique |
| `poids` | `poids` | identique |

C'est le principe de P6.8a (*les codes sont adoptés, jamais réinventés*), et c'est ici l'ordre de
résolution du corpus qui tranche : CDC_09 (données nationales) prime, et ce vocabulaire est publié
sous gouvernance.

**Écart avec le corpus, assumé et écrit** : le contrat littéral du §5.2 n'est pas reproduit
tel quel. La divergence est d'orthographe et d'unité, jamais de sens.

### 5.2 Décision E2 — LA LIGNE LA PLUS IMPORTANTE : ce que gouverne `referentiels_mesure`, et ce qu'il ne gouverne pas

`ReferentielMesure` porte `valeur_min`/`valeur_max` (plausibilité), `unite`, `decimales`, **et**
`critique_bas`/`critique_haut` avec `statutPour()` qui classe en `critique | bas | eleve | normal`.

La tentation est immédiate : écrire une règle de protocole `SI temperature_statut = 'critique'`.
**Il ne faut pas**, et c'est le point de conception de cet incrément.

`critique_haut = 39.5` vit dans un référentiel gouverné par les **deux signatures administratives**
du §10 (P6.3). Une règle qui ferait dépendre le **niveau d'urgence d'un citoyen** de cette valeur la
soumettrait à deux signatures là où **le §7 en exige quatre**. C'est **exactement l'asymétrie que
P10b-3-i a passé un incrément entier à refermer** pour l'impact des réponses — on ne la rouvre pas
un cran plus bas.

Partage retenu, et il est net :

- `referentiels_mesure` fournit **l'unité, les décimales et les bornes de plausibilité**. C'est une
  question de **qualité de donnée** (« 300 °C n'est pas un patient »), pas une décision clinique.
- **Le seuil qui change un triage est une règle de protocole**, sous quatre validations : le
  protocole compare la **valeur brute** (`SI temperature >= 39.5 ET age < 5 ALORS …`).

Conséquence testable : **le triage n'appelle jamais `statutPour()`**, et aucun fait `*_statut`
n'existe. Un vecteur dédié échoue si une clé de statut apparaît dans les faits.

**Et c'est le §1.2 retourné à l'endroit** : `if temperature > 39: urgence = True` est le
contre-exemple littéral du corpus ; ici la même phrase devient une règle **en base, versionnée,
relue et signée par quatre validateurs**. C'est le gain réel de cet incrément, indépendamment de
l'IA.

### 5.3 Décision E3 — le carnet PROPOSE, le patient CONFIRME ; une mesure ancienne n'est jamais présentée comme le présent

Une température prise il y a trois mois n'est pas une température. La faire entrer dans une règle
clinique serait la faute des « trois sources » de P6.4b — où la réponse fut de **dire laquelle des
trois on tient** (*« vous êtes à X »* = mesure, *« ville choisie »* = déclaration, *« dernière
position connue »* = souvenir), jamais de les confondre.

Proposition :

1. `referentiels_mesure` gagne **`fraicheur_max_minutes`** — une **donnée par type**, publiée sous
   la gouvernance existante (température : quelques heures ; poids : plusieurs mois). Aucun seuil
   de fraîcheur codé nulle part.
2. Dans la fenêtre : le champ est **pré-rempli** depuis le carnet, **avec sa date affichée**, et le
   patient corrige s'il veut.
3. Hors fenêtre : la valeur est **montrée comme contexte** (« dernière température connue :
   38,2 °C, il y a 3 jours ») et **n'est pas pré-remplie**. Elle **n'entre dans aucune règle**.

Ce qui est enregistré : `origine ∈ {saisie, reprise_du_carnet}`. Aucune valeur périmée n'atteint
une règle clinique sans qu'un humain l'ait eue sous les yeux.

**Coût assumé** : une colonne de plus sur un référentiel gouverné, donc **une étape de publication**
au déploiement.

### 5.4 Décision E4 — le triage n'écrit RIEN dans le carnet

Tentant : le triage collecte une température, on l'enregistre dans `mesures_sante`. **Non.**

Ce serait ouvrir un **4ᵉ chemin d'écriture** dans une table du carnet, avec la question du rejeu et
de la suppression par le patient — raisonnement W3 de P6.8b, où le calendrier vaccinal *répond et
prévient, mais n'écrit rien*.

Les valeurs vivent sur **le triage**, dans `triage_constantes` (une ligne par constante, à l'image
de `triage_reponses`) : type, valeur, unité, origine, version du référentiel, et l'identifiant de la
mesure du carnet si elle a été reprise. **Une table et non un blob JSON** — précédent P6.6a (les
interactions sont une relation) et X5 de P10b-3-i (deux listes du même fait finissent par diverger).

### 5.5 Décision E5 — bornes opposables : on REFUSE, on n'écrête jamais

Les bornes sont lues dans la **version publiée** de `seuils_mesure`, jamais dans la table (constat
C1 de L1+L2 : la validation contournait le service).

Une valeur hors bornes est **refusée**, jamais ramenée dans la plage : écrêter accepterait une
saisie fausse **en la corrigeant sans le dire** — *le patient croirait avoir saisi 40,5 et son
dossier porterait 45* (R7 de P10b-3-i, mot pour mot).

Le contrôle vit dans le **service**, pas dans la `FormRequest`, et les vecteurs sont **dédoublés** :
un par HTTP, un appelant le service **directement** comme le ferait un import — parade posée en
P6.6b après quatre occurrences du piège « le vecteur prouve le validateur, pas la garde ».

Refus bruyant si une constante est soumise alors que `seuils_mesure` n'a **aucune version publiée**.
**Risque résiduel nommé** : si l'écran cessait de collecter une constante, une règle qui s'y réfère
ne se déclencherait plus **sans bruit** — un fait *connu mais non renseigné pour ce patient* ne lève
pas, par construction (triage anonyme). Contre-mesure proposée : le contrôle qualité signale une
règle publiée portant sur une constante qu'aucun écran n'alimente.

### 5.6 Décision E6 — `grossesse` : une question pré-remplie, pas un second chemin

Le carnet la connaît (`suivi_grossesse`). En faire **à la fois** un fait dérivé du carnet **et** une
question donnerait deux sources pour un même fait, capables de diverger.

Proposition : **une question du protocole**, conditionnée sur `sexe = F`, **pré-remplie** depuis le
carnet quand il sait. Une seule source (la réponse), le carnet ne fait que proposer. Zéro code —
c'est une version du questionnaire.

Précédent qui commande la prudence : P6.7a, où les strates de grossesse sont **ajoutées, jamais
choisies** — *le carnet connaît la grossesse, donc la tentation est réelle*.

### 5.7 Ce qui NE change pas

- `AnalyserTriageRequest` gagne `constantes`, rien d'autre ne bouge dans son contrat.
- Aucun écran validé G5 n'est réécrit : les champs s'ajoutent à l'étape existante du flux.
- Le moteur `MoteurProtocole` **ne bouge pas** — c'est le test de la conception, comme en P10b-3-i :
  si le moteur devait changer, c'est que les constantes ne seraient pas des faits.
- `RegistreFaitsProtocole` gagne cinq à six lignes et **le moteur ne bouge pas** (« ajouter un fait
  = ajouter une ligne ici et le produire dans l'assembleur », son propre en-tête).

### 5.8 Preuves prévues

- **G3** : vecteurs dédiés dans les deux sens ; **campagne de mutation** — une garde, ses vecteurs,
  chaque mutation **assertée appliquée et sur le bon site**, ancre tenant **sur une seule ligne**,
  restauration vérifiée par `diff`, **et le vert vérifié AVANT de muter** (les six règles de
  [[harnais-mutation-lecons]]).
- **G2 live MySQL** : valeur hors bornes → refus nommant la borne publiée · `UPDATE` direct sur
  `referentiels_mesure` → **sans effet** avant republication · mesure du carnet dans la fenêtre →
  pré-remplie avec sa date / hors fenêtre → montrée mais non pré-remplie et **absente des faits** ·
  une règle de protocole `SI temperature >= 39.5` publiée sous quatre validations **change le
  niveau** · `mesures_sante` **inchangée** après un triage · base restaurée compte pour compte.
- **Guide** : `GUIDE_TEST_TRIAGE.md` **partie 6**.

### 5.9 Limites que P10c-1 annoncera

- **Aucune IA** — c'est P10c-2 ; cet incrément livre la collecte et le gain de protocole.
- **Aucune allergie structurée** dans le projet : le §5.2 restera partiellement irreprésentable.
- **Contenu de démonstration** : les règles cliniques sur constantes seront en `niveau_preuve = 'D'`,
  sans validateurs forgés (N3 inchangée).
- **`fraicheur_max_minutes` = une donnée de démonstration**, non confrontée à une recommandation.
- Une constante saisie **n'est pas vérifiée** : le patient déclare ce qu'affiche son thermomètre.
  C'est le même régime que `impact_triage` des antécédents — et la même réponse que P10b-3-ii y a
  apportée : si le poids d'une déclaration non vérifiée doit être borné, **c'est une règle de
  protocole**, pas une constante de code.

---

## 6. Ce que je m'engage à ne pas faire

- **Ne pas réimplémenter les règles de triage en Python.** Elles vivent dans les protocoles, sous
  quatre validations. Les redoubler ailleurs ferait **deux vérités** sur le niveau d'urgence d'un
  citoyen, et remettrait dans du code ce que P10b a passé quatre incréments à en sortir. Le triade
  hybride du §1.6 est **distribué** : composante 1 (moteur de règles) = Laravel/CDC_08, composantes
  2 et 3 (XGBoost, SHAP) = le service Python. §5.1 le dit littéralement en nommant CDC_08 comme
  première composante.
- **Ne pas mettre de seuil clinique dans le service Python.** Une garde « SpO2 < 90 » écrite en
  Python serait une règle médicale hors gouvernance — une régression déguisée en incrément.
- **Ne pas inventer un score quand la source manque.** Le service refuse (502 honnête, régime
  ADR-019) ; c'est Laravel qui dégrade et le dit.

---

## 6. Limites qui seront annoncées au G5 (déjà connues)

- Modèle **synthétique**, jamais validé cliniquement ; §7.2 tenue par défaut, faute de jeu réel.
- **Aucune boucle d'apprentissage §5.5.4** : aucun diagnostic final n'est relié à un triage.
- **Aucun NLP** (§5.5.1) : CDC_07 est le document de l'IA générative.
- **`ai-gateway` absent** (§4, §12 étape 1) : appel direct, comme la fraude.
- **Corpus de protocoles de démonstration** (`niveau_preuve = 'D'`), conséquence assumée de A9.
- **Niveaux hospitaliers toujours dormants** (A11).
- **Aucune surveillance de dérive, ni équité, ni canary** (§8) : MLflow en registre fichier.
- **Pas d'allergies structurées** dans le projet — le §5.2 restera partiellement irreprésentable.
