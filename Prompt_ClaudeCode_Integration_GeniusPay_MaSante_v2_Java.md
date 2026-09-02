# PROMPT CLAUDE CODE — INTÉGRATION GENIUSPAY & WEBHOOK
## Projet MaSanté — Microservice `paiement-service` (Java / Spring Boot)
### Version 2.0 — remplace la version Laravel

> ⚠️ **NE PAS EXÉCUTER TANT QUE LES QUATRE CONDITIONS SUIVANTES NE SONT PAS RÉUNIES :**
> 1. Les tables de facturation Laravel existent (`Prompt_ClaudeCode_Tables_Facturation_MaSante.md` exécuté et validé).
> 2. L'incohérence **PostgreSQL / MySQL** est tranchée.
> 3. Les points d'arbitrage A1–A5 du modèle économique sont validés par l'encadreur, **ainsi que le point A6 ci-dessous (garde des clés marchandes)**.
> 4. Les clés sandbox ont été **régénérées** (les précédentes ont circulé hors coffre).
>
> **Le module 1 (triage) doit rester intact.**

---

## POINT D'ARBITRAGE A6 — À TRANCHER AVANT CE PROMPT

Le montage A (un compte marchand GeniusPay par établissement) implique que le `paiement-service` **détienne la clé secrète `sk_` de chaque partenaire**. C'est une garde de secrets appartenant à des tiers, avec la responsabilité juridique correspondante. L'alternative — MaSanté seul marchand, puis reversements — ferait de MaSanté un dépositaire de fonds et invaliderait l'argument de non-assujettissement à l'agrément BCEAO.

**Ce prompt suppose le montage A retenu** et impose en conséquence un chiffrement enveloppe des clés marchandes (§6.2). Si l'encadreur tranche autrement, le prompt doit être réécrit.

---

## COPIER À PARTIR D'ICI

---

Tu interviens sur le **microservice `paiement-service` de MaSanté**, écrit en **Java / Spring Boot**, déjà existant. Il est distinct du backend Laravel (métier, facturation) et du projet Expo (mobile). Ta mission : y intégrer le prestataire de paiement **GeniusPay** — initiation de paiement, réception et traitement du webhook, réconciliation, restitution des frais réels au backend Laravel.

Discipline stricte : **Phase 0 d'audit obligatoire avant toute écriture**, une phase à la fois, arrêt et rapport à la fin de chaque phase, aucune modification hors périmètre.

---

## 1. INTERDICTIONS ABSOLUES

1. **N'écris aucun fichier avant d'avoir terminé la Phase 0 et rendu ton rapport.** Tu t'arrêtes et tu attends ma validation explicite.
2. **N'écris jamais une clé, un secret ou un identifiant marchand dans un fichier versionné** — ni dans le code, ni dans un test, ni dans `application.properties`, ni dans un commentaire, ni dans une fixture. Les seuls marqueurs autorisés dans `application-example.properties` sont vides ou du type `pk_sandbox_XXXXXXXX`.
3. **Ne me demande jamais les clés** et ne les affiche jamais dans ta sortie. Pour vérifier qu'une variable est renseignée, teste sa présence, jamais sa valeur.
4. **N'ajoute aucune dépendance Maven/Gradle** sans me le signaler et t'arrêter. Spring Web, Spring Data JPA, Jackson, Validation, Spring Retry et le driver de base suffisent — vérifie-le en Phase 0 avant de l'affirmer.
5. **Ne rejoue jamais un `POST /payments`.** Voir §7.4 — c'est la règle la plus importante de ce lot.
6. **Ne modifie aucune migration existante.** Toute évolution passe par une nouvelle migration Flyway/Liquibase (outil identifié en Phase 0).
7. **Aucune donnée médicale ne quitte le service.** Ni libellé d'acte, ni code d'acte, ni spécialité, ni nom de service hospitalier ne doit apparaître dans une requête vers GeniusPay, dans un log, ou dans un message d'erreur HTTP. Le champ `description` envoyé à GeniusPay est un libellé **générique et constant**.
8. **MaSanté n'est jamais dépositaire des fonds.** Tu n'implémentes aucun payout, aucun cashout, aucun wallet interne, aucun reversement. Les endpoints `/payouts` et `/account/balance` de GeniusPay sont **hors périmètre**.
9. **Jamais de `double` ou de `float` pour un montant.** Voir §3.
10. **Ne touche à rien dans le projet Laravel ni dans le projet Expo.**

---

## 2. DÉCISIONS D'ARCHITECTURE FIGÉES

| # | Décision | Conséquence |
|---|---|---|
| D1 | Le `paiement-service` Java est le **contexte borné du paiement**. Il possède les transactions et les événements webhook. | Laravel ne parle jamais à GeniusPay directement. |
| D2 | Laravel possède les **factures, commissions, abonnements**. | Le service Java ne crée jamais de facture et ne calcule jamais de commission. Il **restitue les frais réels** à Laravel, qui calcule. |
| D3 | Le code métier ne connaît jamais GeniusPay : il connaît l'interface `PasserellePaiement`. | Toute mention de `geniuspay` hors du package `…adapter.geniuspay` et de la configuration est un défaut. |
| D4 | **Montage A** : un compte marchand par établissement. | Le service détient et chiffre les clés marchandes de chaque partenaire (§6.2). |
| D5 | Le **webhook est la seule source de vérité** du statut. | Ni la réponse d'initiation, ni le retour de l'utilisateur sur `success_url` ne confirment un paiement. `success_url` sert uniquement à l'expérience utilisateur. |
| D6 | Une facture = **une seule transaction**, jamais ligne par ligne. | Le panier est agrégé avant l'appel. |
| D7 | **Sandbox uniquement.** | `geniuspay.environment=sandbox` est la seule valeur acceptée ; toute autre lève une exception au démarrage. |

---

## 3. CONVENTIONS OBLIGATOIRES

**Monnaie.** Le XOF n'a pas de sous-unité. En base et en mémoire, les montants sont des **`long` en francs entiers**. En Java, jamais `double`, jamais `float`.

> ⚠️ **Piège documenté.** GeniusPay renvoie les montants en JSON avec décimales : `"amount": 10000.00`, `"fees": 250.00`. Tu les désérialises en **`BigDecimal`** (jamais en `double`), tu vérifies que la partie décimale est nulle (`stripTrailingZeros().scale() <= 0`), puis tu convertis en `long`. Une partie décimale non nulle est une **anomalie bloquante** : l'événement passe en `ERREUR` et n'est pas traité.

**Énumérations.** Enums Java, colonnes `VARCHAR` en base via `@Enumerated(EnumType.STRING)`. Jamais `ORDINAL` — un réordonnancement de l'enum corromprait silencieusement l'historique financier.

**Immutabilité financière.** Aucune suppression. Un enregistrement change de statut.

**Fuseau.** Tout en UTC (`Instant`). Abidjan est UTC+0 sans heure d'été, mais l'explicitation évite l'ambiguïté.

**Nommage.** Suit la convention **déjà en place dans le service**, relevée en Phase 0, jamais supposée.

---

## 4. PHASE 0 — AUDIT (obligatoire, aucune écriture)

### 4.1 Le service Java existant

1. **Chemin absolu** du `paiement-service`. Confirme que ce n'est ni Laravel ni Expo.
2. **Maven ou Gradle ?** Version de Spring Boot, version de Java (`java -version`).
3. **Dépendances déjà présentes** : Spring Web, Data JPA, Validation, Security, Retry, Actuator, Jackson, driver de base, outil de migration. Liste exhaustive.
4. **Base de données** du service : moteur, nom du schéma. Est-elle **distincte** de la base Laravel ou partagée ? *(Une base partagée contredirait Rule-001 — signale-le si c'est le cas.)*
5. **Outil de migration** : Flyway, Liquibase, ou `ddl-auto` ? *(Si `ddl-auto=update`, c'est un point bloquant : signale-le.)*
6. **Ce qui existe déjà** : liste les packages, les entités JPA, les contrôleurs, les services. **Y a-t-il déjà une notion de transaction de paiement, un adaptateur de passerelle, un endpoint webhook ?** Donne les noms exacts.
7. **Comment Laravel appelle-t-il ce service aujourd'hui ?** REST ? File de messages ? Rien du tout ? Quelle authentification entre les deux ?
8. **Sécurité** : Spring Security est-il configuré ? Quelle chaîne de filtres ? Comment un appel interne est-il authentifié ?
9. **Asynchrone** : y a-t-il `@Async`, un `TaskExecutor`, une file (RabbitMQ, Kafka, Redis) ? De quoi dispose-t-on pour traiter un webhook hors du fil de la requête ?
10. **Journalisation** : quel framework, quel format, y a-t-il déjà un masquage de données sensibles ?
11. **Tests** : JUnit 5 ? Testcontainers ? WireMock ou MockWebServer disponible pour simuler une API HTTP ?
12. **Configuration** : `application.properties` ou `.yml` ? Les profils `sandbox`/`live` existent-ils ? Le fichier de configuration est-il versionné, et contient-il déjà des secrets ? *(Si oui : point bloquant.)*

### 4.2 Le contrat GeniusPay — vérifications à faire, pas à inventer

Le contrat ci-dessous est **établi** à partir de la documentation officielle (`https://pay.genius.ci/doc`) et du guide d'intégration webhook fourni. Tu ne le redécouvres pas : tu **vérifies** les trois points suivants et tu rapportes.

**V1. Quelle URL de base correspond à mes clés sandbox ?** La documentation indique `https://pay.genius.ci/api/v1/merchant`, mais l'outil de test GeniusPay distingue une « old_Version » (`pay.genius.ci`) et une « new_Version » (`geniuspay.ci`). Appelle `GET /account` avec les clés sandbox sur chacune des deux bases et rapporte laquelle répond `200`. **Ne code rien avant d'avoir la réponse.**

**V2. Existe-t-il un en-tête d'idempotence non documenté ?** Inspecte la réponse de `GET /account` : GeniusPay renvoie-t-il un en-tête du type `Idempotency-Key`, `X-Request-Id` ? Rapporte les en-têtes de réponse bruts. *(Attendu : non. Si c'est confirmé, §7.4 s'applique intégralement.)*

**V3. Le webhook est-il configurable par transaction ?** La documentation ne prévoit qu'une configuration au niveau du compte (`POST /webhooks` avec une liste d'événements). Confirme qu'aucun paramètre `webhook_url` n'est accepté sur `POST /payments`. Rapporte le comportement observé.

### 4.3 Contrat de référence (à recopier tel quel dans le code, après vérification V1)

**Authentification sortante** — en-têtes sur chaque requête :

| En-tête | Valeur |
|---|---|
| `X-API-Key` | clé publique `pk_sandbox_…` |
| `X-API-Secret` | clé secrète `sk_sandbox_…` |
| `Content-Type` | `application/json` |

**Exemple canonique de la documentation GeniusPay** (mode checkout, celui que nous retenons) :

```php
// Intégration en 2 lignes
$response = Http::withHeaders([
    'X-API-Key' => 'pk_live_xxx',
    'X-API-Secret' => 'sk_live_xxx',
])->post('https://pay.genius.ci/api/v1/merchant/payments', [
    'amount' => 15000,
    'description' => 'Commande #123',
]);
return redirect($response['data']['checkout_url']);
```

> ⚠️ **Cet exemple est une démonstration commerciale, pas un modèle de production.** Il n'a ni `metadata.order_id` (donc aucun moyen de relier le webhook à une facture), ni `success_url`, ni délai d'expiration, ni gestion d'erreur, ni journalisation. Tu t'en inspires pour le **contrat** (URL, en-têtes, forme du corps, chemin `data.checkout_url`), **jamais pour la structure du code**. Ton implémentation Java doit ajouter les cinq éléments manquants. Reproduis-le en commentaire d'en-tête de l'adaptateur, avec cet avertissement, pour que le prochain développeur ne le recopie pas naïvement.

**`POST /api/v1/merchant/payments`** — corps :

| Champ | Type | Requis | Valeur retenue par MaSanté |
|---|---|:-:|---|
| `amount` | number | oui | montant entier en XOF, **minimum 200** |
| `currency` | string | — | `XOF` |
| `payment_method` | string | — | **omis volontairement** → page de checkout GeniusPay, le patient choisit |
| `description` | string | — | libellé **générique** : `"Règlement MaSanté"`. Jamais l'acte. |
| `customer.name` | string | — | nom du titulaire du compte |
| `customer.phone` | string | — | format international `+225…` |
| `customer.country` | string | — | `CI` |
| `success_url` | string | — | écran de confirmation applicatif — **n'authentifie rien** |
| `error_url` | string | — | écran d'échec applicatif |
| `metadata.order_id` | string | — | **`referenceInterne` MaSanté — champ vital, jamais omis** |
| `metadata.structure_id` | string | — | identifiant de l'établissement |

Réponse `201` : `data.reference` (format `MTX-XXXXXXXXXX`), `data.checkout_url`, `data.status`, `data.expires_at` (**24 h**), `data.fees`, `data.net_amount`.

**`GET /api/v1/merchant/payments/{reference}`** — consultation par référence GeniusPay. Renvoie `status`, `amount`, `fees`, `net_amount`, `metadata`, `completed_at`.

**`GET /api/v1/merchant/payments?from=&to=&status=&per_page=`** — liste paginée. **La réponse de liste ne contient pas `metadata`** : pour retrouver une transaction par `order_id`, il faut lister puis interroger chaque référence individuellement. Contrainte structurante, voir §7.4.

**Statuts** : `pending`, `processing`, `completed`, `failed`, `cancelled`, `refunded`, `expired`.

**Erreurs** : `MISSING_API_KEY` (401), `INVALID_API_KEY` (401), `MERCHANT_INACTIVE` (403), `PAYMENT_INIT_FAILED` (400), `TRANSACTION_NOT_FOUND` (404), `VALIDATION_ERROR` (422), `COUNTRY_NOT_SUPPORTED` (404). **Aucun n'est ré-essayable sur une initiation.**

**Webhook — en-têtes** : `X-Webhook-Signature`, `X-Webhook-Timestamp`, `X-Webhook-Event`, `X-Webhook-Environment`, `X-Webhook-Delivery` (optionnel), `X-Webhook-Retry` (optionnel).

**Webhook — signature** :
```
signature = HMAC-SHA256(timestamp + "." + corps_brut, whsec_…)
```

**Webhook — renvois en cas de 5xx ou timeout** : 5 tentatives — immédiat, 5 min, 30 min, 2 h, 6 h. Délai de réponse attendu : **moins de 10 secondes**.

**Webhook — événements retenus** : `payment.success`, `payment.failed`, `payment.cancelled`, `payment.expired`, `payment.refunded`, `webhook.test`. Les événements `cashout.*` sont **ignorés** (hors périmètre, D-8).

### 4.4 Rapport attendu

Les 12 réponses de §4.1, les 3 vérifications de §4.2, une section **« Points bloquants »**, et une section **« Écarts entre la documentation et le comportement observé »**. Puis tu t'arrêtes.

---

## 5. PHASE 1 — MODÈLE DE DONNÉES

Migrations versionnées (outil identifié en Phase 0). Deux tables.

### `transaction_paiement`

```
id                        UUID, PK
reference_interne         VARCHAR, UNIQUE NOT NULL   -- MS-{structure}-{ULID}, envoyée en metadata.order_id
reference_passerelle      VARCHAR, UNIQUE NULL       -- MTX-XXXXXXXXXX
facture_id                BIGINT NOT NULL            -- identifiant de la facture côté Laravel
structure_id              BIGINT NOT NULL
montant                   BIGINT NOT NULL            -- francs entiers
devise                    CHAR(3) NOT NULL DEFAULT 'XOF'
statut                    VARCHAR NOT NULL           -- voir machine à états §8.3
canal                     VARCHAR NULL               -- wave | orange_money | mtn_money | moov_money | card | …
frais_passerelle          BIGINT NULL                -- champ `fees` renvoyé par GeniusPay
montant_net               BIGINT NULL                -- champ `net_amount` renvoyé par GeniusPay
checkout_url              TEXT NULL
expire_le                 TIMESTAMPTZ NULL           -- expires_at, 24 h
code_erreur               VARCHAR NULL
telephone_masque          VARCHAR NULL               -- 4 derniers chiffres uniquement
initiee_le                TIMESTAMPTZ NOT NULL
finalisee_le              TIMESTAMPTZ NULL
version                   BIGINT NOT NULL            -- @Version, verrouillage optimiste
cree_le, maj_le           TIMESTAMPTZ

INDEX (structure_id, initiee_le)
INDEX (statut, initiee_le)
UNIQUE INDEX partiel sur (facture_id) WHERE statut = 'REUSSIE'
```

> L'index unique partiel garantit qu'**une facture ne peut jamais avoir deux transactions réussies**, tout en autorisant plusieurs tentatives échouées. Sur PostgreSQL : `CREATE UNIQUE INDEX … WHERE statut = 'REUSSIE'`. Sur MySQL : colonne générée. Documente le choix en commentaire de migration.

> `telephone_masque` : jamais le numéro complet. `+225********56`.

### `evenement_webhook`

```
id                        UUID, PK
identifiant_evenement     VARCHAR, UNIQUE NOT NULL   -- champ `id` du payload (UUID GeniusPay)
empreinte_corps           CHAR(64) NOT NULL          -- SHA-256 du corps brut, filet de secours
type_evenement            VARCHAR NOT NULL
reference_passerelle      VARCHAR NULL, INDEX
environnement             VARCHAR NOT NULL           -- sandbox | live
horodatage_declare        BIGINT NULL
signature_valide          BOOLEAN NOT NULL
statut_traitement         VARCHAR NOT NULL           -- RECU | TRAITE | REJETE_SIGNATURE | REJETE_HORODATAGE | REJETE_ENVIRONNEMENT | IGNORE_DOUBLON | IGNORE_NON_GERE | ERREUR
motif_rejet               VARCHAR NULL
numero_tentative          INT NULL                   -- X-Webhook-Retry
corps_brut                TEXT NOT NULL              -- intégral, tel que reçu
adresse_ip                VARCHAR NULL
recu_le                   TIMESTAMPTZ NOT NULL
traite_le                 TIMESTAMPTZ NULL

INDEX (statut_traitement, recu_le)
```

> **Deux clés d'idempotence, pas une.** `identifiant_evenement` est la clé primaire fonctionnelle : c'est le champ `id` du payload, un UUID stable à travers les 5 tentatives de renvoi. `empreinte_corps` est le filet de secours si ce champ venait à manquer. Ne te repose **pas** sur `X-Webhook-Delivery` : la documentation le donne pour optionnel.

**Arrêt et rapport après validation des migrations en `--dry-run`.**

---

## 6. PHASE 2 — CONFIGURATION ET SECRETS

### 6.1 Propriétés du service

```properties
geniuspay.environment=sandbox
geniuspay.base-url=${GENIUSPAY_BASE_URL}
geniuspay.timeout-connexion-ms=5000
geniuspay.timeout-lecture-ms=15000
geniuspay.montant-minimum=200
geniuspay.fenetre-antirejeu-secondes=300
geniuspay.expiration-checkout-heures=24

paiement.plancher-en-ligne-fcfa=5000
paiement.libelle-generique=Règlement MaSanté
```

Classe `@ConfigurationProperties` avec validation (`@NotBlank`, `@Positive`). Garde-fou au démarrage (`@PostConstruct`) : si `environment != "sandbox"`, lever une `IllegalStateException` explicite.

**Le plancher de 5 000 FCFA est paramétrable, jamais en dur** (règle R5b).

### 6.2 Garde des clés marchandes — le point sensible

Le montage A implique de stocker la clé `sk_` de chaque établissement partenaire. Règles :

1. Table `identifiants_marchand` : `structure_id`, `cle_publique` (en clair, elle est publique), `cle_secrete_chiffree` (blob), `secret_webhook_chiffre` (blob), `date_rotation`, `actif`.
2. **Chiffrement enveloppe** : chaque secret est chiffré en AES-256-GCM avec une clé de données, elle-même chiffrée par une clé maître fournie par variable d'environnement (`MASANTE_KMS_KEY`) — et, à terme, par un KMS/HSM. Écris l'abstraction `GestionnaireSecrets` de sorte que le passage à un KMS ne touche aucun code appelant.
3. **Aucun secret n'est jamais journalisé, ni renvoyé par une API, ni inclus dans un message d'exception.** Écris un `toString()` explicite sur toute classe qui en porte un, retournant `"***"`.
4. Le secret webhook `whsec_` n'est renvoyé par GeniusPay **qu'à la création du webhook**. Documente-le : le perdre impose de recréer le webhook.
5. `application.properties` ne contient aucune valeur réelle. Tu m'indiques les variables d'environnement à définir ; je les renseigne moi-même.
6. Vérifie que le fichier de configuration réel est bien dans `.gitignore`. Sinon : **point bloquant**.

**Arrêt et rapport.**

---

## 7. PHASE 3 — ADAPTATEUR ET INITIATION

### 7.1 Structure de packages imposée

```
com.masante.paiement
├── domaine/          Transaction, StatutTransaction, MachineEtats (aucune dépendance Spring)
├── application/      ServicePaiement, ServiceReconciliation, ServiceWebhook
├── port/             PasserellePaiement (interface), NotificateurFacturation (interface)
└── infrastructure/
    ├── adapter/geniuspay/   ClientGeniusPay, MappeurStatutGeniusPay, VerificateurSignature
    ├── web/                 ControleurPaiement, ControleurWebhookGeniusPay
    └── persistence/         entités JPA et repositories
```

Le package `domaine` ne connaît **ni Spring, ni JPA, ni Jackson, ni GeniusPay** (Rule-002).

### 7.2 Le port `PasserellePaiement`

```java
ReponseInitiation initier(DemandeInitiation demande);
EtatTransaction consulterStatut(String referencePasserelle);
boolean verifierSignature(String corpsBrut, String horodatage, String signature, String secret);
```

Les DTO sont des `record` immuables, sans annotation Jackson, sans champ médical.

### 7.3 Le client HTTP

- `RestClient` (Spring 6) ou `WebClient`, avec **délais explicites** issus de la configuration. Jamais de client sans timeout.
- Toute réponse hors 2xx est traduite en exception typée portant le `error.code` de GeniusPay. Aucun `catch (Exception e) {}` vide.
- **Journalisation** : méthode, chemin, `referenceInterne`, code HTTP, durée. **Jamais** le corps, **jamais** les en-têtes d'authentification, **jamais** le numéro du payeur.
- `MappeurStatutGeniusPay` est le **seul** endroit qui connaît les chaînes `pending`, `completed`, etc. Un statut inconnu ne vaut **jamais** succès : il est journalisé et laisse la transaction en `EN_COURS`.

### 7.4 La règle du non-rejeu — à lire deux fois

**GeniusPay n'offre aucune clé d'idempotence sur `POST /payments`, et sa recherche ne porte pas sur `metadata.order_id`.** Deux conséquences non négociables :

**a) Un `POST /payments` ne se rejoue jamais.** Ni `@Retryable`, ni boucle, ni rejeu manuel. Un délai dépassé ou une erreur réseau laisse la transaction en `INITIEE_INCERTAINE`. Rejouer, c'est risquer deux transactions GeniusPay pour une facture — donc, potentiellement, deux débits sur un patient.

**b) La levée d'incertitude passe par deux chemins, dans cet ordre.**

*Chemin nominal — le webhook.* Si GeniusPay a bien créé la transaction, l'événement arrivera avec `metadata.order_id` = notre `referenceInterne`. Le traitement webhook doit donc savoir retrouver une transaction **par `referenceInterne`** et pas seulement par `referencePasserelle` — sans quoi une transaction incertaine ne serait jamais rattachée. C'est le cas d'usage qui justifie d'envoyer `metadata.order_id` sur **chaque** appel, sans exception.

*Chemin de secours — le balayage.* Si aucun webhook n'arrive dans les 15 minutes : `GET /payments?from={jour}&per_page=100`, puis `GET /payments/{reference}` sur chaque candidat non encore rattaché, jusqu'à trouver `metadata.order_id` correspondant. Coûteux, donc plafonné (200 consultations par exécution) et exécuté hors du fil de la requête.

*Si les deux chemins échouent après 24 h* : la transaction passe à `EXPIREE`, la facture est remise à `A_REGLER` côté Laravel, et une alerte est levée. **Aucune facture n'est marquée payée sur une hypothèse.**

> Écris ce raisonnement en Javadoc sur la méthode d'initiation. C'est exactement le genre de règle qu'un développeur pressé casse en ajoutant un `@Retryable` « pour la robustesse ».

### 7.5 `ServicePaiement.initierPourFacture(...)`

Séquence, en transaction de base :

1. Refuser si le montant est sous `paiement.plancher-en-ligne-fcfa` (R17) — le paiement sur place reste possible.
2. Refuser si le montant est sous `geniuspay.montant-minimum` (200 XOF, contrainte GeniusPay).
3. Si une transaction `REUSSIE`, `INITIEE`, `INITIEE_INCERTAINE` ou `EN_COURS` existe pour cette facture, **retourner l'existante**. Si son `checkout_url` n'a pas expiré, la réutiliser.
4. Vérifier que l'établissement a des identifiants marchands actifs. Sinon, exception explicite.
5. Générer `referenceInterne` : `MS-{structureId}-{ULID}`, unique à vie, jamais réutilisée.
6. Persister la transaction en `INITIEE` **avant** l'appel réseau.
7. Appeler `initier()`. Succès → enregistrer `reference`, `checkout_url`, `expires_at`, passer à `EN_ATTENTE`. Échec réseau → `INITIEE_INCERTAINE`, planifier la levée d'incertitude à +15 min. **Aucun second appel.**
8. Journal d'audit.

### 7.6 Endpoints internes

```
POST /interne/v1/paiements                       (appelé par Laravel)
GET  /interne/v1/paiements/{referenceInterne}
```

Authentifiés par un secret partagé ou mTLS entre Laravel et le service — mécanisme identifié en Phase 0, **jamais inventé**. Ces routes ne sont **pas** exposées publiquement.

**Arrêt et rapport.**

---

## 8. PHASE 4 — WEBHOOK

C'est le cœur du lot.

### 8.1 Endpoint

```
POST /webhooks/geniuspay
```

- Exclu de l'authentification applicative (la signature HMAC fait foi), mais **explicitement déclaré** dans la chaîne Spring Security — jamais par un `permitAll()` large qui ouvrirait d'autres routes.
- Le corps est lu en **`String` brut** via `@RequestBody String corpsBrut`. **Jamais** en `@RequestBody JsonNode` ni en DTO désérialisé pour le calcul de la signature.

> ⚠️ **Piège critique, et l'exemple PHP de la documentation officielle GeniusPay tombe dedans.** Il calcule la signature sur `json_encode($request->all())`, c'est-à-dire un ré-encodage du JSON décodé. Or le payload contient `"amount": 10000.00` : décodé puis ré-encodé, il devient `10000.0`. La chaîne diffère d'un octet, la signature échoue. L'exemple Java du guide d'intégration, lui, utilise correctement `rawBody` — c'est celui qui fait foi. **Signe et vérifie toujours sur les octets reçus.**

### 8.2 Vérification, dans cet ordre exact

1. Présence des en-têtes `X-Webhook-Signature`, `X-Webhook-Timestamp`, `X-Webhook-Event` → sinon `400`.
2. Calculer `HMAC-SHA256(horodatage + "." + corpsBrut, secretWebhook)`, comparer avec **`MessageDigest.isEqual()`**. Jamais `String.equals()` — la comparaison doit être à temps constant.
3. Signature invalide → enregistrer l'événement avec `REJETE_SIGNATURE`, répondre **`401`** avec un corps vide de détail. Un attaquant ne doit rien apprendre.
4. Horodatage : rejeter si l'écart avec l'heure serveur dépasse `fenetre-antirejeu-secondes` (300) → `REJETE_HORODATAGE`, `400`.
5. **Vérifier `X-Webhook-Environment` et le champ `environment` du payload.** S'ils ne valent pas `sandbox`, rejeter en `REJETE_ENVIRONNEMENT`. *Sans ce contrôle, un webhook live pourrait solder une facture de test, ou l'inverse.* La documentation ne le mentionne pas dans ses exemples — c'est un ajout délibéré de notre part.
6. **Enregistrer l'événement dans tous les cas**, y compris rejeté. Un rejet silencieux rend l'incident invisible.

### 8.3 Le contrôleur fait quatre choses, et rien d'autre

1. Extraire `id` du payload → tenter l'insertion dans `evenement_webhook`. **Conflit d'unicité → `IGNORE_DOUBLON`, répondre `200` immédiatement.** C'est l'idempotence.
2. Si `type_evenement` est un `cashout.*` ou un événement non géré → `IGNORE_NON_GERE`, `200`.
3. Si `webhook.test` → journaliser, `200`.
4. Sinon, publier l'événement pour traitement **asynchrone** (mécanisme identifié en Phase 0) et répondre **`200` en moins de 500 ms**.

> **Aucune logique métier dans le contrôleur.** GeniusPay attend une réponse en moins de 10 secondes et réessaie 5 fois sur 5xx ou délai dépassé. Un traitement synchrone lent produit des renvois concurrents — cause n°1 des doubles écritures en paiement.

### 8.4 Le traitement asynchrone

Verrouillage pessimiste sur la transaction (`SELECT … FOR UPDATE`), ou verrouillage optimiste avec rejeu sur `OptimisticLockException`. Séquence :

1. Retrouver la transaction : d'abord par `reference_passerelle`, **sinon par `metadata.order_id`** (cas §7.4). Introuvable → `ERREUR`, alerte, pas de rejeu infini.
2. **Contrôle du montant.** `data.amount` doit égaler `montant` au franc près, après conversion `BigDecimal` → `long`. Divergence ou décimale non nulle → `ERREUR`, alerte, **aucune facture soldée**. Un écart de montant est un incident, jamais une tolérance.
3. **Machine à états.** Écris-la explicitement dans le domaine :

```
INITIEE ─────────────► EN_ATTENTE ──► EN_COURS ──► REUSSIE ──► REMBOURSEE
   │                        │             │            
   └──► INITIEE_INCERTAINE ─┘             ├──► ECHOUEE   (terminal)
                                          ├──► ANNULEE   (terminal)
                                          └──► EXPIREE   (terminal)
```
Un état terminal ne se remplace jamais, **sauf** `REUSSIE → REMBOURSEE`. Toute transition interdite est journalisée et l'événement passe en `IGNORE_DOUBLON` — c'est le cas normal d'un renvoi tardif.

4. Sur `payment.success` : enregistrer `canal` (`data.provider`), `frais_passerelle` (`data.fees`), `montant_net` (`data.net_amount`), `finalisee_le`, puis notifier Laravel via `NotificateurFacturation` en transmettant **le montant, les frais réels et le net** — c'est Laravel qui solde la facture et calcule la commission (D2). La notification est elle-même idempotente côté Laravel, sur `referenceInterne`.
5. Sur `payment.failed` / `cancelled` / `expired` : statut correspondant, notifier Laravel pour remise en `A_REGLER`.
6. Marquer `TRAITE`.

> **Les frais viennent de GeniusPay, ils ne se recalculent pas.** La documentation renvoie `fees` et `net_amount` sur chaque transaction. Toute tentative de les reconstituer à partir de « 100 FCFA + 1 % » produira des écarts et cassera le reçu transparent promis aux partenaires.

### 8.5 Réconciliation

Tâche planifiée toutes les 10 minutes, protégée contre le chevauchement (`ShedLock` si présent, sinon verrou en base) :

- Transactions non terminales de plus de 5 minutes et de moins de 24 h avec `reference_passerelle` → `GET /payments/{reference}`.
- Transactions en `INITIEE_INCERTAINE` de plus de 15 minutes sans référence → balayage §7.4.b, plafonné.
- Au-delà de 24 h (durée de vie du lien de checkout) → `EXPIREE`, notification à Laravel, journalisation.
- **Le même `MachineEtats` que §8.4** est appliqué. Factorise-le : deux implémentations divergentes du même automate sont une bombe à retardement.

**Arrêt et rapport.**

---

## 9. PHASE 5 — TESTS

JUnit 5. **Aucun test ne touche le réseau** : WireMock ou MockWebServer.

### Initiation
1. `refuse_sous_le_plancher_metier` — 4 999 FCFA.
2. `refuse_sous_le_minimum_geniuspay` — 150 FCFA.
3. `initiation_unique_par_facture` — deux appels, une seule transaction, `checkout_url` réutilisée.
4. `timeout_ne_rejoue_jamais` — WireMock en délai dépassé : **exactement une** requête sortante, transaction en `INITIEE_INCERTAINE`.
5. `metadata_order_id_toujours_present` — inspecte le corps envoyé.
6. `aucune_donnee_medicale_sortante` — vérifie que `description` vaut le libellé générique et qu'aucun code d'acte n'apparaît.

### Webhook
7. `signature_valide_acceptee_200`.
8. `signature_invalide_rejetee_401` — et l'événement est enregistré en `REJETE_SIGNATURE`.
9. `signature_calculee_sur_corps_brut` — **le test qui compte** : payload contenant `"amount": 10000.00`, signature calculée par GeniusPay sur les octets exacts. Une implémentation qui ré-encode échoue ici. Ajoute un commentaire expliquant pourquoi ce test existe.
10. `horodatage_hors_fenetre_rejete` — 10 minutes.
11. `environnement_live_rejete_en_sandbox`.
12. `doublon_ignore` — même `id`, deux envois : une seule notification à Laravel.
13. `renvoi_apres_etat_terminal_ignore` — `payment.failed` après `payment.success`.
14. `montant_divergent_bloque` — facture non soldée, événement en `ERREUR`.
15. `montant_a_decimale_non_nulle_bloque` — `"amount": 10000.50`.
16. `reponse_sous_500ms` — le traitement est bien asynchrone.
17. `cashout_ignore_sans_erreur`.

### Réconciliation
18. `reconciliation_rattrape_webhook_manquant`.
19. `levee_incertitude_par_balayage` — transaction sans référence, retrouvée par `metadata.order_id`.
20. `expiration_apres_24h` — facture remise à `A_REGLER`.

### Sécurité
21. `aucun_secret_dans_les_logs` — provoque une erreur d'authentification et vérifie qu'aucune valeur `sk_` ni `whsec_` n'apparaît.
22. `tostring_masque_les_secrets`.

---

## 10. PHASE 6 — PLAN D'EXÉCUTION ET DE TEST DU WEBHOOK

À rédiger dans `docs/PLAN_TEST_WEBHOOK.md`, commandes prêtes à coller.

### Séquence de mise en place

| # | Action | Vérification |
|---|---|---|
| 1 | `ngrok http 8080` | URL HTTPS obtenue. **Elle change à chaque redémarrage** (offre gratuite). |
| 2 | Démarrer le service avec le profil sandbox | `GET /actuator/health` répond `UP` |
| 3 | Créer le webhook : `POST /api/v1/merchant/webhooks` avec l'URL Ngrok et la liste d'événements | **Noter immédiatement le `whsec_` : il n'est renvoyé qu'une fois** |
| 4 | Renseigner `whsec_` en variable d'environnement, redémarrer | — |
| 5 | Déclencher le test depuis le dashboard GeniusPay (icône éclair) | `webhook.test` reçu, `200`, événement en base |

### Scénarios de validation

| # | Scénario | Attendu |
|---|---|---|
| 1 | Initiation sur facture à 15 000 FCFA | `201`, `checkout_url`, statut `EN_ATTENTE` |
| 2 | Initiation à 3 000 FCFA | refus métier, message « paiement en ligne indisponible sous 5 000 FCFA » |
| 3 | Initiation à 150 FCFA | refus, minimum GeniusPay |
| 4 | Double initiation même facture | même `referenceInterne`, même `checkout_url`, une seule ligne |
| 5 | Paiement complété sur la page de checkout sandbox | webhook `payment.success`, facture soldée côté Laravel, `fees` et `net_amount` enregistrés |
| 6 | Rejeu à l'identique du webhook n°5 (curl) | `200`, `IGNORE_DOUBLON`, **aucune seconde notification à Laravel** |
| 7 | Webhook avec signature falsifiée | `401`, `REJETE_SIGNATURE`, facture inchangée |
| 8 | Webhook avec horodatage de 10 minutes | `400`, `REJETE_HORODATAGE` |
| 9 | Webhook `payment.success` avec `"amount": 14000.00` sur une facture de 15 000 | `ERREUR`, facture **non** soldée, alerte |
| 10 | Webhook avec `"environment": "live"` | `REJETE_ENVIRONNEMENT` |
| 11 | Ngrok coupé pendant un paiement, puis relancé + réconciliation | facture rattrapée sans webhook |
| 12 | Simulation d'un délai dépassé à l'initiation (WireMock) | `INITIEE_INCERTAINE`, **une seule** requête sortante, levée d'incertitude au balayage |

> Les scénarios **11 et 12 sont les plus importants du lot** : ils prouvent que le système survit à une coupure réseau, qui est la norme et non l'exception dans le contexte de déploiement visé. Ce sont eux à montrer au jury.

### Fin de session

Supprimer le webhook pointant vers l'URL Ngrok périmée (`DELETE /webhooks/{id}`). Une URL abandonnée pointant vers une machine tierce est une fuite de données.

---

## 11. CHECKLIST FINALE

- [ ] Les migrations passent dans les deux sens
- [ ] Aucune dépendance ajoutée sans validation
- [ ] `git grep -iE "pk_sandbox|sk_sandbox|whsec_"` ne retourne que des marqueurs d'exemple
- [ ] `git grep -i "geniuspay"` ne retourne que `infrastructure/adapter/geniuspay`, la configuration et les tests
- [ ] Aucun `@Retryable`, aucune boucle de rejeu sur le chemin d'initiation
- [ ] Le package `domaine` ne contient aucun import Spring, JPA, Jackson ou GeniusPay
- [ ] Les 22 tests passent
- [ ] Aucun fichier Laravel ni Expo dans `git status`
- [ ] `docs/PLAN_TEST_WEBHOOK.md` existe et ses 12 scénarios ont été exécutés

---

## 12. HORS PÉRIMÈTRE

- Payouts, cashouts, solde marchand, wallet interne — **contredisent D8**
- Remboursements (l'état `REMBOURSEE` existe, la logique de déclenchement non)
- Génération et prélèvement des factures partenaires mensuelles (Laravel)
- Calcul de commission (Laravel — ce service fournit seulement les frais réels)
- Notifications Firebase et leurs règles de confidentialité (R14)
- Écrans du portail établissement et de l'application Expo
- Passage en production, retrait du garde-fou sandbox, rotation des clés
- Rétention et purge de `evenement_webhook`

---

## FIN DU PROMPT
