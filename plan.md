# Plans de travail et d'exécution — MaSanté (IVOIRSANTÉ)

> Un bloc par réflexion, sous un grand titre numéroté. **On n'efface jamais un plan clos** : on le
> marque terminé et on garde son contenu, parce qu'un plan dit *pourquoi* on a fait ce qu'on a fait.
>
> Règle de tenue (`CLAUDE.md`) : **décision → `CLAUDE.md` → `plan.md` → exécution → `handoff.md`**.

| Plan | Sujet | État |
|---|---|---|
| **PLAN 1** | B3-c — Code-barres + traçabilité nationale des médicaments (CDC_11 §7.6) | ✅ **Terminé — VALIDÉ G5 le 2026-09-04**, G4 propriétaire OK |

---

# PLAN 1 : Code-barres et traçabilité nationale des médicaments (B3-c, CDC_11 §7.6)

**Lot** : B3 (Pharmacie), troisième sous-incrément, après B3-a (servir une ordonnance) et B3-b (le
stock réel). **Étape 7** de l'ordre CDC_11 §12.
**ADR** : ADR-055 (§10 à écrire). **Guide** : `GUIDE_TEST_APPLICATIONS_METIER.md`, **partie 13**.
**Date du G0** : 2026-09-03. **Aucun code écrit à ce stade.**

---

## 1. Ce que le corpus demande, et le problème que cela pose

Le §7.6 de CDC_11 tient **en une seule phrase** :

> **7.6 Traçabilité nationale des médicaments**
> Lutte contre les médicaments falsifiés, suivi de consommation, statistiques nationales.

**Trois finalités, aucun mécanisme.** C'est le constat qui commande tout le lot. Partout ailleurs
dans ce projet, le corpus décrivait un cycle, des états, des champs à transcrire ; ici il faut
**concevoir**. D'où une règle de méthode propre à cet incrément :

> *Chaque élément livré doit être justifié par celle des trois finalités qu'il sert.
> Ce qui n'en sert aucune n'entre pas.*

---

## 2. G0 — dix constats, vérifiés en base et dans le code

| # | Constat | Conséquence |
|---|---|---|
| **Q1** | Le §7.6 tient en une phrase (ci-dessus) | Il faut concevoir, pas transcrire |
| **Q2** | **`code_barres` n'existe nulle part** — 0 occurrence dans `app/`, `database/`, mobile, web, shared | Le manque **P7** du G0 de B3 est confirmé |
| **Q3** | **Une trace locale partielle existe déjà, et le plan G1 ne le disait pas** : `mouvements_stock.delivrance_id` est un **identifiant sans clé étrangère**, et son commentaire pose que le mouvement « ne disparaît pas si le patient supprime son ordonnance » | Mais elle est **conditionnelle** (elle n'existe que si l'officine tient l'article — décision délibérée de B3-b) **et locale** (`stock_id` → `stocks_officine`, en `cascadeOnDelete` vers `structures_sanitaires` **et** `medicaments`). *Elle disparaît avec le produit dont elle devait suivre la consommation* → **elle ne peut pas devenir le registre national par extension** |
| **Q4** | `delivrances` cascade sur `ordonnance_id` **et** sur `structure_id`, et **son propre commentaire nomme B3-c** comme porteur de la trace qui survit | Le point de conception central était déjà écrit dans le code de B3-a — **vérifié, pas rappelé de mémoire** |
| **Q5** | **`lots_medicaments` n'existe pas** : B3-b a fait de `lot` et `date_peremption` des **colonnes de `mouvements_stock`** | Le plan G1 listait cette table dans P1 ; **B3-c ne doit pas la créer** — ce serait un second endroit où dire la même chose (refus P6.6a) |
| **Q6** | **Les « statistiques nationales » n'ont aucun consommateur** : `StatistiqueController::global()` compte établissements, RDV, triages, avis, signalements, scans — **rien sur les médicaments** | Sans un écran, la troisième finalité du §7.6 serait un socle à vide (refus P6.3-D3) |
| **Q7** | **Le scan du portail dépend d'un CDN** : `html5-qrcode` depuis `cdn.jsdelivr.net` (limite ouverte de P6.4d) | *Une officine sans internet ne pourrait pas scanner* — même nature que l'argument K4 qui a fait servir Bootstrap en local |
| **Q8** | **Aucune infrastructure de scan mobile** : ni `expo-camera`, ni lecteur de code-barres dans `apps/mobile` | Un scan mobile exigerait une dépendance (§2.6) |
| **Q9** | Le point d'accroche est propre : `ServiceDelivrance::delivrer()` enveloppe tout dans un `DB::transaction` et y appelle déjà `ServiceStockOfficine::sortirPourDelivrance()` | Le registre s'y branche de la même façon |
| **Q10** | Les chaînes d'audit sont un **registre fermé de six journaux** (ADR-042) | La question « faut-il une septième chaîne ? » doit être posée — et tranchée (E7) |

---

## 3. Les décisions de conception (E1 → E9)

### E1 — Le registre national est une table à part

`delivrances` **cascade sur l'ordonnance**, parce que le patient est maître de son carnet (loi
2013-450). On ne peut pas retirer cette cascade : *son droit de supprimer ne doit pas être empêché
par un besoin statistique*. Or le registre doit survivre **exactement à ce que `delivrances` ne
survit pas**.

**Deux tables, deux natures** :

| | |
|---|---|
| `delivrances` (B3-a, **non touchée**) | l'**acte**, rattaché au dossier du patient |
| `traces_dispensation` (B3-c) | le **fait national**, détaché |

### E2 — La décision centrale : le registre ne porte aucune donnée nominative

Ni patient, ni prescripteur, ni ordonnance, ni posologie, ni instructions. **C'est ce qui rend sa
survie acceptable** : autrement on aurait construit un dossier médical qui survit à la suppression
du dossier médical — l'inverse exact de la loi.

Il porte le **quoi** (identité de produit figée), le **combien**, le **quand**, le **où** (officine).

La ligne est construite **par liste blanche explicite**, jamais `toArray()` moins quelques clés :
*une future colonne fuiterait silencieusement dans un registre qu'on croit dénominalisé* (motif
exact de l'export anonymisant, P10c-3-i).

### E3 — Dénominalisé n'est pas anonyme, et c'est dit avant de coder

`delivrance_ligne_id` est conservé comme **identifiant sans clé étrangère** — il sert la clé
d'idempotence et la réconciliation. Conséquence énoncée : *tant que la délivrance existe, qui tient
la base peut remonter au patient* ; une fois l'ordonnance supprimée, la trace devient réellement
orpheline. Même formulation qu'en P10c-2-i.

### E4 — `code_barres` sur `medicaments`, jamais sur l'article d'officine

(Décision **D4** du plan G1 du lot, confirmée.) Un EAN/GTIN identifie un **produit du fabricant** :
deux officines qui vendent la même boîte scannent le même code. Le mettre sur l'article d'officine
le ferait ressaisir par chacune, et diverger.

Il **entre dans la projection gouvernée** (`SourceMedicaments::extraire()`) — *l'empreinte du
référentiel change, ce n'est pas une dérive* (précédent `forme_juridique`, P6.4d). Il reste
**vide**, et l'absence est **comptée et affichée** : 5<sup>e</sup> application du motif
`loinc` / CIM / `numero_agrement`.

### E5 — Ce que le code-barres prouve, et ce qu'il ne prouve pas

**Un falsificateur recopie un code-barres.** Le scan permet de dire « **ce code n'est pas au
référentiel** » — **jamais** « cette boîte est authentique ». Ce qui prouverait l'authenticité est
la **sérialisation unitaire**, explicitement écartée par le plan G1 (elle suppose un dispositif
national).

> *Un écran qui afficherait « authentique » sur la foi d'un EAN mentirait à un pharmacien sur le
> seul point où cela compte.*

→ L'écran dit **« connu du référentiel »** ou **« inconnu »**, et un code inconnu **signale sans
bloquer** — même raisonnement que B3-b : refuser priverait le patient de son traitement.

### E6 — Le champ de saisie **est** le scanner (et cela règle Q7 sans le corriger)

Un lecteur de code-barres USB de comptoir **se comporte comme un clavier** : il « tape » le code
puis un retour chariot. **Un simple champ texte le reçoit, sans aucune dépendance et sans
internet.** La caméra reste un confort, jamais le mécanisme.

*Faire dépendre une fonction de pharmacie d'un CDN reproduirait le défaut que K4 de P6.4d a corrigé
pour Bootstrap.*

### E7 — Append-only à deux niveaux, mais **pas** une chaîne de hachage

L'append-only (modèle + déclencheurs, motif `mouvements_stock`) empêche une officine de réécrire son
historique. Une **septième chaîne** ADR-042 protégerait contre **un autre risque** (celui qui tient
la base), et ADR-042 vient de montrer ce que coûte une chaîne : déclaration d'origine, ancrage de
tête, procédure de scellement, et le piège des identifiants pris dans l'empreinte.

> *On ne durcit pas par symétrie décorative* — précédent P6.4a, qui a refusé le journal de
> non-réutilisation pour les établissements.

### E8 — Une dispensation non rattachée au référentiel **entre quand même**, et se compte

**Décision trouvée en écrivant ce plan, pas au G0.** Le lien ordonnance → référentiel est
**facultatif** (B3-a) : une ligne servie peut n'avoir aucun `medicament_id`. Trois issues :

| | |
|---|---|
| Ne rien écrire | **la consommation nationale serait fausse en silence** — la panne muette que ce projet refuse partout |
| Écrire sans le dire | le registre mêlerait du vérifié et du non vérifié sans distinction |
| **Écrire, et compter** | ✅ retenu |

`medicament_code IS NULL` **est** le marqueur — aucune colonne supplémentaire : l'écran de
statistiques compte et affiche « N dispensations non rattachées au référentiel ». Fait **dérivé**,
jamais stocké (le stock est une somme, P5.3a). 6<sup>e</sup> application du motif d'honnêteté.

### E9 — Le scan lit la **table**, et l'asymétrie est nommée

`code_barres` entre dans la projection **gouvernée** (E4) mais la recherche par code-barres lit la
**table**. Trois raisons, dans cet ordre :

1. Une colonne neuve **n'est dans aucune version déjà publiée** : lire la version publiée rendrait
   la fonctionnalité **morte à la livraison**, jusqu'à une republication.
2. Tout le domaine pharmacie lit déjà la table (`stocks_officine.medicament_id`,
   `ordonnance_lignes.medicament_id`) — faire lire la version publiée à un seul écran créerait une
   **incohérence entre deux écrans voisins**.
3. Un refus bruyant (503) devant un comptoir **bloquerait une dispensation**, ce que E5 refuse déjà
   pour un code inconnu.

**L'asymétrie est donc assumée et nommée**, comme celle de `poids_severite` en P10b-3-ii : la donnée
est gouvernée, sa lecture ne l'est pas. **Porteur** : l'élévation de la gouvernance du socle P6.3,
déjà nommée par P10b-3-ii — *une dette sans porteur ne se fait jamais* (leçon L1+L2).

---

## 4. Schéma exact

### 4.1 `medicaments` — une colonne

```php
$table->string('code_barres', 14)->nullable()->after('cename_reference');
$table->unique(['pays_code', 'code_barres'], 'uq_medicament_code_barres');
```

- `UNIQUE(pays_code, code_barres)` — cohérent avec `UNIQUE(pays_code, code)` de P6.6a et
  `UNIQUE(pays_code, identifiant)` de P6.4a. MySQL autorise plusieurs `NULL` : les fiches sans
  code-barres ne se heurtent pas.
- **`code_barres` EST dans `$fillable`** — contrairement à `code`, qui est attribué par la machine
  (`AttributeurCodeMedicament`) : un code-barres est **saisi par un agent habilité**, sa garde est
  la permission `medicament.referentiel` + la validation `ReglesCodeBarres`.

### 4.2 `traces_dispensation` — le registre national

```php
Schema::create('traces_dispensation', function (Blueprint $table) {
    $table->id();
    $table->string('pays_code', 2)->default('CI');

    // ── LE QUOI — identité de produit, FIGÉE pour survivre au retrait de la fiche ──
    $table->foreignId('medicament_id')->nullable()
          ->constrained('medicaments')->nullOnDelete();
    $table->string('medicament_code', 12)->nullable();      // NULL = non rattaché (E8)
    $table->string('medicament_nom', 200);
    $table->string('medicament_dci', 200)->nullable();
    $table->string('medicament_dosage', 100)->nullable();

    // ── LE COMBIEN ──
    $table->unsignedInteger('quantite');

    // ── LE OÙ — identifiant sans clé étrangère + identifiant national figé ──
    $table->unsignedBigInteger('structure_id')->nullable();
    $table->string('structure_identifiant_national', 12)->nullable();

    // ── LE QUAND ──
    $table->timestamp('dispensee_le');

    // ── Réconciliation et idempotence (E3) — identifiant, jamais clé étrangère ──
    $table->unsignedBigInteger('delivrance_ligne_id')->nullable();

    $table->timestamp('created_at')->useCurrent();

    $table->unique('delivrance_ligne_id', 'uq_trace_delivrance_ligne');
    $table->index(['pays_code', 'medicament_code'], 'idx_trace_produit');
    $table->index('dispensee_le', 'idx_trace_date');
    $table->index('structure_id', 'idx_trace_officine');
});
```

**Ce qui n'y est PAS, et pourquoi** :

| Écarté | Raison |
|---|---|
| `membre_id`, `ordonnance_id`, `prescripteur` | **E2** — c'est ce qui rend la survie acceptable |
| `posologie`, `duree`, `instructions` | E2 — ce qui décrit un traitement, pas un produit |
| `lot`, `date_peremption` | **Le stock de B3-b n'est pas suivi lot par lot** : attribuer un lot à une sortie serait **inventer une attribution** |
| `region_id` / `district_id` figés | La géographie se joint par `structure_id` tant que l'officine existe. La figer serait une **seconde copie** de ce que P6.4a gouverne |
| Colonne `statut` / `total` | Une valeur recalculable finit par diverger (P5.3a) |
| `delivrance_id` | Redondant : il se joint par `delivrance_lignes` tant qu'elle existe, et ne dit plus rien après. *Une clé de moins vers le nominatif est une clé de moins* |

### 4.3 Les gardes du moteur (deux dialectes)

| Garde | Nature |
|---|---|
| `UPDATE` sur `traces_dispensation` → refusé | Déclencheur (**MySQL** `SIGNAL SQLSTATE '45000'` / **SQLite** `RAISE(ABORT)`) |
| `DELETE` sur `traces_dispensation` → refusé | idem |
| `quantite = 0` → refusé | idem |
| `UNIQUE(delivrance_ligne_id)` | Déclarative |
| `UNIQUE(pays_code, code_barres)` | Déclarative |

⚠️ **Apostrophes doublées (`''`) dans tout message de déclencheur** — piège trouvé au G2 de B3-b :
`ne s'efface pas` referme la chaîne SQL et rend la migration **impossible à rejouer**.
**La migration doit être rejouée**, pas seulement passée une fois.

---

## 5. Classes et fonctions — noms retenus et leur raison

| Nom | Emplacement | Raison du nom |
|---|---|---|
| **`ReglesCodeBarres`** | `App\Services\Medicament` | **Classe pure** (motif `ReglesReversement`, `ReglesOrientation`, `ReglesCalendrierVaccinal`) : elle calcule, elle ne touche ni la base ni la session. `estGtin()`, `normaliser()` |
| **`ServiceCodeBarres`** | `App\Services\Medicament` | Résout une saisie → produit. Séparé de `ReglesCodeBarres` : *les règles jugent, le service rassemble l'état* (leçon P6.5b) |
| **`ServiceTracabiliteMedicament`** | `App\Services\Medicament` | Écrit le registre et en dérive la consommation. **Pas `Registre…`** : dans ce dépôt, `Registre*` désigne une **liste blanche fermée** (`RegistreSectionsCarnet`, `RegistreActionsProtocole`) — le mot est pris |
| **`TraceDispensation`** | `App\Models` | Le modèle. Append-only par crochets `updating`/`deleting` |

**Méthodes retenues** :

```
ReglesCodeBarres::normaliser(string $saisie): string      // espaces, tirets, insécables
ReglesCodeBarres::estGtin(string $code): bool             // 8/12/13/14 chiffres + clé mod 10
ReglesCodeBarres::cleDeControle(string $chiffres): int

ServiceCodeBarres::identifier(?string $saisie): ?Medicament   // null = inconnu, jamais une exception
ServiceCodeBarres::assertSaisieValide(string $saisie): string // refus NOMMÉ à l'entrée au référentiel

ServiceTracabiliteMedicament::inscrire(Delivrance $d): int         // dans la tx de delivrer()
ServiceTracabiliteMedicament::consommation(?string $du, ?string $au): array
ServiceTracabiliteMedicament::couvertureCodeBarres(): array        // N sur M, compteur d'honnêteté
```

**Contrainte de frontière** : `inscrire()` est appelé **dans la transaction** de
`ServiceDelivrance::delivrer()`, au même endroit que `sortirPourDelivrance()` (Q9). Il **ne décide
rien** : il enregistre ce que la délivrance a établi.

---

## 6. Ce qui change dans l'existant (et ce qui n'y touche pas)

| Fichier | Changement | Nature |
|---|---|---|
| `ServiceDelivrance::delivrer()` | **une ligne** : appel à `inscrire()` dans la tx | additif |
| `SourceMedicaments::extraire()` | `'code_barres' => $m->code_barres` | **fait diverger l'empreinte** (E4, assumé) |
| `Medicament` | `code_barres` dans `$fillable` | additif |
| `ReferentielMedicamentController` | champ + validation `ReglesCodeBarres` | additif |
| `StatistiqueController::global()` | bloc médicaments (Q6) | additif |
| `StockOfficineController` | champ de scan pour retrouver un produit (E6) | additif |
| `delivrance/*.blade.php` | champ de scan au comptoir (E6) | additif |
| **`delivrances`, `delivrance_lignes`, `ordonnance_lignes`** | **rien** | B3-a est G5 |
| **`stocks_officine`, `mouvements_stock`** | **rien** | B3-b est G5 |

---

## 7. Ce qu'il faudra prouver

### G3 — tests (≈ 24 vecteurs dédiés)

**`ReglesCodeBarres` (purs)** — GTIN-8/12/13/14 valides · clé de contrôle fausse → refus · non
numérique → refus · longueur non normalisée → refus · espaces et tirets normalisés.

**Gouvernance du code-barres** — non-GTIN refusé **par son message** · doublon dans le même pays →
`1062` · **le même GTIN accepté dans un autre pays** · sans la permission → 403.

**Le registre** —
- servir 2 lignes → **2 traces**
- **servir une ligne non rattachée au référentiel → 1 trace à `medicament_code` NULL** (E8)
- **VECTEUR CENTRAL** : supprimer l'ordonnance → `delivrances` cascade → **la trace demeure**
  (miroir du vecteur d'absence de B3-a)
- **VECTEUR CENTRAL** : la trace **ne porte aucune donnée nominative** — on cherche le nom du
  patient, son `membre_id` et la posologie dans **toute la ligne** (motif du vecteur anti-fuite de
  P7-D1)
- retirer le produit du référentiel → `medicament_id` NULL, **code et libellé figés intacts**
- append-only : `UPDATE` et `DELETE` refusés **par le moteur**, dans les deux dialectes
- idempotence : `UNIQUE(delivrance_ligne_id)`

**Le scan** — code connu → produit · code inconnu → `null`, **et la délivrance passe quand même**
(E5) · code invalide → refus nommé.

**Miroirs de projection** — renseigner un code-barres → **l'empreinte du référentiel change** ·
une dispensation → **empreinte inchangée**.

**Statistiques** — consommation par produit · compteur des non-rattachées · couverture code-barres.

### Campagne de mutation

≈ **7 tueuses + 1 témoin volontairement VERT** (*un harnais qui ne prévoit que des tueuses ne se
teste jamais lui-même*). Chaque mutation : **assertée appliquée**, ancre **sur une seule ligne** et
**jamais préfixe du remplacement**, arbre **restauré et vérifié par `diff`**. Vert vérifié **avant**
de muter.

### G2 — live sur MySQL réelle

Base **sauvegardée puis restaurée compte pour compte**. `artisan serve` réel, comptes de portail
réels.

1. Schéma : colonne, 2 unicités, **3 déclencheurs**.
2. `ERROR 1644` sur `UPDATE`, sur `DELETE`, sur quantité nulle. `ERROR 1062` sur doublon.
3. Saisie d'un code-barres au référentiel : GTIN faux → **refusé en nommant la raison** ; GTIN juste
   → accepté ; **empreinte du référentiel avant/après**.
4. Scan au comptoir : code connu → produit nommé ; **code inconnu → « inconnu du référentiel », et
   la délivrance se fait quand même**.
5. Délivrance réelle de 2 lignes → 2 traces vérifiées **en base, colonne par colonne**.
6. **Suppression de l'ordonnance** → délivrance disparue, **traces intactes**.
7. Écran de statistiques : consommation, compteur des non-rattachées, couverture.
8. Restauration, vérifiée par comptage.

---

## 8. Limites qui seront annoncées

- **La lutte contre les falsifiés n'est qu'à moitié servie** : on détecte l'inconnu, on ne prouve
  pas l'authentique (E5). La sérialisation unitaire suppose un dispositif national.
- **Aucun code-barres réel** : la colonne naît vide, et c'est compté et affiché. Les charger est
  **de la donnée, zéro code** — et **tant que ce n'est pas fait, le scan ne reconnaît rien**.
- **Aucun scan mobile** (Q8) : dépendance §2.6.
- **La caméra du portail reste sur un CDN** — B3-c ne la corrige pas, il **ne s'y appuie pas**
  (E6). La limite de P6.4d reste ouverte, avec son porteur.
- **Pas de lot sur la trace** : le stock de B3-b n'est pas suivi lot par lot → un **rappel de lot**
  national n'est pas réalisable, et le dire vaut mieux que le suggérer.
- **Dénominalisé, pas anonyme** (E3).
- **Asymétrie gouvernance / lecture** (E9), avec son porteur.
- **`disponibilité des médicaments` du §8.5** (portail Ministère) reste **hors périmètre** : c'est
  une autre section, et B3-c sert le §7.6.

---

## 9. Ordre d'exécution

1. Migration (`2026_09_03_000005_tracabilite_medicaments.php`) — colonne, table, 3 déclencheurs.
2. `ReglesCodeBarres` (pure) + ses vecteurs — **avant tout le reste**, c'est le socle calculatoire.
3. `TraceDispensation` (append-only) + `ServiceTracabiliteMedicament::inscrire()`.
4. Branchement d'une ligne dans `ServiceDelivrance::delivrer()`.
5. `ServiceCodeBarres` + saisie gouvernée au référentiel + `SourceMedicaments`.
6. Champs de scan (délivrance, stock).
7. Bloc statistiques.
8. Vecteurs restants, campagne de mutation, Pint (**baseline établie AVANT**), typecheck.
9. G2 live, guide **partie 13**, ADR-055 **§10**.
10. `handoff.md` + `CLAUDE.md` mis à jour → soumission au G4 du propriétaire.

**Le G5 n'est jamais auto-déclaré** : il attend le « validé » écrit du propriétaire.

---

## 10. Exécution — ce qui a réellement changé par rapport à ce plan

Les dix étapes ont été suivies dans l'ordre. **Un point du schéma a dû être corrigé pendant
l'exécution**, trouvé par un vecteur de G3 et non par la relecture : §4.2 prévoyait `medicament_id`
en clé étrangère `nullOnDelete`. Un test a montré que la nullification déclenchée par le moteur à la
suppression du médicament parent est elle-même un `UPDATE`, que le déclencheur append-only bloquant
tout **refuse** — empêchant purement et simplement de retirer un produit du référentiel.
`medicament_id` est donc devenu un **identifiant sans clé étrangère**, comme `structure_id` et
`delivrance_ligne_id` juste à côté de lui dans le même schéma (ADR-042 D1) — cohérent avec ce que le
§4.2 disait déjà de ces deux colonnes, appliqué à la troisième qui avait été oubliée.

Le reste du plan (E1→E9, le schéma, les noms de classes, les limites annoncées en §8) a été suivi
sans écart. **VALIDÉ G5 le 2026-09-04** — G4 propriétaire OK. Détail complet dans `CLAUDE.md`
(entrée B3-c) et ADR-055 §10. Guide `GUIDE_TEST_APPLICATIONS_METIER.md` partie 13.
