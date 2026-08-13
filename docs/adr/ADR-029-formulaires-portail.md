# ADR-029 — Formulaires du portail : compléter là où ils vivent, migrer le portail en une fois (P6.4d)

**Statut : Accepté** — 2026-08-13 · Contexte : CDC_09 §4.2 · CDC_11 §3.1 · §1.2.4 · Clôt [ADR-026](ADR-026-referentiel-etablissements.md) M3/M6, [ADR-027](ADR-027-villes-geolocalisation.md) N5, [ADR-028](ADR-028-images-etablissements.md) O1 · En tension avec [ADR-011](README.md).

---

## 1. Contexte

Dernier incrément de P6.4. Trois dettes ouvertes attendaient un écran : le formulaire ne couvrait qu'un tiers du schéma (**M3**), `forme_juridique` n'existait pas (**M6**), `ville_id` n'était posé que par le seeder (**N5**), et aucun écran ne déposait d'image (**O1**).

Le G0 a établi cinq choses.

**J1** — `_form.blade.php` collecte **11 champs** ; P6.4a en a ajouté 22, P6.4b `ville_id`, P6.4c les images. Aucune n'était saisissable.

**J2 — Le portail Next existe, et ce n'est pas un squelette.** `apps/web/src/app/(portail)/` porte **trois modules réels** — rendez-vous (P4), alertes de fraude (B2), MFA (P1) — avec garde serveur et couche `lib/`. Les deux reports précédents (M1, O1) visaient donc une cible qui existe.

**J3** — La « Méthode 1 » est déjà implémentée (`store()` crée l'établissement, le compte gestionnaire sans mot de passe, et un lien d'activation). La **Méthode 2** n'existe pas.

**J4 — `specialites_json` est un champ MORT** : écrit par le formulaire, lu par **personne**. Le filtre `?specialite=` de P3 passe par `services_etablissement.specialite`, une **autre** colonne, qui porte aussi l'**orientation après triage (F1.5)**.

**J5 — Bootstrap arrive d'un CDN**, et le bleu est recopié en dur (`#1E6BB8`, `#0C3463`). Ces valeurs correspondent aujourd'hui exactement à `blue.600` et `blue.900` de `palette.json` — **vérifié** — mais ce sont des **copies**.

---

## 2. Décision principale : compléter en Blade, et faire de la migration un module

La question posée était : « Bootstrap peut-il produire un design moderne ? » **Techniquement oui** — c'est un cadre CSS, et ce qu'on appelle « le look Bootstrap » est son thème par défaut.

**Mais la vraie question n'était pas le plafond de Bootstrap.** Le portail Next **consomme déjà** `@masante/shared/tailwind-preset` : un rendu moderne, aligné sur le design system, **existe déjà dans le projet**. Moderniser Blade reviendrait donc à écrire un **second design system par-dessus Bootstrap**, en doublon de celui qui existe, pour un portail qu'ADR-011 condamne.

**L'exigence de design moderne plaide donc pour Next, pas contre.** Mais migrer les **seuls** écrans d'établissement couperait le portail en deux : un gestionnaire irait en Blade pour ses services, ses agents et ses disponibilités, et en Next pour son établissement — **pire que l'un ou l'autre des extrêmes**. La migration d'ADR-011 porte sur **tout** le portail (dix-sept zones), ou sur rien.

**Retenu** : P6.4d livre ce dont le référentiel a besoin, **là où le formulaire vit déjà**, sans dépenser dans une impasse. **La migration du portail devient un module identifié** — c'est là que le design moderne se fera **une fois**, sur le design system partagé.

> Cette décision assume un **coût de reprise** : le travail de formulaire fait ici sera refait en Next. Il est préféré au coût de construire un second design system, puis de le jeter.

---

## 3. Décisions secondaires

### 3.1 Le contrôle région/district passe de la détection à l'interdiction

P6.4a **détecte** un district hors de sa région dans les contrôles qualité du référentiel. Le formulaire est l'endroit où il faut l'**empêcher**.

C'est **l'anomalie la plus sournoise du lot** : une validation `exists:` accepte les deux références, car chacune est valide **prise séparément** ; seule leur **combinaison** est fausse. Détecter après coup oblige à retrouver qui a saisi quoi ; au formulaire, l'agent a encore l'information sous les yeux. Le message nomme le district **et sa vraie région**, et la liste affiche « *Région — District* » pour que le couple se voie.

### 3.2 Deux axes juridiques, pas un *(lève M6)*

`statut_juridique` dit **qui possède** (public, privé, universitaire, militaire). `forme_juridique` dit **sous quelle forme de droit** (SARL, SA, association, EPN). Une clinique privée peut être une SARL ou une SA ; les fondre rendrait impossible la question « combien de SARL parmi les cliniques privées ? », exactement le genre de statistique que §4.4 assigne à ce référentiel.

**Texte libre et non énumération**, à dessein : les formes de droit varient d'un pays à l'autre et le référentiel est multi-pays. Une énumération figée sur le droit ivoirien devrait être migrée à chaque pays ajouté.

**Les deux entrent dans la projection gouvernée** : les deux engagent une autorité. Conséquence attendue et non une dérive — **l'empreinte du référentiel change** avec cet incrément.

### 3.3 `specialites` est retiré du formulaire, la colonne est conservée *(K2)*

**Ma recommandation initiale visait la mauvaise porte.** J'avais proposé une table de référence pour `specialites_json` « en préservant le contrat `?specialite=` de P3 » : ce filtre ne passe pas par cette colonne. `specialites_json` était **écrit et lu par personne**.

Poser un garde-fou dessus reviendrait à **garder une porte que personne n'emprunte** pendant que la vraie reste ouverte. On cesse donc de faire saisir une donnée morte ; **la colonne et les données existantes sont conservées** — une migration destructive aurait perdu de l'information réelle pour un gain nul.

**La vraie table de référence est consignée pour P10** : `services_etablissement.specialite` porte le filtre **et l'orientation après triage**, et P10 refond déjà le triage — y faire la bascule évite de toucher deux fois un module validé G5 (même raisonnement que le foyer désigné pour L1 d'ADR-025). **Conséquence en attendant : une faute de frappe sur un code de spécialité coûte une mauvaise orientation.**

### 3.4 Bootstrap servi en local *(K4)*

Sans internet, le portail ne s'affichait pas « moins bien » : il s'affichait **sans aucun style**, donc inutilisable. Dans un établissement à connectivité intermittente, ce n'est pas cosmétique. S'y ajoutait une dépendance externe hors lockfile (§2.6).

**Piège rencontré** : `.gitignore` porte `**/vendor/` pour Composer, ce qui ignorait silencieusement `public/vendor/`. Le correctif aurait fonctionné sur la machine de développement et **disparu partout ailleurs** — un correctif invisible est pire qu'un défaut connu. Les fichiers vivent donc dans `public/assets/bootstrap/`, et `git check-ignore` fait partie de la checklist.

### 3.5 Le formulaire d'images est séparé

Un envoi de fichier échoue plus souvent qu'une saisie de texte (taille, format, réseau). Fondu dans le même formulaire, un refus d'image ferait perdre **trente champs déjà remplis**. Les gardes ne sont pas réécrites : elles vivent dans `ImagesEtablissement`, le service que l'API mobile utilise déjà — motif de P4, où le workflow de validation des rendez-vous a été extrait dans un service partagé.

---

## 4. Conséquences et limites

**Acquis.** Le formulaire couvre le schéma ; M3, M6, N5 et O1 (côté Blade) sont refermées ; l'anomalie région/district est empêchée à la saisie ; le portail fonctionne sans internet.

**Limites assumées, reportées dans le guide (partie 5).**

- **P1 — La « Méthode 2 » n'est pas livrée : M1 d'ADR-026 RESTE OUVERTE.** Tant qu'elle tient, l'affirmation de CDC_11 §3 selon laquelle les deux méthodes sont implémentées **est fausse dans ce projet**.
- **P2 — Le design du portail n'est pas retouché** *(§2)*. **La migration vers Next devient un module identifié**, pas une intention.
- **P3 — Table de référence sur `services.specialite` non faite**, consignée pour **P10** *(§3.3)*.
- **P4 — `commune` reste un texte libre** (N3 d'ADR-027).
- **P5 — Trois autres bibliothèques arrivent encore d'un CDN** : `html5-qrcode` (écran de scan) et Chart.js (deux écrans de statistiques). **Même défaut que Bootstrap** — sans internet ces écrans cassent. Hors du périmètre de la décision K4, mais réel et consigné plutôt que tu.

**Aucune dépendance nouvelle** : Bootstrap était déjà utilisé ; il cesse simplement d'être chargé depuis un tiers.
