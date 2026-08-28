# Plan de test — intégration GeniusPay et webhook

> Lot 7. Complète `GUIDE_TEST_Paiement_G4_P5.md`. Commandes prêtes à coller.
> **Bac à sable uniquement** : le service refuse de démarrer si `GENIUSPAY_ENVIRONNEMENT` vaut autre
> chose que `sandbox`.

---

## 0. Ce que ce plan prouve, et ce qu'il ne prouve pas

Il prouve que le service **ouvre un vrai checkout chez GeniusPay**, qu'il **vérifie une signature sur
les octets reçus**, qu'il **ne rejoue jamais** un `POST /payments`, et qu'il **ne solde aucune facture
sur une hypothèse**.

> **Mise à jour du 2026-08-28 — la limite principale est levée.** La session de phase 6 a été jouée :
> un paiement a été **réellement effectué sur la page de checkout du bac à sable** (scénario
> `success`, passerelle `wave`), et GeniusPay a émis un `payment.success` **de son propre chef**. Les
> quatre `webhook.test` et ce `payment.success` sont tous arrivés en `signature_valide = t`.
> Ce qui reste non prouvé contre le prestataire est le **balayage du `§7.4.b`**, faute d'un
> `GET /payments` fonctionnel (voir `§4`), et le comportement en environnement `live`.

### Le point décisif, et pourquoi il pouvait tout arrêter

Les exemples PHP, Node et Python du guide d'intégration **décodent puis ré-encodent le JSON** avant
de signer : `10000.00` y devient `10000.0`, la chaîne diffère d'un octet, la signature diffère. Si le
serveur de GeniusPay avait signé de cette façon, notre vérification — calculée sur les octets reçus —
aurait échoué, et la consigne était de s'arrêter plutôt que de « normaliser » le corps.

**Elle correspond.** Le prestataire signe bien le corps brut ; c'est la documentation qui est fautive.

---

## 0 bis. Résultats de la session du 2026-08-28

Webhook `4433` (sandbox), tunnel Ngrok, marchand `ETS-G2`. **Sept livraisons réelles, toutes en
`signature_valide = t`** — aucune signature falsifiée n'a eu à être fabriquée pour le prouver.

| Événement réel | Sort | Ce qu'il établit |
|---|---|---|
| `webhook.test` ×4 | `IGNORE_NON_GERE` | la signature est calculée comme le prestataire la calcule |
| `payment.success` (#1) | **`ERREUR`** — « montant divergent : 0 contre 15000 » | le garde-fou refuse de solder un montant qu'il ne comprend pas |
| `payment.success` (#2, après correctif) | `TRAITE` | le chemin **nominal** solde par lui-même, sans réconciliation |
| `payment.failed` | `TRAITE` | transaction `ECHOUEE`, **facture restée `EMISE`, `montant_regle = 0`** |
| `payment.success` (#3, après correctif `gateway`) | `TRAITE`, `canal = wave` | le canal est lu dès l'événement |

**Le paiement #1 est le plus instructif de la session.** La facture a bien été soldée — mais par la
réconciliation, pas par le webhook. Sans lecture de la base, on aurait conclu « tout fonctionne » ;
c'est le `statut_traitement` de l'événement, et lui seul, qui a montré que le chemin nominal était
cassé. **Un système qui s'en sort n'est pas un système dont le chemin nominal marche.**

Le scénario `Failure` du bac à sable a bien été éprouvé : page **et** événement disent l'échec. Le
simulateur respecte le scénario choisi.

> **Les données de cette session sont CONSERVÉES en base de développement** (décision du propriétaire,
> 2026-08-28) : cinq factures `ETS-G2`, dont `FCT-ETSG2-2026-000004` restée `EMISE` avec sa
> transaction `ECHOUEE`, et les sept événements dans `carte_evenements_webhook`. Contrairement aux
> G2 précédents, **elles ne sont pas à restaurer** : ce sont les pièces de la phase 6, et la ligne
> `ERREUR — montant divergent : 0 contre 15000` est la seule trace du défaut corrigé.

---

## 1. Préalables

| # | Élément | Vérification |
|---|---|---|
| 1 | `services/payment/.env` renseigné | `git check-ignore -v .env` renvoie une ligne — il n'est **pas** versionné |
| 2 | `GENIUSPAY_BASE_URL` | tranchée par la vérification V1 (consignée dans `docs/adr/ADR-044`), jamais écrite en dur |
| 3 | Clés marchandes | `GENIUSPAY_API_KEY` / `GENIUSPAY_API_SECRET` dans `.env` uniquement |
| 4 | Service démarré | `curl -s localhost:8080/actuator/health` → `UP` |

> **Piège de poste (Git Bash / Windows).** Un argument commençant par `/api/...` est converti en chemin
> Windows par MSYS avant d'atteindre le script — le principal est alors signé sur **le mauvais chemin**
> et le service répond `401` sans autre explication. Préfixer par `MSYS_NO_PATHCONV=1`. En contrepartie
> le chemin du script Python doit être donné en forme Windows (`C:/…/signer.py`), qui n'est plus
> convertie non plus.

---

## 2. Mise en place du webhook (session Ngrok)

| # | Action | Vérification |
|---|---|---|
| 1 | `ngrok http 8080` | URL HTTPS obtenue. **Elle change à chaque redémarrage** (offre gratuite). |
| 2 | Enregistrer le marchand (§3, W1) | la réponse donne le `cheminRappel` avec son **slug opaque** |
| 3 | Créer le webhook chez GeniusPay sur `https://<ngrok>/<cheminRappel>` | **noter immédiatement le `whsec_` : il n'est renvoyé qu'une fois** |
| 4 | Déposer le `whsec_` (§3, W5) | `secretWebhookEnregistre: true` |
| 5 | Déclencher `webhook.test` depuis le tableau de bord | `200`, événement en base en `IGNORE_NON_GERE` |

> **Une seule structure de test.** Chaque établissement a sa propre URL de rappel : en recréer un par
> partenaire à chaque session Ngrok n'est pas praticable en démonstration.

> **Si la vérification de signature échoue à l'étape 5 : ARRÊT ET RAPPORT.** Aucune normalisation
> improvisée du corps reçu. Les exemples PHP, Node et Python du guide d'intégration re-encodent le
> JSON et produisent une signature différente ; si le *serveur* signait de la même façon fautive, une
> vérification correcte échouerait. Le contourner affaiblirait la vérification **pour toujours**.

---

## 3. Scénarios

Préparer une fois par session :

```bash
cd services/payment
set -a && . ./.env && set +a
export MSYS_NO_PATHCONV=1
SIGNER="C:/chemin/vers/signer.py"     # forme Windows : MSYS ne la convertit pas
sg() { python "$SIGNER" "$1" "$2"; }  # rend X-Principal puis X-Principal-Sig
```

| # | Scénario | Attendu |
|---|---|---|
| W1 | Enregistrer le marchand `ETS-G2` | `200`, `cheminRappel` avec un slug **aléatoire** — jamais `ETS-G2` |
| W2 | Initier à **4 999 FCFA** | `422`, « paiement en ligne indisponible sous 5000 FCFA », **rien écrit en base** |
| W3 | Initier à **150 FCFA** (plancher abaissé) | `422`, message citant le **prestataire** et son minimum |
| W4 | Initier à **15 000 FCFA** | `200`, `checkoutUrl`, statut `EN_ATTENTE`, `referencePasserelle` renseignée |
| W5 | Déposer le `whsec_` | `200` ; en base le secret est **chiffré**, jamais en clair |
| W6 | Seconde initiation, **même facture**, autre clé d'idempotence | `rejoue: true`, **même** lien, **une seule** ligne en base |
| W7 | Webhook à **signature falsifiée** | `401` corps vide, événement `REJETE_SIGNATURE`, transaction inchangée |
| W8 | Webhook à **horodatage de 10 minutes** | `400`, `REJETE_HORODATAGE` |
| W9 | Webhook sur un **slug inconnu** | `401` — **indistinguable** d'une signature fausse |
| W10 | Webhook `"environment":"live"` | `400`, `REJETE_ENVIRONNEMENT` |
| W11 | Webhook `payment.success` avec **14 000** sur une facture de 15 000 | `200` reçu, puis `ERREUR` au traitement, **facture NON soldée** |
| W12 | Webhook `payment.success` **légitime** | transaction `REUSSIE`, `frais_passerelle` et `montant_net` **renseignés par le prestataire**, paiement partagé `SUCCESS` |
| W13 | **Rejeu à l'identique** de W12 | `200`, une seule ligne d'événement, **aucune seconde notification** |
| W14 | `payment.failed` **après** W12 | `IGNORE_DOUBLON` — un état terminal ne se remplace jamais |
| W15 | `cashout.completed` | `200`, `IGNORE_NON_GERE` (D8 : MaSanté n'est jamais dépositaire) |
| W16 | Facture inexistante à l'initiation | **refus immédiat** par le moteur (clé étrangère), jamais un checkout ouvert dans le vide |

> **W11 et W16 sont les deux à montrer.** Le premier prouve qu'un écart de montant est un **incident**
> et jamais une tolérance. Le second prouve que l'incohérence est attrapée **là où l'appelant peut
> encore corriger** — pas trois minutes plus tard dans un relais, alors que le patient a déjà le lien.

### Commandes

```bash
# W1 — enregistrement du marchand
O=$(sg POST /api/v1/interne/geniuspay/marchands); P=$(echo "$O"|head -1); S=$(echo "$O"|tail -1)
curl -s -X POST http://localhost:8080/api/v1/interne/geniuspay/marchands \
  -H "X-Principal: $P" -H "X-Principal-Sig: $S" -H "Content-Type: application/json" \
  -d "{\"etablissementRef\":\"ETS-G2\",\"clePublique\":\"$GENIUSPAY_API_KEY\",\"cleSecrete\":\"$GENIUSPAY_API_SECRET\"}"

# W4 — checkout réel (FACT doit être une facture EXISTANTE de ce service)
O=$(sg POST /api/v1/interne/geniuspay/paiements); P=$(echo "$O"|head -1); S=$(echo "$O"|tail -1)
curl -s -X POST http://localhost:8080/api/v1/interne/geniuspay/paiements \
  -H "X-Principal: $P" -H "X-Principal-Sig: $S" -H "Idempotency-Key: demo-1" \
  -H "Content-Type: application/json" \
  -d "{\"factureId\":\"$FACT\",\"montant\":15000,\"etablissementRef\":\"ETS-G2\"}"

# W5 — dépôt du secret webhook
O=$(sg POST /api/v1/interne/geniuspay/marchands/ETS-G2/secret-webhook); P=$(echo "$O"|head -1); S=$(echo "$O"|tail -1)
curl -s -X POST http://localhost:8080/api/v1/interne/geniuspay/marchands/ETS-G2/secret-webhook \
  -H "X-Principal: $P" -H "X-Principal-Sig: $S" -H "Content-Type: application/json" \
  -d '{"secretWebhook":"whsec_…"}'
```

### Lectures de contrôle

```sql
-- Événements reçus, avec leur sort
SELECT evenement_id, statut_traitement, signature_valide, motif_rejet
FROM carte_evenements_webhook WHERE psp = 'geniuspay' ORDER BY recu_le;

-- État de la transaction et projection sur la machine partagée
SELECT g.statut_geniuspay, g.frais_passerelle, g.montant_net, g.canal, p.statut
FROM geniuspay_transactions g JOIN payments p ON p.id = g.paiement_id;

-- Aucun secret en clair
SELECT CASE WHEN encode(secret_webhook_chiffre, 'escape') LIKE '%whsec_%'
            THEN 'FUITE' ELSE 'CHIFFRE' END FROM identifiants_marchand;
```

---

## 4. Ce qui n'est pas couvert par une session Ngrok

| Scénario | Pourquoi, et où il est prouvé |
|---|---|
| Délai dépassé à l'initiation | **Impossible à provoquer** contre le vrai prestataire. Prouvé par simulation, avec le compteur de requêtes sortantes : `ClientGeniusPayTest.timeout_ne_rejoue_jamais`. |
| Ngrok coupé puis réconciliation | Le rattrapage par `GET /payments/{ref}` fonctionne (l'endpoint répond). Le **balayage** (transaction sans référence) ne peut pas être prouvé en bac à sable : `GET /payments` y répond `500` systématiquement. |
| Facture soldée côté Laravel | Le canal interne (lot 6) est borné au **transport** : il journalise, il ne déclenche pas encore la commission. |

---

## 5. Fin de session — obligatoire

```bash
curl -s -X DELETE "$GENIUSPAY_BASE_URL/api/v1/merchant/webhooks/<id>" \
  -H "X-API-Key: $GENIUSPAY_API_KEY" -H "X-API-Secret: $GENIUSPAY_API_SECRET"
```

Ce n'est **pas optionnel**. Une URL Ngrok abandonnée est réattribuée à quelqu'un d'autre : le webhook
continuerait d'y livrer des événements de paiement — montants, références, identifiants de facture —
sur la machine d'un inconnu. C'est une fuite de données, pas un oubli d'entretien.
