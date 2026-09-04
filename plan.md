# Plans de travail et d'exécution — MaSanté (IVOIRSANTÉ)

> Un bloc par réflexion, sous un grand titre numéroté. **On n'efface jamais un plan clos** : on le
> marque terminé et on garde son contenu, parce qu'un plan dit *pourquoi* on a fait ce qu'on a fait.
>
> Règle de tenue (`CLAUDE.md`) : **décision → `CLAUDE.md` → `plan.md` → exécution → `handoff.md`**.

| Plan | Sujet | État |
|---|---|---|
| **PLAN 1** | B3-c — Code-barres + traçabilité nationale des médicaments (CDC_11 §7.6) | ✅ **Terminé — VALIDÉ G5 le 2026-09-04**, G4 propriétaire OK |
| **PLAN 2** | B3-d — Panier et commande de médicaments (CDC_11 §9.5, §10.5 · CDC_01 §6.6) | ⏸️ **G1 rédigé, EN ATTENTE** — dépend désormais du PLAN 3 pour son règlement en ligne. Aucun code écrit. |
| **PLAN 3** | B4 — Paiement en ligne réel (GeniusPay) : canal Laravel→Java, commission, et le rendez-vous | 🔵 **B4-a (le canal) : ✅ VALIDÉ G5 le 2026-09-04** — G4 propriétaire OK, G5 « c'est bon pour le G5 ». ADR-056, guide partie 15. B4-b (rendez-vous) non commencé. |

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

---

# PLAN 2 : Panier et commande de médicaments (B3-d, CDC_11 §9.5 / §10.5, CDC_01 §6.6)

**Lot** : B3 (Pharmacie), **quatrième et dernier** sous-incrément, après B3-a (servir une
ordonnance), B3-b (le stock réel) et B3-c (code-barres et traçabilité).
**ADR** : ADR-055 (§11 à écrire). **Guide** : `GUIDE_TEST_APPLICATIONS_METIER.md`, **partie 14**.
**Date du G0** : 2026-09-04. **Aucun code écrit à ce stade.**

---

## 1. Ce que le corpus demande — quatre passages qui décrivent le même parcours

CDC_11 **§9.5 Achat d'un médicament** :

```
Le patient recherche
→ Choisit la pharmacie où le médicament est disponible, avec itinéraire calculé
→ Ajoute au panier
→ Choisit Retrait ou Livraison
→ Paiement → Commande
```

> **Si ordonnance obligatoire** : le patient importe son ordonnance → le pharmacien valide → la
> vente est autorisée.

CDC_01 **§6.6** dit la même chose et ajoute le **suivi de commande**. CDC_01 **§7.2** liste l'écran
patient « Pharmacie (recherche, panier, commande, suivi livraison) », **§7.6** les écrans pharmacien
« Commandes clients, Livraisons ». CDC_04 **§107** nomme les tables `commandes`, `commande_lignes`,
`livraisons`, `alertes_rupture`.

**Deux passages contraignent le périmètre plus qu'ils ne le décrivent**, et ce sont eux qui
commandent les décisions :

CDC_11 **§9.6** :
> **Paiement direct** : le paiement est traité directement par le prestataire de paiement de
> l'hôpital ou de la pharmacie. **La plateforme ne manipule jamais les fonds.**

CDC_11 **§10.5** :
> **Médicament nécessitant une ordonnance** : l'application **ne doit pas** permettre l'achat sur la
> base du triage. Le patient doit d'abord consulter un professionnel de santé.

---

## 2. G0 — neuf constats, vérifiés en base réelle et dans le code

| # | Constat | Comment vérifié |
|---|---|---|
| **Q1** | **Aucune table du domaine commande n'existe** : `commandes`, `commande_lignes`, `paniers`, `renouvellements`, `livraisons`, `alertes_rupture` — toutes absentes. | `Schema::hasTable()` sur la base `ivoirsante` réelle |
| **Q2** | **`medicaments.ordonnance_requise` existe et est une colonne DORMANTE au sens fort** : écrite au portail, entrée dans la projection gouvernée (`SourceMedicaments`), affichée par le mobile comme une note — et **lue par aucune garde métier**. | `SHOW COLUMNS` + recherche exhaustive des 5 sites d'usage |
| **Q3** | **L'itinéraire existe déjà, entièrement côté mobile** (`src/api/itineraire.ts` : `calculerItineraire` + `dureesVers`, OSRM public), et `PrixMedicamentService::comparer()` renvoie déjà `latitude`/`longitude` de chaque officine. | Lecture des deux fichiers |
| **Q4** | **Le stock réel existe depuis B3-b** et **alimente `prix_pharmacie.disponible`** — « les pharmacies l'ayant en stock » a déjà sa source, sans rien ajouter. | `ServiceStockOfficine`, `PrixMedicamentService` |
| **Q5** | **Le seul paiement du projet est SIMULÉ** (`RecuRdvService` : `transaction_ref = 'SIM-…'`, `statut = 'paye'` d'emblée, commentaire « ⚠️ PAIEMENT SIMULÉ »). | Lecture de `RecuRdvService` |
| **Q6** | **Le plan G1 du lot B3 avait écarté le paiement ET la livraison** (« une commande payée est un autre sujet qu'une commande passée » ; « §7.4 mentionne livraison, rayon comme **données de la fiche**, pas comme un service à construire ») — alors que §9.5 et §6.6 les nomment tous deux dans le parcours. **Contradiction à trancher ici.** | `docs/PLAN_G1_B3_Pharmacie.md` §3 |
| **Q7** | **`renouvellements` n'appartient PAS à ce domaine** : CDC_04 §105 le range avec `ordonnances`/`delivrances`/`signatures_electroniques` (prescriptions), §107 range `commandes`/`commande_lignes`/`livraisons` en pharmacie. **CDC_11 ne le mentionne nulle part**, et CDC_01 §17 module 7 dit « Pharmacie (recherche, panier, ordonnance, commande) » — **sans renouvellement**. | CDC_04 §105/§107, CDC_11 entier, CDC_01 §17 |
| **Q8** | **B3-a a créé le jeton d'ordonnance** : le pharmacien lit une ordonnance **sans ouvrir le dossier**. Le « le patient importe son ordonnance » du §9.5 a donc un chemin meilleur que l'upload. | `ServiceDelivrance::ordonnancePourJeton()` |
| **Q9** | **Le rôle `pharmacien` porte `medicament.manage`, `qr.scan`, `ordonnance.delivrer`** — aucune permission de commande. `mouvements_stock.type` est déjà `enum('entree','sortie','peremption','ajustement')` avec un `motif` libre : **une sortie pour commande ne demande aucune migration**. | `PortailRolesSeeder`, migration `stock_officine` |
| **Q10** | **Le domaine COMMISSION existe entièrement, et la règle que le propriétaire rappelle y est DÉJÀ CODÉE** : `CommissionService`, `BaremeCommission` (paliers par volume mensuel), `PlanTarifaire` (`commission_incluse`), `CommissionTransaction`, `FacturePartenaire`, `GenererFacturesPartenaireCommand`, un écran de facturation. Et littéralement : `$estPharmacieHorsLigne = $structure->type === 'pharmacie' && $regleEnLigne === false;` → **exonérée**. Le service **refuse même de deviner** `regleEnLigne` (il journalise un `warning` s'il n'est pas fourni). | Lecture de `CommissionService` |
| **Q11** | **`calculerEtEnregistrer()` n'a AUCUN appelant en production** — seuls un TODO et des tests le nomment ; `commissions_transaction` compte **0 ligne** en base réelle, `baremes_commission` aussi. La raison est **précise et documentée** dans `PaiementNotificationController` : *le payload du microservice Java ne porte aucun identifiant de structure MaSanté*, or `structure_sanitaire_id` est `NOT NULL` — « appeler quand même écrirait une commission rattachée à la mauvaise structure, ou planterait ». **Vérifié côté Java** : ni `NotificateurLaravelHttp` ni le modèle `Paiement` ne portent cet identifiant. | Recherche exhaustive Laravel + Java, comptages en base réelle |
| **Q12** | **Aucun paiement patient ne passe par le microservice Java aujourd'hui** : le rendez-vous est réglé par `RecuRdvService`, **100 % Laravel et simulé** (`SIM-…`). `factures_patient` est d'ailleurs liée au **rendez-vous** (`rendez_vous_id`), pas générique ; `commissions_transaction.facture_patient_id` est **nullable**. | `RecuRdvService`, `SHOW COLUMNS` |

**Q2 est le constat central**, et il a la forme exacte de ceux qui ouvrent les incréments de ce
projet : une donnée gouvernée, correctement saisie, que **rien ne lit au moment où elle compterait**.
Le §10.5 en fait pourtant une règle de sécurité, pas un affichage.

**Q7 corrige le titre du sous-lot.** `handoff.md` et le plan de lot annoncent « panier, commande,
**renouvellement** ». Le corpus applicatif ne demande le renouvellement nulle part ; il n'est nommé
que par le CDC_04, dans le domaine **prescription**. Le livrer ici serait ouvrir un sujet médical
(qui renouvelle ? sur quelle durée ? avec quelle validation ?) au milieu d'un sujet commercial.
**Il sort du périmètre, et le plan le dit plutôt que de le laisser dans un titre.**

---

## 3. Les décisions de conception (F1 → F12)

### F1 — Le panier vit sur le téléphone, jamais au serveur

**CDC_04 §107 ne nomme aucune table `paniers`** — il nomme `commandes` et `commande_lignes`. Ce
n'est pas un oubli : un panier est un état **éphémère, personnel, qui n'engage rien**.

Et surtout : *le contenu d'un panier de médicaments dit ce dont on se soigne*. Le stocker au serveur
ajouterait une donnée de santé que personne ne lit, qu'il faudrait purger, et qui n'existe que
pour survivre à un changement d'appareil — un confort payé en donnée sensible conservée.

→ **Le panier est un état local du mobile** (store Zustand, comme le reste de l'état local).
Le serveur ne reçoit que la **commande** : l'acte, jamais l'intention.

### F2 — Une commande s'adresse à UNE officine

Le §9.5 dit « choisit **la** pharmacie où le médicament est disponible ». Un panier réparti sur
plusieurs officines produirait une commande que personne ne peut honorer. Le patient qui veut deux
produits dans deux officines passe **deux commandes** — c'est la réalité du comptoir.

### F3 — LA DÉCISION CENTRALE : `ordonnance_requise` cesse d'être décorative

C'est Q2 refermé. Une ligne de commande portant un produit à `ordonnance_requise = true` **exige une
ordonnance désignée** ; sinon la commande est **refusée en nommant le produit** (jamais un refus
opaque — patron de tout le lot).

**La garde vit au serveur.** Le mobile peut griser un bouton, mais le refus qui compte est celui du
serveur : une règle qui ne vivrait que dans le front serait exactement ce que CDC_01 §0.1 interdit,
et il suffirait d'un client modifié pour commander un antibiotique sans ordonnance.

**`ordonnance_requise` est FIGÉ sur la ligne de commande** au moment où elle est passée (patron des
valeurs figées de B3-a, P6.6b, P7-D2) : *une commande refusée parce qu'un produit exigeait une
ordonnance doit rester explicable même si ce produit passe en vente libre le mois suivant.*

### F4 — L'ordonnance est DÉSIGNÉE dans le carnet, jamais téléversée

Le §9.5 dit « le patient **importe** son ordonnance ». Le prendre au pied de la lettre — téléverser
une photo — serait un recul par rapport à ce que ce projet a déjà construit :

- ses ordonnances sont **déjà dans son carnet**, et depuis **B2-c** elles **désignent leur
  prescripteur** ; depuis **P6.5b** certaines sont **signées** — une photo ne l'est pas ;
- depuis **B3-a** chacune porte un **jeton** qui la fait lire au pharmacien **sans ouvrir le
  dossier** ;
- une photo obligerait le pharmacien à juger de l'authenticité d'une image, c'est-à-dire à faire à
  la main ce que le §7.2 lui promet de vérifier.

→ Le patient **désigne une de ses ordonnances**, la commande porte `ordonnance_id`, et le
pharmacien la lit **par le chemin de B3-a, inchangé**.

> **Conséquence honnête, à dire avant de coder** : un patient dont l'ordonnance est en papier et
> hors carnet **ne peut pas commander** un produit sous ordonnance. C'est **plus restrictif** que le
> §9.5 littéral, et c'est le sens sûr — *un pharmacien qui juge une photo est un pharmacien qui juge
> seul*. Le chemin papier reste celui du comptoir, qui n'a jamais cessé d'exister.

### F5 — Le pharmacien valide avant que la vente soit autorisée (§9.5, littéral)

Donc la commande a un **cycle**, et son état initial n'est pas « acceptée ».

| État | Qui le pose | Sens |
|---|---|---|
| `en_attente` | le patient (à la création) | l'officine ne l'a pas encore vue |
| `acceptee` | le pharmacien | l'officine s'engage à préparer |
| `refusee` | le pharmacien | **motif obligatoire** |
| `prete` | le pharmacien | disponible au retrait, ou prête à partir |
| `remise` | le pharmacien | remise au patient — état **terminal** |
| `annulee` | le patient | tant que rien n'est remis |

**Un seul état terminal de succès, `remise`** : le mode (retrait ou livraison) est déjà porté par la
commande, donc `retiree`/`livree` seraient **deux valeurs pour un fait déjà connu** — *une valeur
dérivable n'est jamais stockée* (principe tenu depuis P5.3a).

**Les états sont fournis par le backend, jamais déduits par le front** (règle de frontière).

**L'enum va dans `@masante/shared` dès le premier jour, avec sa garde anti-divergence** : il est lu
par le mobile **et** par le portail. Le précédent B1-a est net — `RendezVousStatut` y était une clé
morte pendant que trois copies divergeaient ; on ne recommence pas. Patron :
`RendezVousStatutSourceUniqueTest` et `PermissionsSourceUniqueTest`.

### F6 — Deux modes de règlement, et la commission ne s'applique qu'en ligne

> **Arbitrage du propriétaire, 2026-09-04** : « une fois la commande passée par le patient, il peut
> la payer **en ligne** [ou] une fois **à la pharmacie**, et **pour chaque paiement en ligne on
> applique les commissions** — et c'est ce que j'avais dit. »

**Il avait dit vrai, et la règle est déjà dans le code** (Q10). `CommissionService` porte
littéralement :

```php
$estPharmacieHorsLigne = $structure->type === 'pharmacie' && $regleEnLigne === false;
$exoneree = $estPharmacieHorsLigne || $planCommissionIncluse;
```

**Une pharmacie réglée hors ligne est exonérée ; réglée en ligne, elle ne l'est pas.** B3-d n'a donc
**rien à inventer sur la commission** : il a à **appeler ce qui existe**, correctement.

→ La commande porte un **mode de règlement** : `sur_place` ou `en_ligne`.

| Mode | Ce qui se passe | Commission |
|---|---|---|
| `sur_place` | La plateforme ne touche à rien — **le §9.6 littéral** | **Aucune** (exonération déjà codée) |
| `en_ligne` | Laravel enregistre le règlement **et appelle `CommissionService::calculerEtEnregistrer()`** avec `regleEnLigne = true` | **Calculée**, palier et taux **figés sur la ligne** |

**Ce qui rend ce chemin possible ici alors qu'il est bloqué ailleurs.** Le blocage documenté dans
`PaiementNotificationController` (Q11) est que **le payload Java ne porte aucun identifiant de
structure MaSanté**. Laravel, lui, **connaît l'officine** : la commande la porte (F2). Le blocage ne
concerne donc **que la notification entrante**, jamais ce chemin-ci. **B3-d donne à
`CommissionService` son PREMIER APPELANT** — il en avait zéro, et `commissions_transaction` est vide
en base réelle.

**Ce que le règlement en ligne EST, et ce qu'il n'est PAS, dit avant de coder.** L'encaissement
lui-même reste **SIMULÉ**, exactement comme celui du rendez-vous (Q5, Q12) : `frais_passerelle` et
`frais_prestataire` valent 0, et ce n'est pas une valeur inventée — c'est le coût réel d'une
passerelle qui n'existe pas encore (formulation du contrôleur Java, reprise telle quelle).
**La commission, elle, serait RÉELLE**, et `GenererFacturesPartenaireCommand` facturerait un
partenaire sur la base d'un encaissement fictif.

→ **Le règlement en ligne est donc GATÉ OFF par défaut** (`commande.reglement_en_ligne`), régime
« prêt à activer » du projet (cashback P5.3b-3, push P7-D1, MFA P1) : le mécanisme est **livré et
prouvé au G2 drapeau ON**, et **rien n'est facturé à personne** tant que le propriétaire ne
l'allume pas. Drapeau OFF, seul `sur_place` est proposé — et la pharmacie est exonérée, ce qui est
le comportement d'aujourd'hui.

**Quand le règlement en ligne devient possible** : une fois la commande **acceptée** par l'officine,
**jamais avant** — *on ne paie pas avant de savoir que la pharmacie peut servir*, et **aucun
remboursement n'existe dans ce projet**.

**Le montant reste indicatif et figé** : le patient retrouve ce qui lui avait été annoncé. Ce n'est
pas un engagement de prix de la pharmacie, et l'écran le dit.

**Ce que B3-d ne fait PAS** : brancher les paiements patients sur **GeniusPay réel**. Cela exigerait
d'ajouter `structureSanitaireId` au contrat du microservice Java, d'y router l'initiation depuis
Laravel et d'en gérer le retour — un **lot transversal** qui vaut autant pour le **rendez-vous** que
pour la pharmacie, et qui n'a rien de spécifique au médicament. C'est le lot que B1-d avait déféré
« faute de cible réelle » ; **il a désormais une cible, mais ce n'est pas celui-ci**.

> **Prérequis de déploiement** : `BaremesCommissionSeeder` — `baremes_commission` compte **0 ligne**
> en base réelle, et `baremeActif()` **lève** si aucun palier ne couvre le volume. Un règlement en
> ligne sur une base non seedée échouerait bruyamment (ce qui est le bon comportement, mais il faut
> le savoir). Même famille que le backfill du pivot de P11.2 et `MaladieSeeder` de B2-b.

> **`facture_patient_id` reste NULL sur la commission** (la colonne est nullable) : une commande de
> médicaments n'est pas un acte au sens de `factures_patient`, qui porte `rendez_vous_id`,
> `moment_paiement`, `tarif_source` et la prise en charge CMU. Lui en fabriquer une serait tordre une
> table de facturation de soins pour y loger un achat de comptoir.

### F7 — Retrait ou livraison : on enregistre le CHOIX, on ne construit pas un service de livraison

`livraison_disponible` et `rayon_livraison_km` existent depuis **B3-b** et sont, aujourd'hui, **des
données que personne ne lit**. B3-d leur donne leur premier consommateur : **la livraison n'est
proposée que si l'officine la déclare**, et le serveur **refuse** une commande en livraison chez une
officine qui ne la fait pas.

Ce qu'on ne construit **pas** : tournées, livreurs, suivi de course, table `livraisons`. Cette table
du CDC_04 §107 suppose un métier (affecter un livreur, suivre une course) dont **aucun écran, aucun
acteur, aucune permission n'existe** — ce serait le socle à vide refusé depuis P6.3-D3. L'adresse est
donc portée **par la commande**.

> **`rayon_livraison_km` n'est PAS transformé en garde de distance** : le patient ne déclare pas de
> coordonnées à la commande, et déduire sa position d'une adresse en texte libre exigerait un
> géocodeur (dépendance, §2.6). **Le rayon est affiché, pas appliqué** — une information, jamais une
> garantie. Dit plutôt que déguisé.

### F8 — Le stock est CONSULTÉ, jamais engagé

Tentation : décrémenter à la commande, pour « réserver ». Écartée pour trois raisons :

1. une commande refusée ou jamais retirée rendrait le stock **faux** ;
2. il faudrait une expiration de réservation, donc un état de plus et une tâche planifiée
   qu'**aucun cron ne fait tourner ici** ;
3. surtout, **B3-b a posé que le stock est la somme de faits datés** — une réservation n'est pas une
   sortie ; l'y écrire mentirait sur ce qui est réellement sorti.

→ La commande **consulte** la disponibilité pour informer ; **le stock ne bouge qu'à la remise**.
→ Une commande sur un produit en rupture est **acceptée avec un avertissement**, jamais bloquée :
refuser sur la foi d'un inventaire que l'officine ne tient peut-être pas priverait un patient d'un
produit qui est peut-être là (raisonnement de B3-b, où une délivrance passe même sans inventaire).

### F9 — La remise n'invente pas un second chemin de sortie de stock

Quand le pharmacien remet une commande, la sortie de stock passe par le **mouvement d'inventaire de
B3-b** (`type = 'sortie'`, motif « Remise d'une commande ») — le même chemin que la délivrance
d'ordonnance, avec un motif qui dit d'où il vient. **Aucune migration** sur `mouvements_stock` (Q9).

> **Limite à annoncer, et elle est HÉRITÉE, pas créée ici** : `traces_dispensation` (B3-c) est
> adossée aux **lignes de délivrance**, donc à une **ordonnance**. Une vente libre commandée
> n'entrera pas au registre national — **exactement comme une vente libre au comptoir n'y entre pas
> déjà**. B3-d n'agrandit pas le trou, il l'étend à un canal de plus, et le dit. Le refermer
> exigerait de rendre `delivrance_ligne_id` nullable et de lui ajouter une seconde clé
> d'idempotence, sur une table **append-only livrée la veille** : c'est un incrément à part.

### F10 — Permission neuve `commande.traiter`, et la garde qui lui donne son sens

Le critère du projet est constant : *réutiliser une permission n'est juste que si c'est le même
acte* (P11.1-D5 : « approuver une candidature, c'est créer un établissement » → même permission ;
B3-a : « tenir un prix et dispenser sont deux gestes » → permissions distinctes).

Accepter une commande est un acte **de relation client** ; dispenser est un acte **pharmaceutique**.
→ **`commande.traiter`**, donnée au seul rôle `pharmacien`, déclarée **des deux côtés** (sans quoi la
garde anti-divergence de P11.0 casse le build).

**Et c'est la remise qui donne son sens à la séparation** : remettre une commande **portant une
ordonnance** est une **délivrance** — elle exigera donc **les deux** permissions,
`commande.traiter` **et** `ordonnance.delivrer`.

### F11 — Anti-IDOR, patron du projet

Le patient lit **ses** commandes (celles des membres de son carnet) ; le pharmacien lit celles de
**son** officine. Une commande d'une autre officine, ou d'un autre patient → **404, jamais 403** :
un 403 confirmerait qu'une commande existe là.

### F12 — Notification : l'état, jamais le contenu

Trois valeurs neuves de `TypeNotification` : `COMMANDE_ACCEPTEE`, `COMMANDE_REFUSEE`,
`COMMANDE_PRETE`.

**La règle inviolable de P7-D1 mord ici** : *aucun contenu médical dans une notification* — un push
s'affiche sur un écran verrouillé, et **un nom de médicament désigne une pathologie**. Le message dit
qu'une commande a changé d'état, **jamais ce qu'elle contient**. Patron exact de P6.8b, où la
notification dit qu'une vaccination est due et jamais laquelle. Un vecteur dédié cherche un nom de
produit dans toute la charge utile et casse le build s'il l'y trouve.

---

## 4. Schéma exact

### 4.1 `commandes`

| Colonne | Type | Raison |
|---|---|---|
| `id` | bigint auto | |
| `reference` | string(20) **unique** | Opaque et non séquentielle (`CMD-` + 10 aléatoires) — patron `DEM-` de P11.1 : un compteur laisserait deviner le volume et énumérer les commandes des autres |
| `pays_code` | string(2) | Cohérence multi-pays du dépôt |
| `membre_id` | FK `membres_famille` **cascade** | Le bénéficiaire. Cascade : une commande n'a aucun sens sans le dossier auquel elle se rattache — même choix que `delivrances` sur `ordonnances` |
| `user_id` | FK `users` **nullOnDelete** | Qui a passé la commande (un responsable commande pour son enfant) — l'auteur peut disparaître, la commande reste |
| `structure_id` | FK `structures_sanitaires` **restrict** | F2. `restrict` : on ne supprime pas une officine qui a des commandes en cours — patron `couvertures_membre` (P6.8d) |
| `ordonnance_id` | FK `ordonnances` **nullOnDelete** | F4. Nullable : la vente libre n'en a pas |
| `mode_retrait` | enum(`retrait`,`livraison`) | F7 |
| `adresse_livraison` | text **chiffré**, nullable | Donnée personnelle : chiffrée au repos comme le reste du carnet, lisible par le chemin autorisé |
| `statut` | enum(6 valeurs de F5) | Défaut `en_attente` |
| `montant_indicatif_cfa` | unsigned int, nullable | F6. `null` si aucun prix connu — *on ne fabrique pas un montant* |
| `mode_reglement` | enum(`sur_place`,`en_ligne`) | F6. Défaut `sur_place` — **le seul mode disponible drapeau OFF** |
| `regle_le` | datetime nullable | Non nul = réglé. **Aucun état `payee` dans le cycle** : le règlement est un fait distinct de l'avancement de la commande, et les mélanger rendrait insoluble « acceptée mais pas encore payée » — patron `estRegle()` de B1 |
| `reference_reglement` | string(30) nullable, **unique** | Clé d'idempotence transmise à `CommissionService` comme `reference_interne_paiement` — c'est elle qui garantit qu'un rejeu ne recalcule jamais une commission (premier geste du service) |
| `commentaire` | string(500), nullable | Du patient à l'officine |
| `motif_refus` | string(300), nullable | **Obligatoire si `refusee`** (garde du moteur) |
| `traite_par_user_id` | FK `users` nullOnDelete | Le pharmacien qui a décidé |
| `acceptee_le`, `prete_le`, `remise_le`, `annulee_le` | datetime nullable | Chaque étape porte sa date, comme `checked_in_at`/`termine_le` de B1 |
| `created_at`, `updated_at` | | Une commande **change d'état** : elle n'est pas append-only |

**Index** : `(structure_id, statut)` pour la file du pharmacien ; `(membre_id, created_at)` pour
« mes commandes ».

### 4.2 `commande_lignes`

| Colonne | Type | Raison |
|---|---|---|
| `id` | bigint auto | |
| `commande_id` | FK **cascade** | |
| `medicament_id` | FK `medicaments` **nullOnDelete** | La table **n'est pas append-only** → la nullification par le moteur est sans danger ici. *C'est la différence exacte avec `traces_dispensation` (B3-c §10.10), et elle est écrite pour qu'on ne la reproduise pas à l'envers.* |
| `medicament_code`, `nom`, `dci`, `dosage` | string nullable | **FIGÉS** à la commande (patron B3-a/B3-c) |
| `ordonnance_requise` | boolean | **FIGÉ** — F3 |
| `ordonnance_ligne_id` | FK `ordonnance_lignes` nullOnDelete | Quand la ligne vient d'une ordonnance désignée |
| `quantite` | unsigned int | ≥ 1, garde du moteur |
| `prix_unitaire_indicatif_cfa` | unsigned int nullable | **FIGÉ** — F6 |
| `created_at`, `updated_at` | | |

**`UNIQUE(commande_id, medicament_id)`** : on n'ajoute pas deux fois le même produit, on augmente la
quantité — l'unicité rend le doublon **inexprimable** plutôt que de le corriger après coup (geste de
P6.8c).

### 4.3 Gardes du moteur — déclencheurs dans les **deux** dialectes

1. `statut = 'refusee'` ⟺ `motif_refus` non vide ;
2. `mode_retrait = 'livraison'` ⟹ `adresse_livraison` non nulle ;
3. `quantite ≥ 1` sur les lignes ;
4. `regle_le` non nul ⟹ `reference_reglement` non nulle — *un règlement sans référence serait un
   encaissement qu'on ne peut ni rapprocher, ni rejouer sans risque de double commission*.

Un `CHECK` serait ici techniquement possible (aucune de ces colonnes ne subit d'action référentielle,
donc **pas d'erreur 3823**) — mais il ne peut pas porter de message. Les déclencheurs, eux, nomment
la garde violée, et c'est le style tenu par tout le lot B3.

> **Piège connu, à ne pas répéter** : l'apostrophe française dans un message de `SIGNAL` doit être
> **doublée**, sinon la migration passe une fois et devient **impossible à rejouer** (défaut réel de
> B3-b, trouvé au G2 et invisible aux vecteurs). `COALESCE(cond, 0) = 0` et non `NOT(cond)` : une
> comparaison `NULL` ne déclencherait rien et la violation passerait **sans bruit**.

---

## 5. Classes et fonctions — noms retenus et leur raison

| Nom | Rôle |
|---|---|
| `App\Services\Medicament\ServiceCommande` | Le patient passe, règle et annule. Porte les gardes F2, F3, F4, F6, F7, F8, F11 |
| `App\Services\CommissionService` | **APPELÉ, JAMAIS MODIFIÉ** — `regler()` lui passe `regleEnLigne`, `structureSanitaireId`, `montantBrut`, `referenceInternePaiement` ; l'exonération, le palier, l'arrondi et l'idempotence restent **entièrement chez lui**. Le réécrire créerait deux façons de calculer une commission |
| `App\Services\Medicament\ServiceTraitementCommande` | Le pharmacien accepte, refuse, prépare, remet. Porte F5, F9, F10, F11 |
| `App\Models\Commande`, `App\Models\CommandeLigne` | |
| `App\Support\StatutCommande`, `App\Support\ModeRetraitCommande` | Miroirs PHP de l'enum partagé |
| `@masante/shared` → `CommandeStatut`, `ModeRetraitCommande` | **Source unique** (F5) |
| `App\Http\Controllers\Api\V1\CommandeController` | Patient (Sanctum) |
| `App\Http\Controllers\Portail\CommandeClientController` | Pharmacien (Blade) |
| `apps/mobile/src/store/panier.ts` | Le panier local (F1) |

**Deux services et non un** : le patient et le pharmacien ne partagent **aucune** garde — l'un
prouve qu'il commande pour son propre carnet, l'autre qu'il traite pour son officine. Les fondre
produirait une classe où chaque méthode commencerait par se demander qui l'appelle.

---

## 6. Ce qui change dans l'existant

| Fichier | Changement | Risque |
|---|---|---|
| `@masante/shared` | **+2 enums** et leurs libellés | Nul (additif) ; garde anti-divergence obligatoire |
| `TypeNotification` | **+3 valeurs** | Nul (additif) |
| `PortailRolesSeeder` + `permissions.ts` (web) | **+1 permission** `commande.traiter` → `pharmacien` | La garde de P11.0 casse le build si l'un des deux manque — c'est voulu |
| `ServiceStockOfficine` | **Aucun changement de schéma** ; appelé avec un motif neuf | Nul (Q9) |
| `PrixMedicamentService::comparer()` | **Inchangé** — la commande le lit, ne le modifie pas | Nul |
| `ServiceDelivrance` | **Inchangé** — la remise d'une commande sous ordonnance l'appelle | À vérifier au G3 : ne pas dupliquer sa logique |
| `CommissionService` | **Aucun changement** — B3-d en devient le **premier appelant** | Le service est déjà couvert par ses propres tests ; les nôtres vérifient **l'appel**, pas le calcul |
| `config/masante.php` | **+1 drapeau** `commande.reglement_en_ligne`, **OFF par défaut** (F6) | Nul |
| Mobile : `ComparateurEcran` | **+ un bouton « Ajouter au panier »** sur chaque offre | Écran validé G5 — modification **additive** seulement |

**Ce qui ne bouge pas, et c'est explicite** : `prix_pharmacie`, `stocks_officine`,
`mouvements_stock`, `delivrances`, `traces_dispensation`, `ordonnances`, `ordonnance_lignes`.
B3-d **consomme** tout ce que B3-a/b/c ont construit et n'en modifie rien.

---

## 7. Ce qu'il faudra prouver

**G3** — vecteurs dédiés, dans les deux sens, avec une campagne de mutation (tueuses **et** un
témoin volontairement vert). Les vecteurs obligatoires :

1. **Le vecteur central** — un produit à `ordonnance_requise = true` commandé **sans ordonnance** est
   **refusé en nommant le produit** ; le même produit **avec** une ordonnance désignée passe.
2. **Le client ne décide pas** : `statut`, `reference`, `montant_indicatif_cfa`,
   `ordonnance_requise`, les valeurs figées — tous envoyés par le client, tous **ignorés**.
3. **L'ordonnance d'autrui** : désigner l'ordonnance d'un membre qui n'est pas celui de la commande
   → refus.
4. **Livraison chez une officine qui n'en fait pas** → refus par son motif.
5. **Le stock ne bouge pas à la commande** ; il bouge **à la remise**, une seule fois.
6. **Anti-IDOR** : commande d'une autre officine → **404** ; d'un autre patient → **404**.
7. **La notification ne porte aucun nom de produit** (recherche dans toute la charge utile).
8. **Cycle** : chaque transition interdite refusée **par son motif**, jamais par un code seul.
9. **Remise d'une commande sous ordonnance** → crée une **délivrance** (B3-a) et sa **trace**
   (B3-c) ; remise d'une vente libre → **mouvement de stock seul**, et le vecteur dit que la trace
   n'existe pas (F9, limite assumée et **prouvée** plutôt qu'affirmée).
10. **Les valeurs figées survivent** : renommer le produit au référentiel après coup ne change pas la
    ligne de commande.
11. **Règlement `sur_place` → AUCUNE commission** (`commissions_transaction` reste vide) ;
    **règlement `en_ligne` → une commission calculée**, avec son taux et son volume cumulé figés.
    *Les deux vecteurs en miroir sont la preuve : l'un sans l'autre ne prouverait rien.*
12. **Le règlement en ligne est refusé drapeau OFF**, et refusé **avant acceptation** de la commande
    — chacun **par son motif**, jamais par un code partagé.
13. **Rejouer un règlement ne crée pas une seconde commission** (idempotence par
    `reference_reglement`), et **ne double pas** le montant réglé.
14. **`baremes_commission` vide → échec bruyant**, jamais une commission à 0 silencieuse : le
    vecteur vérifie que le refus **nomme** le palier manquant.

**G2 (live)** — base MySQL réelle sauvegardée puis **restaurée compte pour compte**, `artisan serve`
réel, chaîne HTTP réelle avec session et CSRF côté portail, jeton Sanctum réel côté patient :
un parcours complet **commande → acceptation → prête → remise**, les refus **chacun par son motif**,
les gardes du moteur éprouvées en SQL direct (`ERROR 1644`, `ERROR 1062`), et la vérification que
`prix_pharmacie`/`traces_dispensation` sont dans l'état attendu.

---

## 8. Limites qui seront annoncées

1. **L'encaissement reste SIMULÉ**, comme celui du rendez-vous : `frais_passerelle` et
   `frais_prestataire` valent 0 — *le coût réel d'une passerelle qui n'existe pas encore*, jamais une
   valeur inventée. **La commission, elle, est réelle**, d'où le drapeau OFF par défaut (F6).
   **Brancher GeniusPay pour de vrai est un lot transversal à part**, qui vaut autant pour le
   rendez-vous, et qui suppose d'ajouter `structureSanitaireId` au contrat du microservice Java.
2. **Aucun service de livraison** : le mode et l'adresse sont enregistrés, rien n'est suivi (F7).
   `rayon_livraison_km` est **affiché, pas appliqué**.
3. **Une ordonnance papier hors carnet ne permet pas de commander** un produit sous ordonnance (F4).
4. **La vente libre commandée n'entre pas au registre national** — limite **héritée** de B3-c (F9).
5. **`renouvellements` n'est pas livré** et sort du périmètre, avec sa raison (Q7).
6. **Aucune alerte de rupture** (`alertes_rupture` du CDC_04 §107) : `seuil_alerte` existe depuis
   B3-b et s'affiche déjà à l'écran du pharmacien ; une table d'alertes supposerait un canal et une
   tâche planifiée qu'aucun cron ne fait tourner ici.
7. **Écran pharmacien en Blade**, sans investissement de design (décision K1 de P6.4d) — la
   migration du portail reste le module identifié par ADR-011/ADR-029.
8. **Le panier ne survit pas à la désinstallation** de l'application (F1, assumé).

---

## 9. Ordre d'exécution

1. Enums partagés (`@masante/shared`) + miroirs PHP + **garde anti-divergence** — d'abord, parce que
   tout le reste les lit.
2. Migration (`commandes`, `commande_lignes`, **4** déclencheurs × 2 dialectes) + modèles.
3. `ServiceCommande` (le patient) + ses gardes.
4. `ServiceTraitementCommande` (le pharmacien) + le cycle + le branchement stock/délivrance.
5. **Le règlement** (F6) : drapeau de configuration, `regler()`, et l'appel à `CommissionService`
   — **après** le cycle, puisqu'il n'est possible qu'une fois la commande acceptée.
6. Permission + notifications.
7. API patient (Sanctum) + écran portail (Blade).
8. Mobile : store panier, bouton au comparateur, écran commande, « mes commandes ».
9. G3 : vecteurs + campagne de mutation + Pint (baseline établie **avant** tout formatage) +
   typecheck ×3 + `expo-doctor`.
10. G2 live sur MySQL réelle (drapeau ON pour prouver la commission, `BaremesCommissionSeeder`
    joué), puis restauration vérifiée.
11. Documentation : ADR-055 §11, guide partie 14, `CLAUDE.md`, `plan.md`, `handoff.md`.

---

## 10. Les trois arbitrages du propriétaire (2026-09-04)

| # | Question posée | Décision | Suite |
|---|---|---|---|
| **A** | **Le paiement** : la commande encaisse-t-elle ? | **Deux modes de règlement** — en ligne **ou** à la pharmacie ; **la commission s'applique au règlement en ligne**. *Décision CONTRE ma recommandation initiale (« aucun paiement »), et le propriétaire avait raison* : la règle « pharmacie hors ligne = exonérée » est **déjà codée** dans `CommissionService`, qu'aucun appelant n'avait jamais atteint (Q10, Q11). | **F6 réécrit** ; §4.1 gagne trois colonnes ; une quatrième garde du moteur ; six vecteurs obligatoires de plus |
| **B** | **L'ordonnance** : désignée dans le carnet, ou téléversée en photo ? | **Désignée dans le carnet**, en assumant qu'une ordonnance papier ne permette pas de commander. | **F4 inchangé** |
| **C** | **Le périmètre** : un seul incrément, ou coupé en deux ? | **Un seul.** | **Inchangé** — le règlement en ligne s'y ajoute sans le couper |

**Ce que l'arbitrage A a changé dans ce plan, et pourquoi il fallait re-vérifier avant d'écrire.**
Ma recommandation initiale s'appuyait sur le §9.6 seul (« la plateforme ne manipule jamais les
fonds »). Elle ignorait que **tout le domaine commission existait déjà** — barèmes par palier, plan
tarifaire, transactions, factures partenaires, écran de facturation — et surtout qu'il portait
**textuellement** la distinction en ligne / hors ligne pour les pharmacies. *Le G0 initial avait
regardé le domaine de la commande ; il n'avait pas regardé celui de la commission, parce que le
corpus applicatif n'y renvoyait pas.* Le rappel du propriétaire (« c'est ce que j'avais dit ») a
envoyé chercher au bon endroit, et **quatre constats vérifiés en base et dans le code Java**
(Q10→Q12) ont remplacé une recommandation par une décision documentée.

**Aucune ligne de code ne sera écrite avant validation de ce plan ainsi corrigé.**

---

## 11. Report — PLAN 2 attend PLAN 3 (décision propriétaire, 2026-09-04)

> « on va brancher les paiements sur GeniusPay et aussi le brancher au rendez-vous »

Le propriétaire demande le **lot transversal** que §F6 nommait hors périmètre. Il devient **PLAN 3**,
et **B3-d en dépend** : son règlement en ligne serait, sinon, un second mécanisme simulé qu'il
faudrait défaire aussitôt après. **PLAN 2 reste écrit et valable** — F1→F5 et F7→F12 ne bougent pas ;
seul F6 sera **remplacé** par un branchement sur le canal réel de PLAN 3.

---

# PLAN 3 : Paiement en ligne réel (GeniusPay) — canal, commission, rendez-vous (B4)

**Lot** : **B4**, transversal. Il précède B3-d (PLAN 2) et modifie le règlement du **rendez-vous**
(lot B1, validé G5 — donc **par ajout, jamais par remplacement**).
**ADR** : ADR-056 (à écrire), amendant ADR-013 et ADR-044.
**Date du G0** : 2026-09-04. **G1 VALIDÉ par le propriétaire le 2026-09-04** (« je valide le G1 de
B4-a »). Exécution de B4-a en cours.

---

## 1. Ce que le corpus demande

CDC_11 **§9.6** :
> **Paiement direct** : le paiement est traité directement par le prestataire de paiement de
> l'hôpital ou de la pharmacie. **La plateforme ne manipule jamais les fonds.**

C'est **exactement** ce que le montage A de GeniusPay réalise déjà (P5.6b) : chaque établissement a
**son** compte marchand, l'argent va **chez lui**, MaSanté ouvre le checkout et constate l'issue.
La commission de plateforme est **facturée séparément** (`FacturePartenaire`), jamais prélevée sur
les fonds.

CDC_11 **§9.2** (rendez-vous) : « paiement à la réservation », moyens « carte bancaire, Visa,
Mastercard, Orange Money, MTN Money, Wave, Moov Money », et « le système génère facture, reçu,
numéro de transaction, historique ».

---

## 2. G0 — douze constats, vérifiés dans le code Java, le code PHP et la base réelle

| # | Constat | Comment vérifié |
|---|---|---|
| **R1** | **Le point d'entrée Java EXISTE déjà, complet** : `POST /api/v1/interne/geniuspay/paiements` (principal signé, rôle `SYSTEME`, `Idempotency-Key`) accepte `factureId`, `montant`, `devise`, **`etablissementRef`**, `patientRef`, `correlationId`, `objet`, et rend `checkoutUrl`, `referenceInterne`, `statutPartage`, `fraisPasserelle`, `montantNet`. Plus `GET /paiements/{referenceInterne}`, `POST /marchands`, `POST /marchands/{ref}/secret-webhook`. | `GeniusPayController` |
| **R2** | **LE CONSTAT QUI RENVERSE TOUT** : l'agrégat `Paiement` **PORTE** `etablissementRef` (colonne `etablissement_ref`, `updatable = false`) **et** `factureId` ; `setStatut()` construit l'événement **dans cette même classe**. Or `TransitionTerminaleEvenement` ne les recopie pas, et son commentaire affirme « **le domaine n'en a pas** ». **C'est inexact.** Ce qui était vrai, c'est qu'ils pouvaient être **nuls faute d'émetteur** — Laravel n'initiant aucun paiement. *Le champ existe ; en devenir l'émetteur, c'est le remplir.* | `Paiement.java` (l. 69-70, 84, 179-186), `TransitionTerminaleEvenement.java` |
| **R3** | **`NotificateurFacturation` met `fraisPasserelle` et `fraisPrestataire` à `0` EN DUR**, avec sa raison écrite (« le paiement est simulé, c'est le coût exact d'une passerelle qui n'existe pas »). **Avec GeniusPay réel, cette phrase devient fausse** — `GeniusPayTransaction` porte de vrais frais, renseignés à la création, par le webhook (`data.path("fees")`) et par la réconciliation. | `NotificateurFacturation.java` l. 74-75 |
| **R4** | **La dette nommée de P5.6b mord précisément ici** : le webhook ne porte **ni frais ni net** dans le cas nominal, et la réconciliation **ne revisite jamais** une transaction terminale → *un paiement soldé par le chemin nominal n'a jamais ses frais*. En bac à sable, `fees` vaut **toujours 0**. | `CLAUDE.md` (P5.6b), `ServiceWebhookGeniusPay`, `ServiceReconciliationGeniusPay` |
| **R5** | **Côté Laravel, le client sortant a été DÉLIBÉRÉMENT RETIRÉ** (P5.6a, « plutôt que laissé appeler dans le vide »). Il ne reste que `VerificateurPrincipalSigne` (entrant) et `config('masante.paiement_service.principal_secret')`. **Aucune base URL du service de paiement n'est configurée.** | Recherche exhaustive, `config/masante.php` l. 209-219 |
| **R6** | **Le principal signé a TROIS implémentations** : Java vérifie (`ServicePrincipal`), Node mint (`apps/web/src/lib/paiement.ts`), Python mint (`signer.py`, dev). Format : `X-Principal = base64(JSON {sub,roles,iat,exp,method,path,nonce})`, `X-Principal-Sig = base64(HMAC-SHA256(X-Principal, base64_decode(secret)))`, **`path` sans query**. Une **quatrième, en PHP**, est à écrire. | Les trois fichiers |
| **R7** | **`CommissionService` est complet et n'a AUCUN appelant** (`commissions_transaction` : 0 ligne). La règle « pharmacie hors ligne = exonérée » y est **textuelle**, et il **refuse de deviner** `regleEnLigne`. | Constats Q10/Q11 du PLAN 2 |
| **R8** | **Le rendez-vous est réglé par `RecuRdvService::payer()`, 100 % Laravel et SIMULÉ** : `Paiement` (`SIM-…`, `statut = 'paye'`), `FacturePatient` née **`PAYEE`** et `RecuRdv` créés **dans la même transaction, immédiatement**. Module **validé G5** (B1). | `RecuRdvService.php` |
| **R9** | **`structures_sanitaires.identifiant_national` : 0 sur 12** en base réelle (backfill P6.4a non rejoué). Et l'unicité y est `(pays_code, identifiant)` — **CI et SN peuvent partager `ETS000001`**. | Comptage en base, ADR-026 |
| **R10** | **`baremes_commission` : 0 palier ; `plans_tarifaires` : 0 ; `abonnements_structure` : 0.** `baremeActif()` **lève** si aucun palier ne couvre le volume. | Comptages en base réelle |
| **R11** | **Le webhook GeniusPay arrive sur le JAVA**, jamais sur Laravel (montage A, slug opaque par établissement, signature HMAC sur le corps brut). **Laravel n'a donc aucune signature prestataire à vérifier** — c'est fait, et prouvé en réel (sept livraisons authentiques, P5.6b). | `GeniusPayController`, CLAUDE.md P5.6b |
| **R12** | **Le mobile n'a aucun écran de paiement en ligne** : le rendez-vous se règle par un appel qui rend immédiatement un reçu. Un `checkoutUrl` doit être **ouvert** quelque part. | `apps/mobile` |

**R2 est le constat central**, et il est du même genre que Q2 pour B3-d : *une capacité présente dans
le code, que rien n'exploite, et dont un commentaire affirme l'absence*. La différence tient à ceci :
**le commentaire n'était pas absurde quand il a été écrit** — sans émetteur, le champ était vide en
pratique, et rattacher une commission à un `etablissementRef` nul aurait été pire que ne rien faire.
Ce plan supprime la cause, pas seulement le symptôme.

---

## 3. Les décisions de conception (S1 → S10)

### S1 — `etablissementRef` porte l'identifiant NATIONAL préfixé du pays, jamais l'id technique

`CI-ETS000001`, jamais `7`. Un identifiant technique **ne veut rien dire hors de la base Laravel**
(argument déjà opposé à `symptome_id` en P10b-3-i), et le microservice est un système **séparé** qui
doit rester lisible seul. Le préfixe pays est **obligatoire** : R9 établit que l'unicité de
`identifiant_national` est `(pays_code, identifiant)` — **sans le pays, deux établissements de deux
pays partageraient la même référence de paiement**. Le dépôt emploie déjà cette forme dans ses
contrôles d'unicité (`CI-PRO000001`, P6.5a).

> **Conséquence dure, à dire** : **le backfill de P6.4a devient un prérequis**. Un établissement sans
> identifiant national **ne peut pas encaisser en ligne**, et le refus doit le **nommer** — jamais
> échouer sur une contrainte obscure. Un vecteur dédié.

### S2 — L'événement porte ce que le domaine SAIT, et Laravel refuse quand il ne sait pas

`TransitionTerminaleEvenement` gagne `etablissementRef` et `factureId` — **recopiés de l'agrégat,
jamais devinés**. Le commentaire qui affirme le contraire est **corrigé**, avec sa date et sa raison
(*une archive de décision ne se réécrit pas en silence*).

**S'ils sont nuls, ils partent nuls**, et **Laravel refuse de calculer une commission** en le
journalisant — au lieu de la rattacher au hasard. C'est la garantie que le lot 6 cherchait ; elle est
tenue **par le refus**, pas par l'absence du champ.

### S3 — Les frais cessent d'être `0` en dur, et leur absence se DIT

`NotificateurFacturation` lira les frais réels de la transaction GeniusPay quand le paiement en
vient. Mais R4 est un vrai trou : sur le chemin nominal, **les frais ne sont pas connus**.

**Ce que les frais changent, et ce qu'ils ne changent pas** : la commission vaut
`montantBrut × taux` — **les frais n'y entrent pas**. Ils entrent dans
`montantNetStructure = montantBrut − fraisPasserelle − fraisPrestataire − montantCommission`,
c'est-à-dire dans **ce qui sera reversé à l'établissement**. Des frais faux ne faussent pas la
commission ; ils faussent le **net dû au partenaire**.

→ **Décision du propriétaire (2026-09-04) : calculer, et l'inscrire.** La charge porte les frais tels
que la transaction les connaît, et `null` quand elle ne les connaît pas ; Laravel calcule la
commission **avec 0 explicite** et **inscrit sur la ligne que les frais n'étaient pas connus**,
plutôt que de laisser croire qu'ils valaient zéro. *Refuser aurait laissé des paiements réels sans
aucune commission, en attendant une passe de complétion qui n'existe pas encore.*

> **Ce plan donne enfin un PORTEUR à la dette de P5.6b** (« passe de complétion des frais ») : elle
> cesse d'être une note de bas de page pour devenir une condition d'exactitude du reversement.
> **Elle n'est pas livrée ici** — appeler le prestataire dans la transaction qui solde est interdit
> (P7-D1) — mais elle est nommée, et c'est ce lot qui la rend nécessaire.

### S4 — Laravel devient émetteur : quatrième implémentation, donc garde anti-divergence

`SigneurPrincipalSortant` (PHP), **miroir exact** de ce que `VerificateurPrincipalSigne` vérifie et
de ce que `paiement.ts` mint. Quatre implémentations du même format, c'est quatre occasions de
diverger : **une garde d'exécution** vérifie qu'un principal minté par PHP est accepté par le
vérifieur PHP **et** que sa forme est celle documentée (patron `PermissionsSourceUniqueTest`,
`RendezVousStatutSourceUniqueTest`).

**Aucune dépendance** : `hash_hmac`, `base64_encode`, `Str::uuid()` sont natifs ; le client HTTP est
`Illuminate\Support\Facades\Http`.

### S5 — Le rendez-vous garde son chemin simulé et gagne un chemin réel À CÔTÉ

B1 est **validé G5**. `payer($rdv, $mode)` **n'est pas touché**. On ajoute `ouvrirPaiementEnLigne()`.

**Le changement de TEMPORALITÉ est le vrai sujet, et il est plus profond que l'ajout d'une méthode.**
Aujourd'hui `payer()` crée `Paiement` + `FacturePatient` (**`PAYEE`**) + `RecuRdv` **dans la même
transaction, immédiatement**. En ligne, l'issue n'est connue **que plus tard**.

| Étape | Aujourd'hui (simulé) | En ligne (réel) |
|---|---|---|
| Le patient déclenche | `Paiement` + facture `PAYEE` + reçu | `FacturePatient` **`A_REGLER`** + checkout ouvert. **Aucun reçu** |
| Le patient paie chez GeniusPay | — | (hors plateforme) |
| Notification reçue | — | facture → **`PAYEE`**, `Paiement`, **`RecuRdv` créé**, **commission calculée** |

`StatutFacturePatient::A_REGLER` **existe déjà** — `RecuRdvService` le nomme dans son propre
commentaire pour dire qu'il ne l'emploie pas. **On ne crée rien : on emprunte enfin le chemin que
l'énumération prévoyait.**

> **Conséquence tenue par l'existant** : le check-in exige un reçu (B1-c/B1-d). Tant que le paiement
> en ligne n'est pas confirmé, **il n'y a pas de reçu, donc pas de check-in** — exactement le bon
> comportement, et **obtenu sans écrire une garde**.

### S6 — La notification est le SEUL moment où un règlement en ligne devient vrai

Jamais le retour de l'application, jamais un « le patient a cliqué sur payé ». C'est le principe déjà
tenu par P5.4a pour la 3DS (« le statut n'est jamais déclaré par le client ») et par les mandats
(« le résultat est décidé par la passerelle »). **Le mobile ne peut pas solder une facture.**

Traitement **idempotent** : la même notification rejouée ne crée **ni un second reçu, ni une seconde
commission** (la clé d'idempotence de `CommissionService` est déjà là ; il faut la même sur le reçu).

### S7 — Pas de drapeau global : la disponibilité est une propriété de L'ÉTABLISSEMENT

> **Arbitrage du propriétaire, 2026-09-04** : **actif d'emblée**, contre ma recommandation d'un
> drapeau « prêt à activer ».

**Cela m'oblige à revoir la décision, et le résultat est meilleur que ce que je proposais.** Un
drapeau global aurait été **binaire pour tous les établissements**, alors que la réalité du montage A
est **par établissement** : celui qui a déclaré son compte marchand chez GeniusPay peut encaisser,
l'autre non. *Un interrupteur unique aurait dit « oui » pour des officines qui n'ont aucun compte, et
« non » pour celles qui en ont un.*

→ **Aucun drapeau de configuration.** Le paiement en ligne est proposé **si et seulement si**
l'établissement remplit les deux conditions réelles :

1. il a un **identifiant national** (S1) ;
2. il a un **compte marchand enregistré** côté GeniusPay.

**Comment Laravel sait la seconde, sans créer une seconde vérité.** La liste des marchands vit dans
le microservice, et **elle doit y rester** : la recopier côté Laravel produirait deux réponses
possibles à « cet établissement peut-il encaisser ? », divergeant le jour où l'une est mise à jour
sans l'autre — le défaut que ce projet refuse partout.

→ Le Java expose `GET /api/v1/interne/geniuspay/marchands/{etablissementRef}` — **« configuré :
oui / non », JAMAIS les clés** (le contrôleur ne les cite déjà pas, même à l'enregistrement).
Laravel l'interroge et **met la réponse en cache quelques minutes** : c'est un cache, pas une copie —
il se périme tout seul et n'est jamais la source.

**Ce que « actif d'emblée » implique, et qui est assumé** : un défaut du canal se verra
**immédiatement par les patients**, sans interrupteur pour l'éteindre. La contrepartie est que
**rien n'est proposé qui ne puisse aboutir** — un établissement non configuré n'affiche tout
simplement pas le mode « en ligne », et s'il est demandé quand même (client modifié, course entre
deux écrans), le serveur **refuse en le nommant**.

> **Le chemin existant reste intact et disponible** : `payer($rdv, $mode)` n'est pas touché (S5).
> Un établissement sans compte marchand se règle **exactement comme aujourd'hui**, et les vecteurs
> de B1 passent **sans modification** — c'est ce qui remplace la sécurité qu'aurait donnée le
> drapeau.

### S8 — Le mobile ouvre le checkout dans le NAVIGATEUR, pas dans une WebView

`Linking.openURL()` — **natif, aucune dépendance** (§2.6). Et c'est le bon choix **pour une raison de
sécurité, pas de commodité** : sur une page de paiement, l'utilisateur doit pouvoir **voir l'URL et
le cadenas**. Une WebView les masque et habitue à saisir ses identifiants bancaires dans un cadre
que l'application contrôle — exactement ce qu'on demande aux gens de ne jamais faire.

Au retour, l'application **interroge le serveur** (elle ne suppose rien du résultat, S6).

### S9 — Aucune donnée de carte, jamais, nulle part

Le patient saisit ses coordonnées **chez GeniusPay**. Laravel ne voit ni PAN, ni CVV, ni jeton — la
frontière PCI de P5.4a (`FiltreAntiPan`) reste **entière**, et rien de ce lot ne s'en approche.

### S10 — Découpage en deux incréments, et l'ordre est contraint

| Sous-lot | Contenu | Pourquoi cet ordre |
|---|---|---|
| **B4-a** | Le **canal** : signeur PHP + client + config ; `etablissementRef`/`factureId` dans l'événement Java ; frais réels dans la charge ; **Laravel appelle enfin `CommissionService`**. Prouvé bout en bout par un **paiement de test**, sans toucher aucun écran. | Le canal doit être **prouvé seul**. Le brancher au rendez-vous en même temps mêlerait deux causes de panne — et B1 est G5 |
| **B4-b** | Le **rendez-vous** : `A_REGLER`, `ouvrirPaiementEnLigne()`, solde à la notification, écran mobile, écran portail. | S'appuie sur un canal déjà éprouvé |

**B3-d (PLAN 2) vient après** et se contente de brancher son règlement sur le même canal.

---

## 4. Ce qu'il faudra prouver

**G3** — vecteurs dédiés des deux côtés (PHPUnit et JUnit), campagne de mutation côté PHP.
Vecteurs obligatoires :

1. **Un principal minté par PHP est accepté par le vérifieur PHP**, et sa forme est celle des trois
   autres implémentations (S4).
2. **`etablissementRef` nul → aucune commission**, et le refus est **journalisé** (S2).
3. **Établissement sans identifiant national → refus qui le NOMME** (S1).
4. **`baremes_commission` vide → échec bruyant**, jamais une commission à 0 silencieuse (R10).
5. **Notification rejouée → ni second reçu, ni seconde commission** (S6).
6. **Établissement sans compte marchand → le mode « en ligne » n'est PAS proposé**, et s'il est
   demandé quand même le serveur **refuse en le nommant** (S7). Le règlement d'aujourd'hui reste
   disponible : **les vecteurs de B1 passent sans modification**.
7. **Facture `A_REGLER` tant que la notification n'est pas venue** ; **pas de reçu, donc pas de
   check-in possible** (S5).
8. **Frais inconnus → commission calculée à 0 ET la ligne dit que les frais étaient inconnus** (S3).
9. **Pharmacie réglée en ligne → commission ; hors ligne → exonérée** — les deux vecteurs en miroir.

**G2 (live)** — base MySQL réelle sauvegardée puis restaurée ; le microservice Java **réellement
démarré** ; un **checkout GeniusPay réel ouvert en bac à sable**, payé, et la notification
**réellement reçue** par Laravel — c'est le seul moyen de prouver S6. Puis les refus, chacun par son
motif.

---

## 5. Limites qui seront annoncées

1. **Les frais ne sont pas complétés** (R4/S3) : la dette de P5.6b est **nommée avec son porteur**,
   pas refermée. Conséquence exacte : le **net dû au partenaire** peut être surestimé.
2. **Aucun remboursement** : rien dans ce projet ne rembourse un paiement patient.
3. **Le bac à sable GeniusPay renvoie `fees = 0`** — le chemin « frais connus » ne sera donc prouvé
   qu'en simulation, jamais en réel. **Dit, pas déguisé.**
4. **Aucun paiement partiel, aucun échéancier.**
5. **`plans_tarifaires` et `abonnements_structure` sont vides** : l'exonération par plan
   (`commission_incluse`) existe dans le code et **ne sera exercée que par un vecteur**, jamais en
   réel.
6. **Le portail n'a pas d'écran de rapprochement** des commissions (l'écran de facturation existe
   depuis le lot 8 et n'est pas retouché).
7. **Aucun interrupteur pour éteindre le paiement en ligne** (arbitrage C) : un défaut du canal se
   verra immédiatement par les patients. Le seul recours d'exploitation est de **retirer le compte
   marchand** d'un établissement chez GeniusPay — c'est réel, mais ce n'est pas un interrupteur
   global, et il faut le savoir.
8. **Aucun écran d'enregistrement du compte marchand** : `POST /marchands` s'appelle en direct sur le
   microservice. Tant qu'un établissement n'y est pas déclaré, il n'encaisse pas en ligne — et
   l'écran le dit plutôt que de proposer un bouton qui échouerait.

---

## 6. Ordre d'exécution (B4-a)

1. Java : `etablissementRef` + `factureId` dans l'événement et dans la charge ; frais réels ;
   **`GET /marchands/{etablissementRef}`** (« configuré : oui/non », jamais les clés — S7) ;
   correction du commentaire obsolète. Tests JUnit.
2. Laravel : `SigneurPrincipalSortant` + garde anti-divergence + config (base URL, drapeau).
3. Laravel : `ClientPaiementGeniusPay` (initier, consulter).
4. Laravel : `PaiementNotificationController` **appelle enfin `CommissionService`** — le TODO du
   lot 6 disparaît, avec la résolution `etablissementRef` → `structure_sanitaire_id`.
5. G3 : vecteurs + mutation + Pint (baseline **avant** tout formatage) + typecheck.
6. G2 live : Java démarré, checkout réel, notification réellement reçue.
7. Documentation : ADR-056, guide **partie 15**, `CLAUDE.md`, `plan.md`, `handoff.md`.

---

## 7. Les trois arbitrages du propriétaire (2026-09-04)

| # | Question posée | Décision | Suite |
|---|---|---|---|
| **A** | **Découpage** : B4-a (canal seul) puis B4-b (rendez-vous) ? | **Oui** — conforme à S10. | Inchangé |
| **B** | **Frais inconnus** : calculer à 0 en le disant, ou refuser ? | **Calculer et l'inscrire** — refuser laisserait des paiements réels **sans aucune commission**, en attendant une passe de complétion qui n'existe pas. | **S3 confirmé** |
| **C** | **Gating** : OFF par défaut ? | **NON — actif d'emblée**, *contre ma recommandation*. | **S7 RÉÉCRIT**, et le résultat est meilleur : la disponibilité devient une propriété **de l'établissement** au lieu d'un interrupteur global |

**Ce que l'arbitrage C a changé, et pourquoi il améliore la conception.** Je proposais un drapeau
« prêt à activer », par prudence. Il aurait été **binaire pour tous les établissements**, alors que
la réalité du montage A est **par établissement** — *un interrupteur unique aurait dit « oui » pour
des officines sans compte marchand, et « non » pour celles qui en ont un*. Sans drapeau, la question
devient la bonne : **cet établissement-ci peut-il encaisser ?** La réponse vit côté GeniusPay, elle
y reste (S7), et rien n'est proposé qui ne puisse aboutir.

**Ce qui est assumé** : sans interrupteur, un défaut du canal se verra **immédiatement par les
patients**. La contrepartie est que le règlement d'aujourd'hui **reste intact et disponible** — un
établissement non configuré se règle exactement comme avant.

---

## 8. G1 validé — exécution

**Validé par le propriétaire le 2026-09-04** : « je valide le G1 de B4-a ». Exécution engagée dans
l'ordre du §6. Les écarts éventuels entre ce plan et le code réellement écrit seront consignés
ci-dessous au fil de l'exécution, jamais en silence (patron tenu depuis B3-c §10).

### 8.1 Écarts trouvés EN IMPLÉMENTANT, pas au G1

**R2' — `etablissementRef` seul ne suffisait pas à isoler GeniusPay.** En codant S2, la relecture de
`ServiceCarte`/`ServicePaiement` (Java) a montré que la carte et le mobile money portent **eux
aussi** un `etablissementRef` — filtrer sur sa seule présence aurait calculé une commission MaSanté
sur **tous** les paiements de la plateforme, une décision de politique commerciale jamais prise.
**`canal` ajouté à l'événement** (`Paiement.getCanal()`, jamais recalculé) : Laravel ne déclenche
`CommissionService` que sur `canal === 'geniuspay'` **et** `statut === SUCCESS`. Documenté dans le
Javadoc de `TransitionTerminaleEvenement` avec sa date et sa raison — une archive ne se réécrit pas
en silence.

**`paiementId` ajouté à l'événement.** Absent du plan initial : la clé d'idempotence de
`CommissionService` (`reference_interne_paiement`, censée porter la forme `MS-{structure}-{ULID}`
d'après le commentaire du lot 6) n'avait en réalité aucun porteur côté charge JSON — ni
`referenceInterne` (vit sur `GeniusPayTransaction`, jamais recopiée sur `Paiement`) ni `factureId`
seul (pas garanti unique dans le temps, un second checkout pouvant viser la même facture après
échec). `paiementId` (`Paiement.id`, déjà sur l'événement) l'est : un `Paiement` n'atteint un état
terminal qu'**une seule fois** (garde de répétition de `setStatut`), donc `geniuspay-paiement:{id}`
identifie sans ambiguïté LA transition qui a déclenché la notification, même après un rejeu du
relais.

**Défaut de mon propre harnais de test, trouvé par le PREMIER run Java** : `evenementPret()`
construisait un `Paiement` resté à `INITIATED` — `INITIATED → SUCCESS` n'est **pas** une transition
permise par `MachineEtatsPaiement` (seul `PENDING` y mène). Le paiement réel passe par `PENDING` à
l'ouverture du checkout (`ServiceGeniusPay::executer()`) ; le harnais ne le reproduisait pas, donc
`appliquer()` refusait silencieusement la transition du paiement (tout en terminant l'événement
webhook en `TRAITE`), et aucun événement de domaine ne partait jamais — invisible aux vecteurs plus
anciens, qui ne vérifient que `evenement.getStatutTraitement()`, jamais l'historique du `Paiement`.
Corrigé (`paiement.setStatut(PENDING)` avant le webhook).

### 8.2 G3 — résultats

Java : tests ciblés verts, **suite complète verte** (1 échec confirmé être un flake de contention
réseau — `AdaptateurGeniusPayTest`, vert isolément, non lié à B4). PHP : 39 tests ciblés verts, suite
complète Laravel **1702/1702, 17 882 assertions, 0 échec**. **Campagne de mutation PHP : 7 tueuses +
1 témoin volontairement vert** sur les gardes canal/statut/résolution/frais-inconnus/idempotence/
pays/secret-manquant — chaque mutation assertée appliquée, arbre restauré et vérifié par `diff`.
Pint propre sur tous les fichiers touchés (baseline établie contre `HEAD` avant tout formatage).

### 8.3 G2 — live, réel, résultats

Base MySQL réelle. Java **réellement démarré** (docker compose, Postgres+Redis réels — l'image a dû
être reconstruite depuis zéro, ~20 min sous ce poste). `php artisan serve` réel. Un établissement
réel créé (`StructureSanitaire` id 18, `CI-ETS900010`), un marchand GeniusPay réellement enregistré
(réutilisant les identifiants sandbox du lot 7), un secret webhook réellement déposé.

**Chaque brique nouvelle de B4-a prouvée avec des données réelles, jamais simulées à l'intérieur du
test** :
- `GET /marchands/{ref}` (S7) : `configure:false` avant dépôt du secret, `configure:true` après —
  en réel, sur le nouvel endpoint.
- `ClientPaiementGeniusPay::estConfigure()` : premier appel réseau réel (**28,5 s**, sous la
  contention de ce poste), second appel servi par le cache (**4 ms**) — le contraste **est** la
  preuve que le cache évite un second aller-retour réseau.
- **Deux checkouts GeniusPay réellement ouverts en bac à sable** (vraie `checkoutUrl`, vraie
  `referencePasserelle` type `SANDBOX_…`) contre le compte sandbox du lot 7 réutilisé pour un
  nouveau marchand. Un troisième essai a expérimenté `INITIEE_INCERTAINE` (délai réseau réel sous
  charge) — comportement de sécurité **correct et attendu** (§7.5.3, aucun second appel), pas un
  défaut.
- **Deux webhooks `payment.success` crafted à la main mais signés avec le VRAI secret déposé**,
  POST réel sur `/api/v1/paiement-webhooks/geniuspay/{slug réel}` → signature vérifiée, transition
  `INITIEE_INCERTAINE→REUSSIE` réelle (autorisée par `MachineEtatsGeniusPay`, prévue pour ce cas
  exact), frais et canal réellement extraits du webhook.
- **Notification réellement relayée** par le scheduler Java automatique (`PlanificateurNotifications`,
  pas seulement l'endpoint manuel) — appel HTTP réel, signé par `SigneurPrincipalSortant` (Java),
  vérifié par `VerificateurPrincipalSigne` (PHP) : preuve croisée Java→PHP du principal signé, sens
  jamais exercé en réel avant B4 (le sens PHP→Java l'a été via `estConfigure()` ci-dessus).
- **Deux défauts réels trouvés PAR LE G2, invisibles aux 1741 tests verts** : (1) `baremes_commission`
  vide sur la base de dev réelle → `RuntimeException` réelle, journalisée, **rien écrit** — le
  comportement attendu de R10/S3, mais qui a fallu être déclenché en vrai pour être vu ; corrigé par
  `BaremesCommissionSeeder`. (2) **ma propre migration `frais_connus` n'avait jamais été rejouée sur
  la vraie base MySQL** — seulement sur SQLite via `RefreshDatabase` en test. `SQLSTATE[42S22]:
  Column not found` réelle, journalisée, **rien écrit**. Corrigé par `artisan migrate --force`.
  *Aucun des deux n'était visible en test parce que les deux bases partent toujours neuves en test —
  c'est précisément ce que le G2 live existe pour attraper.*
- **Après correction, un troisième cycle complet a produit une commission RÉELLE** :
  `structure_sanitaire_id=18`, `montant_brut=18000`, `frais_passerelle=200` (du webhook),
  `frais_connus=true`, `taux_bps_applique=250`, `montant_commission=450` (18000×250/10000, exact),
  `montant_net_structure=17350` (18000−200−0−450, exact), `reference_interne_paiement=
  'geniuspay-paiement:9ba137b9-…'`.
- **Trois refus/garanties prouvés en direct**, en appelant l'endpoint Laravel réel avec un principal
  réellement signé par `SigneurPrincipalSortant` : `canal=carte` (même établissement réel, statut
  SUCCESS) → **0 commission créée** ; `etablissementRef` inconnu (`CI-ETS999999`) → **0 commission
  créée** ; **rejeu exact** de la notification qui avait créé la commission (même `paiementId`,
  montant et frais falsifiés à 999999/999) → **0 seconde commission, montant inchangé à 18000**.

**Ce qui n'a pas été refait en direct, et pourquoi c'est suffisant** : les vecteurs négatifs
(canal≠geniuspay, établissement inconnu, rejeu) ont été exercés contre l'endpoint Laravel réel avec
un principal réellement signé, MAIS sans repasser par un troisième cycle GeniusPay complet — le
CANAL Java→Laravel pour CES cas précis (un `canal` autre que `geniuspay`, ou un `etablissementRef`
non résoluble) est déjà couvert par la campagne de mutation Java (`canal` recopié tel quel de
l'agrégat, jamais deviné) et par les tests JUnit dédiés ; ce que le G2 devait prouver ici est le
comportement du **contrôleur Laravel réel** face à ces payloads, ce qui est fait.

**Données de test conservées en base de développement** (décision par défaut, aucune instruction
contraire) : établissement `CI-ETS900010` (« Officine G2 B4-a », id 18), 3 factures Java, 1
commission réelle. Nommées et scopées sans ambiguïté (motif P5.6b phase 6). Java et Laravel laissés
**démarrés** pour permettre au propriétaire de rejouer ou d'inspecter au G4.

**G3 et G2 sont faits.**

### 8.4 G4 — validé par le propriétaire

**Le propriétaire a réalisé son propre test réel et l'a validé le 2026-09-04** (« G4 validé »).
Java et Laravel étaient laissés démarrés depuis le G2, avec les données réelles produites (§8.3)
encore en base.

**Documentation écrite dans la foulée** (elle était délibérément différée jusqu'ici, pour que
d'éventuelles remarques du G4 y trouvent leur place) : `docs/adr/ADR-056-paiement-en-ligne-geniuspay.md`
(amende `[[ADR-013]]` et ADR-044), guide `GUIDE_TEST_APPLICATIONS_METIER.md` partie 15, index mis à
jour.

### 8.5 G5 — validé

**Le propriétaire a écrit « c'est bon pour le G5 » le 2026-09-04.** B4-a (le canal) est
**VALIDÉ G5**. B4-b (le rendez-vous) reste à faire ; B3-d peut désormais s'y brancher une fois B4-b
livré.
