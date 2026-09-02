# PROMPT CLAUDE CODE — AMENDEMENTS GENIUSPAY v3 (overlay, ne remplace pas le v2)
## Microservice Java `paiement-service` — Lot 7 de la séquence post-facturation

> **Ce document ne recopie pas `Prompt_ClaudeCode_Integration_GeniusPay_MaSante_v2_Java.md`.** Il s'exécute avec lui : lis le v2 dans son intégralité d'abord, puis applique les amendements ci-dessous, qui **priment** en cas de conflit avec le v2. Toutes les sections du v2 non mentionnées ici restent inchangées.
>
> Un overlay plutôt qu'une réécriture complète : retranscrire de zéro un document de cette taille est le geste qui introduit le plus de risques de divergence involontaire. Le v2 reste la référence structurelle ; ce document est la liste exhaustive de ce qui change.

---

## CONDITIONS D'EXÉCUTION

Toutes celles du v2, **plus** :
- A6 validé : **montage A retenu** (compte marchand par établissement).
- Le micro-lot secrets (B4) exécuté : plus aucun secret en clair dans `docker-compose.yml`.
- L'ADR interne actant B3 et B6 (voir ci-dessous) rédigé et relu.

---

## COPIER À PARTIR D'ICI

---

## AMENDEMENTS AU CONTRAT (§4.3 du v2)

1. **Bearer token.** La documentation GeniusPay mentionne un mode d'authentification alternatif par Bearer token. **Non utilisé.** Le client reste exclusivement sur `X-API-Key` / `X-API-Secret`.
2. **Événement `payment.initiated`.** Absent de la liste `§4.3` du v2, mais réellement émis par GeniusPay. Ajoute-le explicitement à la liste des événements traités en `IGNORE_NON_GERE`. **Aucun événement ne doit tomber dans un `default` non nommé.**
3. **Exemples de signature du guide d'intégration.** Seul l'exemple **Java** (`rawBody`) fait foi. Les exemples PHP, Node et Python re-encodent le JSON et produisent une signature différente sur `10000.00` → `10000` ou `10000.0`. Ne t'en inspire pour rien, pas même comme référence de structure.
4. **URL de base.** Résultat de la vérification V1 à injecter via `GENIUSPAY_BASE_URL` — jamais en dur. Si les deux bases (`pay.genius.ci` et `geniuspay.ci`) répondent `200` à l'appel authentifié, retiens `geniuspay.ci` (Cloudflare, stable) plutôt que `pay.genius.ci` (a expiré une fois lors de l'audit).

## AMENDEMENT AU PORT — §7.2 du v2 **annulé**

**Ne crée pas** `PasserellePaiement` avec les méthodes `initier`/`consulterStatut`/`verifierSignature` du v2. Le port `PasserellePaiement` **existe déjà** dans le projet, avec la signature `payer` / `statut` / `rembourser`, aux côtés de `RegistrePasserelles` et `AdaptateurSimule`. GeniusPay est une **implémentation supplémentaire** de ce port existant, enregistrée dans `RegistrePasserelles` exactement comme `AdaptateurSimule` — jamais un nouveau port parallèle.

## AMENDEMENT À LA MACHINE À ÉTATS — §8.3 du v2 **réinterprété**

**N'écris pas** une seconde machine à états partagée. La machine `@masante/shared` (`INITIATED → PENDING → PROCESSING → SUCCESS/FAILED/CANCELLED → REFUNDED`) reste **intacte**, inchangée, non touchée.

Le §8.3 du v2 devient la définition d'un **sous-état backend-only**, `StatutGeniusPay`, sur le modèle de `StatutCarte` (P5.4a). Projection obligatoire par un `switch` Java **exhaustif, sans `default`** :

| `StatutGeniusPay` | `PaiementStatut` partagé |
|---|---|
| `INITIEE` | `INITIATED` |
| `INITIEE_INCERTAINE` | `INITIATED` — jamais `PENDING` : la transaction peut ne pas exister chez GeniusPay |
| `EN_ATTENTE` | `PENDING` |
| `EN_COURS` | `PROCESSING` |
| `REUSSIE` | `SUCCESS` |
| `ECHOUEE` | `FAILED` |
| `ANNULEE` | `CANCELLED` |
| `EXPIREE` | `FAILED` — le détail « expiré » reste en base pour la réconciliation et le back-office ; le patient voit « échoué » |
| `REMBOURSEE` | `REFUNDED` |

## AMENDEMENT AU WEBHOOK — §8.1 du v2 **réécrit sur deux points**

### 1. Route et exemption de sécurité

**N'utilise pas** `POST /webhooks/geniuspay`. Suis la convention déjà en place dans le projet (`POST /api/v1/…-webhooks/{psp}`, relevée en Phase 0 du v2). La route webhook entre dans la **liste d'exemption explicite** du filtre du principal signé, **chemin par chemin, jamais par préfixe ni joker**. N'ajoute pas `spring-boot-starter-security` : son absence est délibérée (23 contrôleurs existants seraient verrouillés par auto-configuration).

### 2. Résolution du secret webhook en montage A — trou non couvert par le v2

Le montage A donne un `whsec_` par établissement. Le webhook doit être vérifié **avant** toute lecture de confiance du corps — donc avant de connaître l'identité de l'établissement. Solution imposée :

- **Une URL de rappel distincte par établissement**, portant un `slug` **opaque et aléatoire**, stocké dans `identifiants_marchand`. **Jamais le `structure_id` en clair dans l'URL** — cela énumérerait la liste des partenaires.
- Le `slug` **sélectionne le secret candidat**, c'est le HMAC qui décide : un `slug` valide avec une signature fausse est rejeté en `REJETE_SIGNATURE` comme n'importe quel autre webhook invalide.
- **Ne jamais** essayer plusieurs secrets en cascade jusqu'à trouver une correspondance — coût O(n) et oracle de temps offert à l'attaquant.

### 3. Table d'événements — pas de duplication (B6)

**Ne crée pas** une nouvelle table `evenement_webhook` parallèle à celle qui existe déjà (P5.4a). Étends la table existante par **nouvelle migration Flyway**, avec un discriminant `psp`, en ajoutant seulement les colonnes du §5 du v2 qui manquent réellement (`empreinte_corps`, `horodatage_declare`, `environnement`, `statut_traitement`, `numero_tentative` — vérifie en Phase 0 lesquelles existent déjà). Réutilise tels quels `SignatureHmac`, `AntiRejeuWebhook`, la déduplication `UNIQUE(psp, evenement_id)`, la réponse `401` générique.

## AMENDEMENT AUX SECRETS — §6.2 du v2, complément

Le montage A implique un secret webhook par établissement en plus de la clé `sk_`. Applique le même chiffrement enveloppe (AES-256-GCM, `GestionnaireSecrets`) aux deux, sans distinction de traitement.

## AMENDEMENT AUX DÉPENDANCES — §1-4 du v2

**Spring Retry n'est pas nécessaire** — confirmé par l'audit, ne l'ajoute pas malgré ce que suggérait le v2.

**WireMock est autorisé**, portée `testImplementation` uniquement — c'est la **seule** dépendance de test permise pour ce lot. Testcontainers reste refusé.

## AMENDEMENT À LA PHASE 6 (PLAN DE TEST) DU v2

Chaque URL de rappel étant désormais **par établissement** (slug), la validation sandbox se limite à **une seule structure de test** — créer un webhook par établissement à chaque session Ngrok n'est pas praticable en démonstration. Le `DELETE /webhooks/{id}` de fin de session devient **obligatoire**, pas optionnel : une URL Ngrok abandonnée pointant vers une machine tierce est une fuite de données.

**Si la vérification de signature échoue à l'étape « `webhook.test` déclenché depuis le dashboard »** : arrêt et rapport immédiat. Aucune normalisation improvisée du corps reçu — cela affaiblirait la vérification de façon permanente. Signale l'échec, n'essaie pas de le contourner.

## AMENDEMENT AU §7.6 DU v2 (endpoints internes)

Le mécanisme d'authentification entre Laravel et le service **est** le principal signé (`ServicePrincipal`), déjà implémenté côté Java. Ne le réinvente pas ; l'exposition du canal sortant/entrant est traitée par le prompt du lot 6 de cette séquence, pas ici.

## CHECKLIST — s'ajoute à celle du v2

- [ ] `git grep -n "initier\|consulterStatut\b"` dans le package `port/` ne retourne aucune trace d'un second port `PasserellePaiement`
- [ ] Aucun nouveau `evenement_webhook` créé — une seule table, étendue
- [ ] Chaque webhook enregistré porte un `slug` opaque, jamais un `structure_id`
- [ ] `payment.initiated` figure explicitement dans la liste `IGNORE_NON_GERE`
- [ ] `GENIUSPAY_BASE_URL` est lu depuis la configuration, jamais en dur

## FIN DU PROMPT
