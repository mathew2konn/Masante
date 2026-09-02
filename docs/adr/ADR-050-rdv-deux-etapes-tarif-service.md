# ADR-050 — RDV à deux étapes : le médecin valide, le service tarife, l'enum mort est enfin branché

- **Statut** : **Accepté — B1-a VALIDÉ (G5, 2026-09-02).** G4 propriétaire OK.
- **Date** : 2026-09-01
- **Module** : B1-a — premier sous-incrément du lot RDV (suite de P11, Applications métier)
- **Corpus** : CDC_11 §9.1 (« le médecin fait la validation finale ») · CDC_02 §2.2 (source unique)

---

## 1. Contexte — ce que le G0 a établi

`RendezVousValidationService` ne codait qu'une seule transition (`en_attente → confirme|refuse`),
malgré le libellé « workflow deux étapes complet » du P4 validé G5 (2026-08-01) : le texte de la
même entrée ne décrivait que confirmer/refuser. `personnel_accueil` et `medecin` portaient
**exactement la même permission** `rdv.validate` pour l'appeler — rien dans le code ne
distinguait leurs rôles, malgré l'octroi de `rdv.validate` au rôle `medecin` en P11.0 (dette
« annoncée avec son porteur »).

Le tarif était porté par `medecins.tarif_consultation`, avec repli sur `structures_sanitaires.tarif_min_cfa` —
`services_etablissement` n'a jamais eu de colonne tarif.

`@masante/shared::RendezVousStatut` (sept valeurs, dont `PREVALIDE_SECRETAIRE`) était une **clé
totalement morte** — zéro import dans tout le monorepo, confirmé par recherche exhaustive.
Pendant ce temps, le vrai contrat (cinq valeurs) était dupliqué **indépendamment trois fois** :
PHP (`RendezVousValidationService::STATUTS`), web (`apps/web/src/lib/rdv-types.ts`), mobile
(`apps/mobile/src/types/structure.ts`).

`rendez_vous.triage_id` existait déjà depuis la création de la table — rien à créer sur ce point.

## 2. Décisions

### D1 — Nouveau statut `prevalide`, ENUM élargi jamais réécrit
Précédent : élargissement d'ENUM sans conversion des lignes historiques (P6.4a, P10b-1).
`rendez_vous.statut` gagne `prevalide` entre `en_attente` et `confirme`. Transitions redéfinies :
`previsalider()` (`en_attente → prevalide`, permission `rdv.prevalider`), `confirmer()` (accepté
**seulement** depuis `prevalide`, permission `rdv.validate`), `refuser()` (accepté depuis
`en_attente` **ou** `prevalide`, les deux permissions ouvrent ce chemin).

### D2 — Répartition RBAC (referme réellement la dette P6.5a/P11.0)
`personnel_accueil` **perd `rdv.validate`**, reçoit **`rdv.prevalider`** — lecture littérale de
CDC_11 §9.1 : jusqu'ici c'était l'inverse qui était vrai en pratique. `medecin` garde
`rdv.validate` seul (désormais réellement significatif). `gestionnaire_etablissement` garde les
deux (supervision).

**L'AUTORISATION EST VÉRIFIÉE DANS LE SERVICE, PAS DANS CHAQUE CONTRÔLEUR** : les deux
contrôleurs (Blade + API Next) délégueraient sinon la même vérification à deux endroits, avec le
risque qu'ils divergent un jour — `previsalider()`/`confirmer()`/`refuser()` prennent
l'utilisateur en paramètre et abortent eux-mêmes en 403 si la permission manque.

### D3 — Tarif porté par le service, sans repli bruyant destructeur
`services_etablissement.tarif_consultation_cfa` (nullable, additive) devient la source première.
`RecuRdvService::tarifPour()` : `service ?? medecin ?? structure` — le repli reste (un refus
bruyant casserait tous les établissements dont le service n'a pas encore de tarif configuré),
mais la source retenue est désormais **tracée** sur la facture (`factures_patient.tarif_source`)
— précédent `delai_source` (P6.7b), `provenance` (P6.8d) : *un montant ne doit jamais mentir sur
d'où il vient.*

### D4 — L'enum partagé mort est retiré et remplacé par le vrai contrat
`RendezVousStatut` (sept valeurs, `PREVALIDE_SECRETAIRE`) sort de `@masante/shared`, remplacé par
le contrat réel à **six valeurs** (`en_attente|prevalide|confirme|refuse|annule|honore`), miroir
de `RendezVousValidationService::STATUTS`. Web et mobile **importent désormais littéralement** le
type partagé au lieu de le redéclarer — leur divergence devient structurellement impossible (un
TypeScript qui ne compile pas la trahirait). PHP, qui ne peut pas importer un fichier TypeScript,
reçoit une garde d'exécution dédiée (`RendezVousStatutSourceUniqueTest`, motif
`PermissionsSourceUniqueTest`/`NisVecteursPartagesTest`) : elle vérifie **d'abord** avoir extrait
un nombre plausible de valeurs, sinon elle comparerait deux listes vides et passerait.

## 3. Défaut réel trouvé et corrigé

`PortesPortailTest::le_personnel_d_accueil_porte_exactement_ce_que_portait_l_agent_de_garde`
(P11.0) protégeait comme invariant du renommage `agent_garde → personnel_accueil` le fait que
l'accueil porte `rdv.validate` — exactement la dette que B1-a soldait. **Réécrit pour dire la
garantie neuve, pas corrigé pour passer** (précédent P6.4d) : l'accueil porte désormais
`rdv.prevalider` et ne porte **plus** `rdv.validate`, et le vecteur l'assert explicitement dans
les deux sens.

## 4. Preuve

**G3** : suite Laravel complète **1477/1477**, 17 320 assertions ; Pint propre sur tout le code
neuf (baseline établie contre `HEAD` — les fixers déjà présents dans les fichiers préexistants
touchés, non liés à cet incrément, ne sont pas reformatés) ; typecheck ×3 ; `next lint` sans
avertissement ; build Next vert (routes `previsalider`/`confirmer`/`refuser` enregistrées) ;
`expo-doctor` 18/18. **Mutation, trois gardes tuées** : l'inversion de l'état requis par
`confirmer()`, le `&&` substitué au `||` de `refuser()`, l'inversion de priorité de
`tarifPour()` — chacune tuée par son vecteur dédié, arbre restauré et revérifié.

**G2 live réel** (curl contre un `php artisan serve` dédié, base MySQL dev réelle sauvegardée
puis restaurée) : médecin tente de confirmer un `en_attente` → **409** ; accueil tente de
confirmer directement → **403** ; accueil prévalide → **200**, `statut=prevalide` ; accueil
re-prévalide → **409** ; médecin confirme → **200**, `statut=confirme` ; paiement patient →
montant **12000 venant du service**, vérifié directement en base
(`factures_patient.tarif_source='service'`).

## 5. Ce qui n'est pas dans ce lot

Fiche RDV enrichie (photo médecin, référent, triage associable a posteriori) → B1-b. Partage
temporaire 30 min + présence temps réel Reverb → B1-c. Facture/vérification/pont GeniusPay → B1-d.

Voir `docs/PLAN_G1_B1_Parcours_RDV.md` ; guide `GUIDE_TEST_APPLICATIONS_METIER.md` **partie 4**.
