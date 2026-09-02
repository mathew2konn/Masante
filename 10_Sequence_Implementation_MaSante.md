# SÉQUENCE D'IMPLÉMENTATION — Facturation, recouvrement, GeniusPay
## Feuille de route à suivre lot par lot, sans y revenir

> Ce document est l'index de la séquence. Cocher une case ici après le test réel du module, conformément à la méthode de travail du projet : un module à la fois, testé avant de passer au suivant, correction chirurgicale si problème.

---

## ORDRE D'EXÉCUTION ET DÉPENDANCES

```
                    ┌─────────────────────────────────────┐
                    │  Tables_Facturation_MaSante_v2.md    │
                    │  (Laravel — déjà écrit, en attente   │
                    │   de A1–A5 + module 1 validé)        │
                    └──────────────┬────────────────────────┘
                                   │
              ┌────────────────────┼────────────────────┐
              ▼                    ▼                    ▼
        Lot 1 — Recouvrement  Lot 2 — Commission   Lot 4 — Correction
        (imputation, Palier0) (barème, pharmacie)  colonne `actif`
              │                    │              (décision produit
              │                    │               d'abord, puis code)
              └─────────┬──────────┘
                        ▼
              Lot 3 — Génération factures
              partenaires fin de mois
                        │
                        ▼
              Lot 8 — Contrôleurs / routes API
                        │
                        ▼
              Lot 9 — Notifications facturation


        Lot 5 — Reprise flux RDV / paiements  ─── indépendant, parallélisable
                                                    dès que Tables_Facturation
                                                    est mergé

        Lot 6 — Canal interne Laravel ↔ Java  ─── dépend de Lot 2 (Laravel)
                                                    et de ServicePrincipal (Java,
                                                    déjà existant)

        Lot 7 — Amendements GeniusPay v3      ─── indépendant de tout le reste
        (Java, paiement-service)                  ci-dessus. Dépend de : A6 validé,
                                                    micro-lot secrets (B4), ADR B3/B6,
                                                    résultat de V1 (URL de base).
```

**Deux chantiers parallélisables sans interférence** : le chantier Laravel (Tables_Facturation → lots 1/2/3/4/5/8/9) et le chantier Java (lot 7, GeniusPay). Ils se rejoignent au lot 6.

---

## LISTE DE SUIVI

| # | Lot | Fichier prompt | Projet | Statut |
|---|---|---|---|---|
| 0 | Schéma de facturation | `Prompt_ClaudeCode_Tables_Facturation_MaSante_v2.md` | Laravel | ☐ en attente d'A1–A5 |
| 1 | Service de recouvrement (imputation, Palier 0) | `01_Prompt_ClaudeCode_Service_Recouvrement_Partenaire.md` | Laravel | ☐ |
| 2 | Service de calcul de commission | `02_Prompt_ClaudeCode_Service_Commission.md` | Laravel | ☐ |
| 3 | Génération des factures partenaires | `03_Prompt_ClaudeCode_Generation_Factures_Partenaire.md` | Laravel | ☐ |
| 4 | Correction colonne `actif` | `04_Prompt_ClaudeCode_Correction_Colonne_Actif.md` | Laravel | ☐ décision produit à rendre d'abord |
| 5 | Reprise flux RDV → `factures_patient` | `05_Prompt_ClaudeCode_Reprise_Flux_RDV_Paiements.md` | Laravel | ☐ |
| 6 | Canal interne Laravel ↔ Java | `06_Prompt_ClaudeCode_Canal_Interne_Laravel_Java.md` | Laravel + Java | ☐ |
| 7 | Amendements GeniusPay v3 | `07_Prompt_ClaudeCode_Amendements_GeniusPay_v3.md` | Java | ☐ attend A6, B4, ADR B3/B6, V1 |
| 8 | Contrôleurs / routes API de facturation | `08_Prompt_ClaudeCode_API_Facturation.md` | Laravel | ☐ |
| 9 | Notifications de facturation | `09_Prompt_ClaudeCode_Notifications_Facturation.md` | Laravel | ☐ |
| 10 | Écrans portail établissement + patient | **Pas de prompt — voir note ci-dessous** | Frontend | — |

---

## POURQUOI L'ITEM 10 N'A PAS DE PROMPT

Les neuf lots ci-dessus sont du code serveur : un schéma, une règle de calcul, une transition d'état — des choses qui se spécifient entièrement par écrit avant d'être codées, exactement comme les prompts précédents de ce programme.

Les écrans du portail établissement et de l'application patient sont d'une autre nature. Leur forme dépend de choix qu'un texte ne tranche pas bien : où placer le tableau de bord de facturation dans la navigation existante, comment présenter visuellement le reçu détaillé du §2.4, quelle maquette pour l'écran « Mon plan ». Écrire un prompt maintenant, avant que l'API du lot 8 ne soit stabilisée et testée, produirait des écrans à refaire dès que la forme des réponses JSON changerait d'un détail.

**Quand vous y arriverez** : les maquettes se font avec un outil de design (Figma, ou le skill de design de ce même programme) avant le code d'écran — pas l'inverse. Une fois les maquettes validées, un prompt Claude Code pour l'implémentation des écrans redevient pertinent, avec le même formalisme que les neuf lots ci-dessus.

---

## RAPPELS DE MÉTHODE (déjà énoncés dans les instructions du projet)

- Un module à la fois. Un module fini, testé (Expo, tunnel Ngrok), puis seulement le suivant.
- Un problème détecté : analyse ciblée sur la partie en cause, correction qui ne touche qu'elle.
- Les documents `claude/Arbitrage_Audit_Phase0_GeniusPay.md`, `claude/Amendement_Essai_et_Recouvrement_MaSante.md` et `claude/Prelevement_Commission_a_la_Source.md` restent les références de décision : un prompt qui semble les contredire a probablement dérivé, pas eux.
