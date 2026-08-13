# PLAN G1 — P6.4d « Formulaires du portail » (CDC_09 §4.2, CDC_11 §3.1)

**Statut : ✅ VALIDÉ (G5, 2026-08-13) — G2 et G3 prouvés, G4 propriétaire OK.**
Décision consignée : [ADR-029](adr/ADR-029-formulaires-portail.md) · Guide : [GUIDE_TEST_REFERENTIELS.md](../GUIDE_TEST_REFERENTIELS.md) **partie 5**.
Suit P6.4c (validé G5) · **Dernier incrément de P6.4 — le module est COMPLET.**

---

## 1. Ce que P6.4d doit refermer

Trois dettes ouvertes par les incréments précédents, toutes nommées et aucune oubliée :

- **M3** (ADR-026) — le formulaire d'administration collecte **11 champs** quand la base en porte une trentaine ;
- **M6** (ADR-026) — la colonne **`forme_juridique`** n'existe pas, et l'interprétation qui l'a écartée était le choix le plus discutable de P6.4a ;
- **N5** (ADR-027) — `ville_id` est posé par le seeder, **aucun écran ne le change** ;
- **O1** (ADR-028) — aucun écran ne permet de déposer une image.

---

## 2. G0 — ce que la lecture du code a établi

### J1 — Le formulaire couvre environ un tiers du schéma
`_form.blade.php` : `nom, type, adresse, commune, latitude, longitude, telephone, whatsapp, specialites, tarif_min, tarif_max, partenaire`. P6.4a a ajouté 22 colonnes, P6.4b `ville_id`, P6.4c les images. Aucune n'est saisissable.

### J2 — Le portail Next existe, et ce n'est pas un squelette
`apps/web/src/app/(portail)/` porte **trois modules réels** — rendez-vous (P4), alertes de fraude (B2), MFA (P1) — avec garde serveur (`getMe` + `estProfessionnel`, cookie httpOnly) et couche `lib/`. Les deux reports précédents (M1, O1) visaient donc une cible qui existe.

### J3 — La « Méthode 1 » est déjà implémentée
`store()` crée l'établissement, son compte gestionnaire **sans mot de passe**, et un lien d'activation à usage unique. La **Méthode 2** n'existe pas — c'est M1.

### J4 — `specialites_json` est un champ MORT
Écrit par le formulaire, **lu par personne** : ni la fiche mobile, ni la tuile, ni le portail, ni aucun filtre. Le type mobile le déclare, `itineraire.tsx` le met à `null`.

**Le filtre `?specialite=` de P3 passe par `services_etablissement.specialite`** — un *code* contraint par `^[a-z_]+$`, indexé, qui porte **aussi l'orientation après triage (F1.5)**. C'est **cette** colonne qui mériterait une table de référence, pas celle du formulaire.

### J5 — Bootstrap arrive d'un CDN, et le bleu est recopié
`layout.blade.php:7` charge Bootstrap 5.3.3 depuis jsDelivr : **sans internet, le portail s'affiche sans style**. Et `#1E6BB8` / `#0C3463` sont écrits en dur dans un `<style>` en ligne. Ils valent aujourd'hui exactement `blue.600` et `blue.900` de `palette.json` — **vérifié** — mais ce sont des **copies**, et ce projet s'est déjà fait mordre deux fois par ce motif.

---

## 3. Décisions du propriétaire (2026-08-13)

| # | Décision | Justification |
|---|---|---|
| **K1** | **P6.4d reste en Blade, sans investir dans le design.** La migration du portail devient un **module à part**. | Moderniser Bootstrap reviendrait à écrire un **second design system** par-dessus, en doublon de `@masante/shared`, pour un portail qu'ADR-011 condamne. Et migrer les seuls écrans d'établissement **couperait le portail en deux** : le gestionnaire irait en Blade pour ses services et ses agents, en Next pour son établissement. La migration porte sur **tout** le portail (dix-sept zones) ou sur rien. |
| **K2** | **`specialites` est RETIRÉ du formulaire** ; la colonne reste en base. La table de référence est **consignée pour `services.specialite`**, à son écran. | On ne fait plus saisir au gestionnaire une donnée que rien ne lit. Poser un garde-fou sur `specialites_json` reviendrait à **garder une porte que personne n'emprunte** pendant que la vraie reste ouverte. |
| **K3** | **La Méthode 2 reste hors périmètre** ; **M1 reste ouverte et dite.** | C'est un parcours public complet (demande, vérification, publication, notifications) — un module, pas un formulaire. |
| **K4** | **Bootstrap est servi en local.** | Correction chirurgicale et indépendante : sans internet le portail est **inutilisable**, pas seulement laid. Dans un hôpital à connectivité intermittente, ce n'est pas cosmétique. |

---

## 4. Périmètre

1. Migration additive : colonne **`forme_juridique`** (lève M6).
2. **Formulaire complet**, groupé comme CDC_11 §3.1 le décrit — général, légal, coordonnées, rattachement sanitaire, capacités, description — avec `ville_id` (lève **N5**) et `identifiant_national` **en lecture seule** (il est attribué, jamais saisi).
3. **Contrôle « le district appartient-il à la région déclarée ? » AU FORMULAIRE.** P6.4a le *détecte* dans les contrôles qualité ; le formulaire est l'endroit où il doit être **empêché**. C'est l'anomalie la plus sournoise du lot : les deux références sont valides séparément, seule leur combinaison est fausse.
4. **Écran d'images** (lève **O1** côté Blade) : dépôt par catégorie, aperçu, suppression.
5. `specialites` retiré (K2).
6. **Bootstrap et ses icônes servis depuis `public/vendor/`** (K4).

## 5. Hors périmètre

| # | Limite |
|---|---|
| **P1** | **Méthode 2 non livrée** — M1 d'ADR-026 **reste ouverte** : tant qu'elle tient, l'affirmation de CDC_11 §3 est fausse dans ce projet. |
| **P2** | **Le design du portail n'est pas retouché** *(K1)*. Le portail Blade reste hors du design system, et **la migration vers Next devient un module identifié**, pas une intention. |
| **P3** | **Table de référence sur `services.specialite` non faite** — consignée pour **P10**, où la refonte du triage est déjà au programme (même raisonnement que le foyer désigné pour L1 d'ADR-025). Conséquence en attendant : **une faute de frappe sur un code de spécialité coûte une mauvaise orientation après triage**. |
| **P4** | `commune` reste un texte libre (N3 d'ADR-027). |
| **P5** | Aucun écran Next : les images et les champs restent gérés en Blade jusqu'à la migration. |

## 6. Preuves attendues

- **G2 live** : formulaire complet enregistré et relu ; district hors région **refusé au formulaire** ; `identifiant_national` non modifiable ; dépôt/suppression d'image depuis l'écran ; portail **stylé sans internet**.
- **G3** : tests dédiés dans les deux sens ; suite complète (référence : **530 tests / 14 692 assertions**) ; typecheck ×3.
- **G4** : **partie 5** du guide `GUIDE_TEST_REFERENTIELS.md`.
