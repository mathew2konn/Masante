# PLAN G1 — P6.3 « Socle référentiel » (CDC_09 §10, §12, §14.1)

**Statut : ✅ MODULE VALIDÉ G5 (2026-08-13)** — G1 tranché, G2 live et G3 prouvés, **G4 propriétaire OK**.
Date : 2026-08-13 · Module précédent : P7 Carnet familial partagé (COMPLET) · Corpus : CDC_09 §10/§12/§14.1, CDC_04 §… (conventions, audit, cache), ADR-024.
Décision finale : [ADR-025](adr/ADR-025-socle-referentiel.md) · Guide : [GUIDE_TEST_REFERENTIELS.md](../GUIDE_TEST_REFERENTIELS.md).

> **Décisions tranchées par le propriétaire au G1** : **D1 (a)** version du référentiel entier +
> instantané JSON · **D2 (a)** cycle §10 complet avec quatre-yeux · **D3 (a)** `referentiels_mesure`
> + `symptomes` · **D4 (a)** cache Laravel à clé versionnée, Redis en bascule de configuration ·
> **D5** permissions attachées à **aucun rôle métier**, accordées nominativement.
>
> **Ce que le G2 live a changé au plan.** Les trois invariants du cycle de vie devaient être des
> `CHECK`. MySQL 8.4 les a refusés — **erreur 3823** : un `CHECK` ne peut pas porter sur une colonne
> subissant une action référentielle, or `propose_par`/`decide_par` sont `nullOnDelete` et
> `referentiel_id` est `cascadeOnDelete` (cousin exact de l'erreur 1215 de P6.1). Retenu : des
> **triggers** dans les deux dialectes, voie explicitement prévue par CDC_04 §139 ; l'unicité, elle,
> reste déclarative. Détail en [ADR-025 §3](adr/ADR-025-socle-referentiel.md).
>
> **Une limite s'est ajoutée en cours de route** : **L7** — aucun écran, la gouvernance s'exerce par
> API (comme les incréments du service de paiement). L'écran d'administration viendra avec le
> portail des référentiels (P6.4+).

---

## 0. Ce que « socle référentiel » veut dire ici

CDC_09 §14 ordonne la construction : **1. « Modèle de données des référentiels + versionnage + audit »**, puis 2. NIS (fait, P6.1), 3. MPI (abandonné → remplacé par P7), **4. établissements, 5. professionnels, 6. médicaments, 7. laboratoires, 8. transverses**.

P6.3 est donc l'**étape 1** : la *gouvernance* commune à tous les référentiels — pas le contenu d'un référentiel particulier. Les référentiels métier (établissements, professionnels, médicaments, laboratoires) sont P6.4 → P6.7 et **ne sont pas touchés ici**.

---

## 1. G0 — audit réel (ce qui a été lu, pas supposé)

### F1 — ADR-022, ADR-023 et ADR-024 n'existent qu'en une ligne de tableau
`docs/adr/` contient les fichiers ADR-014 → **ADR-021** seulement. ADR-022/023/024 sont enregistrés « Accepté (G1) » dans `docs/adr/README.md` mais **sans fichier de décision**. Or **ADR-024 est le fondement de ce module** (« enrichissement additif, jamais de remplacement »). → Écrire `ADR-025-socle-referentiel.md` pour P6.3, et **matérialiser ADR-024** en fichier puisque P6.3 en dépend directement.

### F2 — Le mot « référentiel » désigne déjà quatre choses dans le code, aucune gouvernée
| Table existante | Ce que c'est réellement | Statut |
|---|---|---|
| `referentiels_mesure` (7 lignes seedées) | **Règles cliniques** : bornes de plausibilité, normalité, seuils critiques, conseil au patient | Aucun versionnage, aucun audit |
| `symptomes` | **Règles de triage** : `poids_severite`, `drapeau_rouge`, questions complémentaires JSON | Aucun versionnage, aucun audit |
| `etapes_prenatales` | Calendrier prénatal national | idem |
| `structures_sanitaires`, `medicaments`, `medecins` | Référentiels nationaux **de facto** (CDC_09 §4/§5/§6), nés comme tables d'annuaire applicatives | idem — leur mise à niveau est P6.4/P6.5/P6.6 |

Le commentaire de la migration `referentiels_mesure` dit lui-même : « *un médecin peut les corriger par un UPDATE, sans redéployer* ». C'est exactement l'`UPDATE` que §10 exige de **versionner et d'auditer**.

### F3 — Aucun versionnage nulle part
CDC_09 §10 : « *toute décision clinique ou financière conserve la version du référentiel utilisée* ». Vérifié : `triages` **ne stocke aucune version de protocole** (alors que CDC_04 §115 la prévoit dans le schéma cible), `ordonnances` non plus. Aujourd'hui, si l'on corrige le poids de sévérité d'un symptôme, **plus aucun triage passé n'est explicable**.

### F4 — Aucun journal d'audit générique
`journaux_audit` (CDC_04 §125, §189) n'existe pas côté Laravel. Ce qui existe est **spécialisé** : `acces_dossier` (lecture de dossier), `nis_journal` (attribution de NIS), `carnet_transferts` (revendication), et — **côté Java uniquement** — `audit_entries` à hachage chaîné (P5.1). **Une modification de référentiel n'est tracée nulle part.**

### F5 — Le cache Laravel est `database`, pas Redis
`.env` : `CACHE_STORE=database`. Les variables `REDIS_*` sont présentes mais **inutilisées** par l'API (Redis tourne pour le microservice paiement, en Docker). Conséquence technique dure : **le driver `database` ne supporte pas `Cache::tags()`** — une invalidation « par référentiel » via des tags est impossible. Ce n'est pas une gêne : §10 demande « *invalidation par événement lors d'une nouvelle version* », c'est-à-dire précisément une **clé de cache portant le numéro de version** (cf. §4.4).

### F6 — `pays_code` n'existe que sur le NIS
Posé par P6.1 sur `membres_famille`, `nis_compteurs`, `nis_journal`. Le principe §1.2.5 (« un jeu de référentiels par pays, **aucun changement de code** ») n'est aujourd'hui porté par rien d'autre.

### F7 — Aucune permission d'écriture de référentiel, et la « double validation » n'existe qu'en Java
`RoleSeeder` crée les 11 rôles **sans leur attribuer aucune permission** (« *recevront leurs permissions lors de la construction des modules web* »). §10 exige « *accès en écriture strictement réservé aux rôles habilités, avec MFA et double validation* » :
- **MFA** existe (P1) mais reste gouverné par la bascule `MFA_ENFORCE` (**OFF** en MVP) → on ne peut pas honnêtement le déclarer actif ici ;
- **double validation** = le motif « quatre-yeux » déjà éprouvé en Java (P5.5b-1 : approbateur ≠ calculateur, garanti par CHECK) — **rien d'équivalent en PHP**.

---

## 2. Périmètre de P6.3 — ce qui est livré

1. **Registre des référentiels** — une table déclarant chaque référentiel national gouverné : code, libellé, `pays_code`, rôle propriétaire (§10 « chaque référentiel a un responsable désigné »), version publiée courante.
2. **Cycle de vie versionné** (§10) — `proposition → validation → publication → archivage`, une version = une ligne immuable, numérotation croissante par référentiel et par pays.
3. **Quatre-yeux** — le validateur ne peut pas être l'auteur de la proposition (garanti en base par `CHECK`, pas seulement en PHP), miroir du motif P5.5b-1 et de la règle P7-C « l'auteur ne peut pas se valider lui-même ».
4. **Instantané publié** — le contenu du référentiel au moment de la publication est figé en JSON dans la version. C'est **lui** qui rend une décision passée rejouable, sans toucher aux tables métier (cf. §4.2).
5. **Journal d'audit immuable à hachage chaîné** — toute modification d'un référentiel produit une entrée append-only (§11 « *toute modification d'un référentiel produit une entrée d'audit immuable* »), en portant en PHP le motif déjà prouvé en Java.
6. **Diffusion en lecture** (§10) — API de lecture d'un référentiel publié, servie par un cache **cache-aside dont la clé porte le numéro de version** → une publication invalide instantanément, sans tags, quel que soit le store.
7. **Contrôles qualité** (§10) — unicité, format, cohérence, refus des valeurs aberrantes, exécutés **à la validation** : une version incohérente ne peut pas être publiée.
8. **Estampille de version** — un moyen de tamponner une décision avec la version utilisée, **fourni mais branché sur zéro décision existante** (voir §5, limite L2).

## 3. Ce que P6.3 ne fait PAS (limites annoncées, pas oubliées)

| Hors périmètre | Pourquoi |
|---|---|
| Enrichir `structures_sanitaires`, `medecins`, `medicaments` | C'est P6.4 / P6.5 / P6.6. ADR-024 : enrichissement additif, **à leur incrément**. |
| Basculer les lectures existantes (triage, annuaire, mesures) sur le service de diffusion | Ces modules sont **validés G5** ; « corrections chirurgicales uniquement ». La bascule se fera module par module, additivement. Conséquence honnête : en P6.3, la diffusion cachée sert **la nouvelle API**, pas encore les écrans existants. |
| Estamper les décisions passées (triages, ordonnances déjà enregistrés) | On ne réécrit pas l'historique. Une décision antérieure au socle n'a pas de version — et le dira, plutôt que d'en inventer une. |
| Redis, Elasticsearch | Aucune dépendance ni infrastructure nouvelle (§2.6). Voir décision D4. |
| FHIR, SNOMED, CIM-11, LOINC, DICOM | CDC_09 §9 — étape 9 de l'ordre §14. |
| Synchronisation nationale, diffusion par événements inter-services | Étape 10 de l'ordre §14. |
| MFA obligatoire sur l'écriture | Gouverné par la bascule `MFA_ENFORCE` existante (P1), **OFF en MVP**. On ne le déclare pas actif. |

---

## 4. Décisions à trancher (le cœur du G1)

### D1 — Granularité du versionnage
| Option | Description | Coût / risque |
|---|---|---|
| **(a) Version du référentiel entier + instantané JSON** *(recommandée)* | Un numéro par référentiel, incrémenté à chaque publication ; le contenu publié est figé en JSON dans la ligne de version. | Aucune modification des tables métier → **compatible ADR-024** et avec les modules G5. Rejouabilité complète. Coût : l'instantané duplique la donnée (volume maîtrisé sur des référentiels de règles ; à réévaluer pour les médicaments en P6.6). |
| (b) Versionnage ligne à ligne (SCD-2 `valide_du`/`valide_au`) | Chaque ligne de chaque référentiel porte sa validité temporelle. | Impose de modifier **chaque table de référentiel**, y compris celles des modules validés G5. Contredit frontalement ADR-024 et « corrections chirurgicales ». |

**Recommandation : (a).** C'est le même motif que l'instantané des paramètres des alertes de fraude (P5.3b-2) et le cut-off T de l'auditeur d'intégrité (P5.3b-4), tous deux éprouvés.

### D2 — Le cycle §10 complet, ou publication seule ?
| Option | Description |
|---|---|
| **(a) Cycle complet** *(recommandée)* | Un habilité **propose** un changement (brouillon), un second habilité **valide** (quatre-yeux + contrôles qualité), la publication crée la version et invalide le cache. |
| (b) Publication seule | Un habilité publie directement une version ; l'audit trace qui. Plus rapide, mais §10 dit explicitement « proposition → validation par l'autorité compétente → publication » **et** « double validation ». |

**Recommandation : (a).** Le motif humain existe déjà deux fois dans le projet (contributions au brouillon de P7-C, quatre-yeux de P5.5b-1) ; le refaire ici est cohérent, et (b) ne satisferait pas §10.

### D3 — Quels référentiels sont mis sous gouvernance dès P6.3 ?
| Option | Description |
|---|---|
| **(a) `referentiels_mesure` + `symptomes`** *(recommandée)* | Les deux référentiels qui portent de **vraies règles cliniques** — donc les seuls dont une décision passée doit pouvoir être rejouée. Preuve réelle au G2 sans toucher aux tables d'annuaire. |
| (b) Aucun (socle « à vide ») | Le registre serait vide au G2 : **on ne prouverait rien**. Un run vert sur des données absentes ne prouve pas la détection (leçon P5.3b-4). |
| (c) Tout, y compris structures/médicaments | Empiète sur P6.4/P6.6 et touche des modules validés G5. |

**Recommandation : (a).** Sans réécrire ni le triage ni les mesures : leur lecture actuelle reste **inchangée** ; le socle enregistre, versionne, audite et **diffuse** ces référentiels par la nouvelle API.

### D4 — Cache : Redis maintenant ?
| Option | Description |
|---|---|
| **(a) `Cache` de Laravel + clé portant la version** *(recommandée)* | Aucun code ne connaît le store. `CACHE_STORE=redis` deviendra un changement de **configuration**, zéro ligne de code — « prêt à activer », honnêtement. Fonctionne dès aujourd'hui en `database`. |
| (b) Brancher Redis sur l'API dès maintenant | Nouvelle infrastructure à faire tourner sous WAMP pour le G4, sans bénéfice de démonstration. |

**Recommandation : (a).** Note : le budget §12 « lecture < 50 ms » ne sera **pas** démontré en cache `database` — il sera **mesuré et rapporté tel quel**, sans être présenté comme atteint.

### D5 — Qui a le droit d'écrire ?
§10 : « rôles habilités (autorités, super administrateurs) ». Proposition : deux permissions spatie neuves, **attribuées à aucun rôle par défaut** — précédent explicite de `urgence.bris_de_glace` (P7) et `dossier.ecrire` (P7-D0) : le gestionnaire les accorde nominativement.
- `referentiel.proposer` — soumettre une proposition ;
- `referentiel.publier` — valider et publier (le validateur ≠ auteur).

Rôles cibles naturels : `ministere`, `super_admin`, `admin_ivoirsante`. **À confirmer.**

---

## 5. Limites qui seront écrites dans le guide de test (pas dissimulées)

- **L1** — La diffusion cachée sert la **nouvelle API** de référentiels. Les écrans existants (triage, mesures, annuaire) continuent de lire leur table en direct : leur bascule est un incrément additif ultérieur, module par module.
- **L2** — L'estampille de version est **fournie et testée**, mais branchée sur **aucune** décision existante : brancher `triages` reviendrait à modifier un module validé G5.
- **L3** — « Lecture < 50 ms » (§12) : mesuré en cache `database`, rapporté honnêtement, non déclaré atteint.
- **L4** — MFA sur l'écriture : gouverné par la bascule P1 existante, **OFF en MVP**.
- **L5** — Aucune synchronisation nationale, aucun événement inter-services, aucun standard d'interopérabilité (§9) : étapes 9 et 10 de l'ordre §14.
- **L6** — L'instantané JSON est adapté à des référentiels de **règles** (dizaines à centaines de lignes). Sa pertinence pour un référentiel volumineux (médicaments) sera **réexaminée** en P6.6, pas présumée.

---

## 6. Preuves attendues aux gates

- **G2 (backend prouvé avant tout écran)** — MySQL live : cycle proposition → validation → publication ; quatre-yeux refusé en base **et** en service ; version incohérente refusée à la publication ; chaîne d'audit vérifiée (altération d'une ligne → chaîne cassée détectée) ; lecture avant/après publication montrant le **basculement de clé de cache** ; immuabilité d'une version publiée ; rejeu idempotent.
- **G3** — suite PHPUnit complète verte (base actuelle : **417 tests / 14 435 assertions**), tests dédiés écrits **dans les deux sens** (ce qui doit passer *et* ce qui doit être refusé) ; `pnpm typecheck` sur les 3 workspaces.
- **G4** — guide de test dédié **`GUIDE_TEST_REFERENTIELS.md`** (règle propriétaire du 2026-08-11 : un module sans guide ne peut pas être validé), indexé dans `GUIDE_TEST_INDEX.md`.
- **G5** — écrit, avec les limites L1→L6 reportées telles quelles.

---

## 7. Dépendances

**Aucune dépendance nouvelle.** Hachage : `hash()` de PHP. Cache : façade Laravel. Permissions : spatie, déjà présent. JSON : natif MySQL 8.4.
