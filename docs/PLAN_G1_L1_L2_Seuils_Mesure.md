# Plan G1 — L1 + L2 sur `seuils_mesure`

**Referme la moitié du défaut du G0 de P6.3.** Bascule la lecture des seuils de mesure sur le
référentiel gouverné (L1) et estampille chaque mesure de la version qui l'a qualifiée (L2).

- **Périmètre décidé par le propriétaire (2026-08-14)** : `seuils_mesure` **seul**, L1 **et** L2.
  `symptomes_triage` reste rattaché à **P10** (ADR-025 §5.3) — la refonte du triage y repassera de
  toute façon, et y faire la bascule maintenant modifierait un module G5 deux fois.
- **Comportement avant la première publication décidé par le propriétaire** : **refus bruyant**. La
  publication de la v1 devient une étape de déploiement documentée, faite par des humains habilités
  via la gouvernance §10 — jamais par un seeder, qui contournerait le quatre-yeux dès le premier jour.
- **Référence** : ADR-025 §5 (statut de L1/L2), CDC_09 §10 et §1.2.4.

---

## 1. G0 — ce que le code dit réellement

Sept constats, tous vérifiés dans les fichiers. Trois d'entre eux (C1, C5, C6) n'étaient **pas**
anticipés par ADR-025 §5 et changent la forme du travail.

### C1 — la lecture est concentrée, mais **deux lectures contournent le service**

`MesureSanteService::referentiels()` tient en une ligne mémoïsée (`MesureSanteService.php:45`). Mais
`MesureSanteController` lit **directement le modèle** à deux endroits, sans passer par le service :

- ligne 47 — `Rule::in(ReferentielMesure::pluck('type_mesure')->all())`, filtre du journal ;
- ligne 118 — `$types = ReferentielMesure::pluck('type_mesure')->all()`, règles de saisie.

**Il faut les basculer aussi.** Les laisser produirait la contradiction exacte que le socle cherche à
supprimer : la saisie accepterait un type absent de la version publiée, et le référentiel diffusé
dirait autre chose que les règles qui gouvernent l'écriture. **Deux vérités.**

### C2 — la qualification vit sur le **modèle**, et c'est ce qui rend la bascule chirurgicale

`ReferentielMesure::statutPour()` est une méthode du modèle, pure : elle ne lit que ses propres
attributs. `MesureSanteService::enregistrer()` s'en sert (`$seuils->statutPour(...)`, `$seuils->unite`).

Donc **réhydrater des modèles non persistés** depuis l'instantané (`new ReferentielMesure($ligne)`,
jamais sauvegardé) conserve `statutPour()`, les casts et l'ordre — et **aucun des quatre
consommateurs ne change de contrat**. C'est la voie chirurgicale, contre l'alternative qui aurait
imposé de réécrire le service, le contrôleur et le type mobile.

Les douze champs de l'instantané sont tous dans `$fillable` : l'hydratation par assignation de masse
passe sans exception.

### C3 — le JSON perd trois champs, et le mobile ne les a jamais déclarés

`SourceSeuilsMesure::extraire()` **n'inclut ni `id` ni les timestamps** — contrairement à
`SourceSymptomesTriage`, qui inclut `id` délibérément (un triage archivé y désigne ses symptômes).
Ici, `type_mesure` est `UNIQUE` : c'est la clé naturelle, l'`id` technique n'a rien à porter.

Aujourd'hui `GET /mesures` sérialise le modèle entier, donc `referentiels[].id`, `created_at` et
`updated_at` sortent. Après la bascule, non. **Vérifié côté mobile** : `types/mesure.ts` ne déclare
**aucun** des trois, et `MesuresEcran.tsx:122` n'utilise que `r.type_mesure`. La bascule **rapproche**
donc la réponse du contrat déclaré au lieu de l'en éloigner.

Le cache hors-ligne P2 stocke le JSON brut : les entrées mises en cache avant la bascule portent des
champs en trop, ce que l'interface TypeScript tolère. Aucune purge nécessaire.

### C4 — rien n'est publié aujourd'hui

Le seeder de P6.3 enregistre le registre et **ne publie rien** (décision assumée). Donc
`DiffusionReferentiel::lire()` lève aujourd'hui un 404 « aucune version publiée ». C'est ce constat
qui rendait la question du propriétaire nécessaire, et sa réponse est le **refus bruyant**.

### C5 — la suite de tests d'un module G5 va échouer, et c'est la preuve que la garantie est réelle

`MesureSanteTest::setUp()` seede `ReferentielMesureSeeder`, c'est-à-dire **la table**. Après la
bascule, ses vecteurs échouent tant qu'aucune version n'est publiée.

Il faut donc un helper de test qui enregistre, propose et publie la v1 — et il lui faut **deux
comptes habilités**, parce que le quatre-yeux ne se contourne pas, pas même en test. Ce coût n'est
pas un effet de bord à absorber discrètement : c'est la démonstration que la gouvernance s'applique
vraiment au chemin de lecture.

### C6 — **deux** commentaires promettent l'inverse de ce que le code fera, pas un

ADR-025 §5.4 n'en annonçait qu'un. Il y en a deux :

| Fichier | Ce qu'il promet |
|---|---|
| `2026_07_13_000003_create_referentiels_mesure_table.php` | « *un médecin peut les corriger par un `UPDATE`, sans redéployer* » |
| `MesureSanteController.php:19-20` | « *Corriger une norme médicale = un `UPDATE` en base, sans redéployer l'app* » |

Le modèle `ReferentielMesure.php:9` porte la même phrase. **Trois endroits**, à corriger dans le même
incrément — sans quoi le code affirmera durablement le contraire de ce qu'il fait.

### C7 — `mesures_sante` n'a aucune colonne de version

L2 est donc une migration **additive** : une colonne nullable. Les mesures antérieures resteront à
`NULL` et le diront.

---

## 2. Décisions

| # | Décision | Pourquoi |
|---|---|---|
| **D1** | Périmètre `seuils_mesure` seul, L1 **et** L2 | Propriétaire. C'est le référentiel **sans foyer** d'ADR-025 §5.3 : si ce n'est pas maintenant, ce n'est jamais. L2 sans L1 produirait une mention fausse (§5.2). |
| **D2** | Aucune version publiée → **échec bruyant** | Propriétaire. Un repli silencieux laisserait un oubli de publication passer inaperçu, et la garantie serait inactive sans que personne ne le sache. |
| **D3** | Réhydrater des modèles **non persistés** depuis l'instantané | C2 : conserve `statutPour()`, les casts et le contrat des quatre consommateurs. Correction chirurgicale, pas réécriture. |
| **D4** | Contenu **et** numéro de version mémoïsés ensemble | Une seule lecture par requête, et l'estampille de L2 est déjà en main au moment d'écrire — L2 devient presque gratuite, comme l'annonçait ADR-025 §5.2. |
| **D5** | Estampille **nullable**, jamais rétroactive | Les mesures antérieures n'ont eu **aucune** version ; leur en attribuer une serait un mensonge d'archive (même refus qu'en P6.3). |
| **D6** | Les deux `pluck` du contrôleur passent par le service | C1. Sinon la validation et la diffusion divergent. |
| **D7** | La publication de la v1 est une **étape de déploiement**, pas un seeder | Publier depuis un seeder contournerait le quatre-yeux — refus déjà posé en P6.3. |

---

## 3. Ce qui est construit

### L1 — la lecture bascule

- `MesureSanteService::referentiels()` lit `DiffusionReferentiel::lire('seuils_mesure')` et hydrate
  des `ReferentielMesure` non persistés, triés par `ordre`.
- `MesureSanteService::versionReferentiel()` — le numéro en vigueur, mémoïsé avec le contenu.
- `MesureSanteService::typesConnus()` — remplace les deux `ReferentielMesure::pluck()` du contrôleur.
- Aucune signature publique existante ne change : `referentiels()` renvoie toujours une
  `Collection<ReferentielMesure>`, `referentiel(string)` toujours `?ReferentielMesure`.

### L2 — l'estampille

- Migration additive : `mesures_sante.referentiel_version` (`unsignedInteger`, **nullable**).
- Posée par `enregistrer()` hors `$fillable`, comme `statut_norme` et `unite` — un dérivé du serveur.
- Les deux lignes d'une tension portent la même version : elles naissent dans la même transaction.

### Ce qui est corrigé en même temps

- Les **trois** commentaires de C6.
- Le guide de test et ADR-025 §5, dont la limite L1/L2 devient partiellement refermée.

### Ce qui n'est **pas** touché

`MesuresEcran.tsx`, `types/mesure.ts`, `api/mesures.ts`, le cache P2, la table `referentiels_mesure`
et son seeder. `symptomes_triage` et `TriageService` non plus.

---

## 4. Preuves attendues

### G3 — vecteurs dédiés, écrits dans les deux sens

| # | Vecteur | Ce qu'il prouve |
|---|---|---|
| 1 | Aucune version publiée → l'écran des mesures **échoue bruyamment** | D2 : pas de repli silencieux |
| 2 | `UPDATE` direct sur `referentiels_mesure` → la qualification **ne change pas** | **Le vecteur central de L1** : le miroir exact du « `UPDATE` direct ne change pas le diffusé » de P6.3, cette fois du côté qui décide |
| 3 | Publier une v2 au seuil corrigé → la qualification **change** | Sans lui, le vecteur 2 prouverait seulement que plus rien ne marche |
| 4 | Une mesure enregistrée porte la version en vigueur | L2 |
| 5 | Deux mesures encadrant une publication portent **deux versions différentes** | Ce qui rend une mesure « critique » d'hier explicable — le défaut du G0 |
| 6 | Une mesure antérieure à la bascule reste à `NULL` | D5 : aucune estampille rétroactive |
| 7 | Le proposeur ne peut pas publier sa propre proposition | Le quatre-yeux s'applique au chemin de lecture comme au reste |
| 8 | Un type absent de la version publiée est **refusé à la saisie** | C1/D6 : validation et diffusion disent la même chose |

Plus : suite complète verte (les vecteurs hérités de `MesureSanteTest` adaptés, **pas contournés**),
`pnpm typecheck` ×3.

### G2 — live MySQL

Publier v1 par la gouvernance réelle (deux comptes habilités, via l'API) · saisir une mesure et lire
sa version en base · `UPDATE` direct d'un seuil **sans effet** sur la qualification · publier v2 ·
la nouvelle mesure porte v2, l'ancienne v1 · base restaurée.

---

## 5. Limites annoncées

1. **`symptomes_triage` reste ouvert.** L1/L2 ne sont refermées **que pour les mesures** ; le triage
   garde son foyer P10. Le défaut du G0 de P6.3 est donc **à moitié** refermé, pas refermé.
2. **Rupture d'exploitation assumée** (ADR-025 §5.4) : corriger un seuil exigera désormais une
   proposition **et** une publication par deux personnes habilitées. C'est exactement ce que veut
   CDC_09 §1.2.4 — et c'est une vraie rupture avec la promesse actuelle.
3. **Multi-pays** : la version est stockée sans code pays, un déploiement servant un seul pays
   (`referentiels.pays_defaut`). À revoir si un jour une instance sert deux pays.
4. **Aucun écran de gouvernance** (limite L7 d'ADR-025, inchangée) : proposer et publier se font par
   API. La v1 exige donc une procédure de déploiement écrite, pas un clic.
5. **Les mesures antérieures ne seront jamais estampillées.** Elles diront « version inconnue », ce
   qui est la vérité.
