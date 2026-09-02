# PROMPT CLAUDE CODE — CONTRÔLEURS ET ROUTES API DE FACTURATION
## Backend Laravel — Lot 8 de la séquence post-facturation

> Applique `claude/00_Conventions_Transversales_MaSante.md`, non recopiées ici.

---

## CONDITIONS D'EXÉCUTION

Lots 1, 2 et 3 exécutés et testés (recouvrement, commission, génération des factures). Ce lot expose leur résultat, il n'ajoute aucune logique de calcul.

---

## COPIER À PARTIR D'ICI

---

## INTERDICTIONS ABSOLUES

1. **Aucune logique métier dans les contrôleurs.** Ils lisent, appellent un service existant, sérialisent. Toute logique de calcul ou d'imputation appartient déjà aux lots 1–3.
2. **L'enregistrement d'un règlement partenaire n'est jamais accessible à l'établissement lui-même.** Seul le back-office MaSanté peut déclarer un règlement reçu. Un établissement qui pourrait déclarer lui-même « j'ai payé » ouvrirait un risque de fraude direct sur son propre recouvrement.
3. **Aucune route ne retourne les données d'un établissement à un autre.** Vérification de propriété systématique.
4. **Aucun champ médical** ne transite par ces routes (cohérent avec R14).
5. **Aucune nouvelle migration.**

## PHASE 0 — AUDIT CIBLÉ

1. Confirme le mécanisme d'authentification API existant (Sanctum, Passport, autre) et comment une route vérifie aujourd'hui qu'un utilisateur appartient à une structure donnée (policy, middleware).
2. Confirme les conventions de pagination et de format de réponse déjà en usage dans l'API (enveloppe JSON, noms de champs).
3. Confirme le mécanisme de rôle/permission existant pour distinguer un utilisateur « établissement » d'un utilisateur « back-office MaSanté ».

**Arrête-toi et rapporte.**

## PHASE 1 — Routes et contrôleurs

Toutes les routes sous `/api/etablissement/facturation/` sont protégées par le middleware d'authentification existant **et** une policy vérifiant que l'utilisateur appartient à la structure demandée.

| Méthode | Route | Fait |
|---|---|---|
| GET | `/tableau-bord` | Volume du mois en cours, palier de commission actif, montant restant avant le palier inférieur (lecture seule, calcul délégué à une méthode de requête, pas un nouveau service) |
| GET | `/transactions` | Liste paginée des `commissions_transaction` de la structure, avec le détail façon reçu (§2.4) |
| GET | `/factures` | Historique des `factures_partenaire` de la structure |
| GET | `/factures/{id}` | Détail d'une facture, y compris ses règlements (`reglements_facture_partenaire`) |

Route back-office, sous `/api/backoffice/facturation/`, protégée par le rôle back-office :

| Méthode | Route | Fait |
|---|---|---|
| POST | `/factures/{id}/reglements` | Appelle `RecouvrementPartenaireService::enregistrerReglement()` (lot 1). Ne contient aucune logique d'imputation propre. |

## PHASE 2 — TESTS (`tests/Feature/`)

1. `test_etablissement_ne_voit_que_ses_propres_donnees` — tentative d'accès croisé refusée (403 ou 404 selon convention du projet).
2. `test_etablissement_ne_peut_pas_declarer_son_propre_reglement` — route back-office inaccessible à un compte établissement.
3. `test_pagination_respecte_la_convention_du_projet`.
4. `test_tableau_de_bord_reflete_le_palier_courant`.
5. `test_detail_facture_inclut_les_reglements`.
6. `test_aucun_champ_medical_dans_les_reponses` — inspection du payload JSON sur toutes les routes de ce lot.

## CHECKLIST FINALE

- [ ] Aucune migration ajoutée
- [ ] Aucune logique de calcul dans un contrôleur (`grep` de contrôle sur les mots-clés `round(`, `bareme`, `imputation` dans `app/Http/Controllers/`)
- [ ] Les 6 tests passent
- [ ] Policy de propriété vérifiée sur chaque route

## HORS PÉRIMÈTRE

Le portail établissement lui-même (écrans, lot séparé — voir note de séquencement), l'export PDF, les notifications.

## FIN DU PROMPT
