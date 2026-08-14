# Index des guides de test — MASANTÉ

Point d'entrée unique pour retrouver **quel guide teste quel module**.

> **Règle de méthode (CDC_01 §2.4)** : à partir de P6.1, **tout module a son propre guide de test**,
> écrit avant le G4 et conservé après le G5 comme procédure de non-régression. Un module sans guide
> ne peut pas être déclaré validé.

---

## Convention de nommage

```
GUIDE_TEST_<SUJET>.md        à la racine du dépôt
```

Le `<SUJET>` nomme le **domaine**, pas le numéro d'incrément — un domaine peut couvrir plusieurs
incréments successifs, ajoutés en **parties** dans le même fichier plutôt qu'en fichiers nouveaux
(c'est ce qui est fait pour le paiement et la fraude, et qui évite l'éparpillement).

**Structure attendue de chaque guide**, dans cet ordre :
1. Périmètre, et **ce que le module ne fait pas** (les limites, dites honnêtement) ;
2. Prérequis (backend, base, comptes de test, tunnel) ;
3. Scénarios **front** (libellés exacts affichés, jamais d'icônes inventées) ;
4. Scénarios **backend** (curl reproductibles, réponses attendues littérales) ;
5. Invariants **base de données** ;
6. Commandes de qualité (G3) ;
7. **Checklist de clôture** cochable ;
8. **Pièges rencontrés** — ce qui a réellement fait échouer le test la première fois.

---

## Guides existants

| Module | Domaine | Guide | Sections |
|--------|---------|-------|----------|
| **P1** | Identité — RBAC + MFA TOTP | [GUIDE_TEST_G4_P1.md](GUIDE_TEST_G4_P1.md) | A mobile · B web · C checklist |
| **P5** | Paiement (microservice Java) | [GUIDE_TEST_Paiement_G4_P5.md](GUIDE_TEST_Paiement_G4_P5.md) | voir table ci-dessous |
| **CDC_05** | Fraude IA (microservice Python) | [GUIDE_TEST_Fraude_G4.md](GUIDE_TEST_Fraude_G4.md) | §0–6 socle · §7 extraction réelle (A) · §8 routage (B1) · §9 écran admin (B2) |
| **P6.1** | Identifiant National de Santé | [GUIDE_TEST_NIS.md](GUIDE_TEST_NIS.md) | A mobile · B backend · C base · D qualité · E checklist · F pièges |
| **P6.3 / P6.4** | Référentiels nationaux (CDC_09) | [GUIDE_TEST_REFERENTIELS.md](GUIDE_TEST_REFERENTIELS.md) | **Partie 1** socle (§1–8) · **Partie 2** établissements (§2.1–2.8) · **Partie 3** villes et géolocalisation (§3.1–3.8) · **Partie 4** images (§4.1–4.8) · **Partie 5** formulaires du portail (§5.1–5.8) · **Partie 6** bascule des seuils de mesure, L1+L2 (§6.1–6.8) |
| **P6.5** | Professionnels de santé + PKI (CDC_09 §5) | [GUIDE_TEST_PROFESSIONNELS.md](GUIDE_TEST_PROFESSIONNELS.md) | **Partie 1** référentiel professionnel (§1–8) · **Partie 2** PKI et signature électronique (§2.1–2.8) |
| **P7** | Carnet familial partagé | [GUIDE_TEST_CARNET_FAMILIAL.md](GUIDE_TEST_CARNET_FAMILIAL.md) | **A** partage en lecture · B revendication · C brouillon · D notifications |

### Détail du guide Paiement (13 parties)

| Incrément | Partie | Cas |
|-----------|--------|-----|
| P5.1 Socle + prise en charge CNAM/assurance | A | 1–12 |
| P5.2a Facturation | B | 13–18 |
| P5.2b Avoir, versionnage, signature | C | 19–27 |
| P5.3a Wallet + double écriture | D | 28–34 |
| P5.3b-1 Sécurité wallet (PIN, OTP, limites) | E | — |
| P5.3b-2 Détection de fraude + gel | F | — |
| P5.3b-3 Cashback + bonus | G | — |
| P5.3b-4 Contrôle d'intégrité interne | H | — |
| P5.4a Cartes bancaires | I | 57–67 |
| P5.5a Reversements — socle et relevé | J | 68–74 |
| P5.5b-1 Destinations chiffrées + quatre-yeux | K | 75–81 |
| P5.5b-2 Décaissement (simulé) | L | 82–89 |
| P5.5c Rapprochement factures ↔ reversements | M | 90–93 |
| P5.4b Mandats récurrents + P5.4c notifications | — | 94–104 |

---

## Modules validés sans guide dédié

Ces modules ont été validés G5 **avant** l'instauration de la règle. Leur procédure de test est
consignée dans les fiches mémoire et les ADR, mais pas dans un guide cochable.

| Module | Domaine | Validé | Guide |
|--------|---------|--------|-------|
| **P0** | Socle monorepo, `@masante/shared`, design system | 2026-08-01 | à produire sur demande |
| **P2** | Profil + dossier médical, cache chiffré hors ligne | 2026-08-01 | à produire sur demande |
| **P3** | Annuaire établissements/médecins + carte OSM | 2026-08-01 | à produire sur demande |
| **P4** | Rendez-vous, workflow de validation en deux étapes | 2026-08-01 | à produire sur demande |

> Les scénarios critiques de P2/P3 (mode avion, cache chiffré, dégradation de la carte) sont
> partiellement rejoués par **GUIDE_TEST_NIS.md §A.6** en non-régression.

---

## À venir

| Module | Domaine | Guide prévu |
|--------|---------|-------------|
| **Migration du portail Blade → Next** | Module identifié par ADR-029 : dix-sept zones, où le design moderne se fera **une fois** sur le design system partagé | guide propre |
| **P6.5** | Référentiel des professionnels + PKI | **partie 6** de `GUIDE_TEST_REFERENTIELS.md` |
| **P6.6** | Référentiel des médicaments | **partie 7** de `GUIDE_TEST_REFERENTIELS.md` |
| **P6.7** | Laboratoires + catalogue des analyses | **partie 8** de `GUIDE_TEST_REFERENTIELS.md` |

> **P6.2 (MPI — détection de doublons et fusion, ADR-022) est ABANDONNÉ** : remplacé par le module
> **P7 Carnet familial partagé** (décision propriétaire 2026-08-11). Le NIS rend la fusion largement
> inutile — elle ne réparerait que les doublons nés avant lui, et ce projet n'en a aucun.
> Aucun `GUIDE_TEST_MPI.md` ne sera produit.

Les référentiels d'annuaire relèvent tous du **même domaine** que P6.3 : ils s'ajouteront en
**parties** du guide existant, pas en fichiers nouveaux (convention ci-dessus).
