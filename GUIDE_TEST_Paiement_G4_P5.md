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

**P5.3b-1 Sécurité du Wallet** :
- [ ] 28b PIN défini (**204**) OK
- [ ] 31 Débit bon PIN → **201**, `"signee": true` OK
- [ ] 37 Débit **sans PIN → 401** OK
- [ ] 38 **3 mauvais PIN → verrou (423)**, puis bon PIN → 423 OK
- [ ] 39 Plafond opération → débit au-dessus **422** OK
- [ ] 40 OTP requis > seuil : sans OTP **401**, avec OTP valide **201** ; sous le seuil `requis:false` OK
- [ ] 41 Audit **intègre**, opérations **signées**, somme des écritures **= 0** OK

Si tout est coché → répondez-moi **« P5.3b-1 OK »** : j'inscris le **G5** de P5.3b-1 et j'enchaîne
P5.3b-2 (détection de fraude + gel sur suspicion). Si un point coince, dites-moi le numéro et ce que vous voyez.
