# PROMPT CLAUDE CODE — GÉNÉRATION DES FACTURES PARTENAIRES EN FIN DE MOIS
## Backend Laravel — Lot 3 de la séquence post-facturation

> Applique `claude/00_Conventions_Transversales_MaSante.md`, non recopiées ici.

---

## CONDITIONS D'EXÉCUTION

Lots 1 (`RecouvrementPartenaireService`) et 2 (`CommissionService`) exécutés et testés. Ce lot les consomme, il ne les modifie pas.

---

## COPIER À PARTIR D'ICI

---

Tu écris la commande planifiée qui, chaque mois, agrège l'abonnement et les commissions d'une structure en une facture partenaire unique.

## INTERDICTIONS ABSOLUES

1. **Aucune nouvelle migration.**
2. **Une seule facture par structure et par période.** La contrainte `unique(structure_sanitaire_id, periode_debut, periode_fin)` existe déjà : si elle se déclenche, c'est que la commande a déjà tourné pour cette période — passe à la structure suivante, ne force rien.
3. **Pas de proratisation.** Ce lot facture toujours un mois plein. Si un abonnement démarre en cours de mois, la première facturation pleine intervient au cycle suivant — ceci est une simplification assumée, pas un oubli ; si Mathieu veut du prorata, c'est un lot séparé, à ne pas improviser ici.
4. **Aucune génération de PDF, aucun envoi de notification.** Écrans du portail (lot séparé, hors séquence Laravel).
5. **Tu ne modifies jamais une facture déjà `EMISE` autrement qu'en lui ajoutant des règlements** — ce que fait déjà le lot 1, pas celui-ci.

## PHASE 0 — AUDIT CIBLÉ

1. Confirme le mécanisme de planification de tâches du projet et où sont déclarées les commandes existantes (`app/Console/Commands/`).
2. Confirme la colonne qui détermine la date de facturation d'un abonnement (`date_prochaine_facturation` sur `abonnements_structure`, posée par le lot Tables_Facturation).
3. Confirme qu'aucune commande de génération de facture n'existe déjà sous un autre nom.

**Arrête-toi et rapporte.**

## PHASE 1 — Commande `factures:generer-partenaires`

Fichier : `app/Console/Commands/GenererFacturesPartenaireCommand.php`.

1. Sélectionne tous les abonnements dont `date_prochaine_facturation` est atteinte ou dépassée, quel que soit leur statut (`ACTIF`, `SUSPENDU`, `ESSAI` — un abonnement en essai ne génère pas d'abonnement facturé, voir point 3).
2. Pour chaque structure, détermine la période : `periode_debut` = dernière `date_prochaine_facturation` (ou date de début d'abonnement si première facture), `periode_fin` = veille de la date du jour.
3. **Montant abonnement.** Si l'abonnement est encore en `ESSAI` sur toute la période, `montant_abonnement = 0`. Sinon, le montant mensuel du plan tarifaire en vigueur.
4. **Montant commissions.** Somme de `commissions_transaction.montant_commission` où `statut = CALCULEE`, `structure_sanitaire_id` = la structure, `date_transaction` dans la période.
5. Dans une transaction de base de données :
   - crée la `facture_partenaire` (`reference` au format `FP-{structureId}-{AAAAMM}`, `montant_total = montant_abonnement + montant_commissions`, `statut = EMISE`, `date_emission = aujourd'hui`, `date_echeance = aujourd'hui + 15 jours` — délai paramétrable, jamais en dur) ;
   - met à jour chaque `commissions_transaction` incluse : `facture_partenaire_id` renseigné, `statut = FACTUREE` ;
   - avance `date_prochaine_facturation` de l'abonnement d'un mois.
6. **Si `montant_total` est nul** (aucune commission, abonnement à 0, cas du Palier 0 pur ou d'un mois sans activité) : ne crée pas de facture. Une facture à zéro franc n'a aucune utilité et polluerait l'historique.
7. Journalise un résumé : nombre de factures créées, montant total généré, structures sautées (déjà facturées, ou montant nul).

## PHASE 2 — TESTS (`tests/Feature/`)

1. `test_facture_generee_avec_abonnement_et_commissions`.
2. `test_essai_en_cours_abonnement_zero`.
3. `test_montant_nul_ne_genere_pas_de_facture`.
4. `test_commission_facturee_pointe_bien_la_facture_generee`.
5. `test_double_execution_meme_periode_ne_duplique_pas` — lancer la commande deux fois sur la même période ne crée pas deux factures.
6. `test_date_prochaine_facturation_avancee_d_un_mois`.
7. `test_seules_les_commissions_calculees_sont_incluses` — une commission déjà `FACTUREE` sur une facture antérieure n'est pas reprise.

## CHECKLIST FINALE

- [ ] Aucune migration ajoutée
- [ ] Aucun fichier PDF généré par ce lot
- [ ] Les 7 tests passent
- [ ] La commande est idempotente sur une même période (vérifié par test 5)
- [ ] Aucun fichier touché hors `app/Console/Commands/`, `tests/Feature/`

## HORS PÉRIMÈTRE

La proratisation, la génération de PDF, l'envoi de la facture au partenaire (notifications, lot 9), l'affichage portail (lot séparé).

## FIN DU PROMPT
