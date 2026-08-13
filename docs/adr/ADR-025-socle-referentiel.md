# ADR-025 — Socle référentiel : registre, versionnage, gouvernance et diffusion (P6.3)

**Statut : Accepté** — 2026-08-13 · Contexte : CDC_09 §10, §11, §12, §14.1 · Complète [ADR-024](ADR-024-referentiels-nationaux.md) · Suit [ADR-021](ADR-021-identifiant-national-sante.md).

---

## 1. Contexte

CDC_09 §14 ordonne la construction des données nationales, et place en **étape 1** le « modèle de données des référentiels + versionnage + audit ». Les étapes suivantes — NIS (fait, P6.1), établissements, professionnels, médicaments, laboratoires — s'y appuient.

L'audit G0 a établi trois faits, tous vérifiés dans le code et non supposés :

1. **Aucun versionnage n'existe.** `triages` ne stocke pas la version du protocole utilisé (alors que CDC_04 §115 la prévoit au schéma cible), `ordonnances` non plus. Corriger le `poids_severite` d'un symptôme rend donc **tout triage antérieur inexplicable**.
2. **Aucun journal d'audit générique côté Laravel.** `journaux_audit` (CDC_04 §125/§189) n'existe pas ; ce qui existe est spécialisé (`acces_dossier`, `nis_journal`, `carnet_transferts`) et le hachage chaîné n'existe qu'en Java (`audit_entries`, P5.1). **Une modification de référentiel n'est tracée nulle part.**
3. **Le cache Laravel est `database`, pas Redis** (`CACHE_STORE=database` ; les `REDIS_*` du `.env` ne servent qu'au microservice paiement). Le driver `database` **ne supporte pas `Cache::tags()`**.

Par ailleurs, quatre tables portent déjà des données de référence sans aucune gouvernance : `referentiels_mesure` (seuils cliniques), `symptomes` (règles de triage), `etapes_prenatales`, et les annuaires `structures_sanitaires` / `medecins` / `medicaments`.

---

## 2. Décision

Livrer la **gouvernance commune** à tous les référentiels — pas le contenu d'un référentiel particulier, qui reste l'objet de P6.4 → P6.7.

### 2.1 Versionnage : référentiel entier + instantané JSON *(décision G1 D1-a)*

Une version = un numéro croissant par référentiel **et** le contenu publié figé en JSON dans la ligne de version.

**Alternative écartée** : versionnage ligne à ligne (SCD-2 `valide_du`/`valide_au`). Plus fin, mais il aurait fallu ajouter deux colonnes à **chaque** table de référentiel, y compris celles de modules validés G5 — en contradiction frontale avec ADR-024 et avec « corrections chirurgicales uniquement ». L'instantané fige le contenu **sans toucher à une seule table métier**. C'est le motif déjà éprouvé du snapshot des paramètres d'alerte de fraude (P5.3b-2) et du cut-off T de l'auditeur d'intégrité (P5.3b-4).

### 2.2 Cycle de vie complet §10 *(décision G1 D2-a)*

`proposition → validation → publication → archivage`, avec **quatre-yeux** : le décideur n'est jamais l'auteur.

Le motif humain existe déjà deux fois dans le projet — « l'auteur ne peut pas se valider lui-même » (P7-C) et « approbateur ≠ calculateur » (P5.5b-1). La variante « publication seule » aurait été plus rapide mais ne satisfait pas §10, qui exige *à la fois* « proposition → validation par l'autorité compétente → publication » **et** « double validation ».

### 2.3 Anti-substitution à la publication

À la décision, le contenu est **ré-extrait** et son empreinte comparée à celle de la proposition. S'il a bougé, on refuse (409).

Sans ce contrôle, on publierait un contenu que **personne n'a relu** ; et surtout le référentiel diffusé cesserait de correspondre à la table que lisent réellement le triage et les mesures — deux vérités. C'est le contrôle « destination révoquée depuis le figeage » de P5.5b-2, transposé.

### 2.4 Diffusion : clé de cache portant le numéro de version

`referentiel:CI:seuils_mesure:v7`. Publier la version 8 fait lire `…:v8`, absente du cache : le nouveau contenu est servi immédiatement, **sans qu'aucune ligne de code ne supprime quoi que ce soit**.

Ce n'est pas un contournement de l'absence de `Cache::tags()` sur le driver `database` : c'est la lecture littérale de §10, « invalidation par événement **lors d'une nouvelle version** ». Effet de bord recherché : le mécanisme est **indépendant du store**, et passer à Redis devient un changement de configuration (`CACHE_STORE=redis`), zéro ligne de code.

**La ligne de registre n'est délibérément pas mise en cache** : c'est une lecture indexée sur une table de quelques lignes, et la cacher imposerait précisément l'invalidation explicite qu'on vient d'éviter. Un cache qu'on n'a pas à invalider est un cache qu'on ne peut pas oublier d'invalider.

### 2.5 Audit : chaîne de hachage globale

Chaque entrée porte l'empreinte de la précédente. **Globale et non par référentiel** : une chaîne par référentiel laisserait effacer l'historique entier d'un référentiel sans que rien ne le révèle.

`acteur_nom` **entre dans le calcul de l'empreinte**, pas seulement `acteur_id`. Le test de détection d'altération l'a montré : sans lui, on pouvait réécrire « Système » à la place du nom d'un agent sans rompre la chaîne — or c'est ce nom-là qu'un humain lit dans un audit.

Le journal ne recopie **jamais** le contenu du référentiel : il prouve qu'un changement a eu lieu et par qui, l'instantané porte ce qui a changé. Les dupliquer créerait deux vérités (même raisonnement qu'en P7-D0, où l'identité du soignant reste dans `acces_dossier`).

### 2.6 Liste blanche fermée des référentiels gouvernés *(décision G1 D3-a)*

`RegistreReferentiels` — **`seuils_mesure` et `symptomes_triage` seulement**, les deux qui portent de vraies règles cliniques, donc les seuls dont une décision passée doit pouvoir être rejouée.

Le code du référentiel arrive par l'URL : sans liste blanche, il serait un choix libre du client, donc une porte vers n'importe quelle table (même raison qu'en P7-C pour les sections du carnet). Ajouter un référentiel = **ajouter une classe et une ligne**, le moteur ne change pas.

### 2.7 Habilitation *(décision G1 D5)*

Deux permissions spatie, `referentiel.proposer` et `referentiel.publier`, **attachées à aucun rôle métier** — troisième occurrence du précédent `urgence.bris_de_glace` (P7) / `dossier.ecrire` (P7-D0). Deux permissions et non une : le quatre-yeux suppose que proposer et décider puissent être portés par des personnes différentes.

Vérifiées **dans le service** et non par le middleware `permission:` de spatie : ces routes sont authentifiées par Sanctum alors que les permissions vivent sur le guard `web` — le middleware refuserait sur un désaccord de guard plutôt que sur un défaut de droit (piège rencontré en P4 sur `rdv.validate`).

---

## 3. Le point dur : MySQL refuse les `CHECK` visés — erreur 3823

Les trois invariants du cycle de vie devaient être des `CHECK`, comme l'unicité du dossier titulaire en P6.1. **Le G2 live l'a interdit** :

> `Column 'decide_par' cannot be used in a check constraint 'ck_ref_version_quatre_yeux': needed in a foreign key constraint referential action.`

Un `CHECK` MySQL ne peut pas porter sur une colonne qui **subit une action référentielle**. Or les trois conditions touchent au moins une telle colonne : `propose_par` / `decide_par` sont `nullOnDelete` (l'audit doit survivre à la suppression d'un compte) et `referentiel_id` est `cascadeOnDelete`. C'est le cousin exact de **l'erreur 1215** rencontrée en P6.1 sur la colonne générée. SQLite (tests) refuse de son côté `ALTER TABLE … ADD CONSTRAINT`.

**Retenu : des triggers `BEFORE INSERT`/`BEFORE UPDATE`, dans les deux dialectes** (`SIGNAL SQLSTATE '45000'` en MySQL, `RAISE(ABORT)` en SQLite) — CDC_04 §139 prévoit exactement ce recours (« triggers : contrôle d'intégrité métier ne pouvant être garanti autrement ») et P5.5a l'avait déjà retenu pour la même raison. L'unicité, elle, reste **pleinement déclarative** (`UNIQUE(verrou_unicite)`, `UNIQUE(referentiel_id, numero)`).

**Écarté** : renoncer aux `nullOnDelete` pour sauver les `CHECK`. La suppression d'un compte serait alors bloquée par l'historique de gouvernance, ou pire, l'emporterait sur lui.

`COALESCE(condition, 0) = 0` et non `NOT(condition)` : une comparaison avec NULL vaut NULL, et un test `WHEN NULL` ne déclencherait rien — la violation passerait sans bruit.

---

## 4. Conséquences

**Acquis.** Un référentiel national a désormais un responsable, un cycle de décision à deux personnes, un historique immuable, un audit détectable en cas d'altération et une diffusion en lecture. Une décision peut citer une version, et cette version reste rejouable.

**Limites assumées, écrites aussi dans le guide de test.** **L1 et L2 sont d'une autre nature que les cinq suivantes — leur statut est détaillé au [§5](#5-statut-de-l1-et-l2--la-dette-qui-referme-le-trou-du-g0).**

- **L1** — La diffusion cachée sert la **nouvelle API**. Le triage, les mesures et l'annuaire continuent de lire leur table en direct : leur bascule est un incrément additif ultérieur, module par module (ils sont validés G5). → **§5**
- **L2** — L'estampille de version est **fournie et testée**, branchée sur **aucune** décision existante. Estamper rétroactivement les décisions passées serait pire que de ne rien faire : elles n'ont eu aucune version, et leur en inventer une serait un mensonge d'archive. → **§5, et notamment §5.2 : L2 n'est pas recevable avant L1.**
- **L3** — « Lecture < 50 ms » (§12) : le cache est `database`, pas Redis. Le budget n'est **pas déclaré atteint**.
- **L4** — MFA sur l'écriture (§10) : gouverné par la bascule `MFA_ENFORCE` de P1, **OFF en MVP**.
- **L5** — Ni synchronisation nationale, ni diffusion par événements inter-services, ni FHIR/SNOMED/CIM/LOINC/DICOM (§9) : étapes 9 et 10 de l'ordre §14.
- **L6** — L'instantané JSON convient à des référentiels de **règles** (dizaines de lignes). Sa pertinence pour un référentiel volumineux sera **réexaminée en P6.6**, pas présumée.
- **L7** — Aucun écran. La gouvernance s'exerce par API ; l'écran d'administration viendra avec le portail des référentiels (P6.4+).

**Aucune dépendance nouvelle** : `hash()` de PHP, façade `Cache`, spatie déjà présent, JSON natif MySQL 8.4.

---

## 5. Statut de L1 et L2 — la dette qui referme le trou du G0

L1 et L2 sont d'une autre nature que L3→L7. Les cinq dernières sont des **choix de périmètre** (Redis, MFA, interopérabilité, écran) : le module reste entier sans elles. **L1 et L2, non** — tant qu'elles ne sont pas faites, le défaut identifié au G0 (« corriger un seuil rend tout triage antérieur inexplicable ») est **outillé mais pas refermé**. Le versionnage existe ; rien ne s'en sert encore.

### 5.1 Elles ne sont planifiées nulle part

Elles sont **décidées et consignées**, ici et au §1 du guide de test. Elles ne sont **portées par aucun incrément** : ni P6.4, ni la suite du découpage P6.5 → P6.9. Cette absence est délibérément écrite plutôt que laissée implicite — une dette qu'on croit planifiée est une dette qu'on ne replanifie jamais.

### 5.2 L'ordre n'est pas neutre : **L1 avant L2, jamais l'inverse**

`DiffusionReferentiel::estampille()` renvoie le numéro de la version **publiée**. Si un module continue de calculer à partir de sa table métier (L1 non faite) et qu'on lui fait apposer « calculé avec v7 », **la mention est fausse dès que la table a divergé de v7** — et elle diverge en permanence entre deux publications ; c'est précisément ce que `masante:referentiel:controler` rapporte sous « DIVERGENTE de la table ».

Faire L2 seule remplacerait donc « on ne sait pas » par une **affirmation fausse**, c'est-à-dire le contraire de ce que le versionnage cherche. **L2 n'est recevable qu'une fois L1 faite sur le même référentiel**, et de préférence dans le même incrément — une fois la lecture basculée, l'estampille devient presque gratuite (le numéro est déjà en main).

### 5.3 Foyers pressentis, et l'un des deux n'en a pas

| Référentiel | Lu aujourd'hui par | Foyer naturel |
|---|---|---|
| `symptomes_triage` | `TriageService` | **P10** — la refonte « Triage → protocoles + IA » est déjà au programme (CLAUDE.md). Y faire la bascule évite de toucher un module validé G5 **deux fois**. |
| `seuils_mesure` | `MesureSanteService` | **aucun** — aucune refonte des mesures n'est prévue. À rattacher explicitement à un incrément, faute de quoi cette dette n'a personne pour la porter. |

### 5.4 Le changement de modèle d'exploitation à assumer le jour venu

Une fois la bascule faite, **corriger un seuil par un `UPDATE` n'aura plus aucun effet** tant qu'une version n'est pas publiée. C'est exactement ce qu'exige CDC_09 §1.2.4 (« référentiel = source unique de vérité ») et c'est le but recherché — mais c'est une rupture réelle avec la promesse actuelle, écrite noir sur blanc dans le commentaire de la migration `referentiels_mesure` : « *un médecin peut les corriger par un `UPDATE`, sans redéployer* ».

Ce commentaire devra être corrigé **en même temps** que la bascule, sans quoi le code affirmera durablement l'inverse de ce qu'il fait.
