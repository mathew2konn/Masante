# PROMPT CLAUDE CODE — SERVICE DE RECOUVREMENT PARTENAIRE (imputation + bascule Palier 0)
## Backend Laravel — Lot 1 de la séquence post-facturation

> Applique `claude/00_Conventions_Transversales_MaSante.md`, non recopiées ici.

---

## CONDITIONS D'EXÉCUTION

1. `Prompt_ClaudeCode_Tables_Facturation_MaSante_v2.md` exécuté, testé, mergé — en particulier `factures_partenaire`, `reglements_facture_partenaire`, `abonnements_structure` (colonnes `statut`, `motif_suspension`, `date_bascule_palier0`).
2. `docs/REGLES_RECOUVREMENT_PARTENAIRE.md` existe. **C'est le cahier des charges de ce prompt.** Toute divergence entre ce document et ce prompt se résout en faveur de `docs/REGLES_RECOUVREMENT_PARTENAIRE.md` — signale l'écart et arrête-toi plutôt que de trancher seul.

---

## COPIER À PARTIR D'ICI

---

Tu écris le service qui impute les règlements des partenaires sur leurs factures et déclenche la bascule au Palier 0 en cas d'impayé. C'est la seule pièce du programme qui écrit sur `abonnements_structure.statut`.

## INTERDICTIONS ABSOLUES

1. **Aucune nouvelle migration.** Le schéma existe déjà.
2. **Tu n'écris jamais sur `structures_sanitaires.actif`.** Cette colonne est hors périmètre de ce lot — voir §7 du prompt Tables_Facturation v2.1 sur son statut ambigu. La confondre avec la bascule Palier 0 mélangerait une décision administrative et une décision de recouvrement.
3. **Tu ne touches ni à `paiements`, ni aux rendez-vous.**
4. **Tu ne génères aucune facture** — c'est le lot suivant.
5. **Tu n'envoies aucune notification** (Firebase, e-mail) — lot séparé.
6. **Aucune suppression d'établissement, en aucune circonstance.**

## PHASE 0 — AUDIT CIBLÉ (pas de nouvelle écriture)

1. Confirme la présence et le contenu exact de `docs/REGLES_RECOUVREMENT_PARTENAIRE.md`.
2. Confirme que `FacturePartenaire` porte bien les accesseurs `solde` et `estSoldee`, et les garde-fous d'immutabilité décrits en Phase 2 du prompt Tables_Facturation v2.1.
3. Recherche dans le projet toute trace d'un mécanisme existant de bascule de palier ou de suspension (`grep -ri "suspendu\|palier0\|bascule"`). S'il en existe un, rapporte-le et arrête-toi : ne le duplique pas.
4. Confirme le mécanisme de planification de tâches disponible (`app/Console/Kernel.php` ou équivalent Laravel 11+).
5. Confirme qu'aucun verrou de ligne (`lockForUpdate`) n'est déjà utilisé ailleurs sur `factures_partenaire` d'une manière qui entrerait en conflit.

**Arrête-toi et rapporte.**

## PHASE 1 — `RecouvrementPartenaireService`

Fichier unique : `app/Services/RecouvrementPartenaireService.php`.

### `enregistrerReglement(int $structureSanitaireId, int $montant, string $moyen, ?string $referenceExterne, \DateTimeInterface $dateReglement): array`

1. **Transaction de base de données, avec verrou** (`lockForUpdate()`) sur les factures de la structure concernée — un règlement concurrent à une notification de commission ne doit jamais produire une imputation incohérente.
2. Sélectionne les factures de la structure dont le statut est `EMISE`, `PARTIELLEMENT_REGLEE` ou `IMPAYEE`, triées par `date_echeance` croissante (la plus ancienne d'abord). Le partenaire ne désigne jamais la facture qu'il règle.
3. Impute le montant reçu sur la première facture jusqu'à extinction de son solde, puis reporte l'excédent sur la suivante, ainsi de suite.
4. Pour chaque facture touchée : crée une ligne dans `reglements_facture_partenaire` (montant réellement imputé à **cette** facture, moyen, référence externe, date), incrémente `montant_regle`, recalcule le statut (`PARTIELLEMENT_REGLEE` si solde > 0, `PAYEE` si solde = 0, `date_paiement` posée dans ce cas).
5. **Cas de l'excédent au-delà de toutes les factures dues.** Si le montant reçu dépasse la somme des soldes de toutes les factures impayées de la structure, arrête l'imputation au dernier centime dû et **journalise l'excédent sans le stocker** (log applicatif, montant et structure). La gestion d'un avoir est explicitement hors périmètre de ce lot — ne l'improvise pas.
6. Si toutes les factures de la structure sont soldées après imputation et que l'abonnement est `SUSPENDU` pour motif `IMPAYE`, appelle `reactiver()` (voir plus bas) dans la même transaction.
7. Retourne le détail de l'imputation (facture par facture) pour l'appelant.

### `verifierEcheances(): void` — tâche planifiée, quotidienne

1. Sélectionne les `factures_partenaire` au statut `EMISE` ou `PARTIELLEMENT_REGLEE` dont `date_echeance` est dépassée de 30 jours ou plus et dont le solde est positif.
2. Passe leur statut à `IMPAYEE`.
3. Pour chaque structure concernée dont l'abonnement est encore `ACTIF` ou `ESSAI` : passe `abonnements_structure.statut` à `SUSPENDU`, `motif_suspension` à `IMPAYE`, `date_bascule_palier0` à l'instant présent.
4. **N'écrit rien d'autre.** Ni sur `actif`, ni sur une table de recherche, ni sur les rendez-vous.

### `reactiver(int $structureSanitaireId): void`

1. Vérifie que le solde total des factures de la structure est nul.
2. Passe `abonnements_structure.statut` à `ACTIF`, efface `motif_suspension` et `date_bascule_palier0`.
3. **Aucune ressaisie.** Ni nouveau dossier, ni nouvelle vérification, ni nouvelle pièce.

## PHASE 2 — TESTS (`tests/Feature/`)

1. `test_imputation_sur_facture_la_plus_ancienne` — deux factures impayées, un règlement partiel : la plus ancienne décroît en premier.
2. `test_report_excedent_sur_facture_suivante` — un règlement couvrant la première facture avec un reliquat impute le reliquat sur la deuxième.
3. `test_excedent_au_dela_du_du_est_journalise_sans_etre_stocke` — aucune ligne fictive créée, aucune colonne modifiée au-delà du dû.
4. `test_solde_impaye_bascule_palier0_a_30_jours_pas_avant` — à J+29, rien ne bouge ; à J+30, bascule.
5. `test_bascule_n_ecrit_jamais_sur_actif` — inspection explicite : après bascule, `structures_sanitaires.actif` est inchangé.
6. `test_reglement_partiel_ne_reactive_pas` — payer l'équivalent de l'abonnement seul laisse l'abonnement `SUSPENDU`.
7. `test_reactivation_efface_motif_et_date` — après solde nul, `motif_suspension` et `date_bascule_palier0` redeviennent `null`.
8. `test_reglement_immuable_apres_imputation` — hérité des garde-fous de modèle, revérifié dans ce contexte d'appel.
9. `test_verrou_concurrentiel` — deux imputations simultanées sur la même structure ne produisent pas de double comptage (test avec transactions imbriquées ou simulation de concurrence).

## CHECKLIST FINALE

- [ ] Aucune migration ajoutée
- [ ] Aucune écriture sur `structures_sanitaires.actif` dans tout le service (`grep -n "actif" app/Services/RecouvrementPartenaireService.php` ne retourne rien)
- [ ] Aucune écriture sur `paiements` ou la table des rendez-vous
- [ ] Les 9 tests passent
- [ ] La tâche planifiée est enregistrée et documentée (fréquence, heure)
- [ ] Aucun fichier touché hors `app/Services/`, `app/Console/`, `tests/Feature/`

## HORS PÉRIMÈTRE

Le calcul de commission (lot 2), la génération des factures (lot 3), les notifications (lot 9), la correction de `actif` (lot 4), les routes API (lot 8).

## FIN DU PROMPT
