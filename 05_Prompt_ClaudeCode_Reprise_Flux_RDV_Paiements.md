# PROMPT CLAUDE CODE — REPRISE DU FLUX RENDEZ-VOUS SUR `factures_patient`
## Backend Laravel — Lot 5 de la séquence post-facturation
## Touche du code déjà en production sur le module rendez-vous — prudence maximale

> Applique `claude/00_Conventions_Transversales_MaSante.md`, non recopiées ici.

---

## CONTEXTE

Le module rendez-vous possède déjà une table `paiements`, avec un encaissement **simulé** (statut posé d'office, référence de transaction fictive). Le lot Tables_Facturation a introduit `factures_patient`, avec une colonne `rendez_vous_id` prévue pour ce rattachement. **Décision prise : `factures_patient` devient la seule source de vérité pour la question « cet acte a-t-il été réglé ? ». `paiements` n'est ni supprimée, ni migrée rétroactivement — elle devient un vestige historique, lu mais plus jamais écrit.**

---

## COPIER À PARTIR D'ICI

---

## INTERDICTIONS ABSOLUES

1. **Ne supprime jamais la table `paiements`, ni aucune de ses lignes.**
2. **N'écris plus jamais de nouvelle ligne dans `paiements`**, à l'issue de ce lot.
3. **Ne migre pas rétroactivement l'historique de `paiements` vers `factures_patient`.** Fabriquer une facture patient pour un paiement simulé passé falsifierait l'historique financier. Les anciennes lignes restent lisibles telles quelles, dans `paiements`, point final.
4. **Aucune nouvelle migration structurelle sur `paiements` ou sur la table des rendez-vous** au-delà de ce que ce prompt décrit explicitement.
5. **N'écris aucune logique de facturation ici** (calcul, imputation) — ce lot ne fait que brancher la création d'une `facture_patient` au bon moment du parcours rendez-vous.

## PHASE 0 — AUDIT CIBLÉ (obligatoire, aucune écriture)

1. Liste **exhaustivement** tous les endroits du code qui lisent ou écrivent `paiements` : contrôleurs, modèles, vues, jobs, tests.
2. Pour chacun, indique s'il s'agit d'une lecture (« ce RDV est-il payé ? ») ou d'une écriture (création du paiement simulé).
3. Confirme le point exact du parcours de réservation où un paiement est aujourd'hui déclenché (avant confirmation ? à la confirmation ?).
4. Confirme le type de clé primaire de la table des rendez-vous, pour vérifier la compatibilité avec `factures_patient.rendez_vous_id`.
5. Recherche s'il existe des rendez-vous futurs déjà réservés avec un paiement simulé en attente — ce sont les cas de transition à ne pas casser.

**Arrête-toi et rapporte. N'écris rien avant validation de ton rapport.**

## PHASE 1 — Bascule du point de création

1. Au point identifié en Phase 0.3, remplace la création d'une ligne `paiements` par la création d'une `facture_patient` (statut initial selon `moment_paiement` : `A_REGLER` si `AVANT_ACTE`, comportement existant conservé si `APRES_ACTE`), avec `rendez_vous_id` renseigné.
2. **N'écris plus aucune nouvelle ligne dans `paiements`** à partir de ce point du code. Le modèle Eloquent `Paiement` (ou équivalent) reste, mais ses méthodes de création ne sont plus appelées depuis ce parcours.

## PHASE 2 — Bascule des lectures, avec repli explicite

1. Pour chaque lecture identifiée en Phase 0.1 (« ce RDV est-il payé ? ») : interroge d'abord `factures_patient` via `rendez_vous_id`. Si aucune facture n'existe pour ce rendez-vous (cas d'un RDV antérieur au basculement), **replie sur `paiements`**, en lecture seule.
2. **Marque ce repli explicitement dans le code** (commentaire `// TODO repli historique — supprimable après <date à définir avec Mathieu>, une fois qu'aucun rendez-vous actif ne dépend plus de \`paiements\`.`) — ne le laisse pas silencieux, sinon personne ne saura un jour s'il est sûr de le retirer.

## PHASE 3 — TESTS (`tests/Feature/`)

1. `test_nouveau_rdv_cree_une_facture_patient_pas_un_paiement` — vérifie qu'aucune ligne n'est ajoutée à `paiements` lors d'une réservation après bascule.
2. `test_ancien_rdv_avec_paiement_simule_reste_lisible_via_repli`.
3. `test_nouveau_rdv_avec_facture_prioritaire_sur_repli` — si les deux existaient par accident, `factures_patient` gagne toujours.
4. `test_aucune_ecriture_sur_paiements_apres_bascule` — test global, exécute un scénario de réservation complet et vérifie que le compte de lignes de `paiements` n'a pas bougé.
5. `test_rendez_vous_id_correctement_rattache`.

## CHECKLIST FINALE

- [ ] `paiements` n'a reçu aucune écriture nouvelle (test 4 vert)
- [ ] Aucune ligne historique de `paiements` modifiée ou supprimée
- [ ] Tous les repli sont marqués d'un commentaire explicite et daté
- [ ] Les 5 tests passent
- [ ] `git diff` ne montre aucune migration structurelle sur `paiements` ou les rendez-vous

## HORS PÉRIMÈTRE

Le retrait effectif de `paiements` — lot futur, seulement quand le repli n'est plus jamais emprunté en production. La logique de calcul de reste à charge CMU sur `factures_patient` — déjà couverte par le lot Tables_Facturation.

## FIN DU PROMPT
