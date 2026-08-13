# ADR-024 — Référentiels nationaux : enrichissement additif, jamais de remplacement (P6.3+)

**Statut : Accepté** — tranché au G1 de P6 (2026-08-11), matérialisé en fichier au G5 de P6.3 (2026-08-13) parce que P6.3 en dépend directement · Contexte : CDC_09 §4, §5, §6, §7 · Complété par [ADR-025](ADR-025-socle-referentiel.md).

---

## 1. Contexte

CDC_09 décrit des référentiels nationaux d'établissements (§4), de professionnels (§5), de médicaments (§6) et de laboratoires (§7), avec des champs que le projet ne porte pas encore : identifiant national, district sanitaire, niveau de soins, numéro d'ordre professionnel, DCI normalisée, `pays_code`.

Or ces référentiels **existent déjà de facto** dans le code, nés comme tables applicatives :

| Table | Née pour | Statut du module |
|---|---|---|
| `structures_sanitaires` | annuaire géolocalisé (Module 3) | **P3 validé G5** |
| `medecins` | choix du praticien pour un RDV (Module 3/5) | **P4 validé G5** |
| `medicaments` | comparateur de prix CENAME (Module 5) | validé |
| `referentiels_mesure`, `symptomes`, `etapes_prenatales` | règles cliniques en base | validés |

La question posée au G1 était donc : crée-t-on les tables « nationales » de CDC_04 §121 (`etablissements_nationaux`, `professionnels_nationaux`, `medicaments_nationaux`…) à côté, ou fait-on évoluer l'existant ?

---

## 2. Décision

**Les tables existantes sont enrichies sur place. Aucune n'est remplacée, aucune n'est doublée.**

Concrètement, chaque incrément P6.4 → P6.7 ajoute à sa table les colonnes que CDC_09 exige (identifiant national, district sanitaire, niveau de soins, numéro d'ordre, `pays_code`), par **migration strictement additive**, et n'en modifie ni n'en supprime aucune.

### Pourquoi

- **P3 et P4 sont validés G5**, et la méthode impose « corrections chirurgicales uniquement ». Créer `etablissements_nationaux` à côté de `structures_sanitaires` obligerait à réécrire l'annuaire, la carte, la recherche, les RDV et le cache hors-ligne P2 — c'est-à-dire à défaire du travail prouvé pour obtenir le même contenu sous un autre nom.
- **Deux tables pour la même chose, c'est deux vérités.** Le jour où l'une des deux diverge — et elle divergerait — plus personne ne saurait laquelle fait foi. C'est exactement le problème que CDC_09 §1.2.4 vient résoudre (« référentiel = source unique de vérité »).
- Le même raisonnement a déjà tranché deux fois dans ce projet : le NIS s'est posé **sur** `membres_famille` plutôt que de créer un porteur parallèle (ADR-021), et la revendication de carnet garde l'`id` de la ligne existante plutôt que de fusionner (P7-B).

---

## 3. Conséquences

- Le nom des tables ne suit pas la nomenclature de CDC_04 §121 (`structures_sanitaires` et non `etablissements_nationaux`). **Assumé** : le contenu et les garanties comptent, pas l'étiquette — et renommer coûterait une réécriture de modules prouvés pour un gain nul.
- La mise sous gouvernance de ces référentiels (versionnage, audit, diffusion — ADR-025) est **indépendante** de leur enrichissement : le socle observe une table sans la modifier. Un référentiel peut donc être gouverné avant d'être enrichi, et inversement.
- **P6.3 n'enrichit rien.** Il ne place sous gouvernance que `referentiels_mesure` et `symptomes_triage`, qui n'ont besoin d'aucune colonne nouvelle. Les annuaires entrent au registre avec leur incrément dédié.
- L'ajout de `pays_code` aux référentiels d'annuaire reste **à faire**, incrément par incrément. Aujourd'hui, seuls le NIS (P6.1) et le socle (P6.3) le portent.
