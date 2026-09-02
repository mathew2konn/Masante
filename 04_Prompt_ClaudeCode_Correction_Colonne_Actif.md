# PROMPT CLAUDE CODE — CORRECTION DU DÉFAUT DE LA COLONNE `actif`
## Backend Laravel — Lot 4 de la séquence post-facturation
## ⚠️ Prompt à exécution partielle obligatoire : s'arrête après la Phase 0 pour une décision produit

> Applique `claude/00_Conventions_Transversales_MaSante.md`, non recopiées ici.

---

## CONTEXTE

L'audit du lot Tables_Facturation a établi que la colonne `structures_sanitaires.actif` porte un commentaire de migration affirmant qu'à `false` la structure disparaît de l'annuaire public — et que **c'est faux** : la recherche patient ne lit jamais cette colonne. Ce prompt corrige l'écart, mais **ne peut pas le faire sans une décision de Mathieu**, parce que la correction technique dépend d'une réponse produit qu'aucun document existant ne tranche.

---

## COPIER À PARTIR D'ICI

---

## PHASE 0 — AUDIT (obligatoire, aucune écriture) — PUIS ARRÊT SYSTÉMATIQUE

1. Localise **tous** les points d'entrée de la recherche patient (contrôleur, requête Eloquent ou SQL brut) qui listent des structures sanitaires.
2. Confirme, ligne par ligne, qu'aucun de ces points ne filtre sur `actif`.
3. Recherche toute autre colonne existante qui jouerait un rôle de publication (`publie`, `visible`, `statut_publication`…). S'il en existe une déjà fonctionnelle, rapporte-le : la correction pourrait n'être qu'un renommage de rôle, pas un nouveau filtre.
4. Liste tous les endroits du code (back-office, seed, admin) où `actif` est actuellement écrit, et dans quelle intention apparente (fermeture définitive ? suspension temporaire ? erreur de saisie ?).
5. Confirme qu'`abonnements_structure.statut` (SUSPENDU pour impayé, lot 1 de cette séquence) **n'a aucune interaction actuelle** avec `actif` — ils doivent rester deux colonnes totalement indépendantes.

**Rends ton rapport, puis arrête-toi. Ne passe pas à une Phase 1 sans instruction explicite.**

---

## POINT D'ARBITRAGE PRODUIT — À TRANCHER PAR MATHIEU AVANT TOUTE ÉCRITURE

La question posée par l'audit : **une structure `actif = false` doit-elle disparaître de la recherche patient ?**

Recommandation, cohérente avec tout ce qui a été décidé sur ce programme : **oui**, mais à condition que `actif` reste strictement un commutateur **administratif** (fermeture, radiation, fraude constatée — décision d'un administrateur MaSanté), et jamais un effet de bord d'un impayé. La bascule au Palier 0 pour impayé (lot 1) n'écrit jamais sur `actif` et ne doit jamais y toucher, précisément pour que ces deux mécanismes ne s'emmêlent pas.

Si Mathieu valide cette lecture, la Phase 1 (à écrire dans un prompt séparé, après validation) consistera à :
- brancher `actif = false` comme filtre d'exclusion dans la recherche patient ;
- corriger le commentaire de la migration pour qu'il dise ce que le code fait réellement ;
- écrire un test de non-régression qui **verrouille la distinction** : `test_actif_false_masque_de_la_recherche` et `test_suspendu_pour_impaye_reste_visible` — ce deuxième test est celui qui protège D-E1 dans la durée, et il doit échouer si jamais quelqu'un, plus tard, relie par erreur la bascule Palier 0 à `actif`.

**Ce prompt s'arrête ici.** La Phase 1 ne sera rédigée qu'une fois la question ci-dessus tranchée.

## FIN DU PROMPT
