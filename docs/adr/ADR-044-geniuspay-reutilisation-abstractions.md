# ADR-044 — Intégration GeniusPay : réutilisation des abstractions existantes

- **Statut** : **Accepté** (2026-08-28, G4 propriétaire OK — checkout réel parcouru personnellement en
  sandbox, phase 6). Rédigé « Proposé » le 2026-08-27, condition d'exécution du lot 7 ; la relecture
  qu'il exigeait avant la moindre ligne d'intégration n'avait jamais été actée par écrit alors que
  l'intégration, elle, était déjà écrite et prouvée — corrigé ici plutôt que laissé diverger entre ce
  que dit le statut et ce que montre le reste du document.
- **Date** : 2026-08-27
- **Portée** : microservice `services/payment` (Java Spring Boot, ADR-013).
- **Corpus** : CDC_06 §4.2 (machine à états), §5 (cartes/PSP), §5.4 · CDC_10 (sécurité).
- **Lié à** : [[ADR-013]] (le paiement est un microservice Java), [[ADR-015]] (P5.4a — cartes,
  webhook signé, `StatutCarte` backend-only), [[ADR-018]] (P5.4b/c — mandats, outbox de
  notification), [[ADR-020]] (routage d'alerte, 1er appel sortant).
- **Origine** : arbitrage Phase 0 du lot GeniusPay (points **B3** et **B6**), désormais déposé à
  `services/payment/docs/references/Arbitrage_Audit_Phase0_GeniusPay.md`.

> **Traçabilité — mise à jour du 2026-08-28.** Cet ADR a d'abord été rédigé alors que le document
> source était **absent du dépôt** ; il transcrivait des décisions résumées, sans pouvoir les
> confronter à leur origine, et le disait. L'arbitrage complet (B1→B7) a depuis été fourni et
> déposé : **B3 et B6 y ont été relus, et la transcription ci-dessous leur est conforme**. La
> réserve est donc levée. Ce qu'elle laisse derrière elle mérite d'être noté : la même
> transcription, restée non vérifiée, aurait pu diverger sans que rien ne le signale.

---

## 1. Contexte

Le service `paiement-service` possède déjà, **avant** toute intégration GeniusPay :

- un port `PasserellePaiement` (`payer` / `statut` / `rembourser`), avec `RegistrePasserelles` comme
  registre d'implémentations et `AdaptateurSimule` comme implémentation existante ;
- une machine à états partagée avec le mobile et le web (`@masante/shared`) :
  `INITIATED → PENDING → PROCESSING → SUCCESS/FAILED/CANCELLED → REFUNDED` ;
- une table d'événements webhook, une chaîne de vérification de signature (`SignatureHmac`,
  `AntiRejeuWebhook`), une déduplication `UNIQUE(psp, evenement_id)`, mises en place pour un premier
  prestataire de carte (P5.4a, `StatutCarte`).

Le prompt d'intégration GeniusPay (v2), écrit **avant** la Phase 0 de ce service, proposait un second
port (`initier`/`consulterStatut`/`verifierSignature`), une seconde machine à états en français, et
un second endpoint/table de webhook. Les trois auraient dupliqué une abstraction qui existe déjà et
fonctionne.

## 2. Décision

### B3 — Pas de second port, pas de seconde machine

GeniusPay est une **implémentation supplémentaire** de `PasserellePaiement`, enregistrée dans
`RegistrePasserelles` comme n'importe quel autre prestataire. La machine à états partagée n'est
**jamais modifiée**.

Un sous-état backend-only, `StatutGeniusPay`, est introduit sur le modèle de `StatutCarte`, projeté
sur le `PaiementStatut` partagé par un `switch` Java **exhaustif et sans `default`** :

| `StatutGeniusPay` | `PaiementStatut` partagé |
|---|---|
| `INITIEE` | `INITIATED` |
| `INITIEE_INCERTAINE` | `INITIATED` (jamais `PENDING` — la transaction peut ne pas exister chez GeniusPay) |
| `EN_ATTENTE` | `PENDING` |
| `EN_COURS` | `PROCESSING` |
| `REUSSIE` | `SUCCESS` |
| `ECHOUEE` | `FAILED` |
| `ANNULEE` | `CANCELLED` |
| `EXPIREE` | `FAILED` (le détail « expiré » reste en base pour la réconciliation, jamais exposé au patient tel quel) |
| `REMBOURSEE` | `REFUNDED` |

### B6 — Pas de seconde table d'événements

La table existante (P5.4a) est **étendue par migration additive** avec un discriminant `psp`, plutôt
que dupliquée. `SignatureHmac`, `AntiRejeuWebhook` et la déduplication `UNIQUE(psp, evenement_id)`
sont réutilisés tels quels. La route suit la convention déjà en place
(`/api/v1/…-webhooks/{psp}`), jamais `POST /webhooks/geniuspay`.

### Complément propre au montage A (compte marchand par établissement)

Chaque établissement reçoit une **URL de rappel distincte**, portant un identifiant **opaque et
aléatoire** (`slug`) stocké dans `identifiants_marchand` — **jamais le `structure_id` en clair**, qui
énumérerait la liste des partenaires. Le `slug` **sélectionne** le secret candidat ; c'est la
vérification HMAC qui **décide**, jamais une boucle d'essai sur plusieurs secrets (coût O(n) et
oracle de temps offert à l'attaquant).

## 3. Conséquences

- Aucune fragmentation entre deux ports de paiement, deux machines à états, deux tables d'événements
  pour deux prestataires (carte, GeniusPay). Une intégration future suit le même patron.
- Le prochain développeur qui découvre `StatutGeniusPay` comprend, par ce document, pourquoi il
  existe et pourquoi il n'est pas fusionné avec `PaiementStatut`.
- **Le coût** : un `switch` de projection à maintenir à chaque nouvel état GeniusPay. Le choix
  explicite de « sans `default` » transforme un état non projeté en **échec de compilation** plutôt
  qu'en bug silencieux — ce coût est accepté.

## 4. Résultat des vérifications V1 et V2 (2026-08-28)

Exécutées avec les clés **sandbox** fournies par le propriétaire, selon le script du §5 de
l'arbitrage. Consignées ici parce que l'arbitrage l'exige : « le résultat est consigné dans l'ADR et
injecté par `GENIUSPAY_BASE_URL` ».

### V1 — quelle URL de base répond aux clés sandbox ?

`GET /api/v1/merchant/account`, trois mesures par base :

| Base | Code | Temps (3 appels) |
|---|---|---|
| `https://pay.genius.ci` | **200** ×3 | 2,25 s · 2,13 s · 1,76 s |
| `https://geniuspay.ci` | **200** ×3 | 0,77 s · 0,58 s · 0,85 s |

**Les deux répondent `200`.** La règle de départage de l'arbitrage s'applique sans discussion :
**`geniuspay.ci` est retenue**. L'observation la renforce sans la fonder — cette base répond environ
trois fois plus vite, et ses en-têtes confirment `Server: cloudflare`, l'argument de stabilité
invoqué par l'arbitrage.

**La valeur n'est écrite nulle part en dur** : elle sera injectée par `GENIUSPAY_BASE_URL` (§6.1 du
v2), comme le prévoit l'arbitrage. Aucune ligne de code GeniusPay n'existe encore à ce jour.

### V2 — existe-t-il un en-tête d'idempotence ?

**Non.** Les en-têtes bruts de `GET /account` sur la base retenue ne portent ni `Idempotency-Key`,
ni `X-Request-Id`, ni aucun identifiant de requête ou de corrélation. Ce que l'arbitrage annonçait
comme « confirmé » l'est désormais **avec des clés valides**, et non par déduction depuis une
réponse non authentifiée.

Conséquence : **`§7.4` s'applique intégralement, sans dérogation** — un `POST /payments` ne se
rejoue jamais, l'idempotence est entièrement à la charge du service.

### V3 — exécutée sur autorisation explicite du propriétaire

`POST /payments` portant un `webhook_url` : **accepté (`201`), et sans effet** — le champ n'est ni
repris dans la réponse, ni honoré. La conduite est donc celle que l'arbitrage avait prévue : on s'en
tient à la configuration du webhook **au niveau du compte**.

### Ce que V3 a révélé au-delà de sa question — écarts au contrat `§4.3`

Quatre écarts, tous constatés sur une réponse réelle, aucun supposé :

| Contrat annoncé (`§4.3` du v2) | Comportement observé |
|---|---|
| Référence au format `MTX-XXXXXXXXXX` | `SANDBOX_DYSVNOKRTVQ3RWTV` |
| `expires_at` à **24 h** | **30 minutes** (`00:12:37` → `00:42:37`) |
| `data.fees` et `data.net_amount` à la création | **absents** ; présents sur `GET /payments/{ref}` |
| `data.status` renseigné | **`null`** à la création |

Plus deux constats de structure : la réponse porte des champs non documentés (`scenario`, `gateway`,
`tokens_remaining`, un `id` entier distinct de la référence), et GeniusPay **fusionne ses propres
clés dans `metadata`** — notre `order_id` y survit intact, ce qui est le seul point qui compte.

**Conséquence de conception, et elle n'est pas cosmétique** : l'échéance est **celle que renvoie le
prestataire**, jamais un « maintenant + N heures » calculé chez nous. Une durée recopiée de la
documentation aurait fait tenir pour ouvert, pendant vingt-trois heures et demie, un lien déjà mort.

### Cinquième écart, trouvé en phase 6 sur un `payment.success` authentique

| Contrat annoncé | Comportement observé |
|---|---|
| `"amount": 10000.00` (nombre JSON) | `"amount": "15000.00"` (**chaîne**) |

Il ne s'agit pas d'un détail de sérialisation. Sur un nœud textuel, `JsonNode.decimalValue()` ne
parse rien et rend **zéro** : le contrôle de montant lisait « 0 contre 15000 » et refusait de solder
une facture pourtant réglée. **Le garde-fou a tenu** — il a classé l'événement en `ERREUR` et n'a
rien soldé, plutôt que de solder zéro — et la réconciliation par `GET /payments/{ref}` a rattrapé
derrière, parce que ce chemin-là désérialise vers `BigDecimal` et accepte donc la chaîne.

Deux enseignements, et le second est le plus utile. **Le premier** : deux chemins qui lisent la même
donnée de deux façons différentes finissent par diverger, et c'est le moins typé qui casse. **Le
second** : la lecture corrigée accepte nombre *et* chaîne, mais **ne rend jamais de valeur par
défaut** — une chaîne illisible lève et l'événement part en `ERREUR`. Rendre 0 « pour que la lecture
passe » aurait contourné par le bas le contrôle de divergence qui existe précisément pour empêcher
qu'une facture soit soldée sur un montant inventé.

Trouvé **au G2 live**, sur un paiement réellement effectué sur la page de checkout, jamais par
relecture ni par les 273 tests — dont le payload de référence portait `10000.00` en nombre, tel que
la documentation le montre.

### Sixième écart : le webhook et l'API ne nomment pas le canal de la même façon

| `GET /payments/{ref}` | Webhook `payment.success` |
|---|---|
| `payment_provider` / `payment_method` | **`gateway`** (`"gateway":"wave"`) |

Nous ne lisions que les deux premiers : le canal restait **nul** jusqu'au passage de la
réconciliation, alors que l'événement le portait. Les trois noms sont désormais lus. Le fait notable
n'est pas le nom manquant, c'est que **deux représentations du même objet, chez le même prestataire,
emploient deux vocabulaires** — l'adaptateur est le seul endroit qui doit le savoir, et c'est
précisément pour cela qu'il existe.

### Ce que le webhook ne porte pas, et la conséquence qui n'est pas réparée

Le `payment.success` ne contient **ni `fees` ni `net_amount`** ; seul `GET /payments/{ref}` les
porte. Or la réconciliation **ne revisite jamais une transaction terminale** (`rattraper` sort si
`estTerminal()`, et le balayage ne sélectionne que `EN_ATTENTE`/`EN_COURS`).

**Conséquence, constatée et non corrigée** : une transaction soldée par le webhook — c'est-à-dire le
chemin nominal — n'a **jamais** ses frais renseignés. Le premier paiement de la phase 6 les avait,
uniquement parce que son webhook avait échoué et que la réconciliation avait pris le relais.

Ce n'est pas réparé maintenant, pour une raison de conception et non de temps : appeler
`GET /payments/{ref}` pendant le traitement du webhook mettrait **un appel sortant dans la
transaction qui solde une facture**, ce que ce projet s'interdit depuis P7-D1 (« un tiers n'a jamais
le droit de mettre en péril l'écriture »). La forme correcte est une **passe de complétion séparée**,
sur les transactions terminales aux frais absents — c'est une fonctionnalité, pas une validation, et
elle n'avait pas à être improvisée en fin de session de test.

En attendant, le reçu ne montre **rien** plutôt qu'un montant faux : les frais ne se recalculent
jamais chez nous (§5). La dégradation va dans le sens sûr, et elle est dite.

### Un endpoint documenté qui ne fonctionne pas

`GET /api/v1/merchant/payments` (liste paginée) répond **`500`** à chaque appel, avec ou sans
paramètres, clés valides. `GET /api/v1/merchant/payments/{reference}` fonctionne, lui, et porte bien
`fees`, `net_amount`, `status` et `metadata`.

C'est le **chemin de secours du `§7.4.b`** — le balayage qui lève une incertitude quand aucun webhook
n'arrive — qui repose dessus. Il est écrit conformément au contrat et éprouvé par simulation, mais
**il n'a pas pu être prouvé contre le prestataire**. Tant qu'il reste en panne, la levée
d'incertitude repose sur le webhook seul, et l'échéance d'abandon fait le reste : la transaction est
déclarée échue et la facture retourne à la charge du partenaire. Autrement dit, la dégradation va
dans le sens sûr — aucune facture n'est soldée sur une hypothèse — mais elle est réelle et elle est
dite.

---

## 5. Ce que l'implémentation a ajouté à cette décision (2026-08-28)

Le lot 7 a été écrit, et cinq choix ont dû être tranchés que l'ADR n'avait pas prévus. Ils sont
consignés ici parce qu'ils prolongent B3 et B6 plutôt que de s'y substituer.

**Le canal s'appelle `geniuspay`, pas `orange_money`.** `AdaptateurSimule` revendique déjà les canaux
opérateurs. Les revendiquer aussi aurait fait dépendre le choix de passerelle de l'ordre d'injection
des beans — donc du hasard. Mais l'argument décisif est ailleurs : nous n'appelons pas Orange Money,
nous ouvrons une page de checkout hébergée où **le patient** choisit son opérateur. L'opérateur
réellement utilisé nous revient dans `payment_provider` et c'est lui qui est enregistré. Le canal
demandé dit « par où l'on passe », pas « qui encaisse ».

**Aucune table `transaction_paiement` autonome.** `payments` porte déjà montant, devise, canal,
téléphone masqué, établissement, statut partagé et verrou de version. La table satellite
`geniuspay_transactions` ne porte **que** ce que `payments` ne sait pas dire — c'est le motif de
`carte_transactions` (P5.4a), repris et non réinventé. Les recopier aurait produit deux vérités sur
les mêmes faits, et l'écart aurait porté sur un montant.

**L'index « une facture, un checkout réussi » vit sur la table satellite, pas sur `payments`.** Un
index partiel global `payments (facture_id) WHERE statut = 'SUCCESS'` aurait interdit un second
règlement **partiel** — or `FactureStatut.PARTIELLEMENT_PAYEE` existe et le cumul est un cas
légitime (P5.2a). La garantie vaut donc là où elle est vraie : un checkout GeniusPay solde une
facture entière (D6), jamais une ligne.

**Deux ajouts strictement additifs au port.** `RequetePaiement` gagne `etablissementRef` et
`factureId` (le montage A impose de savoir **pour le compte de qui** on encaisse avant de choisir une
clé) ; `ResultatPaiement` gagne un `DetailCheckout` facultatif (sans lui, le port n'aurait pas pu
porter le seul élément que le patient doit réellement recevoir). Dans les deux cas le constructeur
d'origine subsiste, et **aucune passerelle antérieure n'a été modifiée**.

**La table d'événements garde son nom, et c'est une dette dite.** `carte_evenements_webhook` porte
désormais aussi les événements GeniusPay ; son nom ne le dit pas. La renommer imposait de toucher
`ServiceCarte` et son test — un module validé G5 — pour un gain purement cosmétique, hors du
périmètre déclaré. Le fait qui compte est porté par la colonne `psp`, présente depuis l'origine.

### Ce que le G2 live a corrigé, et que la relecture n'avait pas vu

Cinq défauts, chacun trouvé par un moyen différent, aucun par relecture :

1. **L'AAD a attrapé ma propre erreur.** `IdentifiantMarchand` portait `@UuidGenerator` : Hibernate
   posait un identifiant **différent** de celui utilisé dans l'AAD au chiffrement. Le déchiffrement
   échouait — c'est-à-dire que le mécanisme conçu pour empêcher de transplanter la clé d'un marchand
   vers un autre a refusé une clé mal liée, la mienne. `DestinationReversement` (P5.5b-1) faisait
   déjà juste : le précédent existait dans le projet, je ne l'avais pas suivi.
2. **Un refus métier remontait en `500`.** `PaiementEnLigneIndisponibleException` n'était mappée
   nulle part : le partenaire aurait lu « le service est cassé » là où il fallait lire « payez au
   guichet », et aurait réessayé.
3. **Un événement en échec se rejouait indéfiniment**, toutes les cinq secondes, noyant le journal.
   Il est désormais classé `ERREUR` : un incident consultable au lieu d'un bruit.
4. **Une facture inexistante n'était détectée qu'à l'arrivée du webhook**, plusieurs minutes après
   l'ouverture du checkout — alors que le patient avait déjà le lien. Migration V18 : le moteur
   refuse maintenant à l'**initiation**, là où l'appelant peut encore corriger.
5. **Le webhook ne soldait pas la facture locale.** La transaction passait à `REUSSIE`, le paiement à
   `SUCCESS`, et la facture restait `EMISE` : GeniusPay aurait été le **seul** canal à encaisser sans
   solder ce qu'il encaisse, là où la carte, le wallet et le mobile money le font tous.

Deux défauts de plus ont été trouvés **par les tests**, avant le G2 : la lecture du corps avant le
statut rendait tout corps d'erreur illisible (`HttpURLConnection` ne livre `getErrorStream()` qu'une
fois le code lu), et `SimpleClientHttpRequestFactory` traite **401 à part** — un jeu de clés invalide
aurait été classé « incertain », donc sans rejeu et bloqué, au lieu d'échouer franchement.

---

## 6. Ce que cet ADR ne décide pas

- Le **contenu** de l'intégration elle-même (adaptateur, webhook, migration) : c'est le lot 7.
- Le **canal interne** Laravel ⇄ Java : traité par le lot 6 ([[ADR-013]], principal signé), pas ici.
- La **sortie des secrets en clair** de `docker-compose.yml` (point B4) : commit isolé, préalable au
  lot 7, délibérément distinct de cette décision d'architecture.
