# services/payment — Microservice Paiement MASANTÉ (P5.1)

Domaine Paiement indépendant du cœur Laravel (**CDC_06**, **ADR-013**). Spring Boot 3.3 + PostgreSQL + Redis.

> ⚠️ **PAIEMENT SIMULÉ.** Aucune passerelle Mobile Money réelle (Orange/MTN/Wave/Moov) n'est
> accessible à ce projet (FT5). La *structure* du domaine est correcte ; l'exécution ne débite rien.

## Ce que couvre P5.1 (CDC_06 §14, étapes 1-2 + prise en charge §8)
- Interface `PasserellePaiement` (**OCP** — aucun `if canal == …`) + `AdaptateurSimule` + registre.
- **Machine à états stricte** `INITIATED→PENDING→PROCESSING→SUCCESS ↘FAILED/CANCELLED`, `SUCCESS→REFUNDED` (§4.2) — enum **source unique** répliqué de `@masante/shared`.
- **Idempotence** (§9.6) : en-tête `Idempotency-Key` obligatoire → verrou Redis + unicité PostgreSQL.
- **Audit immuable** (§9.7) : journal append-only à **hachage chaîné** (ancre GENESIS + vérification).
- **Moteur de prise en charge CNAM/assurance** (§8) : couverture, ticket modérateur, reste à charge.
  Calcul **backend uniquement** (frontière CDC_01 §0.1).

## Démarrer (Docker — chemin canonique)
```bash
cd services/payment
docker compose up --build
```
- Swagger UI : http://localhost:8080/swagger-ui.html
- Santé : http://localhost:8080/actuator/health
- PostgreSQL exposé sur `localhost:5433`, Redis sur `localhost:6380` (évite les collisions avec WAMP/MySQL).

Le build (`gradle build` dans l'image) **exécute les tests unitaires** (moteur de couverture, machine à
états, chaîne d'audit) — un échec bloque l'image.

## Prouver (G2)
Importer `postman/MASANTE-Payment-P5.1.postman_collection.json` et exécuter la collection de haut en
bas (base `http://localhost:8080`). Elle vérifie : vecteurs CNAM/assurance du CDC, initiation simulée,
**rejeu idempotent (200, aucun doublon)**, en-tête manquant (400), transitions, audit, remboursement,
intégrité de la chaîne. En CLI :
```bash
newman run postman/MASANTE-Payment-P5.1.postman_collection.json
```

## Endpoints
| Méthode | Chemin | Rôle |
|--------|--------|------|
| POST | `/api/v1/payments` | Initier (idempotent). En-tête `Idempotency-Key` requis. 201 neuf / 200 rejeu |
| GET | `/api/v1/payments/{id}` | Consulter |
| GET | `/api/v1/payments/{id}/transitions` | Historique d'états |
| GET | `/api/v1/payments/{id}/audit` | Piste d'audit du paiement |
| POST | `/api/v1/payments/{id}/refund` | Rembourser (SUCCESS→REFUNDED) |
| POST | `/api/v1/coverage/quote` | Prise en charge CNAM/assurance |
| POST | `/api/v1/invoices` | **Émettre une facture** (HT, TVA, remises, prise en charge → reste à payer) |
| GET | `/api/v1/invoices/{id}` | Consulter une facture |
| GET | `/api/v1/invoices/{id}/pdf` | Télécharger la facture en **PDF + QR** |
| GET | `/api/v1/audit/verify` | Vérifier l'intégrité de la chaîne d'audit |

Un paiement peut **solder une facture** : passer `"factureId"` (et `"objet": "FACTURE"`) au `POST /payments` →
la facture passe `EMISE → PARTIELLEMENT_PAYEE → PAYEE`.

## Frontière (rappel CDC_01 §0.1)
TVA, couverture, **ticket modérateur**, **reste à charge**, éligibilité, transitions d'état = **ici**.
Le mobile/web n'AFFICHENT que les montants et états renvoyés ; ils ne les recalculent jamais.

## « Prêts à activer » (incréments ultérieurs, non branchés en P5.1)
Adaptateurs opérateurs réels · RabbitMQ/Kafka · Saga/Outbox · facturation PDF+QR signée · Wallet
double écriture · cartes 3D Secure/tokenisation/PCI DSS · reversements/rapprochement · fraude (CDC_05)
· rebranchement du flux RDV Laravel (Laravel = proxy, §10.4). Voir ADR-013.
