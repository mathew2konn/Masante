# CAHIER DES CHARGES N°6 — ARCHITECTURE PAIEMENT
## Projet MASANTÉ — Plateforme Numérique de Santé de Nouvelle Génération
**Version 1.0 — Document destiné à Claude Code**

---

## 0. Position du document dans le corpus MASANTÉ

Corpus de 13 cahiers des charges interdépendants (tableau complet dans CDC_01 §0). Le domaine Paiement est un **microservice totalement indépendant du cœur applicatif**, ce qui permet de faire évoluer ou remplacer les fournisseurs sans impacter le reste de la plateforme.

Dépendances : CDC_03 (intégration avec Laravel, événements), CDC_04 (schéma des tables financières), CDC_05 (détection de fraude par IA), CDC_09 (référentiels : actes médicaux, tarifs, établissements), CDC_10 (sécurité, PCI DSS, audit), CDC_11 (parcours utilisateur de paiement), CDC_12 (découpage microservices), CDC_13 (données analytiques financières).

---

## 1. Périmètre fonctionnel

Le domaine couvre le paiement de : consultations, médicaments, analyses médicales, examens radiologiques, hospitalisations, ambulances, assurances, cotisations CNAM — ainsi que le portefeuille électronique (Wallet), la facturation et les reversements vers les établissements.

**Principe fondamental — Paiement direct** : le paiement est traité directement par le prestataire de paiement de l'hôpital ou de la pharmacie. **La plateforme MASANTÉ ne manipule jamais les fonds.** Elle orchestre, trace, réconcilie et facture.

---

## 2. Technologie imposée

| Composant | Technologie | Justification |
|-----------|-------------|---------------|
| Microservice paiement | **Java + Spring Boot** | Référence des systèmes financiers et bancaires : fiabilité, sécurité, gestion avancée des transactions, écosystème mature d'API de paiement |
| Base transactionnelle | **PostgreSQL** | Transactions ACID, cohérence forte |
| Cache / idempotence | **Redis** | Verrous, clés d'idempotence, sessions |
| Messagerie | **RabbitMQ** (+ Kafka pour l'analytique) | Événements de paiement |
| Sécurité | TLS 1.3, AES-256, HSM/KMS, PCI DSS | Voir §9 |
| Observabilité | Prometheus, Grafana, ELK, OpenTelemetry | Suivi temps réel des transactions |

Le service de paiement est **indépendant** : le cœur Laravel ne connaît jamais les API des opérateurs.

---

## 3. Architecture

```
Patient / Établissement
        │
Application MASANTÉ (Mobile / Web)
        │
   API Gateway
        │
  Payment Gateway (Spring Boot)
        │
 ┌──────┼─────────┬─────────┬──────────┬────────┐
 Mobile Money  Carte    Wallet   Assurance   CNAM
        │
  Service Paiement → Base Paiement → Facturation → Comptabilité → Reversements
```

### 3.1 Microservices du domaine
`payment-service`, `billing-service`, `invoice-service`, `wallet-service`, `insurance-service`, `cnam-service`, `refund-service`, `settlement-service` (reversements), `notification-service` (transversal), `fraud-detection-service`. Chaque service est indépendant, avec sa propre base et son cycle de déploiement.

### 3.2 Principes de conception
Paiement omnicanal, temps réel, différé, fractionné ; haute disponibilité ; forte sécurité ; audit complet ; traçabilité ; **idempotence** ; conformité **PCI DSS**.

### 3.3 Extensibilité (OCP obligatoire)
```java
public interface PaymentGateway {
    PaymentResult pay(PaymentRequest request);
    PaymentStatus status(String transactionId);
    RefundResult refund(RefundRequest request);
}
```
Implémentations : `OrangeMoneyAdapter`, `MtnMoMoAdapter`, `WaveAdapter`, `MoovMoneyAdapter`, `VisaAdapter`, `MastercardAdapter`, `AmexAdapter`, `GimUemoaAdapter`, `WalletAdapter`, `CnamAdapter`, `InsuranceAdapter`, `TresorPublicAdapter`.
**Interdiction absolue** d'un `if (type == "orange") … if (type == "wave") …`. Ajouter un moyen de paiement ne doit jamais modifier le code existant.

---

## 4. Mobile Money (canal principal)

Plus de 90 % des paiements numériques en Côte d'Ivoire passent par le Mobile Money. Opérateurs à intégrer : **Orange Money (Max it)**, **MTN Mobile Money (MoMo)**, **Wave**, **Moov Money**, et tout opérateur futur — chacun derrière son propre adaptateur, afin d'isoler les changements d'API et de limiter l'impact des pannes.

### 4.1 Flux de paiement
```
Patient → Choix Mobile Money → Choix opérateur → Création transaction
→ Redirection / appel API opérateur → Validation PIN → Paiement
→ Confirmation → Webhook → MASANTÉ → Facture → Notification
```

### 4.2 États d'une transaction (machine à états stricte)
```
INITIATED → PENDING → PROCESSING → SUCCESS
                              ↘ FAILED
                              ↘ CANCELLED
SUCCESS → REFUNDED
```
Toute transition est horodatée, persistée et auditée. Aucune transition arbitraire n'est autorisée.

### 4.3 Données stockées par transaction
Transaction ID (interne), montant, devise (XOF par défaut), numéro de téléphone (masqué à l'affichage), opérateur, statut, référence opérateur, dates (création, mise à jour, confirmation), webhook reçu (contenu signé), historique complet des transitions, `Idempotency-Key`, `correlationId`, établissement bénéficiaire, patient, objet du paiement (rendez-vous, ordonnance, hospitalisation…).

### 4.4 Sécurité des échanges opérateurs
Signature **HMAC**, webhooks signés et vérifiés, protection **anti-replay** (timestamp + nonce), TLS, journalisation complète, liste blanche d'adresses IP lorsque l'opérateur le permet, secrets en coffre (Vault/KMS — jamais dans le code).

### 4.5 Robustesse
Timeouts stricts, retries avec backoff **uniquement sur les opérations idempotentes**, réconciliation périodique (interrogation du statut auprès de l'opérateur pour les transactions restées `PENDING`/`PROCESSING`), file de compensation, bascule vers un autre fournisseur en cas de panne prolongée d'un prestataire.

---

## 5. Cartes bancaires

Supports : **Visa**, **Mastercard**, **American Express**, **GIM-UEMOA** (si disponible via la passerelle choisie).

### 5.1 Processus
```
Création paiement → Authentification 3D Secure 2 → Autorisation bancaire
→ Capture → Confirmation → Facture
```

### 5.2 Tokenisation
Les numéros de cartes ne sont **jamais** stockés. Seul un **token** fourni par la passerelle est conservé (`Carte → Tokenisation → Token sécurisé → Base`). Aucune donnée PAN, CVV ou piste magnétique ne transite ni ne réside dans les systèmes MASANTÉ.

### 5.3 Conformité
**PCI DSS**, **EMV**, **3D Secure 2**, **TLS 1.3**. Périmètre PCI réduit au maximum par délégation à la passerelle certifiée.

### 5.4 Paiements récurrents
Abonnements premium, suivi médical, télémédecine, assurances : mandats stockés sous forme de tokens, échéancier, notifications avant prélèvement, possibilité d'annulation à tout moment.

---

## 6. Wallet MASANTÉ

Portefeuille électronique interne créé automatiquement pour chaque utilisateur.

### 6.1 Structure
`Solde`, `Historique`, `Transactions`, `Bonus`, `Cashback`, `Gel temporaire`, `Limites`.

### 6.2 Opérations
Crédit, débit, blocage, déblocage, remboursement, transfert, ajustement, cashback.

### 6.3 Comptabilité en double écriture (obligatoire)
Chaque opération génère **deux écritures** garantissant la cohérence des soldes :
```
Wallet Patient : −10 000 FCFA
Wallet Hôpital : +10 000 FCFA
```
Le solde n'est jamais modifié directement : il est la somme des écritures. Journal comptable immuable, rapprochement quotidien automatique, alerte en cas d'écart.

### 6.4 Sécurité Wallet
PIN Wallet, biométrie, OTP, signature numérique, détection de fraude, limites de montant par opération/jour/mois, gel temporaire en cas de comportement suspect.

---

## 7. Facturation

### 7.1 Documents générés
Facture, devis, reçu, avoir, note de crédit, justificatif, historique.

### 7.2 Génération automatique
```
Consultation (ou tout acte) → Tarification → TVA → Réductions
→ Assurance → CNAM → Facture PDF → Paiement
```

### 7.3 Éléments pris en compte dans le calcul
Actes médicaux, médicaments, laboratoire, radiologie, hospitalisation, ambulance, consommables, remises, subventions, TVA, assurance, CNAM, montant payé, **reste à payer** (paiement par étapes si l'établissement l'autorise).

### 7.4 Facturation électronique
PDF, **QR Code**, signature numérique (PKI — CDC_10), archivage, **numérotation unique** par établissement et par exercice, horodatage. Téléchargeable par le patient.

### 7.5 Versionnage
Toutes les versions d'une facture sont conservées : `Version 1 → Correction → Version 2 → Archivage`. Aucune facture n'est modifiée en place ; une correction produit une nouvelle version et, le cas échéant, un avoir.

---

## 8. Prise en charge : CNAM et assurances

### 8.1 CNAM (Caisse Nationale d'Assurance Maladie)
Objectifs : vérification de l'affiliation, validation des droits, contrôle de l'éligibilité, gestion des prises en charge, réduction du reste à payer, traçabilité des remboursements.

Processus :
1. Le patient renseigne son identifiant CNAM.
2. Le système vérifie son éligibilité (API CNAM).
3. Les garanties applicables sont récupérées.
4. Le montant pris en charge est calculé.
5. Le reste à charge est déterminé.
6. La facture est ajustée avant paiement.

Exemple imposé : consultation 20 000 FCFA, prise en charge CNAM 70 % → CNAM 14 000 FCFA, patient 6 000 FCFA.

Gestion des remboursements : demandes, suivi des dossiers, rejets, corrections, régularisations — **chaque étape historisée** pour faciliter les audits et contrôles administratifs.

### 8.2 Assurances privées, mutuelles, entreprises, ONG, organismes internationaux, programmes gouvernementaux
Processus : identification de l'assureur → vérification de la validité du contrat → contrôle des plafonds et exclusions → calcul de la couverture → répartition entre l'assureur et le patient → génération des pièces justificatives.

Exemple imposé : hospitalisation 250 000 FCFA, couverture 80 % → assurance 200 000 FCFA, patient 50 000 FCFA.

Gestion des sinistres et rejets : demandes de prise en charge, refus, justificatifs complémentaires, recours, remboursements partiels ou complets — toutes les interactions enregistrées pour une traçabilité complète.

### 8.3 Ticket modérateur
Le système calcule couverture, plafond, ticket modérateur et reste à charge. **Le patient ne paie que la différence.**

---

## 9. Sécurité des paiements (architecture multicouche)

1. **TLS 1.3** pour tous les échanges.
2. **AES-256** pour les données sensibles au repos ; clés gérées par **HSM/KMS**.
3. **Authentification forte** : OTP, biométrie, PIN.
4. **Tokenisation** des cartes bancaires (aucun PAN stocké).
5. **Signature des webhooks** (HMAC) et vérification systématique.
6. **Idempotence** des requêtes pour éviter les doubles paiements (`Idempotency-Key` obligatoire sur toute écriture financière ; verrou Redis + contrainte d'unicité en base).
7. **Journal d'audit immuable** (append-only, hachage chaîné) : toute opération financière, tout accès, toute modification.
8. **Détection des comportements frauduleux par IA** (CDC_05 `fraud-detection-service`) : facturations suspectes, comportements anormaux, vélocité de transactions, géolocalisation incohérente.
9. **Surveillance en temps réel** des transactions avec alertes.
10. **Conformité PCI DSS** pour les paiements par carte ; segmentation réseau ; principe du moindre privilège ; revues d'accès périodiques.
11. **Séparation des environnements** : aucune donnée de production en développement ; jeux de test fournis par les prestataires (sandbox).

---

## 10. Intégration avec le reste de la plateforme

### 10.1 Événements publiés (CDC_03 §8)
`PaymentInitiated`, `PaymentPending`, `PaymentConfirmed`, `PaymentFailed`, `PaymentCancelled`, `PaymentRefunded`, `InvoiceIssued`, `InvoiceCorrected`, `WalletCredited`, `WalletDebited`, `InsuranceClaimSubmitted`, `InsuranceClaimApproved`, `InsuranceClaimRejected`, `SettlementExecuted`, `FraudSuspected`.

### 10.2 Événements consommés
`AppointmentConfirmed` (déclenche la demande de paiement), `ConsultationClosed` (déclenche la facturation), `PrescriptionDelivered`, `AdmissionRegistered`, `DischargeCompleted`, `LaboratoryResultAvailable` (facturation d'examen), `AmbulanceDispatched`.

### 10.3 Cohérence distribuée
**Saga Pattern** pour les processus multi-services (ex. rendez-vous → paiement → confirmation → notification) avec compensation en cas d'échec. **Outbox Pattern** obligatoire : aucun événement de paiement publié avant le commit de la transaction. Cohérence **forte** exigée sur les données financières (pas d'eventual consistency sur les soldes).

### 10.4 Workflow de paiement du rendez-vous (imposé)
```
1. Le patient réserve.
2. La secrétaire pré-valide, puis le médecin confirme (ou le médecin seul).
3. Le patient reçoit la confirmation.
4. Le patient effectue le paiement.
5. Le système génère : facture, reçu, numéro de transaction, historique.
6. Notification de confirmation finale.
```

---

## 11. Reversements et comptabilité

- **Settlement Service** : calcul des sommes dues à chaque établissement/pharmacie, périodicité configurable, retenue éventuelle de commission de plateforme, génération des relevés, exécution et traçabilité des reversements.
- Rapprochement automatique quotidien : transactions opérateurs ↔ transactions MASANTÉ ↔ factures ↔ reversements. Écarts détectés et signalés, jamais corrigés silencieusement.
- Exports comptables normalisés, archivage légal, pistes d'audit complètes.

---

## 12. Performance et disponibilité

- Cohérence forte, latence maîtrisée : initiation de paiement < 300 ms côté MASANTÉ (hors temps opérateur).
- Disponibilité cible du domaine paiement : **99,99 %**, avec **RTO < 30 minutes** et **RPO < 5 minutes** (CDC_10).
- Files d'attente pour les traitements lourds (réconciliation, reversements, exports) exécutés en batch planifié.
- Circuit breakers par opérateur ; une panne d'un prestataire n'affecte pas les autres moyens de paiement ni le reste de la plateforme.
- Réplication de la base paiement, sauvegardes 3-2-1, sauvegardes immuables.

---

## 13. Tests

- Tests unitaires du domaine (calculs de TVA, remises, couverture, reste à charge, double écriture).
- Tests d'intégration avec les **sandbox** des opérateurs (Orange, MTN, Wave, Moov) et de la passerelle carte.
- Tests d'idempotence : rejeu de la même requête, rejeu de webhook, double clic utilisateur.
- Tests de machine à états : toutes les transitions valides et invalides.
- Tests de réconciliation et de compensation (Saga).
- Tests de sécurité : signature de webhook invalide, replay, injection, autorisation croisée (un patient ne peut pas payer/voir la facture d'un autre).
- Tests de charge et de résilience (panne opérateur, timeout, perte de replica).

---

## 14. Ordre de construction recommandé

1. Socle Spring Boot + PostgreSQL + Redis + observabilité + interface `PaymentGateway`.
2. Modèle de données financier + machine à états + idempotence + audit immuable.
3. **Billing/Invoice** : tarification, TVA, réductions, facture PDF avec QR et signature.
4. **Mobile Money** : Orange Money, puis Wave, MTN, Moov (un adaptateur à la fois, testé en sandbox).
5. **Wallet** avec double écriture et sécurité (PIN/OTP/biométrie).
6. **Cartes bancaires** (3D Secure 2, tokenisation, PCI DSS).
7. **CNAM**, puis **assurances privées** (couverture, plafonds, exclusions, reste à charge).
8. **Refunds** et **Settlement** (reversements, rapprochement).
9. **Fraud Detection** (intégration CDC_05).
10. Durcissement sécurité, tests de charge, HA/DR, certification PCI.

Chaque module est testé et validé avant de passer au suivant ; en cas de problème, analyse ciblée et correction de la seule partie fautive.

---

*Fin du CDC_06 — Architecture Paiement.*
