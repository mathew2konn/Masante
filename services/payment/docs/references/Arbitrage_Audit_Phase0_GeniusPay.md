# ARBITRAGE — Rapport d'audit Phase 0 GeniusPay (`paiement-service` Java)

> Réponse aux 7 points bloquants et aux vérifications V1–V3 du rapport Phase 0 rendu par Claude Code.
> Statut : décisions d'architecture proposées, à confirmer par Mathieu (et A6 par l'encadreur).
> Date : 26 août 2026.

---

## 1. Ce que l'audit tranche définitivement

Trois préconditions tombent, il faut le noter avant de discuter des blocages :

1. **La « incohérence PostgreSQL / MySQL » n'existe pas.** PostgreSQL 16 / `masante_payment` (5433) côté Java, MySQL `ivoirsante` (3306) côté Laravel : ce sont deux contextes bornés avec deux bases, c'est-à-dire exactement Rule-001 et ADR-013. Cela lève la **précondition n°2 du prompt GeniusPay** *et* la **précondition n°3 du prompt Tables_Facturation**, qui étaient la même.
2. **V2 est confirmé : aucun en-tête d'idempotence.** `§7.4` (jamais de rejeu d'un `POST /payments`) s'applique intégralement, sans dérogation possible. C'est désormais une contrainte établie, pas une hypothèse.
3. **Spring Retry est absent et inutile.** L'interdiction n°4 n'est pas menacée de ce côté ; l'affirmation « Spring Retry suffit » du prompt est corrigée : il ne faut pas l'ajouter.

---

## 2. Décisions sur les points bloquants

### B1 — Tables de facturation Laravel absentes → **requalifié : bloquant partiel**

L'audit a raison sur le fait, mais la conséquence est plus étroite que « les phases 1 à 6 sont bloquées ».

`NotificateurFacturation` est un **port**. Son implémentation immédiate est une écriture dans `notifications_outbox`, qui existe déjà (P5.4c). Le service peut donc produire ses notifications sans consommateur branché : elles s'accumulent dans l'outbox, et le relais planifié les livrera quand le canal existera.

- **Bloqué réellement** : Phase 6 (plan de test), scénario n°5 (« facture soldée côté Laravel »), scénario n°11.
- **Non bloqué** : Phases 1 à 5 du prompt GeniusPay.
- **Décision** : lancer `Prompt_ClaudeCode_Tables_Facturation_MaSante.md` **en parallèle** sur Laravel, pas en série. Ses trois préconditions sont désormais : A1–A5 validés par l'encadreur, module 1 (triage) validé Postman/Ngrok, incohérence PG/MySQL tranchée — cette dernière est levée par le point 1 ci-dessus.

### B2 — Aucun canal Laravel ↔ Java → **confirmé bloquant, mais le mécanisme existe**

L'audit dit « il n'existe pas ». Précision utile : le **mécanisme** existe (`ServicePrincipal` : `X-Principal` + `X-Principal-Sig`, HMAC lié à `method+path`, nonce Redis anti-rejeu). Ce qui manque, ce sont deux extrémités :

- **Sens sortant Laravel → Java** : un client HTTP Laravel qui signe le principal et frappe `/interne/v1/paiements`. C'est du portage de ce que fait déjà `apps/web/src/lib/paiement.ts`, pas une invention.
- **Sens entrant Java → Laravel** (le sens critique pour D2) : un endpoint Laravel de notification de facturation + vérification HMAC côté Laravel + idempotence sur `referenceInterne`. Rien n'existe.

**Décision** : lot séparé, **avant** la Phase 6, après ou en parallèle des tables de facturation. Prompt à rédiger : « canal interne Laravel ↔ paiement-service », deux sens, réutilisant le principal signé, consommateur de `notifications_outbox` inclus. `§7.6` est amendé : le mécanisme *est* identifié, c'est le principal signé — il n'est ni inventé, ni à réinventer.

> **Mise à jour post-audit Lot 6/7 (à connaître avant de coder ce point) :** le sens sortant Laravel → Java décrit ci-dessus a été retiré du périmètre exécuté — aucun besoin confirmé, Next.js initie déjà les paiements. Seul le sens entrant Java → Laravel (notification) a été construit, et volontairement borné au transport (vérification de signature, journalisation) sans déclencher `CommissionService`, faute d'un identifiant d'établissement fiable sur l'objet `Paiement` Java aujourd'hui (`correlationId` et `factureId` n'en tiennent pas lieu). Ce point — donner à `Paiement` un moyen de porter l'identité de l'établissement — reste ouvert et concerne le lot 7, pas ce lot-ci.

### B3 — Doublon fonctionnel et machine à états → **précédent P5.4a retenu**

**Décision : on ne renomme rien, on ne duplique pas la machine à états.**

- Le port reste `PasserellePaiement` avec `payer` / `statut` / `rembourser`. `§7.2` (`initier`/`consulterStatut`/`verifierSignature`) est **annulé** : `§3 Nommage` du prompt dit lui-même que le nommage suit la convention en place, relevée en Phase 0, jamais supposée. L'existant l'emporte sur le prompt.
- GeniusPay entre comme une implémentation supplémentaire de `PasserellePaiement`, enregistrée dans `RegistrePasserelles`, exactement comme `AdaptateurSimule`.
- La machine partagée `@masante/shared` reste **intacte** (G5 non touché). On introduit `StatutGeniusPay`, sous-état backend-only, projeté par un `switch` exhaustif **sans `default`**, sur le modèle de `StatutCarte`.

Projection imposée :

| `StatutGeniusPay` (backend) | `PaiementStatut` (partagé) | Note |
|---|---|---|
| `INITIEE` | `INITIATED` | |
| `INITIEE_INCERTAINE` | `INITIATED` | **jamais `PENDING`** : la transaction peut ne pas exister chez GeniusPay |
| `EN_ATTENTE` | `PENDING` | checkout créé |
| `EN_COURS` | `PROCESSING` | |
| `REUSSIE` | `SUCCESS` | |
| `ECHOUEE` | `FAILED` | |
| `ANNULEE` | `CANCELLED` | |
| `EXPIREE` | `FAILED` | le détail « expiré » reste en base pour la réconciliation et le back-office ; le patient voit « échoué » |
| `REMBOURSEE` | `REFUNDED` | |

`§8.3` est amendée en conséquence : la machine décrite dans le prompt devient la définition de `StatutGeniusPay`, pas une seconde machine partagée.

> **Formalisée** dans `ADR_GeniusPay_Reutilisation_Abstractions.md`, déposée dans `docs/adr/` du dépôt Java (numérotée à la suite d'ADR-025–ADR-043 — « ADR-044 » selon la dernière vérification).

### B4 — Secrets dans `docker-compose.yml` versionné → **corrigé immédiatement, micro-lot hors prompt**

Ils sont de développement, ils sont documentés — ils sont versionnés, et c'est ainsi qu'un secret de dév devient un défaut de production. Correction avant la Phase 1, en un commit isolé :

1. Les trois valeurs passent en `${MASANTE_PAYMENT_PRINCIPAL_SECRET}` etc., lues depuis `.env` (déjà gitignoré).
2. `.env.example` versionné avec des marqueurs vides.
3. Rotation des trois valeurs de dév.

Aucun autre fichier touché. Ce n'est pas un blocage du lot GeniusPay, c'est une dette à solder pendant qu'elle est visible.

> **Statut** : fait, selon la dernière vérification de Mathieu.

### B5 — WireMock / MockWebServer absents → **dépendance autorisée**

**Autorisé : WireMock, portée `testImplementation` uniquement.** C'est la **seule** dépendance autorisée pour ce lot ; l'interdiction n°4 reste en vigueur pour toute autre.
**Refusé : Testcontainers** — hors périmètre, coût de démarrage disproportionné pour ce lot.

Justification : les tests n°4 (`timeout_ne_rejoue_jamais`), n°9 (`signature_calculee_sur_corps_brut`) et n°12 sont les trois tests qui prouvent le lot au jury. Aucun ne peut être écrit sans simuler un délai dépassé et une réponse HTTP contrôlée au bit près.

### B6 — Deux endpoints webhook → **factoriser, pas dupliquer**

**Décision : une seule chaîne de vérification, une seule table d'événements, deux contrôleurs fins.**

- `SignatureHmac`, `AntiRejeuWebhook`, la déduplication `UNIQUE(psp, evenement_id)`, la réponse `401` générique : réutilisés tels quels, aucune réécriture.
- La table d'événements existante (celle de P5.4a) est **étendue par migration Flyway** avec le discriminant `psp`, si elle ne le porte pas déjà. On ne crée pas `evenement_webhook` en parallèle. Les colonnes de `§5` absentes de l'existant (`empreinte_corps`, `horodatage_declare`, `environnement`, `statut_traitement`, `numero_tentative`) sont ajoutées en nouvelle migration, jamais par modification d'une migration existante.
- La route suit la convention en place (`/api/v1/…-webhooks/{psp}`), pas `POST /webhooks/geniuspay` de `§8.1`.
- **À rédiger avant la Phase 1** : un ADR court actant ce choix, comme P5.4a l'a fait pour `StatutCarte`. C'est la trace qui empêche la divergence dans six mois.

> **Formalisée** dans le même ADR que B3 (`ADR_GeniusPay_Reutilisation_Abstractions.md` — « ADR-044 »), qui couvre les deux décisions dans un seul document.

### B7 — Pas de chaîne Spring Security → **on n'en ajoute pas**

L'absence est délibérée et documentée dans le build. Ajouter `spring-boot-starter-security` verrouillerait 23 contrôleurs existants par auto-configuration : le remède serait pire que le mal.

`§8.1` est réécrit ainsi : *la route webhook est ajoutée à la **liste d'exemption explicite** du filtre du principal signé, **chemin par chemin, jamais par préfixe ni joker***. L'intention de la règle (pas de `permitAll()` large) est respectée ; le moyen change.

---

## 3. Ce que l'audit n'a pas relevé — la résolution du secret webhook en montage A

**C'est le vrai trou du prompt, et il est structurel.**

Le montage A donne un compte marchand GeniusPay par établissement, donc un `whsec_` par établissement (`§6.2`, `secret_webhook_chiffre`). Or `§8.2` impose de vérifier la signature **avant** toute lecture du corps. Question sans réponse dans le prompt : à la réception d'un webhook, **avec quel secret vérifier**, puisque l'identité du marchand n'est connue qu'après avoir fait confiance au corps — ce qui est exactement interdit ?

Deux issues, une seule acceptable :

- ❌ Essayer tous les secrets jusqu'à ce qu'un HMAC passe : coût O(n) par requête, et un oracle de temps offert à l'attaquant.
- ✅ **Une URL de rappel distincte par établissement.** L'URL est enregistrée par compte marchand chez GeniusPay, donc elle peut porter le discriminant : `POST /api/v1/paiement-webhooks/geniuspay/{slug}`, où `slug` est un identifiant **opaque et aléatoire** stocké dans `identifiants_marchand` — **jamais le `structure_id`**, sinon l'énumération de l'URL révèle la liste des partenaires. Le `slug` ne fait que sélectionner le secret candidat : c'est le HMAC qui décide, un `slug` valide avec une signature fausse est rejeté en `REJETE_SIGNATURE` comme n'importe quoi d'autre.

Conséquence sur la Phase 6 : chaque URL Ngrok change à chaque session, et il y a désormais **un webhook à recréer par établissement**. La validation sandbox se limite donc à **une seule structure de test**, et le `DELETE /webhooks/{id}` de fin de session devient obligatoire, pas optionnel.

---

## 4. Correctifs de contrat à intégrer au prompt

| Écart relevé | Décision |
|---|---|
| `Bearer token` accepté en plus de `X-API-Key` | Noté au contrat `§4.3` comme mode alternatif **non utilisé**. On reste sur `X-API-Key` / `X-API-Secret`. |
| `payment.initiated` existe et n'est pas dans `§4.3` | Ajouté à la liste explicite `IGNORE_NON_GERE`. Aucun événement ne tombe dans un `default`. |
| Exemples PHP, Node et Python du guide faux sur la signature | Seul l'exemple **Java** (`rawBody`) fait foi. Les trois autres sont à ignorer, y compris comme source d'inspiration. |

**Risque à observer, non couvert par le prompt.** Les trois exemples fautifs signent une ré-encodage du corps. Si le *serveur* GeniusPay signe de la même manière fautive, une vérification correcte sur octets bruts **échouera**. Le moment de vérité est l'étape 5 de la Phase 6 (`webhook.test` déclenché depuis le dashboard). Consigne : en cas d'échec de signature à cette étape, **arrêt et rapport** — aucune normalisation improvisée du corps, qui affaiblirait la vérification pour toujours.

---

## 5. V1 — à exécuter par Mathieu, hors Claude Code

Les deux bases répondent identiquement sans clés ; seul un appel authentifié tranche.

```bash
for BASE in https://pay.genius.ci https://geniuspay.ci; do
  echo -n "$BASE -> "
  curl -s -o /dev/null -w "%{http_code}\n" \
    -H "X-API-Key: $GP_KEY" -H "X-API-Secret: $GP_SECRET" \
    "$BASE/api/v1/merchant/account"
done
```

Règle de départage, à appliquer sans discussion :

- Une seule base répond `200` → c'est elle.
- **Les deux répondent `200` → `geniuspay.ci`.** Motif : `pay.genius.ci` a expiré au premier appel de l'audit puis répondu en 2,07 s au second ; `geniuspay.ci` est derrière Cloudflare et a répondu de façon stable.
- Le résultat est consigné dans l'ADR et injecté par `GENIUSPAY_BASE_URL`. **La base n'est jamais écrite en dur** — ce point n'est pas négociable, il est déjà prévu par `§6.1`.

**V3** reste non observable sans clés : l'authentification est vérifiée avant le corps. À vérifier une fois les clés en place, en sandbox, par un `POST /payments` portant un `webhook_url` — s'il est accepté sans effet ou rejeté, dans les deux cas on s'en tient à la configuration au niveau du compte.

> **Statut V1** : ne bloque pas l'écriture du code (adaptateur, machine `StatutGeniusPay`, vérification webhook, tests WireMock) — seule la Phase 6 (validation sandbox réelle) l'attend. Voir `07_Prompt_ClaudeCode_Amendements_GeniusPay_v3.md`, section « Conditions d'exécution ».

---

## 6. Séquence validée

| # | Lot | Dépend de | Où |
|---|---|---|---|
| 0 | B4 — secrets hors de `docker-compose.yml` + rotation | — | Java |
| 1 | ADR : `StatutGeniusPay` sous-état (B3) + chaîne webhook factorisée (B6) + slug marchand (§3) | — | Java, doc |
| 2 | `Prompt_ClaudeCode_Tables_Facturation_MaSante.md` | A1–A5 encadreur, module 1 validé | Laravel |
| 3 | Prompt « canal interne Laravel ↔ paiement-service », deux sens (revu : sens entrant seul, voir note B2) | 2 | Laravel + Java |
| 4 | GeniusPay Phases 1 → 5, prompt amendé (§7.2, §8.1, §8.3, §6.2 slug) | 0, 1, V1, A6 | Java |
| 5 | GeniusPay Phase 6 — plan de test et 12 scénarios | 2, 3, 4 | tout |

Les lots 2 et 4 sont parallélisables : c'est la conséquence directe de la requalification de B1.

**Reste à trancher par l'encadreur, hors de portée de l'audit** : A1–A5 (modèle économique) et **A6 (garde des clés `sk_` de tiers)**. Le lot 4 reste gelé tant qu'A6 n'est pas validé, puisque `§6.2` et le slug marchand n'existent que sous montage A.

> **A6 tranché depuis** : montage A retenu (compte marchand par établissement) — voir `07_Prompt_ClaudeCode_Amendements_GeniusPay_v3.md`.
