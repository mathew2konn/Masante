# Guide de test G4 — P5.1 Paiement (microservice)

Objectif : **vous** validez de vos mains que le service de paiement répond correctement, via
**Swagger UI** (navigateur). Aucune installation. ~10 min. À la fin, dites-moi « P5.1 OK » et j'écris le G5.

> ⚠️ Rappel : paiement **simulé** — rien n'est débité. On teste la logique, pas un vrai encaissement.

---

## 0. Prérequis — démarrer la pile (si besoin)

1. **Docker Desktop** lancé (baleine 🐳 fixe dans la barre des tâches).
2. La pile tourne. Pour (re)démarrer, dans un terminal :
   ```powershell
   docker compose -f C:\wamp64\www\IVOIRESANTE\services\payment\docker-compose.yml up -d --no-build
   ```
3. Ouvrir dans le navigateur : **http://localhost:8080/swagger-ui.html**
   → vous voyez 3 groupes : **Paiements**, **Prise en charge**, **Audit**.

Pour chaque test : dérouler l'endpoint → bouton **« Try it out »** → coller le corps → **« Execute »** →
lire la section **Server response** (le **Code** = code HTTP, le **Response body** = le JSON renvoyé).

---

## 1. Prise en charge CNAM (frontière métier)

**Prise en charge → `POST /api/v1/coverage/quote`** — corps :
```json
{ "montantTotal": 20000, "type": "CNAM", "tauxCouverture": 70, "exclu": false }
```
✅ Attendu : **Code 200**, et dans le corps `"montantCouvert": 14000`, `"resteACharge": 6000`.
*(C'est l'exemple imposé du CDC_06 §8.1 : consultation 20 000, CNAM 70 % → patient 6 000.)*

## 2. Prise en charge assurance
Même endpoint — corps :
```json
{ "montantTotal": 250000, "type": "ASSURANCE", "tauxCouverture": 80, "exclu": false }
```
✅ Attendu : **200**, `"montantCouvert": 200000`, `"resteACharge": 50000`. *(CDC_06 §8.2.)*

## 3. Plafond de couverture
```json
{ "montantTotal": 250000, "type": "ASSURANCE", "tauxCouverture": 80, "plafond": 150000, "exclu": false }
```
✅ Attendu : **200**, `"montantCouvert": 150000`, `"resteACharge": 100000`, `"plafondApplique": true`.

---

## 4. Initier un paiement (idempotent, simulé)

**Paiements → `POST /api/v1/payments`**. Deux champs à remplir :
- Le **paramètre d'en-tête `Idempotency-Key`** (champ en haut du formulaire) : taper `mon-test-1`.
- Le **corps** :
```json
{ "montant": 6000, "canal": "orange_money", "objet": "RENDEZ_VOUS", "telephone": "0700000089", "patientRef": "patient-42" }
```
✅ Attendu : **Code 201**, `"statut": "SUCCESS"`, `"telephoneMasque": "********89"`, une `"providerRef": "SIM-…"`.
👉 **Copiez la valeur de `"id"`** (un UUID type `ca02e4dc-…`) : elle sert aux tests 6 à 9.

## 5. Idempotence — rejouer la MÊME clé
Rejouer **exactement** le test 4 : **même `Idempotency-Key` `mon-test-1`**, même corps → **Execute**.
✅ Attendu : **Code 200** (et non 201), avec **le même `id`** qu'au test 4. *(Aucun doublon créé.)*

## 6. Sécurité — clé d'idempotence manquante
Re-`POST /api/v1/payments`, mais **videz le champ `Idempotency-Key`**. Corps :
```json
{ "montant": 6000, "canal": "orange_money", "objet": "RENDEZ_VOUS" }
```
✅ Attendu : **Code 400**, message « En-tête obligatoire manquant : Idempotency-Key ».

---

## 7. Historique des transitions
**`GET /api/v1/payments/{id}/transitions`** — coller votre `id` (test 4) dans le champ `id`.
✅ Attendu : **200**, une liste de 3 étapes finissant par `"statutVers": "SUCCESS"`
(`INITIATED→PENDING→PROCESSING→SUCCESS`).

## 8. Piste d'audit (journal inviolable)
**`GET /api/v1/payments/{id}/audit`** — votre `id`.
✅ Attendu : **200**, 3 entrées (`PaymentInitiated`, `PaymentPending`, `PaymentConfirmed`), chacune avec
un `hash` et un `previousHash` : le `previousHash` de chaque ligne **= le `hash` de la précédente** (chaînage).

## 9. Remboursement
**`POST /api/v1/payments/{id}/refund`** — votre `id`. Corps :
```json
{ "motif": "test G4" }
```
✅ Attendu : **200**, `"statut": "REFUNDED"`.

## 10. Machine à états — remboursement impossible deux fois
Rejouer le test 9 (même `id`).
✅ Attendu : **Code 409**, message « Transition interdite : REFUNDED → REFUNDED ».

## 11. Canal inconnu rejeté
`POST /api/v1/payments`, `Idempotency-Key` = `mon-test-canal`, corps :
```json
{ "montant": 1000, "canal": "bitcoin", "objet": "AUTRE" }
```
✅ Attendu : **Code 400**, « Aucune passerelle ne prend en charge le canal : bitcoin ».

## 12. Intégrité de la chaîne d'audit
**Audit → `GET /api/v1/audit/verify`** → Execute.
✅ Attendu : **200**, `{ "integre": true }`.

*(Fin de P5.1 — déjà validé G5. La suite teste P5.2a.)*

---

# Partie B — Facturation (P5.2a)

Groupe **Factures** dans Swagger.

## 13. Émettre une facture (consultation 20 000, CNAM 70 %)
**`POST /api/v1/invoices`** — corps :
```json
{
  "etablissementRef": "CHU-COCODY",
  "patientRef": "patient-42",
  "lignes": [ { "libelle": "Consultation", "quantite": 1, "prixUnitaire": 20000, "remise": 0, "tauxTva": 0 } ],
  "priseEnCharge": { "type": "CNAM", "tauxCouverture": 70, "exclu": false }
}
```
✅ Attendu : **201**, `"montantTtc": 20000`, `"montantCouvert": 14000`, `"resteAPayer": 6000`,
`"statut": "EMISE"`, un `"numero"` type `FCT-CHUCOCODY-2026-000001`. 👉 **Copiez le `"id"`** de la facture.

## 14. TVA en donnée (18 % de 10 000 = 1 800)
`POST /api/v1/invoices` — corps :
```json
{ "etablissementRef": "CHU-COCODY", "lignes": [ { "libelle": "Analyse", "quantite": 1, "prixUnitaire": 10000, "remise": 0, "tauxTva": 18 } ] }
```
✅ Attendu : **201**, `"totalTva": 1800`, `"montantTtc": 11800`.

## 15. Régler la facture par un paiement
**`POST /api/v1/payments`**, `Idempotency-Key` = `reglt-1`, corps (collez l'`id` de la facture du test 13) :
```json
{ "montant": 6000, "canal": "wave", "objet": "FACTURE", "telephone": "0700000042", "factureId": "COLLEZ_ICI_L_ID_FACTURE" }
```
✅ Attendu : **201**, `"statut": "SUCCESS"`, `"factureId"` renseigné.

## 16. La facture est soldée
**`GET /api/v1/invoices/{id}`** (id du test 13).
✅ Attendu : **200**, `"statut": "PAYEE"`, `"montantRegle": 6000`.

## 17. Télécharger le PDF (avec QR)
**`GET /api/v1/invoices/{id}/pdf`** → Execute → bouton **Download file**.
✅ Attendu : un **PDF** s'ouvre : en-tête MASANTÉ, numéro, lignes, TVA, **Reste à payer** et un **QR Code**.

## 18. Facture invalide rejetée
`POST /api/v1/invoices` — corps `{ "etablissementRef": "X", "lignes": [] }`.
✅ Attendu : **400** (au moins une ligne requise).

*(Fin de P5.2a — déjà validé G5. La suite teste P5.2b.)*

---

# Partie C — Avoir, versionnage & signature (P5.2b)

## 19. Émettre une facture v1 (consultation 20 000, CNAM 70 %)
**`POST /api/v1/invoices`** — même corps qu'au test 13 mais `etablissementRef` = `CHU-TREICHVILLE`.
✅ Attendu : **201**, `"versionNumero": 1`, `"signee": true`. 👉 **Copiez le `"id"`** (= id1).

## 20. Vérifier la signature de la facture
**`GET /api/v1/invoices/{id1}/verify-signature`**.
✅ Attendu : **200**, `{ "integre": true, "signee": true, "signatureValide": true, "algorithme": "SHA256withRSA" }`.

## 21. Corriger la facture → version 2 + avoir
**`POST /api/v1/invoices/{id1}/corriger`** — corps :
```json
{ "lignes": [ { "libelle": "Consultation spécialiste", "quantite": 1, "prixUnitaire": 25000, "remise": 0, "tauxTva": 0 } ], "remiseGlobale": 0, "priseEnCharge": { "type": "CNAM", "tauxCouverture": 70, "exclu": false }, "motif": "Erreur de tarif" }
```
✅ Attendu : **201**, un objet `{ "facture": {…}, "avoir": {…} }` : la **facture** a le **même numéro**,
`"versionNumero": 2`, `"montantTtc": 25000` ; l'**avoir** a `"montant": 20000` (le TTC d'origine).
👉 Copiez l'`id` de la facture v2 (= id2) et l'`id` de l'avoir.

## 22. La v1 est remplacée
**`GET /api/v1/invoices/{id1}`**.
✅ Attendu : **200**, `"statut": "REMPLACEE"`, `"remplaceeParId"` = id2.

## 23. Lister les versions de la lignée
**`GET /api/v1/invoices/{id2}/versions`**.
✅ Attendu : **200**, 2 entrées (même numéro) : v1 `REMPLACEE` et v2 `EMISE`.

## 24. Consulter et télécharger l'avoir
**`GET /api/v1/credit-notes/{idAvoir}`** → `"montant": 20000`, `"signe": true`, numéro `AV-…`.
**`GET /api/v1/credit-notes/{idAvoir}/pdf`** → **Download file** : un PDF « Avoir (note de crédit) » avec QR.

## 25. Annuler la facture v2
**`POST /api/v1/invoices/{id2}/annuler`** — corps `{ "motif": "Doublon" }`.
✅ Attendu : **200**, `{ "facture": { "statut": "ANNULEE" }, "avoir": { "montant": 25000 } }`.

## 26. On ne corrige pas une facture déjà remplacée
**`POST /api/v1/invoices/{id1}/corriger`** (id1 est REMPLACEE) — n'importe quel corps valide.
✅ Attendu : **409** (« Facture REMPLACEE : elle ne peut pas être corrigée »).

## 27. Intégrité de la chaîne d'audit (inchangée)
**`GET /api/v1/audit/verify`** → `{ "integre": true }`.

*(Fin de P5.2b — déjà validé G5. La suite teste P5.3a.)*

---

# Partie D — Wallet & double écriture (P5.3a)

Groupe **Wallet** dans Swagger. Les opérations (crédit/débit/transfert/pay-invoice) demandent un
en-tête **`Idempotency-Key`** (mettez une valeur différente à chaque nouvelle opération).

> ⚠️ **Depuis P5.3b-1**, toute opération **sortante** (débit, transfert, pay-invoice) exige le **PIN**
> du portefeuille (§6.4). Le crédit (entrant) n'en demande pas. Définissez d'abord le PIN (test 28b).

## 28. Créer un portefeuille patient
**`POST /api/v1/wallets`** — corps :
```json
{ "ownerRef": "patient-99", "ownerType": "PATIENT", "devise": "XOF" }
```
✅ Attendu : **201**, `"statut": "ACTIF"`, `"solde": 0`. 👉 Copiez le `"id"` (= walletId).

## 28b. Définir le PIN du portefeuille
**`POST /api/v1/wallets/{walletId}/pin`** — corps `{ "pin": "1234" }`.
✅ Attendu : **204**. (Pour le changer plus tard : `{ "pin": "5678", "ancienPin": "1234" }`.)

## 29. Créditer 50 000 (rechargement simulé)
**`POST /api/v1/wallets/{walletId}/credit`**, `Idempotency-Key` = `cr-1`, corps `{ "montant": 50000, "libelle": "Recharge" }`.
Puis **`GET /api/v1/wallets/{walletId}`**.
✅ Attendu : `"solde": 50000`.

## 30. Idempotence — rejouer le crédit
Rejouer le test 29 **avec la même clé `cr-1`** → puis `GET`.
✅ Attendu : `"solde": 50000` (toujours, **aucun doublon**).

## 31. Débiter 20 000
**`POST /api/v1/wallets/{walletId}/debit`**, `Idempotency-Key` = `db-1`, corps `{ "montant": 20000, "pin": "1234" }`.
✅ Attendu : après `GET`, `"solde": 30000` ; l'opération renvoyée porte **`"signee": true`**.

## 32. Débit supérieur au solde
`/debit`, `Idempotency-Key` = `db-2`, corps `{ "montant": 99999, "pin": "1234" }`.
✅ Attendu : **409** (« Solde insuffisant »).

## 33. Geler puis tenter un débit
**`POST /api/v1/wallets/{walletId}/freeze`** → puis `/debit` (`Idempotency-Key` = `db-3`, `{ "montant": 1000, "pin": "1234" }`).
✅ Attendu : débit **409** (« le portefeuille est GELE »). Puis **`/unfreeze`** pour réactiver.

## 34. Transférer vers un établissement
Créer un wallet établissement (`POST /wallets`, `{ "ownerRef": "CLINIQUE-X", "ownerType": "ETABLISSEMENT" }`) → copier son id.
**`POST /api/v1/wallets/transfer`**, `Idempotency-Key` = `tr-1`, corps :
```json
{ "sourceWalletId": "{walletId}", "destWalletId": "{idEtab}", "montant": 10000, "pin": "1234" }
```
✅ Attendu : après `GET`, source `"solde": 20000`, destinataire `"solde": 10000`. (Le PIN est celui du **wallet source**.)

## 35. Payer une facture depuis le wallet
Émettre une facture (Partie B, `etablissementRef` = `CLINIQUE-X`, une ligne 10 000, sans prise en charge) → copier son id.
**`POST /api/v1/wallets/{walletId}/pay-invoice`**, `Idempotency-Key` = `pay-1`, corps `{ "factureId": "{idFacture}", "montant": 0, "pin": "1234" }` (0 = tout le dû).
✅ Attendu : **201**, opération `PAIEMENT_FACTURE` de 10 000 ; la **facture passe PAYEE** (`GET /invoices/{id}`) ; le solde patient baisse de 10 000.

## 36. Voir le grand livre
**`GET /api/v1/wallets/{walletId}/entries`**.
✅ Attendu : la liste des écritures (montants **signés** : + crédits, − débits).

---

# Partie E — Sécurité du Wallet (P5.3b-1, §6.4)

Rappel : PIN, OTP, limites et signature sont vérifiés **backend seul** (frontière). Utilisez un
**nouveau** portefeuille patient pour ne pas être gêné par un verrou d'un test précédent.

## 37. Débit sans PIN refusé
Créer un wallet patient (test 28), **NE PAS** définir de PIN, le créditer 50 000 (test 29), puis
`/debit` `{ "montant": 1000 }` (sans `pin`).
✅ Attendu : **401** (« Aucun PIN défini »/« PIN requis »). Puis définissez le PIN (test 28b).

## 38. Verrou après 3 mauvais PIN
Sur un wallet **avec** PIN `1234` et du solde : faire **3** débits `{ "montant": 1000, "pin": "0000" }`
(clés `bad-1`, `bad-2`, `bad-3`).
✅ Attendu : les 2 premiers **401** ; le **3ᵉ → 423** (verrouillé). Ensuite un débit **bon PIN** `1234`
→ **423** (toujours verrouillé ~15 min). *(Prenez un nouveau wallet pour la suite.)*

## 39. Limite par opération
Sur un wallet avec PIN et solde : **`PUT /api/v1/wallets/{id}/limits`** corps `{ "plafondOperation": 5000 }`
(puis `GET .../limits` pour vérifier). Débiter `{ "montant": 6000, "pin": "1234" }`.
✅ Attendu : **422** (« Limite par opération dépassée »). Un débit de 5 000 passe (**201**).

## 40. OTP au-delà du seuil (100 000)
Sur un wallet avec PIN et solde ≥ 150 000 :
1. `/debit` `{ "montant": 150000, "pin": "1234" }` (sans OTP) → **401** (« OTP requis »).
2. **`POST /api/v1/wallets/{id}/otp`** `{ "montant": 150000 }` → **200**, `"requis": true`, un `"code"`
   à 6 chiffres (mode **simulé** : le code est renvoyé — SMS « prêt à activer »).
3. `/debit` `{ "montant": 150000, "pin": "1234", "otp": "<code>" }` → **201**.
   *(Sous le seuil, `POST /otp` `{ "montant": 50000 }` renvoie `"requis": false` — aucun OTP.)*

## 41. Intégrité (rappel double écriture + audit)
`GET /api/v1/audit/verify` → `"integre": true`. Les opérations créées ci-dessus portent toutes
`"signee": true`, et la somme de **toutes** les écritures reste **0** (double écriture, §6.3).

---

# Partie F — Détection de fraude + gel sur suspicion (P5.3b-2, §6.4)

Détection par **règles** (la détection par IA reste au futur service CDC_05). **3 paliers** progressifs
(pas un gel binaire) : **ALERTE** (l'op passe) < **CHALLENGE** (re-auth OTP) < **GEL** (bloque + gèle).
Les seuils sont des **données** : l'instance de test tourne avec des seuils abaissés (via variables
d'environnement) pour rendre les paliers visibles — **vélocité ≥ 3 op / 2 min**, **cumul > 50 000 / h**.

## 42. Palier GEL (vélocité + cumul) → blocage + gel
Wallet patient avec PIN `1234`, crédité 300 000. Faire **4** débits rapides de **20 000**
(`Idempotency-Key` différent à chaque fois), tous avec `{ "montant": 20000, "pin": "1234" }` :
- débits **1-2** → **201** (normal) ; débit **3** → **201** (palier ALERTE : l'op passe, une alerte est créée) ;
- débit **4** → **409** « Opération refusée pour raison de sécurité » (**message générique**, ni score ni motif).

Vérifier : `GET /wallets/{id}` → **`"statut": "GELE"`** ; le solde n'a **pas** bougé pour le débit 4
(240 000) ; `GET /api/v1/fraud-alerts/wallet/{id}` montre une alerte **GEL** et une **ALERTE**, statut
`OUVERTE`, avec `motifs` et le `parametres` (snapshot rejouable).

## 43. Palier CHALLENGE (vélocité seule) → re-auth OTP
Nouveau wallet, PIN `1234`, crédité 100 000. Faire **3** débits de **5 000** (→ 201), puis un **4ᵉ** de
5 000 :
1. sans OTP → **401** « Vérification renforcée requise » (générique) ;
2. `POST /wallets/{id}/otp` `{ "montant": 5000 }` → délivre un **`code`** (le challenge exige un OTP même
   sous le seuil de montant) ;
3. `/debit` `{ "montant": 5000, "pin": "1234", "otp": "<code>" }` → **201** (l'opération passe).

## 44. Auto-dégel (gel temporaire à TTL)
Le gel de fraude porte un TTL (défaut 24 h). À l'expiration, la **première opération** ré-active
automatiquement le portefeuille (`WalletUnfrozenAuto` dans l'audit) : le gel n'est jamais éternel, même
sans intervention humaine. *(En test, le TTL peut être forcé côté base pour observer l'auto-dégel sans
attendre.)*

## 45. Revue d'alerte (≠ dégel)
`GET /api/v1/fraud-alerts` (alertes OUVERTES) → copier un `id`. `POST /fraud-alerts/{id}/review`
`{ "revuePar": "agent-1" }` → l'alerte passe **REVUE**. La revue **ne dégèle pas** : le dégel manuel
reste `POST /wallets/{id}/unfreeze`.

## 46. Intégrité après fraude
`GET /api/v1/audit/verify` → `"integre": true` ; la somme de **toutes** les écritures reste **0**
(un GEL bloque l'opération : aucune écriture n'est créée).

---

# Partie G — Cashback (campagnes) + Bonus (P5.3b-3, §6.1/§6.2)

Cashback par **campagnes** (taux en points de base, plafonds, budget = **données**). Le **crédit** du
cashback est **gaté OFF** par défaut (prêt à activer §11) → en dry-run, le montant est **calculé mais non
crédité**. Le **bonus** est actif. L'**acteur** des actes de création monétaire vient de l'en-tête
**`X-Acteur-Id`** (posé par la passerelle) — jamais du corps ; **absent → 401**.

> ⚠️ Le `montant` d'un cashback en **dry-run** n'est **pas** un gain acquis : ne pas l'afficher comme tel.
> Pour observer le crédit réel, l'instance de test est lancée avec `WALLET_CASHBACK_CREDIT_ENABLED=true`.

## 47. Campagne + acteur obligatoire
`POST /api/v1/cashback-campaigns` **sans** en-tête `X-Acteur-Id` → **400** (en-tête obligatoire).
Avec `X-Acteur-Id: admin-1` et un corps (code, `typeOperationSource: "DEBIT"`, `tauxBps: 500`,
plafonds 0, dates larges) → **201**. Recréer une **2ᵉ campagne active** sur le même type → **409**
(une seule active par type).

## 48. Cashback dry-run (crédit OFF) — si l'instance est en mode gaté
Créer un wallet, PIN, créditer, faire un **débit 10 000** (= op source) → copier son `id`.
`POST /api/v1/wallets/{id}/cashback` `{ "operationSourceId": "…" }` →
`{ "accorde": false, "montant": 500, "raison": "crédit désactivé (prêt à activer §11)" }`. `GET .../rewards`
→ `totalCashbackNet: 0` (rien crédité).

## 49. Cashback crédité (crédit ON) + rejeu
Même flux → `{ "accorde": true, "montant": 500 }` ; `GET .../rewards` → `totalCashbackNet: 500`.
Rejouer sur la **même** op source → `"raison": "déjà accordé"`, rewards **inchangé** (pas de double).

## 50. Plafonds & budget
Campagne avec `plafondParOperation: 300` et `budgetTotal: 1000` : un débit de 10 000 (5 % = 500) donne
un cashback **plafonné à 300**. Après trois octrois (900), le 4ᵉ → `{ "accorde": false, "raison":
"budget de campagne épuisé" }`. (Plafonds **par wallet** et **par wallet/jour** de même ; le jour est
keyé sur la **date de l'op source**.)

## 51. Clawback (réversibilité)
`POST /api/v1/wallets/{id}/cashback/reverse` (en-tête `X-Acteur-Id`) `{ operationSourceId,
remboursementId, montantRembourse, montantSource, soldeOperationSource }`. Remboursement **partiel**
→ clawback **proportionnel** ; le remboursement qui **solde** l'op source reprend le **reliquat exact**
(Σ clawbacks = cashback d'origine). `rewards` → cashback net revient à 0. Un remboursement à `montant=0`
ou un 2ᵉ appel du **même** `remboursementId` ne reprend rien (idempotent).

## 52. Bonus (actif, création monétaire tracée)
`POST /api/v1/wallets/{id}/bonus` (en-tête `X-Acteur-Id`, `Idempotency-Key`) `{ "montant": 2000,
"motif": "…" }` → **201** ; `rewards` → `totalBonus: 2000`. Sans en-tête → **401**. L'audit enregistre
l'**acteur**.

---

# Partie H — Contrôle d'intégrité financière interne (P5.3b-4, §6.3)

> **Vocabulaire (important en soutenance)** : ce n'est **pas** un « rapprochement ». Un rapprochement
> confronte **deux sources indépendantes** (relevé opérateur ↔ base). La passerelle réelle et les
> reversements (§11) n'existant pas encore, il n'y a qu'**une** source : cet **auditeur d'intégrité
> interne** vérifie la cohérence de la base **avec elle-même**. Le vrai rapprochement 2 sources = **S11.x**
> (point d'extension documenté, `docs/adr/ADR-014`). Le contrôle est en **lecture seule** et fait de la
> **détection SEULE** : il ne corrige **jamais** un écart (§11).

Trois contrôles : **double écriture wallet** (§6.3), **facture ↔ règlement** (§7.3), **cashback**
(§6.1/§6.2). Il opère sur un **snapshot** figé à un arrêté `T` (fin de journée UTC).

## 53. Jeu SAIN → vert
`POST /api/v1/integrity-checks/run` (sans paramètre = aujourd'hui). Sur les données légitimes créées
plus haut (paiements, factures, wallet, cashback), le run doit ressortir **`statut: "OK"`, `nbEcarts: 0`**.
Un vert **sur données saines** ne prouve pas grand-chose seul (il pourrait être vert parce que vide) —
d'où l'injection d'anomalies au test 54.

## 54. Anomalies injectées → chacune détectée
L'instance de test est lancée avec `INTEGRITE_DEV_SEED=true`.
`POST /api/v1/integrity-checks/dev/seed-anomalies` → **200**, `injecte: 6` (6 anomalies volontaires).
Puis `POST /api/v1/integrity-checks/run` → **`statut: "ECARTS"`**, **`nbEcarts: 7`**.
`GET /api/v1/integrity-checks/{runId}` liste les 7 écarts, un par type :
- `OPERATION_DESEQUILIBREE` **et** `GRAND_LIVRE_NON_NUL` (l'opération déséquilibrée casse aussi le total)
- `SOLDE_NEGATIF_NON_AUTORISE` (un PATIENT à solde négatif ; un compte SYSTEME négatif, lui, est **toléré**)
- `FACTURE_STATUT_INCOHERENT` (facture PAYEE mais reste dû non soldé)
- `ENCAISSEMENT_NON_REPERCUTE` (paiement passerelle SUCCESS non imputé à la facture)
- `CASHBACK_BUDGET_DEPASSE` (cashback consommé > budget de campagne)
- `CLAWBACK_SUPERIEUR_ORIGINE` (reprise > cashback d'origine)

Chaque écart porte `severite`, `reference` (l'entité fautive), `montantAttendu`/`montantConstate` et un
`details` rejouable. **Aucune donnée financière n'est modifiée** (détection seule).

## 55. Idempotence + endpoint de dév masqué en prod
Relancer `POST /run` pour la **même** journée → le verdict est **remplacé**, pas dupliqué :
`GET /api/v1/integrity-checks` ne montre qu'**un** run par journée. Sur une instance **sans**
`INTEGRITE_DEV_SEED` (production), `POST .../dev/seed-anomalies` → **404** (l'endpoint de dév n'existe pas).

## 56. Automatique quotidien
Le contrôle tourne **automatiquement** chaque jour (batch planifié `@Scheduled`, horaire = donnée
`INTEGRITE_PLANIF_CRON`, contrôle la **veille**). L'exécution manuelle ci-dessus sert la preuve ; en
exploitation, le run quotidien alimente les mêmes tables et alerte en cas d'écart.

---

# Partie I — Cartes bancaires (P5.4a, §5)

> **Paiement SIMULÉ (FT5)** : deux PSP simulés déterministes, `sim_tokenise` (frictionless / défi 3DS)
> et `sim_redirige` (redirection + webhook). **Frontière PCI** : aucun PAN/CVV n'entre jamais ; le service
> ne voit que des **tokens** et des métadonnées non sensibles. Le sous-état interne `StatutCarte` n'est
> **jamais** exposé — le front ne reçoit que `PaiementStatut` (générique) + une `ActionClient`
> (`AUCUNE / DEFI_3DS / REDIRECTION / REFUSEE`). Détails : `docs/adr/ADR-015`.

Base : `B=http://localhost:8080/api/v1`. En-têtes : `X-Utilisateur-Id` (identité posée par la passerelle),
`Idempotency-Key` (obligatoire sur toute écriture). Tous les montants sont en **XOF entiers**.

## 57. Frictionless → SUCCESS (+ enrôlement au vault)
`POST $B/card-payments` avec `X-Utilisateur-Id: user-1`, `Idempotency-Key: k-fric-1`, corps
`{"psp":"sim_tokenise","referenceClient":"tok_test_frictionless","montant":6000,"objet":"RENDEZ_VOUS","enregistrerCarte":true}`
→ **201**, `statut: "SUCCESS"`, `action: "AUCUNE"`. Puis `GET $B/cards` (`X-Utilisateur-Id: user-1`) →
**1 carte** `VISA 4242` `parDefaut:true` — **sans** token ni empreinte (frontière PCI).

## 58. Rejeu idempotent
Rejouer **exactement** le POST du test 57 (même `Idempotency-Key: k-fric-1`) → **200**, `rejoue: true`,
même paiement. La passerelle n'est pas ré-appelée.

## 59. Défi 3DS (tokenisé) + finalisation vérité serveur
`POST $B/card-payments` (`referenceClient:"tok_test_challenge"`, `Idempotency-Key: k-chal-1`) → **201**,
`statut: "PENDING"`, `action: "DEFI_3DS"`, `challengeRef` présent. Puis
`POST $B/card-payments/{paiementId}/finalize` → **200**, `statut: "SUCCESS"` (le serveur lit le statut
**autoritatif** du PSP ; le client ne déclare jamais le résultat 3DS).

## 60. Refus
`POST $B/card-payments` (`referenceClient:"tok_test_refus"`) → `statut: "FAILED"`, `action: "REFUSEE"`,
`codeRefus` générique. Aucun `StatutCarte` interne n'apparaît.

## 61. Remboursements (partiel, cumul > capturé, total)
Sur le paiement du test 57 (`{paiementId}`, capturé 6000) :
- `POST .../refund` `{"montant":2000,"devise":"XOF"}` (`Idempotency-Key: k-rf-1`) → **200**, reste `SUCCESS`.
- `POST .../refund` `{"montant":5000}` (cumul 7000 > 6000) → **422** « Remboursement cumulé supérieur au capturé ».
- `POST .../refund` `{"montant":4000}` (cumul 6000 = total) → **200**, `statut: "REFUNDED"`.

Le contrôle du cumul est **backend** (frontière) ; le remboursement va **toujours** vers la carte d'origine.

## 62. Filtre anti-PAN (§9) — PCI en bord d'entrée
`POST $B/card-payments` avec un **PAN** en clair dans un champ, ex.
`{"psp":"sim_tokenise","referenceClient":"4111111111111111","montant":6000,"objet":"RENDEZ_VOUS"}`
→ **422** « Donnée de carte en clair détectée… ». Contrôle : `docker logs payment-payment-1 | grep 4111111111111111`
→ **aucune occurrence** (le corps d'un paiement carte n'est **jamais** journalisé, interdit #7).

## 63. Redirigé + webhook SIGNÉ (source de vérité §7.3)
`POST $B/card-payments` (`psp:"sim_redirige"`, `referenceClient:"red_test_succes"`) → **201**, `PENDING`,
`action: "REDIRECTION"`, `urlRedirection` (la `refPasserelle` est le segment après `/pay/`). Construire le
corps du webhook `{"evenementId":"evt-1","type":"payment.updated","refPasserelle":"<REF>","issue":"AUTORISE","horodatage":"<ISO-8601 UTC now>","marque":"MASTERCARD","last4":"4444"}`,
signer avec `openssl dgst -sha256 -hmac "dev-hmac-sim_redirige" -hex corps.json`, puis
`POST $B/card-webhooks/sim_redirige` (corps brut, en-têtes `X-Signature: <hex>`, `X-Timestamp: <ISO>`) →
**200**. `GET $B/card-payments/{paiementId}` → `statut: "SUCCESS"`.

## 64. Webhook : rejeu, signature altérée, horodatage périmé (anti-fuite)
- **Rejeu** du même webhook (même `evenementId`) → **200** idempotent (pas de double capture).
- **Signature altérée** (`X-Signature: deadbeef…`) → **401** « Webhook rejeté. » (message générique).
- **Horodatage périmé** (`horodatage` = `now − 10 min`, pourtant bien signé) → **401** (fenêtre de fraîcheur
  ±5 min ; l'horodatage est **dans le corps signé**, donc infalsifiable).

Les trois rejets renvoient le **même** 401 générique : on ne révèle jamais lequel des contrôles a échoué.

## 65. Concurrence : 2× finalize → 1 seule capture
Créer un défi (test 59), puis lancer **deux** `POST .../finalize` **en parallèle**. Les deux répondent
**200**, mais le paiement n'est capturé **qu'une fois** : `montant_capture` = montant (pas le double), une
seule transition vers `SUCCESS` (verrou pessimiste `FOR UPDATE`).

## 66. Expiration par job planifié
Créer `referenceClient:"tok_test_expire"` (TTL de défi déjà dépassé) → `PENDING` / `DEFI_3DS`. **Sans**
finaliser, attendre ~60 s : le job d'expiration (`@Scheduled`, ~1 min) bascule le paiement en
`statut: "CANCELLED"` (sous-état `EXPIREE`).

## 67. Réconciliation à DEUX sources (≠ auditeur interne P5.3b-4)
`POST $B/card-reconciliations/run?date=<aujourd'hui>` → un rapport **par PSP** avec `nbEcarts: 0` sur les
données saines (registre local ⇄ vérité PSP). **Preuve de détection** : basculer en base une transaction
capturée en `REFUSEE` (`UPDATE carte_transactions SET statut_carte='REFUSEE' WHERE ref_passerelle LIKE 'SIMRD-SUCCES-%'`),
relancer le run → **`nbEcarts: 1`** avec le détail (`statutLocal:REFUSEE`/`ECHOUEE` vs `issuePsp:AUTORISE`/`REUSSIE`,
`montantLocal` ≠ `montantPsp`). Idempotent (un rapport par `date,psp` au rejeu). **Détection seule** : aucune
donnée n'est corrigée. (Remettre la valeur à `CAPTUREE` pour laisser les données propres.)

---

## (Option) Tout tester d'un coup avec Postman
Importer `services/payment/postman/MASANTE-Payment-P5.1.postman_collection.json` dans Postman, puis
**Run collection** → toutes les requêtes (paiement **et** facturation) s'exécutent avec leurs
vérifications automatiques (tout doit être vert).

---

## Bilan — cochez
**P5.1 (déjà validé G5)** — 1-12 : prise en charge, paiement idempotent, transitions, audit, remboursement.

**P5.2a Facturation (déjà validé G5)** — 13-18.

**P5.2b Avoir + versionnage + signature (déjà validé G5)** — 19-27.

**P5.3a Wallet + double écriture (déjà validé G5)** — 28-36 (avec le PIN du test 28b).

**P5.3b-1 Sécurité du Wallet (déjà validé G5)** — 28b, 31, 37-41.

**P5.3b-2 Détection de fraude + gel sur suspicion (déjà validé G5)** — 42-46.

**P5.3b-3 Cashback (campagnes) + Bonus** :
- [ ] 47 Campagne : sans acteur **400**, avec acteur **201**, 2ᵉ active même type **409** OK
- [ ] 48 Cashback **dry-run** (crédit OFF) : `accorde:false`, montant calculé, rien crédité OK
- [ ] 49 Cashback **crédité** (crédit ON) = taux×base ; rejeu = « déjà accordé », pas de double OK
- [ ] 50 **Plafond** opération (cap) + **budget** épuisé → refus OK
- [ ] 51 **Clawback** proportionnel + reliquat exact ; idempotent par `remboursementId` OK
- [ ] 52 **Bonus** avec acteur **201** / sans acteur **401** ; audit trace l'acteur OK

**P5.3b-4 Contrôle d'intégrité financière interne** :
- [ ] 53 Jeu **sain** → `statut: OK`, `nbEcarts: 0`
- [ ] 54 **6 anomalies injectées** → `statut: ECARTS`, **7 écarts** (un par type, détail listé) OK
- [ ] 55 **Idempotence** (1 run/journée au rejeu) + endpoint dév **404** sans `INTEGRITE_DEV_SEED`
- [ ] 56 Batch **automatique quotidien** (`@Scheduled`, horaire = donnée) présent

**P5.4a Cartes bancaires (§5)** :
- [ ] 57 Frictionless → **SUCCESS** + carte enrôlée au vault (sans token/empreinte)
- [ ] 58 Rejeu même `Idempotency-Key` → **200** `rejoue:true`
- [ ] 59 Défi 3DS → `PENDING`/`DEFI_3DS`, `finalize` → **SUCCESS** (vérité serveur)
- [ ] 60 Refus → `FAILED`/`REFUSEE` (aucun `StatutCarte` exposé)
- [ ] 61 Remboursements : partiel **200** → cumul>capturé **422** → total **REFUNDED**
- [ ] 62 **Filtre anti-PAN** : PAN→**422** + `grep` logs = **0 occurrence**
- [ ] 63 Redirigé + **webhook signé HMAC** → **200** → **SUCCESS**
- [ ] 64 Webhook : rejeu→**200** ; altéré→**401** ; périmé→**401** (générique)
- [ ] 65 **Concurrence** 2×finalize → **1 seule capture**
- [ ] 66 **Expiration** par job (~1 min) → `CANCELLED`
- [ ] 67 **Réconciliation 2 sources** : sain→0 ; anomalie→1 écart ; idempotent

Si tout est coché → répondez-moi **« Cartes OK »** : j'inscris le **G5** de P5.4a.
Si un point coince, dites-moi le numéro et ce que vous voyez.

---

# Partie J — Reversements aux établissements (P5.5a, §11)

> **Décaissement SIMULÉ / différé (FT5)** : P5.5a **calcule** les sommes dues et fige un **relevé
> immuable** ; il ne verse **rien**. L'exécution (grand livre en partie double, destination de paiement
> chiffrée, quatre-yeux) = **P5.5b**. Le vrai rapprochement « factures ↔ reversements » = **P5.5c**
> (S11.x). **Frontière** : tout le calcul (sommes dues, commission, report, net) est **backend seul**
> (`ReglesReversement`) ; le portail établissement (web) sera en lecture seule.

Concepts clés à vérifier : l'**assiette** repose sur `factures.soldee_a` (date de solde **immuable**,
jamais `updated_at`) ; le **double paiement** est bloqué par l'index I1 (une facture sur ≤1 relevé
vivant), **pas** par la fenêtre de dates → une pièce en retard est **rattrapée** au relevé suivant ; un
**net négatif** (remboursements > encaissements) ne décaisse rien et **reporte** la dette ; le **taux de
commission** est une donnée (aucun 0 % implicite : sans config, le calcul **échoue**).

## 68. Taux de commission — garde ADMIN_FINANCE
`POST /api/v1/settlements/commission-config` avec en-têtes `X-Acteur-Id: agent` et
`X-Acteur-Role: AGENT`, corps `{"tauxBps":250,"motif":"x"}` → **403** (rôle insuffisant, message
générique). Rejouer avec `X-Acteur-Role: ADMIN_FINANCE` → **201** (taux plateforme par défaut ouvert,
audité nominativement). `GET .../commission-config` → le taux ouvert.

## 69. Calcul d'un relevé → sommes dues correctes
Prérequis : deux factures **PAYEE** de `ETB-LIVE` (100 000 et 50 000) réglées (leur `soldee_a` est posée
automatiquement au passage à PAYEE). `POST /api/v1/settlements/run` (en-tête `X-Acteur-Id: agent.calcul`)
corps `{"etablissementRef":"ETB-LIVE","periodeDebut":"2026-01-01T00:00:00Z","periodeFin":"2027-01-01T00:00:00Z"}`
→ **201**, `statut: CALCULE`, **brut 150 000**, **commission 3 750** (2,50 %), **net 146 250**,
`soldeReporte 0`, `tentative 1`, numéro `REV-ETBLIVE-2026-000001`.
`GET /api/v1/settlements/{id}` → **2 lignes de type FACTURE** (Σ lignes = en-tête).

## 70. Idempotence de période
Rejouer le **même** `run` (même période) → **409** (un relevé actif couvre déjà la période). Aucun
doublon.

## 71. Chaînage du report + rattrapage automatique
Établissement `ETB-SEQ`, une facture 100 000. `run` P1 `[2026-01-01, 2026-09-01)` → relevé **A**
(`relevePrecedentId: null`, net 97 500). Ajouter une facture 50 000. `run` P2 `[2026-09-01, 2027-09-01)`
→ relevé **B** avec **`relevePrecedentId = A.id`**, net **48 750** — l'assiette de P2 **n'a pas repris**
la 1ʳᵉ facture (déjà imputée sur A, I1), preuve que c'est l'imputation, pas la fenêtre, qui protège.

## 72. Règle d'annulation (dépendance de report respectée)
Sur la chaîne A→B ci-dessus : `POST /api/v1/settlements/{A}/cancel` (motif obligatoire) → **409**
(A a un successeur vivant B). `POST .../{B}/cancel` → **200**. Puis `POST .../{A}/cancel` → **200**.
L'annulation **libère les pièces** (recalcul `tentative+1` possible sur la même période).

## 73. Net négatif → report, aucun décaissement *(logique)*
Prouvé au niveau règle pure (G3, `ReglesReversementTest`) et par les CHECK base
(`V10_verification_invariants.sql`) : remboursements > encaissements → `net = 0`,
`soldeReporte < 0` (repris comme `report_anterieur` au relevé suivant). En live, un remboursement carte
REUSSI de l'établissement (Partie I) entre dans l'assiette par sa date `cree_le`.

## 74. Invariants base (script de recette)
`psql -v ON_ERROR_STOP=1 -f src/test/resources/db/verification/V10_verification_invariants.sql` →
**tout PASS**, `ROLLBACK` (aucune donnée laissée) : `soldee_a` immuable, I1 (facture sur ≤1 relevé
actif), période verrouillée/libérée par annulation, ≤1 successeur vivant, équation d'en-tête, cut-off,
cohérence de ligne, un seul taux ouvert, réconciliation en-tête/lignes vide.

---

## Récapitulatif des cases — Partie J
- [ ] 68 Taux commission : `AGENT`→**403**, `ADMIN_FINANCE`→**201** (audité)
- [ ] 69 `run` → brut 150 000 / commission 3 750 / net 146 250 ; 2 lignes FACTURE
- [ ] 70 `run` identique même période → **409**
- [ ] 71 Chaînage report : B.`relevePrecedentId = A.id` ; assiette P2 sans la facture déjà imputée
- [ ] 72 Annulation : A (successeur vivant)→**409** ; B→**200** ; puis A→**200**
- [ ] 73 Net négatif → net 0 + `soldeReporte < 0` (G3 + CHECK base)
- [ ] 74 `V10_verification_invariants.sql` → tout **PASS** / ROLLBACK

Si tout est coché → **« Reversements OK »** : j'inscris le **G5** de P5.5a.

---

# Partie K — Reversements : destinations, quatre-yeux, constatation (P5.5b-1, §11)

> **Décaissement toujours SIMULÉ / différé (P5.5b-2)** : b-1 **reconnaît la dette** (écriture de
> constatation) mais **ne verse rien**. Nouveautés : registre de **destinations chiffrées** (AES-GCM,
> jamais de MSISDN/IBAN en clair ni en log), **approbation à quatre-yeux** (approbateur ≠ calculateur **et**
> ≠ créateur de la destination), **anti-substitution** de destination, grand livre en **partie double**
> (constatation), **contre-passation** (extourne), état **REJETE**.

> **Authentification des actes sensibles** : `destinations`, `approve`, `reject`, `commission-config`
> exigent un **principal signé** (fini le rôle déclaré en clair). Deux en-têtes : `X-Principal` =
> base64(JSON `{sub,roles,iat,exp,method,path,nonce}`) et `X-Principal-Sig` = base64(HMAC-SHA256 du
> **texte** X-Principal, secret partagé passerelle↔service). En dév, le secret est fixé dans
> `docker-compose.yml` (`MASANTE_PAYMENT_PRINCIPAL_SECRET`). Frappe d'un principal (Python) :
> ```python
> import base64,hmac,hashlib,json,time,uuid
> S=base64.b64decode("cHJpbmNpcGFsLWRldi1zZWNyZXQtMDEyMzQ1Njc4OSEh")
> def mint(sub,roles,method,path):
>     p={"sub":sub,"roles":roles,"iat":int(time.time()),"exp":int(time.time())+120,
>        "method":method,"path":path,"nonce":str(uuid.uuid4())}
>     xp=base64.b64encode(json.dumps(p).encode()).decode()
>     sig=base64.b64encode(hmac.new(S,xp.encode(),hashlib.sha256).digest()).decode()
>     return xp,sig   # -> en-têtes X-Principal / X-Principal-Sig
> ```
> Le `path` signé doit être **exactement** l'URI appelée (ex. `/api/v1/settlements/{id}/approve`).

## 75. Destination : garde de rôle + création chiffrée
`POST /api/v1/settlements/destinations` `{ "etablissementRef":"ETB-B1","type":"MOBILE_MONEY",
"reference":"07 09 01 02 03","motif":"compte Orange" }`. Avec un principal **sans** `ADMIN_FINANCE` →
**403**. Avec `ADMIN_FINANCE` → **201** ; la réponse porte `libelle` (« Orange Money » + 8 hex d'empreinte)
et `empreinte`, **jamais** `ref_chiffree`. Format validé (MSISDN CI 01/05/07 ; IBAN CI mod-97) → sinon **422**.

## 76. Anti-rejeu du principal
Rejouer le **même** couple `X-Principal`/`X-Principal-Sig` (même `nonce`) → **401** (usage unique, Redis).
La fraîcheur (`exp`, ±5 min) et la liaison `method`+`path` sont également contrôlées : réutiliser une
signature d'`approve` d'un relevé sur un autre → **401**.

## 77. Quatre-yeux à l'approbation
Émettre un relevé : `POST /settlements/run` (`X-Acteur-Id: agent.calcul`). L'approuver **avec le même
sub** `agent.calcul` → **409** (l'approbateur ne peut être le calculateur). Avec `sub` = créateur de la
destination → **409**. Avec `dir.finance` (≠ calculateur, ≠ créateur) → **200**.

## 78. Constatation comptable équilibrée
Après approbation, `GET /settlements/{id}/ledger` → une écriture **CONSTATATION**, 3 jambes :
DÉBIT `TRESORERIE` = brut ; CRÉDIT `COMMISSION` + `A_REVERSER` (+ `REMBOURSEMENT`/`CREANCE_ETABLISSEMENT`
selon le cas). **Σdébit = Σcrédit**. Une **2ᵉ** approbation du même relevé est impossible (une seule
constatation par relevé).

## 79. Anti-substitution de destination
Émettre un relevé (fige l'empreinte de la destination **au calcul**). Ouvrir **une nouvelle** destination
(change l'active). Approuver le relevé → **409** « destination changée depuis le calcul » → recalcul requis.

## 80. Rejet + annulation avec extourne
`POST /settlements/{id}/reject` (principal ADMIN_FINANCE, motif) sur un relevé CALCULE → **REJETE**,
pièces libérées. Annuler un relevé **APPROUVE** (`POST /settlements/{id}/cancel`, `X-Acteur-Id`) →
**ANNULE** + une écriture **EXTOURNE** (inverse de la constatation) apparaît au `ledger` (append-only,
aucune ligne effacée).

## 81. Invariants base (script de recette)
`psql -f src/test/resources/db/verification/V11_verification_invariants.sql` → **tout PASS** / ROLLBACK :
signe libre report/solde, quatre-yeux (CHECK), destination active unique, constatation unique, règle
EXTOURNE, **balayage global Σdébit=Σcrédit**, **balance A_REVERSER = Σ net approuvé**.

---

## Récapitulatif des cases — Partie K
- [ ] 75 Destination : role non-ADMIN→**403** ; ADMIN_FINANCE→**201** ; `ref_chiffree` jamais exposée ; format invalide→**422**
- [ ] 76 Rejeu du principal (nonce/liaison requête)→**401**
- [ ] 77 Quatre-yeux : approbateur = calculateur→**409** ; = créateur destination→**409** ; distinct→**200**
- [ ] 78 Constatation : 3 jambes, Σdébit=Σcrédit ; 2ᵉ approbation→**409**
- [ ] 79 Anti-substitution : destination changée depuis le calcul→**409**
- [ ] 80 Reject→REJETE ; annulation d'un approuvé→ANNULE + écriture EXTOURNE
- [ ] 81 `V11_verification_invariants.sql`→tout **PASS** (dont balance A_REVERSER)

Si tout est coché → **« Reversements b-1 OK »** : j'inscris le **G5** de P5.5b-1.
