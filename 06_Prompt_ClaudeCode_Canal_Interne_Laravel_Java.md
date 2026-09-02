# PROMPT CLAUDE CODE — CANAL INTERNE LARAVEL ↔ PAIEMENT-SERVICE (JAVA)
## Deux volets, deux projets, deux exécutions séparées — Lot 6 de la séquence post-facturation

> Le volet Laravel applique `claude/00_Conventions_Transversales_MaSante.md`. Le volet Java applique les conventions déjà en vigueur dans `paiement-service`, relevées par sa propre Phase 0 (rapport GeniusPay).

---

## CONTEXTE COMMUN AUX DEUX VOLETS

L'audit Phase 0 du microservice Java a établi que l'authentification interne existe déjà : `ServicePrincipal`, en-têtes `X-Principal` + `X-Principal-Sig`, HMAC lié à `method+path`, nonce Redis anti-rejeu. **Ce mécanisme n'est ni inventé ni recréé ici — il est réutilisé tel quel, dans les deux sens.**

Ce canal sert deux directions :
- **Laravel → Java** : initiation d'un paiement (`POST /interne/v1/paiements`), déjà exposée côté Java (`§7.6` du prompt GeniusPay), pas encore appelée depuis Laravel.
- **Java → Laravel** : notification de paiement réussi/échoué, portant montant, frais réels et net (D2) — c'est le trou identifié comme B2 dans l'audit GeniusPay, toujours ouvert.

---

## VOLET 1 — LARAVEL (à coller dans le backend Laravel)

### COPIER À PARTIR D'ICI

Tu ajoutes le client sortant et l'endpoint entrant qui relient Laravel au microservice de paiement Java.

### INTERDICTIONS ABSOLUES

1. **Tu ne réinventes pas le mécanisme de signature.** Lis `apps/web/src/lib/paiement.ts` : c'est l'implémentation de référence du principal signé côté client existant. Ton client Laravel doit produire des en-têtes strictement identiques en forme.
2. **Aucun secret partagé écrit en dur.** Variable d'environnement, jamais versionnée.
3. **Aucune nouvelle migration** au-delà de ce que ce volet décrit explicitement.
4. **Tu n'écris jamais directement dans `commissions_transaction`** depuis le contrôleur — tu appelles `CommissionService::calculerEtEnregistrer()` (lot 2). Le contrôleur ne fait que vérifier, désérialiser, et déléguer.

### PHASE 0 — AUDIT CIBLÉ

1. Lis intégralement `apps/web/src/lib/paiement.ts` : algorithme exact de signature, en-têtes produits, gestion du nonce.
2. Confirme l'URL de base du `paiement-service` en environnement de développement (variable d'environnement existante ou à créer).
3. Confirme que `CommissionService` (lot 2) est bien disponible et son contrat d'entrée exact.
4. Vérifie qu'aucun endpoint `/api/interne/` n'existe déjà sous un nom proche.

**Arrête-toi et rapporte.**

### PHASE 1 — Client sortant `PaiementServiceClient`

`app/Services/PaiementServiceClient.php` : méthode `initierPaiement(...)`, signe la requête exactement comme `paiement.ts`, appelle `POST {PAIEMENT_SERVICE_BASE_URL}/interne/v1/paiements`, délais de connexion et de lecture explicites (jamais de client sans timeout), traduit toute réponse hors 2xx en exception typée.

### PHASE 2 — Endpoint entrant de notification

`POST /api/interne/v1/paiements/notification` :

1. Vérifie `X-Principal` + `X-Principal-Sig` selon l'algorithme relevé en Phase 0 — comparaison à temps constant (`hash_equals()`), jamais `===`.
2. Signature invalide → `401`, corps vide de détail, événement journalisé.
3. Désérialise `referenceInterne`, `montant`, `fraisPasserelle`, `fraisPrestataire`, `net`, `statut`, `facturePatientId` (ou la référence permettant de le retrouver).
4. Sur statut de succès : appelle `CommissionService::calculerEtEnregistrer()` (lot 2) — **c'est lui qui gère l'idempotence sur `reference_interne_paiement`**, ce contrôleur ne vérifie pas de doublon lui-même.
5. Sur statut d'échec/annulation : marque la `facture_patient` correspondante `A_REGLER` de nouveau (pas de commission créée).
6. Répond `200` rapidement. Aucune logique métier au-delà de la vérification et de la délégation.

### TESTS
1. `test_signature_valide_acceptee`.
2. `test_signature_invalide_rejetee_401`.
3. `test_notification_dupliquee_ne_cree_pas_deux_commissions` (délégué à l'idempotence du lot 2, vérifié ici de bout en bout).
4. `test_echec_remet_facture_a_regler`.
5. `test_client_sortant_respecte_les_timeouts`.

### CHECKLIST
- [ ] Signature vérifiée à temps constant
- [ ] Aucun secret en dur
- [ ] Les 5 tests passent
- [ ] Aucune logique de commission dans le contrôleur lui-même

---

## VOLET 2 — JAVA (à coller dans `paiement-service`)

### COPIER À PARTIR D'ICI

Tu ajoutes l'appel sortant qui notifie Laravel depuis le relais de l'outbox déjà existant (P5.4c, `notifications_outbox`).

### INTERDICTIONS ABSOLUES

1. **Tu ne modifies pas la machine à états existante ni `PasserellePaiement`.**
2. **Tu ne crées pas de nouvelle table.** `notifications_outbox` existe déjà.
3. **Aucune dépendance ajoutée** sans signalement et arrêt.

### PHASE 0 — AUDIT CIBLÉ

1. Confirme la structure exacte de `notifications_outbox` et du relais planifié qui la consomme (P5.4c).
2. Confirme si un utilitaire de signature **sortante** existe déjà côté Java (le principal signé documenté n'est vérifié qu'en **entrée** par `ServicePrincipal` — un client sortant qui signe une requête vers Laravel n'a peut-être pas d'équivalent). Si aucun n'existe, c'est un point à rapporter, pas à improviser.
3. Confirme l'URL de configuration à ajouter pour joindre Laravel (nouvelle variable d'environnement, jamais en dur).

**Arrête-toi et rapporte.**

### PHASE 1 — Implémentation de `NotificateurFacturation`

1. L'implémentation écrit une ligne dans `notifications_outbox` au moment où une transaction passe `REUSSIE`, `ECHOUEE`, `ANNULEE` ou `EXPIREE` (mêmes événements que la machine à états documentée dans l'arbitrage GeniusPay).
2. Le relais existant est étendu pour cibler `POST {LARAVEL_BASE_URL}/api/interne/v1/paiements/notification`, signé selon le même mécanisme que `ServicePrincipal` vérifie en entrée côté Java — réutilisé en miroir pour signer en sortie.
3. Échec de livraison → le relais réessaie selon sa politique existante (déjà en place pour l'outbox) ; **aucune nouvelle politique de rejeu n'est inventée ici**.

### TESTS
1. `test_notification_ecrite_dans_outbox_sur_succes`.
2. `test_notification_ecrite_dans_outbox_sur_echec`.
3. `test_signature_sortante_verifiable_par_laravel` (test d'intégration, ou a minima vérification unitaire de l'algorithme).

### CHECKLIST
- [ ] Aucune nouvelle table
- [ ] Aucune modification de la machine à états
- [ ] Les 3 tests passent

---

## HORS PÉRIMÈTRE (les deux volets)

L'intégration GeniusPay elle-même (lot séparé, prompt v3/overlay), la génération de factures (lot 3), les notifications patient (lot 9).

## FIN DU PROMPT
