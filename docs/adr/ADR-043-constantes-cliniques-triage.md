# ADR-043 — Les constantes cliniques du §5.2 entrent dans le triage (P10c-1)

- **Statut** : **Accepté — P10c-1 VALIDÉ G5** (2026-08-21). Aucune IA : c'est P10c-2.
- **Date** : 2026-08-21
- **Corpus** : CDC_05 §5.1 (vecteur XGBoost), §5.2 (contrat d'API), §5.4 (fiche) · CDC_08 §1.2
  (aucune règle médicale en dur), §4.3a (moteur), §7 (quatre validations), §13 étape 7 ·
  CDC_09 §10 (gouvernance) · CDC_04 §115 · CDC_00 §4.
- **Lié à** : [[ADR-025]] (socle référentiel, L1+L2), [[ADR-027]] (les trois sources),
  [[ADR-041]] (protocoles médicaux), [[ADR-042]] (un identifiant n'est pas une relation vivante),
  [[ADR-036]] (le calendrier répond et prévient, il n'écrit rien).

---

## 1. Contexte — ce que le G0 a établi

P10a a sorti l'**orientation** du code, P10b le **niveau**, le **questionnaire** et la **borne des
antécédents**. Le triage fonctionne entièrement sans IA : c'est la Phase 1 de CDC_05 §7.3, achevée.

P10c devait livrer le `triage-service`. Le G0 a montré que **ce n'était pas possible honnêtement
dans cet ordre**, pour une raison vérifiée et non supposée :

**Le triage ne collecte aucune constante clinique.** CDC_05 §5.1 énumère le vecteur du modèle —
température, tension, fréquence cardiaque, saturation, douleur, durée, évolution, allergies,
grossesse. `AnalyserTriageRequest` accepte : des symptômes, un âge, un sexe, des réponses.

**Conséquence directe** : le niveau rendu par le triage est, depuis P10b-1, une **fonction
déterministe** du score, et les seules features disponibles sont les termes de ce score. Un XGBoost
entraîné là-dessus atteindrait une exactitude quasi parfaite **et n'aurait rien appris d'autre que
la règle posée à côté de lui**. Il aurait l'apparence d'une IA et la valeur d'une tautologie.

Et le projet avait déjà nommé le manque, avec sa condition de levée, dans
`RegistreFaitsProtocole` :

> *« Déclarer `temperature` ou `spo2` — que CDC_05 §5.2 cite — alors qu'aucun écran ne les collecte
> permettrait d'écrire une règle qui ne se déclencherait jamais. Ce serait publier une garantie
> inerte. **Ces faits entreront quand leur collecte existera.** »*

**Arbitrage du propriétaire (2026-08-21)** : élargir l'entrée d'abord (écran **et** carnet), l'IA
ensuite. Découpage **P10c-1** (collecte) → **P10c-2** (microservice).

---

## 2. Le périmètre annoncé a été réduit, et c'est une décision

Le plan G1 annonçait « constantes vitales, **durée, évolution**, grossesse ». Vérification faite,
**durée et évolution n'ont besoin d'aucun code** : ce sont des **questions de protocole**, et le
mécanisme existe depuis P10b-3-i — `reponse.duree_jours` et `reponse.intensite` (la `douleur` du
§5.2) sont déjà dans le questionnaire de démonstration.

Les ajouter comme « champs du §5.2 » aurait créé un **second chemin** pour dire ce que le
questionnaire dit déjà, avec la divergence qui vient toujours avec (constat X5 de P10b-3-i : deux
listes du même fait dans le même blob).

P10c-1 porte donc **uniquement ce qu'un questionnaire ne peut pas porter** : une valeur **mesurée**,
qui a une unité, des bornes de plausibilité et un nombre de décimales.

---

## 3. Décision E1 — le vocabulaire est ADOPTÉ, pas réinventé

`referentiels_mesure` (gouverné, publié sous quatre yeux depuis L1+L2) porte déjà les constantes du
§5.2, sous d'autres noms :

| §5.2 écrit | le projet dit | pourquoi le projet l'emporte |
|---|---|---|
| `frequence_cardiaque` | **`pouls`** | même fait clinique ; deux noms = deux vérités |
| `spo2` | **`saturation_o2`** | idem |
| `tension: "13/8"` | **`tension_systolique` + `tension_diastolique`, en mmHg** | une chaîne à parser, et une **conversion d'unité sur une tension artérielle** dont l'erreur ne se verrait pas |

Principe de P6.8a (*les codes sont adoptés, jamais réinventés*), et ici l'ordre de résolution du
corpus tranche : CDC_09 prime, et ce vocabulaire est publié sous gouvernance.

**Écart assumé et écrit** : le contrat littéral du §5.2 n'est pas reproduit tel quel. La divergence
est d'orthographe et d'unité, jamais de sens.

---

## 4. Décision E2 — LE POINT DE CONCEPTION : ce que le référentiel gouverne, et ce qu'il ne gouverne pas

`ReferentielMesure` porte `valeur_min`/`valeur_max`, `unite`, `decimales`, **et**
`critique_bas`/`critique_haut` avec `statutPour()` qui classe une valeur en `critique`.

La tentation est immédiate : écrire `SI constante.temperature_statut = 'critique'`. **Il ne faut
pas.**

`critique_haut = 39.5` vit dans un référentiel gouverné par les **deux signatures administratives**
du §10. Une règle qui ferait dépendre le **niveau d'urgence d'un citoyen** de cette valeur la
soumettrait à deux signatures là où **le §7 en exige quatre**. C'est **exactement l'asymétrie que
P10b-3-i a passé un incrément entier à refermer** pour l'impact des réponses ; on ne la rouvre pas
un cran plus bas.

**Partage retenu, et il est net** :

- `referentiels_mesure` fournit **l'unité, les décimales et les bornes de plausibilité**. C'est une
  question de **qualité de donnée** — *300 °C n'est pas un patient, c'est une faute de frappe*.
  Aucune décision clinique là-dedans.
- **Le seuil qui change un triage est une règle de protocole**, sous quatre validations, comparant
  la **valeur brute**.

**Garantie testable** : aucun fait `constante.*_statut` n'existe, le triage n'appelle jamais
`statutPour()`, et un vecteur échoue si une clé de statut apparaît dans les faits.

**Et c'est le §1.2 retourné à l'endroit.** CDC_08 §1.2 donne son contre-exemple littéral —
`if temperature > 39: urgence = True`. Cette phrase existe désormais dans le projet, **en donnée**,
relue et signée par quatre validateurs, corrigible sans déploiement, estampillée sur chaque triage
qu'elle a jugé. **C'est le gain réel de l'incrément, indépendamment de l'IA.**

---

## 5. Décision E3 — le carnet PROPOSE, le patient CONFIRME

*Une température prise il y a trois mois n'est pas une température.* La faire entrer dans une règle
clinique la présenterait comme le présent — la faute des **trois sources** de P6.4b, où la réponse
fut de **dire laquelle des trois** on tient (une mesure, une déclaration, un souvenir).

- `referentiels_mesure` gagne **`fraicheur_max_minutes`** — une **donnée par type**, publiée sous la
  gouvernance existante. Aucun seuil de fraîcheur codé nulle part : une saturation vaut pour l'heure
  qui suit, un poids pour des mois.
- Dans la fenêtre : le champ est **pré-rempli avec sa date**, le patient corrige s'il veut.
- Hors fenêtre : la valeur est **montrée comme contexte** et **n'entre dans aucune règle**.
- `null` veut dire **jamais pré-rempli** — le sens sûr : une donnée absente n'autorise pas
  silencieusement la réutilisation d'une mesure ancienne (motif P6.5a).

**`fraicheur_max_minutes` entre dans la projection gouvernée**, donc **l'empreinte du référentiel
change**. Ce n'est pas une dérive : elle décide si une mesure est **proposée** à un citoyen au
moment d'un triage — une décision de santé publique, pas un réglage d'affichage. La laisser hors de
la projection permettrait de la corriger par un `UPDATE`, sans relecture. Même cas que
`forme_juridique` en P6.4d et le rattachement des spécialités en P6.8a.

---

## 6. Décision E4 — le triage n'écrit RIEN dans le carnet

Enregistrer la température du triage dans `mesures_sante` ouvrirait un **4ᵉ chemin d'écriture** dans
une table du carnet, avec sa question de rejeu et de suppression par le patient — raisonnement W3 de
P6.8b, où le calendrier vaccinal *répond et prévient mais n'écrit rien*.

Les valeurs vivent dans **`triage_constantes`** : une ligne par constante, à l'image de
`triage_reponses`. Une table et non un blob JSON (précédent P6.6a, constat X5 de P10b-3-i).

**`mesure_id` est un identifiant, pas une clé étrangère** : décision D1 d'ADR-042, prise deux jours
plus tôt. Si le patient supprime la mesure de son carnet, la constante du triage doit **conserver la
trace de ce qu'elle a repris** — une action référentielle l'effacerait de l'archive. Bénéfice
second, réel : `origine` et `mesure_id` n'étant soumis à aucune action référentielle, la garde du
moteur peut porter dessus **sans rencontrer l'erreur 3823** (le mur de P6.3).

---

## 7. Décision E5 — bornes opposables : on REFUSE, on n'écrête jamais

Lues dans la **version publiée**, jamais dans la table (constat C1 de L1+L2). Une valeur hors bornes
est refusée : écrêter accepterait une saisie fausse **en la corrigeant sans le dire** — *le patient
croirait avoir saisi 45 et son dossier porterait 42*. C'est mot pour mot la décision R7 de
P10b-3-i.

**Une précision de trop est refusée elle aussi**, et **deux bornes de nature différente** s'y
rencontrent — la plus stricte l'emporte :

- **`decimales` est une donnée du référentiel PUBLIÉ**, donc une règle gouvernée, relue et signée.
  Ne pas l'appliquer reviendrait à publier une contrainte décorative.
- **`decimal(8,2)` est ce que la colonne sait porter.** MySQL arrondit au-delà avec un simple
  avertissement : le patient saisirait 39,555 et son dossier porterait 39,56. Défense en profondeur,
  et si un référentiel publiait une précision intenable, le message nomme la borne **réellement
  appliquée** plutôt que la promesse.

> **Corrigé après le G2 live.** Le serveur acceptait `39,55` alors que le référentiel publie une
> décimale pour la température : seule la capacité de la colonne mordait. **Aucun des 46 vecteurs ne
> le voyait**, parce que tous éprouvaient `39,555` — une valeur que *les deux* bornes refusent ; il a
> fallu celle qui les sépare. C'est la forme exacte du constat X4 de P10b-3-i (« le référentiel
> publiait des bornes et le serveur ne les regardait pas »), que cette décision referme pour
> `valeur_min`/`valeur_max` et laissait ouverte un cran plus loin. Trois vecteurs ajoutés ; le
> vecteur hérité a été **réécrit pour dire la garantie qui tient**, pas corrigé pour passer
> (précédent P6.4d).

Le contrôle vit dans le **service**, pas dans la `FormRequest`, et les vecteurs sont **dédoublés** —
un par HTTP, un appelant le service directement comme le ferait un import (parade P6.6b).

---

## 8. Décision E6 — l'origine est décidée par le serveur

Le client n'envoie qu'un **type** et une **valeur**. C'est le serveur qui reconnaît une valeur
reprise du carnet, en la comparant à celle qu'il a lui-même proposée.

Laisser le client déclarer sa provenance rejouerait la faute refermée quatre fois : `source` d'une
contribution (P7-C), `obligatoire` d'une vaccination (P6.8b), `provenance` d'une couverture
(P6.8d), `medecin_nom` d'une ordonnance (P6.5a).

---

## 9. Conséquences

- **CINQUIÈME étape de déploiement, et elle passe EN PREMIER.** `TRIAGE-NIVEAU` porte une règle sur
  `constante.temperature` ; le contrôle du §7.4 refuse une constante absente de la version publiée
  des seuils. **Publier le protocole avant `seuils_mesure` échoue.** Dix-huit vecteurs de
  `ProtocoleMedicalTest` se sont mis à échouer d'un coup — dont six pour une raison plus instructive
  que les autres : le refus leur revenait avec le motif de la constante manquante **à la place** de
  celui qu'ils vérifiaient. Ils prouvaient un refus, mais plus « par leur motif ». Aucun n'a été
  affaibli ; c'est le montage qui a été complété.
- Un **8ᵉ type de constante exige une migration** : l'ENUM de `referentiels_mesure` plafonne à sept.
  Limite **héritée**, dite plutôt que déguisée — la porte est ouverte du côté des faits de
  protocole, elle reste fermée en amont.
- **Le moteur ne garantit pas les bornes** : elles vivent dans un instantané publié qu'un
  déclencheur SQL ne peut pas lire. La garde est **applicative**, annoncée comme telle (précédent du
  quota d'images, P6.4c).
- **Risque résiduel nommé** : si un écran cessait de collecter une constante, une règle qui s'y
  réfère ne se déclencherait plus **sans bruit** — un fait connu mais non renseigné pour ce patient
  ne lève pas, par construction, et c'est ce qui garde le triage anonyme possible.

---

## 10. Ce que la campagne de mutation a appris

Onze mutations : **dix tueuses, une volontairement verte** (celle-ci teste le harnais lui-même —
sans elle, un lanceur cassé ferait paraître tout le monde tueur).

- **`g5` a d'abord SURVÉCU.** Le vecteur « une fraîcheur absente ne propose jamais » passait pour
  une raison qui n'était pas la garde : sans elle, `(int) null` vaut 0, la fenêtre devient « zéro
  minute », et une mesure passée est écartée de toute façon. *Faire reposer « ne jamais proposer »
  sur le fait que `(int) null` vaut 0 serait un accident, pas une décision.* Le cas qui les sépare
  est une mesure **datée du futur** (horloge d'appareil en avance) — vecteur ajouté, mutation tuée.
  **Septième instance** de la famille « le vecteur prouve autre chose ».
- **`g10` a été refusée par le harnais lui-même**, au titre de sa règle 6 (l'ancre ne doit pas être
  un préfixe du remplacement). La définition était fautive, pas le code.

---

## 11. Limites

- **Aucune IA** : le `triage-service`, XGBoost et SHAP sont P10c-2.
- **Aucun questionnaire personnalisé par l'IA** (§5.5.2) — différé et nommé, arbitrage propriétaire.
- **Aucune compréhension du langage naturel** (§5.5.1) — CDC_07.
- **Aucune allergie structurée** dans le projet : le §5.2 reste partiellement irreprésentable.
- **Durées de fraîcheur = jeu de démonstration**, non confrontées à une recommandation publiée et
  attribuées à aucune autorité. Les corriger est **de la donnée, zéro code**.
- **La règle de fièvre est en `niveau_preuve = 'D'`**, sans validateur forgé (décision N3 d'ADR-041 :
  on ne fabrique jamais une validation clinique).
- **Une constante saisie n'est pas vérifiée** : le patient déclare ce qu'affiche son thermomètre.
  Même régime qu'`impact_triage` — et si le poids d'une déclaration non vérifiée doit être borné,
  ce sera une **règle de protocole**, pas une constante de code.
- **Le contrôle qualité de `seuils_mesure` accepte `decimales` jusqu'à 3**, alors que
  `mesures_sante.valeur` **et** `triage_constantes.valeur` sont l'une et l'autre en `decimal(8,2)` :
  publier trois décimales serait promettre une précision qu'**aucun** des deux stockages ne sait
  tenir. Le triage ne s'y laisse plus prendre — la borne appliquée est la plus stricte des deux, et
  le message la nomme. **Mais la promesse intenable reste publiable**, et ce contrôle appartient au
  socle de P6.3, validé G5 : le resserrer est une décision de gouvernance, pas une correction
  chirurgicale de cet incrément. **Constaté au G2, dit ici, non corrigé** — porteur : le propriétaire.
- **`origine` est un ENUM, donc une garde déclarative dont la force dépend du `sql_mode` de la
  session.** Sur le poste de développement WAMP, le mode **global est vide** et une valeur inventée
  y devient `''` en SQL direct. Laravel pose `STRICT_TRANS_TABLES` sur sa propre session
  (`'strict' => true`) : l'insertion par le code applicatif est bien **refusée**, vérifié au G2. La
  garantie tient donc sur tous les chemins de l'application, et pas au-delà — **annoncée comme
  telle**, jamais déguisée en garantie du moteur (précédent du quota d'images, P6.4c).
