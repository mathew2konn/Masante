# ADR-030 — API d'intégration : se brancher sur les systèmes existants plutôt que les recréer

**Statut : Conçu** — 2026-08-13 · **Pas « prêt à activer »** (voir §6) · Contexte : CDC_09 §9.2, §14 étape 9 · CDC_11 §2.1 module 12, §3.2 · CDC_03 §10.3 · Prolonge [ADR-025 §L5](ADR-025-socle-referentiel.md) · Même classement que [ADR-014](ADR-014-controle-integrite-et-rapprochement.md).

---

## 1. Ce que le corpus impose

> **CDC_09 §9.2 — Intégration des systèmes existants.** « API d'intégration pour les logiciels
> hospitaliers, logiciels de pharmacie (caisse, stock, ERP), assureurs et prestataires de paiement.
> Authentification par **client OAuth2 dédié**, **quotas**, **webhooks signés**, **journalisation
> complète**, **mapping vers les codes du référentiel national**. »
>
> **CDC_11 §3.2 (pharmacies).** « Si la pharmacie possède déjà un logiciel (caisse, stock, ERP), ce
> logiciel envoie automatiquement stock, prix, disponibilité, ordonnances, commandes. **Le
> pharmacien n'a rien à ressaisir.** »

L'« API d'intégration » est le **module 12** de CDC_11 §2.1, et l'**étape 9** de l'ordre de
construction de CDC_09 §14.

---

## 2. L'intention, énoncée par le propriétaire (2026-08-13)

> « Il y a aussi le côté API qui permettra de connecter MaSanté aux logiciels qui existent déjà dans
> les structures, au lieu de recréer une structure entière — par exemple un hôpital et toutes ses
> organisations. »

Cette phrase est consignée parce qu'elle dit une **position d'architecture**, pas seulement une
liste de standards : **MaSanté n'a pas vocation à remplacer le système d'information d'un hôpital
qui en a déjà un.** Là où un logiciel existe, la plateforme s'y branche. Le corpus l'exprime en
creux (« le pharmacien n'a rien à ressaisir ») ; il fallait l'écrire en clair.

### 2.1 Nuance sans laquelle cette décision serait fausse

Il existe **deux populations d'établissements**, et l'une ne doit pas être sacrifiée à l'autre :

| Population | Ce dont elle a besoin |
|---|---|
| **Sans logiciel** — la majorité des centres de santé et cabinets | Le **portail** leur en tient lieu : services, agents, disponibilités, dossier. C'est ce qui existe aujourd'hui, et **ce n'est pas une erreur de conception à corriger**. |
| **Avec un logiciel** — CHU, grandes cliniques, pharmacies équipées d'une caisse ou d'un ERP | Une **API** qui reçoit ce que leur système produit déjà, sans double saisie. |

**Aucune des deux ne doit être imposée à l'autre.** Obliger un CHU à ressaisir dans le portail ce
que son SIH contient déjà garantit que les données seront fausses ou absentes ; obliger un cabinet
de brousse à disposer d'un logiciel pour exister dans l'annuaire national l'exclut purement et
simplement.

---

## 3. Décisions de conception

### 3.1 L'API est un contrat d'échange, **jamais un second chemin d'écriture**

Une intégration qui écrirait directement en base contournerait les règles métier — et ce projet a
posé, module après module, que les états, les calculs et les gardes vivent **dans les services**.
Un logiciel hospitalier qui pousse une ordonnance doit obéir **exactement** aux mêmes règles qu'un
soignant sur le portail : liste blanche de sections, `source`/`added_by` réécrits par le serveur,
journal d'accès (P7-D0).

Sans cette règle, l'API deviendrait la porte dérobée par laquelle tout le reste se contourne.

### 3.2 Le référentiel national est le **pivot**, et c'est pourquoi il vient d'abord

§9.2 exige un « mapping vers les codes du référentiel national ». Un partenaire parle **ses** codes ;
la frontière traduit vers `ETS000152`, un district, une spécialité, un code médicament.

**Sans référentiel, il n'y a rien vers quoi mapper.** C'est la raison pour laquelle CDC_09 §14 place
l'interopérabilité en **étape 9**, après les référentiels (étapes 1 à 8) — et non un ordre
arbitraire. Le travail de P6.1 (NIS) et P6.3/P6.4 (socle, établissements) est **le prérequis
matériel** de cette API.

La traduction se fait dans une **couche anti-corruption** : les codes du partenaire n'entrent jamais
tels quels dans le domaine. Précédent établi : ADR-019, où le service de fraude normalise le
camelCase du service de paiement à sa frontière plutôt que de laisser deux conventions cohabiter.

### 3.3 Trois populations d'authentification, jamais une seule étirée

| Qui | Mécanisme | État |
|---|---|---|
| Un **citoyen** sur mobile | Sanctum + OTP | existe (P1) |
| Un **service interne** de la plateforme | Principal signé HMAC lié à la méthode et au chemin, anti-rejeu | existe (P5.5b-1) |
| Un **système tiers** — SIH, caisse, ERP, assureur | **OAuth2 client credentials**, quotas, journalisation | **n'existe pas** |

Aucun serveur OAuth2 n'est présent : ni Passport, ni `league/oauth2` — **vérifié dans
`composer.json`**. Étirer Sanctum pour couvrir un système tiers reviendrait à donner à une machine
un jeton conçu pour une personne, sans quota ni révocation par client.

### 3.4 Idempotence exigée dès la conception

Un logiciel hospitalier qui n'obtient pas de réponse **réessaiera**. Sans clé d'idempotence, un
réessai crée une seconde ordonnance, un second stock, un second rendez-vous. Le motif est déjà
éprouvé dans ce projet : `Idempotency-Key` du service de paiement (P5.1), verrou Redis plus unicité
en base.

### 3.5 Webhooks signés — la conception est prouvée, le code ne se réutilise pas

Le service de paiement porte un webhook signé **éprouvé** (P5.4a) : HMAC sur le **corps brut avant
désérialisation**, fraîcheur de l'horodatage **signé** ±5 min, anti-rejeu Redis, déduplication
`UNIQUE(psp, evenement_id)`, rejets **401/422 génériques** anti-fuite.

Il est en **Java** et il est **entrant** depuis un prestataire. La forme est la même ; le code, non.
On réutilise la **conception**, pas les classes — le dire évite de promettre une réutilisation qui
n'existe pas.

### 3.6 Ce qui n'est PAS tranché ici

Le détail des ressources **FHIR** (quelles ressources, quels profils), **DICOM/PACS**, et la
**synchronisation nationale** (étape 10 du §14). Les nommer aurait donné l'illusion d'un travail
fait.

---

## 4. Ce que cette décision ne résout pas : M1 reste entière

**L'API d'intégration et la « Méthode 2 » d'onboarding (M1 d'ADR-026) sont deux dettes distinctes**,
et il serait faux de croire que l'une absorbe l'autre :

- **Méthode 2** répond à « comment un établissement **entre** sur la plateforme » — quelqu'un doit
  reconnaître qu'un établissement est légitime et lui attribuer un identifiant national.
- **L'API d'intégration** répond à « comment ses données **circulent** une fois qu'il est entré ».

Une API parfaite n'attribue pas d'identifiant national. Une Méthode 2 parfaite n'évite aucune
ressaisie.

---

## 5. Point d'extension documenté

Ce qui est **fixé** pour que l'incrément puisse être écrit sans rouvrir la conception :

1. **Point d'entrée** : un domaine `integration` distinct de l'API citoyenne — jamais des routes
   ajoutées à `/api/v1` avec une garde différente, ce qui rendrait la surface illisible.
2. **Identité de l'appelant** : un **client** (l'organisation partenaire) rattaché à un ou plusieurs
   établissements par `identifiant_national`. Un client ne parle jamais que pour les établissements
   auxquels il est rattaché — c'est la transposition de la garde de P6.4c, où un gestionnaire n'a de
   droits que sur **son** établissement.
3. **Traduction** : une correspondance `code_partenaire → code_référentiel_national`, **en données**,
   par client. Un code inconnu est **refusé et signalé**, jamais deviné — précédent du découpage
   sanitaire, où une liste inventée qui a l'air juste est plus dangereuse qu'une liste manifestement
   incomplète.
4. **Journal** : chaque appel entrant tracé (client, établissement, ressource, décision), au titre de
   la « journalisation complète » du §9.2 et de la loi 2013-450.
5. **Sens de circulation** : entrant (le partenaire pousse) **et** sortant (webhook signé vers le
   partenaire). Les deux sont exigés par §9.2 ; ne livrer que l'entrant serait une demi-intégration.

---

## 6. Statut : « conçu », pas « prêt à activer »

**Aucune ligne de code n'est écrite, et c'est délibéré.** Aucune API de logiciel hospitalier ivoirien
n'a été vue ; écrire du code contre un contrat qu'on n'a pas lu produirait un adaptateur qu'il
faudrait jeter. C'est exactement le classement retenu par **ADR-014** pour le rapprochement à deux
sources : un **point d'extension documenté**, pas une implémentation spéculative.

**Position dans l'ordre.** CDC_09 §14 la place en **étape 9**. Sont faites : 1 (socle référentiel),
2 (NIS), 4 (établissements). Restent 5 (professionnels + PKI), 6 (médicaments), 7 (laboratoires),
8 (transverses) **avant** celle-ci. L'API d'intégration **n'est pas le prochain incrément**, et
l'annoncer autrement serait faux.

**Limites énoncées.**

- **Q1** — Aucun serveur OAuth2 dans le projet (vérifié).
- **Q2** — Aucun mapping FHIR : ni ressources, ni profils.
- **Q3** — Ni DICOM ni PACS ; ni MinIO (CDC_04 le prévoit, il n'existe pas).
- **Q4** — La synchronisation nationale (étape 10) n'est pas couverte par cette décision.
- **Q5** — **Aucun partenaire réel n'a été consulté.** Tant que ce point tient, toute estimation de
  charge sur cette API serait une invention.
