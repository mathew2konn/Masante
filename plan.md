# Plans de travail et d'exécution — MaSanté (IVOIRSANTÉ)

> Un bloc par réflexion, sous un grand titre numéroté. **On n'efface jamais un plan clos** : on le
> marque terminé et on garde son contenu, parce qu'un plan dit *pourquoi* on a fait ce qu'on a fait.
>
> Règle de tenue (`CLAUDE.md`) : **décision → `CLAUDE.md` → `plan.md` → exécution → `handoff.md`**.

| Plan | Sujet | État |
|---|---|---|
| **PLAN 1** | B3-c — Code-barres + traçabilité nationale des médicaments (CDC_11 §7.6) | ✅ **Terminé — VALIDÉ G5 le 2026-09-04**, G4 propriétaire OK |
| **PLAN 2** | B3-d — Panier et commande de médicaments (CDC_11 §9.5, §10.5 · CDC_01 §6.6) | ⏸️ **G1 rédigé, EN ATTENTE** — dépend désormais du PLAN 3 pour son règlement en ligne. Aucun code écrit. |
| **PLAN 3** | B4 — Paiement en ligne réel (GeniusPay) : canal Laravel→Java, commission, et le rendez-vous | ✅ **B4-a (le canal) : VALIDÉ G5 le 2026-09-04. B4-b (rendez-vous) : VALIDÉ G5 le 2026-09-05** (G4 propriétaire OK « G4 validé », G5 « c'est bon pour le G5 »). **Le lot B4 est COMPLET (a, b).** |
| **PLAN 4** | B5 — Le circuit du laboratoire (CDC_11 §8.1 · CDC_09 §7.4 · CDC_04 §109), périmètre intégral | 🔵 **B5-a VALIDÉ (G5, 2026-09-05)**, G4 propriétaire OK. **EN COURS** — B5-b puis B5-c restent. |

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
**ADR** : ADR-055 (§11 à écrire) et ADR-056 (amendé — troisième émetteur du canal). **Guide** :
`GUIDE_TEST_APPLICATIONS_METIER.md`, **partie 14**.
**Date du G0** : 2026-09-04, **repris et F6 réécrit le 2026-09-05** après PLAN 3 (VALIDÉ G5).
**G1 VALIDÉ (« je valide », 2026-09-05).** Exécution en cours, ordre de §9.

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

### F6 — RÉÉCRIT AU 2026-09-05, APRÈS B4 VALIDÉ G5 : le règlement en ligne emprunte le canal RÉEL, la commission suit sans nouvel appel

> **Ce que F6 disait avant B4** : deux modes, `sur_place` exonéré et `en_ligne` **simulé** (frais à 0
> inventés) appelant `CommissionService` **directement** depuis Laravel, sous un drapeau
> `commande.reglement_en_ligne` gaté OFF — parce qu'un encaissement fictif facturé à un partenaire
> aurait été malhonnête. **Ce plan reste juste dans son analyse, mais il est devenu inutile** : B4
> (VALIDÉ G5 le 2026-09-05) a construit exactement le canal RÉEL que ce texte reportait à « un lot
> transversal ». B3-d n'a plus à choisir entre « simulé et gaté » ou « rien » : il peut **réutiliser
> le canal prouvé**, sans drapeau et sans encaissement fictif.

**Ce que le canal réel de B4 apporte, déjà construit et déjà prouvé G5** : `ResolveurEtablissementRef`
(structure → référence), `ClientPaiementGeniusPay::estConfigure()/creerFacture()/initierCheckout()`,
et surtout **`PaiementNotificationController::calculerCommissionSiApplicable()`**, qui calcule une
commission sur **TOUT succès `canal=geniuspay` portant un `etablissementRef` résoluble — quel que
soit ce qui est payé**. Ce mécanisme a été conçu **générique dès B4-a** (il sert déjà une facture
*et* un rendez-vous, sans distinction) : **B3-d n'a rien à ajouter à `CommissionService`, rien à
appeler depuis le domaine commande — il lui suffit de faire passer le règlement en ligne par le
VRAI checkout, et la commission arrive avec le webhook, exactement comme pour le rendez-vous.**

→ La commande porte toujours un **mode de règlement** : `sur_place` ou `en_ligne` — la table F6
d'origine reste vraie, seule sa colonne « Ce qui se passe » change :

| Mode | Ce qui se passe | Commission |
|---|---|---|
| `sur_place` | La plateforme ne touche à rien, **littéralement aucun appel réseau** — le §9.6 vérifié **par construction**, pas par une exonération calculée | **Aucune** — aucune notification n'est jamais générée pour ce mode, donc `calculerCommissionSiApplicable()` ne s'exécute jamais |
| `en_ligne` | Checkout GeniusPay **réel** ouvert sur l'officine (mécanisme B4-b, transposé) ; la commande devient réglée **à la notification**, jamais avant | **Calculée automatiquement** par le mécanisme B4-a existant, sur des frais **réels** |

**AUCUN DRAPEAU GLOBAL** (`commande.reglement_en_ligne` disparaît du plan) : même raison que S7 de
PLAN 3 pour le rendez-vous — la disponibilité est une **propriété de l'officine** (identifiant
national + marchand GeniusPay déclaré), interrogée par le même `estConfigure()`, jamais un
interrupteur binaire pour toute la plateforme. *Le motif du drapeau (protéger contre une commission
réelle sur un encaissement fictif) disparaît avec l'encaissement fictif lui-même.*

**Pourquoi PAS `factures_patient` — correction d'une anticipation écrite pendant B4-b, revue ici.**
En construisant le règlement du rendez-vous, `RecuRdvService::confirmerReglementEnLigne()` a reçu un
commentaire anticipant que B3-d réutiliserait **la même table** (`FacturePatient`, même préfixe de
corrélation `facture-patient:`) — écrit sans repasser par le G0 de B3-d. **Revu et corrigé ici** :
`factures_patient` est une table de facturation **DE SOINS** (`moment_paiement`, prise en charge
CMU, `reste_a_charge`) — l'arbitrage déjà rendu pour ce lot (§10 ci-dessous, avant même B4) dit
explicitement qu'une commande de médicaments **n'est pas un acte** au sens de cette table, précisément
parce qu'une vente libre n'a ni CMU ni tarif de consultation. Y loger le règlement d'une commande
romprait cette distinction déjà actée. → **`commandes` porte SON PROPRE règlement**
(`mode_reglement`, `regle_le`, `reference_reglement`, **`commande_geniuspay_id`** — nouvelle colonne,
miroir de `facture_geniuspay_id`), avec son **propre préfixe de corrélation `commande:`**,
structurellement PARALLÈLE au mécanisme RDV mais jamais confondu avec lui. **`CommissionService`
n'a besoin d'aucun lien vers la commande** : son idempotence (`reference_interne_paiement =
'geniuspay-paiement:{paiementId}'`) suffit à elle seule, et lui ajouter une FK `commande_id` serait
une donnée que rien ne lirait — même règle que « pas de socle à vide » (P6.3-D3), appliquée à
l'envers (pas de colonne à vide).

**Mécanisme, transposé terme à terme de `RecuRdvService` (B4-b), sur `ServiceCommande` (patient)** :

1. `disponibiliteEnLigne(Commande $commande): bool` — `ResolveurEtablissementRef::formater()` sur
   l'officine de la commande, puis `estConfigure()`. Identique à `RecuRdvService::disponibiliteEnLigne()`.
2. `ouvrirPaiementEnLigne(Commande $commande): array` — refuse si la commande n'est pas `acceptee`
   (inchangé de F6 d'origine : *on ne paie pas avant de savoir que la pharmacie peut servir*), si
   `montant_indicatif_cfa` est nul, ou si l'officine n'est pas configurée. Crée une **vraie** Facture
   Java (`creerFacture()`, une ligne, TVA 0 %, libellé « Commande #{référence} ») **une seule fois**
   (`commande_geniuspay_id` réutilisé aux tentatives suivantes — même raison qu'en B4-b :
   `ServiceWebhookGeniusPay::appliquer()` exige une vraie Facture, sans quoi le règlement échoue en
   silence). Ouvre le checkout avec `correlationId = 'commande:'.$commande->id`, **`objet` =
   `ORDONNANCE` si `ordonnance_id` est renseigné, `AUTRE` sinon** — deux valeurs **déjà présentes**
   dans l'enum Java `ObjetPaiement` (vérifié : `RENDEZ_VOUS, ORDONNANCE, ANALYSE, RADIOLOGIE,
   HOSPITALISATION, AMBULANCE, ASSURANCE, CNAM, FACTURE, ABONNEMENT, AUTRE`), **zéro ligne Java
   touchée** — ce champ ne sert qu'à tracer (docblock Java lui-même : « ne fait que les tracer »).
3. `confirmerReglementEnLigne(int $commandeId, string $paiementIdExterne, string $dateTransaction): void`
   — sous verrou (`lockForUpdate`), idempotent (`regle_le` déjà posé → no-op silencieux), pose
   `regle_le` et `reference_reglement = $paiementIdExterne`.
4. `commandeIdDepuisCorrelation(?string $correlationId): ?int` — parse le préfixe `commande:`,
   `null` sinon. Même forme que `facturePatientIdDepuisCorrelation()`.
5. **`PaiementNotificationController` gagne un TROISIÈME dispatch**, `reglerCommandeSiApplicable()`
   — structurellement parallèle à `reglerFacturePatientSiApplicable()` (même garde canal/statut),
   appelant `ServiceCommande::confirmerReglementEnLigne()`. **Les trois dispatches restent
   indépendants** (le docblock du contrôleur le dit déjà pour les deux premiers ; le troisième
   applique la même règle).

**DÉFAUT RÉEL TROUVÉ EN RELISANT `PaiementNotificationController` AVANT D'ÉCRIRE UNE LIGNE (G0 de
B3-d, pas un test rouge) : les trois dispatches ne sont PAS réellement indépendants aujourd'hui.**
`__invoke()` appelle `calculerCommissionSiApplicable()` **avant** `reglerFacturePatientSiApplicable()`,
**sans aucun `try/catch`** — si le calcul de commission lève (`baremeActif()` sans palier couvrant le
volume, ou toute autre `RuntimeException`/`InvalidArgumentException` du service), **la méthode
entière s'arrête**, et le règlement de la facture/du rendez-vous **n'est jamais atteint**. Le
docblock de la classe affirme pourtant : « un paiement peut régler une facture sans jamais avoir
d'`etablissementRef` résoluble… et réciproquement » — vrai pour les GARDES (`if` qui rendent tôt),
**faux pour une EXCEPTION non gardée**. B3-d ajoute un troisième dispatch au même point de défaillance
: sans correction, une base sans barème actif **bloquerait aussi le règlement d'une commande**, pas
seulement sa commission. → **Corrigé en même temps** (correction chirurgicale sur un fichier
validé G5, additive, zéro changement de comportement pour les deux dispatches existants dans le cas
nominal) : `calculerCommissionSiApplicable()` encapsulée dans un `try/catch (\Throwable)` qui
journalise en `error` et laisse `__invoke()` continuer — la commission peut échouer sans jamais
empêcher un patient d'être livré ou un rendez-vous d'être honoré. **Vecteur dédié** : bareme manquant
+ notification de règlement dans le MÊME appel → la commande (ou le RDV) est réglée, la commission
absente, l'échec journalisé.

**Le montant reste FIGÉ à la commande** (`montant_indicatif_cfa`) : c'est lui, sans recalcul, qui est
envoyé à GeniusPay au moment du checkout — il cesse d'être *seulement* indicatif à cet instant précis
(il devient le montant réellement demandé), mais reste ce que le patient a vu avant de payer, jamais
un second calcul (patron `tarifPour()` de B1-b).

**Ce que B3-d ne fait toujours PAS** : rien à ajouter — le « lot transversal » que l'ancien F6
reportait (brancher GeniusPay pour de vrai) **est PLAN 3, déjà livré et validé G5**. Il n'y a plus de
dette à nommer ici.

> **Prérequis de déploiement, inchangé mais déjà satisfait sur la base de dev** : `BaremesCommissionSeeder`
> a été rejoué pendant le G2 de B4-a/B4-b (`baremes_commission` n'est plus vide sur `ivoirsante`) —
> resterait un prérequis réel sur une base neuve.

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
| `montant_indicatif_cfa` | unsigned int, nullable | F6. `null` si aucun prix connu — *on ne fabrique pas un montant* ; devient le montant RÉEL envoyé à GeniusPay au checkout, jamais recalculé |
| `mode_reglement` | enum(`sur_place`,`en_ligne`) | F6. Défaut `sur_place` |
| `regle_le` | datetime nullable | Non nul = réglé. **Aucun état `payee` dans le cycle** : le règlement est un fait distinct de l'avancement de la commande, et les mélanger rendrait insoluble « acceptée mais pas encore payée » — patron `estRegle()` de B1 |
| `reference_reglement` | string(30) nullable, **unique** | F6 (réécrit) — posé par `confirmerReglementEnLigne()` au `paiementIdExterne` reçu à la notification GeniusPay réelle ; garde l'idempotence de la commande elle-même, indépendante de celle de `CommissionService` |
| `commande_geniuspay_id` | string(36) nullable | F6 (réécrit) — miroir exact de `factures_patient.facture_geniuspay_id` (B4-b) : l'id de la vraie Facture Java, créée une fois et réutilisée à chaque tentative de checkout |
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
| `App\Services\Medicament\ServiceCommande` | Le patient passe, règle et annule. Porte les gardes F2, F3, F4, F7, F8, F11, **et désormais F6** : `disponibiliteEnLigne()`, `ouvrirPaiementEnLigne()`, `confirmerReglementEnLigne()`, `commandeIdDepuisCorrelation()` — transposition terme à terme de `RecuRdvService` (B4-b) |
| `App\Services\ClientPaiementGeniusPay`, `App\Services\ResolveurEtablissementRef` | **RÉUTILISÉS TELS QUELS** (B4) — `ServiceCommande` les injecte, ne les modifie pas. Zéro ligne touchée dans l'un ou l'autre |
| `App\Services\CommissionService` | **NI APPELÉ NI MODIFIÉ par le domaine commande** — F6 (réécrit) : la commission suit automatiquement via le mécanisme déjà générique de `PaiementNotificationController` (B4-a), qui ne sait même pas qu'une commande existe |
| `App\Http\Controllers\Api\V1\Interne\PaiementNotificationController` | **Gagne un troisième dispatch** `reglerCommandeSiApplicable()`, parallèle à `reglerFacturePatientSiApplicable()` ; **`calculerCommissionSiApplicable()` encapsulée en `try/catch`** (défaut réel trouvé en relisant le fichier, F6) |
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
| `CommissionService` | **Aucun changement, aucun appel depuis le domaine commande** (F6 réécrit) — la commission arrive par le mécanisme B4-a déjà générique | Nul |
| `ClientPaiementGeniusPay`, `ResolveurEtablissementRef` | **Aucun changement** — injectés dans `ServiceCommande`, réutilisés tels quels | Nul |
| `PaiementNotificationController` | **+1 dispatch** `reglerCommandeSiApplicable()` ; `calculerCommissionSiApplicable()` encapsulée en `try/catch` (défaut trouvé au G0, F6) | Fichier validé G5 (B4-a) — correction **additive**, comportement nominal des deux dispatches existants inchangé, vecteur dédié à écrire |
| `factures_patient` | **Aucun changement** — B3-d ne l'utilise PAS (correction d'une anticipation erronée écrite pendant B4-b, voir F6) | Nul |
| Mobile : `ComparateurEcran` | **+ un bouton « Ajouter au panier »** sur chaque offre | Écran validé G5 — modification **additive** seulement |

**Ce qui ne bouge pas, et c'est explicite** : `prix_pharmacie`, `stocks_officine`,
`mouvements_stock`, `delivrances`, `traces_dispensation`, `ordonnances`, `ordonnance_lignes`,
`factures_patient`. B3-d **consomme** tout ce que B3-a/b/c ont construit **et le canal réel de B4**,
sans en modifier le cœur.

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
11. **`sur_place` → AUCUN appel réseau, `commande_geniuspay_id` reste NULL, `commissions_transaction`
    ne gagne aucune ligne.** Vecteur qui espionne `ClientPaiementGeniusPay` (mock/spy) et vérifie
    qu'il n'est jamais sollicité — le §9.6 prouvé par l'ABSENCE, patron du vecteur central de B3-a.
12. **`en_ligne` refusé avant acceptation** de la commande, et **refusé si l'officine n'est pas
    configurée GeniusPay** — chacun **par son motif**, jamais par un code partagé (patron
    `ouvrirPaiementEnLigne` du RDV).
13. **Checkout réel ouvert deux fois → une seule Facture Java créée** (`commande_geniuspay_id`
    inchangé au second appel) — même vecteur que B4-b, transposé.
14. **Notification de succès GeniusPay (canal `geniuspay`, `correlationId = 'commande:{id}'`) →
    `regle_le`/`reference_reglement` posés sur la commande, ET une `CommissionTransaction` réelle
    créée pour l'officine** — MÊME mécanisme que B4-b, vecteur qui le PROUVE plutôt que de le
    recoder : **les deux dispatches du contrôleur s'exécutent dans le MÊME appel**, sur la MÊME
    notification.
15. **Rejeu de la même notification (même `paiementId`) → aucune seconde commission, `regle_le`
    inchangé sur la commande** (idempotence à deux niveaux indépendants — `CommissionService` et
    `ServiceCommande::confirmerReglementEnLigne()` — chacun vérifié séparément en base, patron B4-b).
16. **`objet` transmis à Java** vaut exactement `ORDONNANCE` quand la commande porte un
    `ordonnance_id`, `AUTRE` sinon — vecteur qui vérifie la valeur littérale envoyée (garantit
    l'absence de régression sur l'enum Java sans y toucher).
17. **Le défaut de couplage corrigé** : bareme de commission manquant (`baremeActif()` lève) **dans
    le MÊME appel** qu'une notification de règlement de commande → la commande est réglée quand
    même, l'échec de commission est journalisé, **aucune exception ne remonte au webhook**.

**G2 (live)** — base MySQL réelle sauvegardée puis **restaurée compte pour compte**, `artisan serve`
réel, chaîne HTTP réelle avec session et CSRF côté portail, jeton Sanctum réel côté patient :
un parcours complet **commande → acceptation → prête → remise**, les refus **chacun par son motif**,
les gardes du moteur éprouvées en SQL direct (`ERROR 1644`, `ERROR 1062`), et la vérification que
`prix_pharmacie`/`traces_dispensation` sont dans l'état attendu.

---

## 8. Limites qui seront annoncées

1. **Le règlement en ligne dépend entièrement de la disponibilité GeniusPay de l'officine**
   (identifiant national + marchand déclaré — même limite que le rendez-vous, S7 de PLAN 3) :
   **aucun interrupteur global**, le seul recours d'exploitation est le compte marchand de
   l'officine. *Cette limite REMPLACE l'ancienne (encaissement simulé, drapeau gaté OFF) —
   devenue sans objet depuis que B4 a livré le canal réel.*
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
5. **Le règlement en ligne** (F6, réécrit) : `disponibiliteEnLigne()`/`ouvrirPaiementEnLigne()`/
   `confirmerReglementEnLigne()`/`commandeIdDepuisCorrelation()` sur `ServiceCommande`, **après** le
   cycle (n'est possible qu'une fois la commande `acceptee`) ; le 3ᵉ dispatch + le `try/catch` sur
   `PaiementNotificationController`.
6. Permission + notifications.
7. API patient (Sanctum, dont `GET/POST /commandes/{id}/paiement-en-ligne`) + écran portail (Blade).
8. Mobile : store panier, bouton au comparateur, écran commande, « mes commandes », ouverture du
   checkout dans le navigateur (`Linking.openURL`, patron B4-b S8 — jamais une WebView).
9. G3 : vecteurs + campagne de mutation + Pint (baseline établie **avant** tout formatage) +
   typecheck ×3 + `expo-doctor`.
10. G2 live sur MySQL réelle — checkout GeniusPay **réellement ouvert** en bac à sable sur l'officine
    déjà configurée (`CI-ETS900010`, réutilisée de B4), webhook réel, commission réelle vérifiée en
    base, puis restauration vérifiée compte pour compte.
11. Documentation : ADR-055 §11 (amende aussi ADR-056 — B3-d en devient le TROISIÈME émetteur du
    canal, après la facture et le rendez-vous), guide partie 14, `CLAUDE.md`, `plan.md`, `handoff.md`.

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

**Aucune ligne de code n'a été écrite avant validation de ce plan.**

> **Note (2026-09-05)** : la colonne « Suite » de l'arbitrage A ci-dessus décrivait l'implémentation
> **simulée** envisagée à l'époque (trois colonnes, une quatrième garde, six vecteurs). §12 ci-dessous
> la remplace par l'implémentation **réelle**, une fois PLAN 3 livré — la DÉCISION de l'arbitrage
> (deux modes, commission au règlement en ligne) est **inchangée** ; seule sa réalisation technique
> est plus simple que prévu.

---

## 11. Report — PLAN 2 a attendu PLAN 3 (décision propriétaire, 2026-09-04, refermé le 2026-09-05)

> « on va brancher les paiements sur GeniusPay et aussi le brancher au rendez-vous »

Le propriétaire a demandé le **lot transversal** que §F6 nommait hors périmètre. Il est devenu
**PLAN 3** (B4-a le canal, B4-b le rendez-vous), **VALIDÉ G5 le 2026-09-05** — voir `plan.md` PLAN 3.
**PLAN 2 reprend** à cette date : F1→F5 et F7→F12 n'ont pas bougé ; **F6 a été réécrit** (§12) pour
brancher le règlement en ligne sur le canal réel plutôt que sur un mécanisme simulé.

---

## 12. Reprise (2026-09-05) — ce qui change concrètement avec PLAN 3 livré

**Ce G0 tient en une phrase** : tout ce que l'ancien F6 devait CONSTRUIRE (un encaissement, fût-il
simulé, plus un appel explicite à `CommissionService`, plus un drapeau pour ne pas facturer un
partenaire sur du fictif), le canal de PLAN 3 le fait déjà, pour de vrai, sans qu'on lui demande
rien de plus qu'un `correlationId`.

**Trois conséquences vérifiées avant d'écrire quoi que ce soit** :

1. **`CommissionService::calculerEtEnregistrer()` reste APPELÉ PAR UN SEUL ENDROIT**
   (`PaiementNotificationController::calculerCommissionSiApplicable()`), **avant et après B3-d** —
   vérifié par recherche exhaustive (`grep`) sur tout `app/`. B3-d ne lui ajoute pas un second
   appelant : il ajoute un second `correlationId` que ce même endroit sait déjà traiter par
   construction (il ne discrimine que sur `canal`/`statut`/`etablissementRef`, jamais sur *ce qui*
   est payé).
2. **`regleEnLigne` vaut toujours `true` dans le seul appel réel existant** (ligne 199 du
   contrôleur, valeur littérale, jamais une variable) — la branche `estPharmacieHorsLigne` de
   `CommissionService` reste, comme avant B3-d, atteignable **seulement** par les tests unitaires
   directs du service. **Ce n'est pas un défaut introduit par B3-d** : un règlement `sur_place` ne
   génère, PAR CONSTRUCTION, aucune notification GeniusPay — donc aucun appel où faire vivre
   `regleEnLigne = false` n'existera jamais en production, avec ou sans B3-d. Dit plutôt que
   silencieusement contourné.
3. **Le défaut de couplage entre les deux dispatches existants** (§F6 ci-dessus) — trouvé en
   relisant `PaiementNotificationController` pour savoir OÙ brancher le troisième dispatch, pas en
   le cherchant pour lui-même. Sans le corriger, B3-d aurait hérité d'un mode d'échec qu'il n'a pas
   créé mais qu'il aurait rendu deux fois plus probable de toucher (deux domaines métier, RDV et
   commande, désormais suspendus au même appel non protégé).

**Le plan F6 ci-dessus (§3) est la version finale, à valider.**

## 13. G1 validé (« je valide »), exécution, G3, G2 live

**Exécuté tel que F6 réécrit le prévoyait**, sans écart de conception : `Commande`/`CommandeLigne`
(migration `2026_09_05_000001_commandes_medicaments.php`, 4 déclencheurs dual-dialecte),
`StatutCommande`/`ModeRetraitCommande`/`ModeReglementCommande` (enums PHP + `@masante/shared`, garde
anti-divergence `CommandeEnumsSourceUniqueTest`), `ServiceCommande` (patient — F3 double garde
renforcée, F6 réel, F9/F10 côté remise), `ServiceTraitementCommande` (pharmacien — permission
`commande.traiter`), `CommandeController`/`CommandeClientController`, routes Sanctum + Blade,
`PortailRolesSeeder` (+`commande.traiter`), vues Blade, écrans mobiles (panier Zustand, commandes).

**DEUX BUGS `$fillable` TROUVÉS, UN PAR LES TESTS, UN SEULEMENT PAR LE G2 LIVE** (famille
P6.7b/B2-b/B3-b) : `Commande::$fillable` omettait `statut`/`montant_indicatif_cfa`/... (trouvé par
des assertions rouges) ; **`CommandeLigne::$fillable` omettait `medicament_id`** — invisible aux
20 tests automatisés (SQLite et MySQL tolèrent tous deux plusieurs `NULL` sous
`UNIQUE(commande_id, medicament_id)`, donc l'index restait inerte sans qu'aucun test ne le
remarque), **trouvé uniquement par inspection SQL directe au G2 live** (`medicament_id=NULL` sur une
ligne réellement créée), qui aurait cassé en silence `sortirVenteLibre()` en production (elle
s'arrête net si `medicament_id` est nul). Corrigé, un vecteur de régression ajouté
(`test_medicament_id_est_reellement_enregistre_sur_la_ligne`), la garantie reprouvée en direct
(nouvelle commande créée par l'API réelle → `medicament_id` correctement persisté).

**Mutation manuelle — 11 mutations, 11/11 conformes** (script PowerShell, méthodologie à 6 règles
[sauvegarde, unicité de l'ancre, vecteur vert AVANT mutation, mutation vérifiée appliquée sur
disque, filtre PHPUnit ciblé, restauration byte-identique]) : 10 tueuses sur la garde F3 double
(M1, M2), la garde livraison F7 (M3), la garde montant-connu (M4), la garde statut F6 (M5), le
partage F9 (M6), la garde permission F10 (M7), le cycle `assertStatut` (M8), l'anti-IDOR
`assertOfficine` (M9), et **le correctif try/catch de `PaiementNotificationController`** (M10 —
`catch (Throwable $e)` muté en `catch (\LogicException $e)`, reproduisant le bug d'origine : la
`RuntimeException` de `baremeActif()` sans palier redevient non rattrapée) ; 1 témoin (T1, permutation
de deux affectations indépendantes) resté vert. **11/11.**

**Régression complète** : `php artisan test` → **1764/1764** (1 échec transitoire
`PermissionsSourceUniqueTest`, `commande.traiter` manquant côté `@masante/shared` — corrigé, 2/2).
Pint propre sur tout le code neuf, baseline `HEAD` respectée sur le code modifié préexistant.

**G2 live — inventaire du parcours réel joué le 2026-09-05**, sur l'officine 18
(`CI-ETS900010`, déjà GeniusPay-configurée depuis B4-a), Java/MySQL/`artisan serve` hérités déjà
démarrés de la session B4-b :

1. Stock réel entré (`entree` 50 Doliprane, 30 Clamoxyl) via le vrai portail — vérifié en base.
2. Commande ordonnance-liée (id 4, Clamoxyl ×2, `ordonnance_ligne_id` réel) créée par l'API Sanctum
   réelle → cycle réel `accepter → preparer → remettre` via le portail pharmacien → **`Delivrance`
   réelle créée** (id 1, chemin B3-a inchangé), **`traces_dispensation` réelle créée** (medicament 33,
   quantité 2, chemin B3-c inchangé), **mouvement de stock réel** `sortie -2` avec `delivrance_id=1`.
3. Commande vente-libre (id 3, Doliprane ×2) → même cycle → **mouvement de stock réel** `sortie -2`,
   motif « Remise d'une commande », **`delivrance_id` NULL, aucune trace créée** — F9 vérifiée en
   réel dans les deux sens.
4. Commande (id 2) → refus réel avec motif (« Rupture de stock prolongee ») via le portail.
5. **DÉFAUT RÉEL DE CONTRAT TROUVÉ EN DIRECT, absent de tout vecteur** : `ouvrirPaiementEnLigne()`
   sur une commande à 1500 CFA a été refusée par le microservice Java lui-même
   (`422 : « Le paiement en ligne n'est pas disponible sous 5000 FCFA. »`) — un minimum de montant
   du côté GeniusPay/Java, jamais documenté côté B3-d ni côté B4, découvert seulement en tentant un
   vrai checkout. Contourné pour la suite du G2 en composant une commande à 6000 CFA (id 6) ; **le
   plan ni le code ne posent aujourd'hui de garde CÔTÉ LARAVEL sur ce plancher** — limite ajoutée
   ci-dessous, pas corrigée dans ce lot (le refus Java protège déjà, seul le message affiché au
   patient resterait à améliorer si ce plancher devient un vecteur produit).
6. Commande 6 acceptée → `ouvrirPaiementEnLigne()` réel : **vraie Facture Java créée**
   (`f45246b3-…`, stockée sur `commande_geniuspay_id`), **vrai checkout GeniusPay sandbox ouvert**
   (`https://geniuspay.ci/checkout/SANDBOX_GY5KXLJ8Y7QYSOCC`), `mode_reglement` basculé à `en_ligne`
   en base.
7. **Règlement réel** : notification interne signée (principal réel via `SigneurPrincipalSortant`,
   même mécanisme que les tests, envoyée par un vrai `curl` HTTP au `artisan serve` réel, hors
   PHPUnit) avec `canal=geniuspay`, `etablissementRef=CI-ETS900010`, `correlationId=commande:6` →
   `regle_le`/`reference_reglement` réellement posés en base.
8. **Commission réelle créée par le mécanisme GÉNÉRIQUE de B4-a, sans aucun appel neuf depuis le
   domaine commande** — vérifiée en base (`commissions_transaction` id 3 : `montant_brut=6000`,
   `taux_bps_applique=250`, `montant_commission=150`, `facture_patient_id NULL`) : **la preuve
   centrale de F6**.
9. **Idempotence réelle à deux niveaux** : rejeu exact de la même notification (même `paiementId`)
   → toujours 3 commissions (aucune seconde créée), `regle_le` inchangé ; nouvelle tentative de
   paiement sur la commande 6 déjà réglée → refus réel (« Cette commande a déjà été réglée. »).

**Base de dev conservée** (précédent B4-a/B4-b) : commandes 1 à 6, mouvements de stock, délivrance,
trace, commission réelles laissées en l'état pour le G4 du propriétaire. Scripts scratch
(`g2_setup_b3d.php`, `g2_notif_b3d.php`) à supprimer avant tout commit.

### Limite ajoutée par le G2 live, non prévue au plan

- **Aucun plancher de montant n'est vérifié côté Laravel avant d'appeler le microservice** — le
  Java refuse déjà (422, rien d'écrit), donc aucune donnée n'est jamais faussée ; mais le message
  d'erreur brut du microservice remonte tel quel au patient plutôt qu'un message applicatif nommant
  le montant minimal. Dette mineure, non corrigée dans ce lot (le refus est sûr, seule son
  élégance manque).

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

---

## 9. B4-b — G0 complémentaire et plan d'exécution (2026-09-04)

**Statut : G0 fait, G1 rédigé ci-dessous. Aucune ligne de code écrite. En attente de validation
écrite du propriétaire avant tout écrit.**

Le §3 (S1→S10) avait déjà esquissé B4-b en même temps que B4-a, au niveau du principe. Ce G0
relit ce squelette **contre le code réel tel que B4-a l'a laissé** (pas contre le squelette
lui-même) et trouve un défaut réel qu'aucune relecture du squelette seul n'aurait montré.

### 9.1 Le défaut trouvé : `estRegle()` compte une EXISTENCE, pas un ÉTAT

S5 (§3) prescrit : « le patient déclenche → `FacturePatient` **`A_REGLER`** + checkout ouvert.
Aucun reçu. » Mais `RecuRdvService::estRegle()`, la source de vérité qu'utilisent
`RendezVousValidationService::terminer()` (B1-d, clôture), `ServiceFicheParcours` (le champ
`rendez_vous_verifie`) et le `show()` du portail (`reglementVerifie`), est :

```php
public function estRegle(RendezVous $rdv): bool
{
    if (FacturePatient::where('rendez_vous_id', $rdv->id)->exists()) {
        return true;
    }
    return Paiement::where('rendez_vous_id', $rdv->id)->where('statut', 'paye')->exists();
}
```

Elle vérifie une **existence**, pas un **statut** — parce que jusqu'ici la seule façon de faire
naître une `FacturePatient` était `payer()`, qui la crée **déjà `PAYEE`**, dans la même
transaction que le reçu. L'existence ÉTAIT le règlement.

**Poser une `FacturePatient` `A_REGLER` au moment où le checkout s'ouvre casse cette équivalence** :
`estRegle()` répondrait `true` avant tout paiement réel. Le check-in lui-même n'en souffrirait pas
(il est atteint en scannant le QR du **reçu**, qui n'existe pas encore — la garde tient par un
autre chemin), mais **`terminer()` (B1-d) accepterait de clore un rendez-vous jamais payé**, et
`rendez_vous_verifie`/`reglementVerifie` mentiraient à l'écran. C'est exactement la classe de
défaut que ce projet nomme systématiquement à son G0 plutôt que de le découvrir au G2 : *un
invariant qui tenait par accident (« une seule façon d'écrire cette ligne ») cesse de tenir dès
qu'une seconde façon apparaît.*

**Correction, dans le même incrément** : `estRegle()` filtre désormais sur le **statut** —
`PAYEE` ou `PRISE_EN_CHARGE_TOTALE` — jamais la seule existence de la ligne. Vecteur dédié :
une `FacturePatient` `A_REGLER` seule → `estRegle()` rend `false` ; les vecteurs existants de B1
(qui ne construisent que des factures `PAYEE`) passent sans modification.

### 9.2 Le reste du chemin, revu à la lumière du code réel

**Une seule ligne `FacturePatient`, qui CHANGE d'état — jamais une seconde ligne créée à la
notification.** Le premier réflexe (créer la facture seulement à la notification, pour éviter le
défaut du §9.1) a été écarté : il contredirait S5 tel que validé au niveau du principe (« la
facture naît `A_REGLER` »), et il ferait de `factures_patient` une table où « facture » et
« paiement confirmé » seraient la même chose — exactement la confusion qu'`estRegle()` vient de
montrer. La bonne réponse n'est pas de reculer la création, c'est de corriger la garde qui la
supposait synonyme de paiement. `ouvrirPaiementEnLigne()` cherche donc d'abord une
`FacturePatient` `A_REGLER` déjà ouverte pour ce RDV (retaper « Payer en ligne » réutilise la
même ligne, jamais une seconde) ; `confirmerReglementEnLigne()` la fait passer `A_REGLER → PAYEE`
sur PLACE.

**`factureId` envoyé à Java est dérivé de l'id LARAVEL de cette `FacturePatient`, jamais aléatoire
et jamais stocké côté Java comme une relation vivante.** Le contrôleur Java (`GeniusPayController`)
exige un `UUID`, mais **rien, dans `ServiceGeniusPay`, ne le résout contre sa propre table
`Facture`** (vérifié : `executer()` le stocke tel quel sur `GeniusPayTransaction`, jamais un
`facturationRepository.findById(...)`) — c'est un identifiant de corrélation opaque, exactement le
régime de `triage_id`/`mesure_id` (ADR-042 D1), pas une clé étrangère. Aucune `Facture` Java
(`POST /api/v1/invoices`, module P5.2a) n'est donc créée pour un RDV — la créer serait fabriquer
un objet du domaine facturation Java sans qu'aucun écran n'en lise jamais le PDF/QR, le socle à
vide que ce projet refuse depuis P6.3-D3. Construction : 32 caractères hexadécimaux d'une
empreinte SHA-1 de `'facture-patient:'.$facture->id`, mis en forme 8-4-4-4-12 — zéro dépendance
(Laravel n'a pas de générateur d'UUID v5 nommé sans paquet ; `java.util.UUID.fromString()` ne
valide que la forme, jamais la version).

**`correlationId` porte l'identifiant Laravel en clair, sous un préfixe générique** :
`'facture-patient:'.$facture->id` — pas `'rdv:'.$rdv->id`. Une `FacturePatient` peut naître d'un
acte sans rendez-vous (le commentaire de sa propre migration le dit : « passage aux urgences,
achat en officine ») ; nommer le préfixe d'après le rendez-vous aurait fermé la porte que B3-d
(panier/commande) devra emprunter demain avec le même mécanisme. `Idempotency-Key` reste
**aléatoire à chaque appel** (`Str::uuid()`) : c'est le `factureId` stable, pas la clé
d'idempotence, qui fait réutiliser un checkout déjà ouvert côté Java
(`geniusPayTransactions.findByFactureId`, filtré sur les états `INITIEE`/`EN_ATTENTE`/`EN_COURS`/
`INITIEE_INCERTAINE`/`REUSSIE`) — et laisse Java ouvrir un checkout réellement neuf une fois l'ancien
sorti de ces états (échoué, annulé), sans que Laravel ait à distinguer les deux cas.

**`etablissementRef` se construit dans le sens inverse de B4-a.** `ResolveurEtablissementRef`
résout `CI-ETS000001 → id` (B4-a) ; B4-b a besoin de l'inverse, `structure → 'CI-ETS000001'`.
Ajoutée dans la **même classe** (paire naturelle, pas un second endroit où le format `{pays}-{id}`
serait écrit) : `formater(StructureSanitaire $structure): ?string`, `null` si
`identifiant_national` est vide — jamais un format à moitié rempli.

**`patientRef` reste interne à Java, jamais transmis à GeniusPay** (vérifié : `RequetePaiement`,
construit dans `ServiceGeniusPay::executer()` à partir des seuls `montant`/`devise`/référence/
callback, ne porte pas `patientRef` ; le champ n'est lu ailleurs que par
`RequetesSignauxFraude`, extraction interne du module fraude). Valeur : `'membre:'.$rdv->membre_id`
— un identifiant Laravel, jamais un nom.

**`objet` porte `ObjetPaiement.RENDEZ_VOUS`** — la valeur existe **déjà** dans l'enum Java, posée
sans doute en prévision de ce jour ; `ClientPaiementGeniusPay::initierCheckout()` gagne cette clé
optionnelle dans son tableau de demande (`'objet' => 'RENDEZ_VOUS'`), le contrôleur Java la
désérialise par son nom exact.

**Le règlement en ligne d'un rendez-vous DÉCLENCHE la commission existante de B4-a, sans code
supplémentaire — et c'est assumé, pas découvert après coup.** `PaiementNotificationController`
calcule déjà une commission sur tout succès `canal === geniuspay` portant un `etablissementRef`
résoluble (B4-a, S2). Un rendez-vous payé en ligne emprunte ce même canal avec ce même
établissement : il sera donc commissionné exactement comme le serait n'importe quel autre paiement
GeniusPay. Rien à écrire pour l'obtenir ; à dire pour que ce ne soit pas une surprise.

### 9.3 Ce qui s'ajoute (S11 → S13)

**S11 — `estRegle()` filtre sur le statut, jamais la seule existence** (§9.1). Correction
chirurgicale d'un module G5, avec son vecteur de régression dédié — patron déjà tenu par B1-d sur
le périmètre d'`assertPerimetre()`.

**S12 — Une ligne, deux états, jamais une seconde ligne créée à la confirmation** (§9.2).
`ouvrirPaiementEnLigne()` / `confirmerReglementEnLigne()` vivent tous deux dans `RecuRdvService`
(le domaine qui possède déjà `payer()`, `estRegle()`, `tarifPour()` — pas un second service qui
duplique la connaissance de `factures_patient`).

**S13 — Le portail affiche, mais ne fait rien de nouveau.** `Portail\RendezVousController::show()`
gagne un indicateur (« paiement en ligne en attente ») dérivé de l'existence d'une facture
`A_REGLER` — lecture seule, aucune action d'agent : le règlement reste un fait que seule la
notification établit (S6), jamais un agent qui « confirmerait » à sa place.

### 9.4 Endpoints et écran mobile

| Route | Contrôleur | Rôle |
|---|---|---|
| `GET /v1/rendez-vous/{id}/paiement-en-ligne` | `RecuRdvController::disponibiliteEnLigne` (neuf) | `{disponible: bool}` — `estConfigure()` sur l'`etablissementRef` formaté ; `false` sans appel réseau si l'établissement n'a pas d'identifiant national |
| `POST /v1/rendez-vous/{id}/paiement-en-ligne` | `RecuRdvController::payerEnLigne` (neuf) | Ouvre (ou réutilise) le checkout ; rend `{checkout_url, reference}` |
| `POST /api/v1/interne/paiements/notification` | `PaiementNotificationController` (étendu) | Règle la facture en plus de calculer la commission (déjà existant) |

Mobile (`recu/[id].tsx`, seul écran de paiement RDV existant) : dans le bloc « Écran de paiement »,
un second bouton « Payer en ligne (GeniusPay) » apparaît **si** `disponibiliteEnLigne` répond
`true`, à côté des chips de mode simulé existants — **aucun des deux chemins n'est retiré** (S7 :
« le chemin existant reste intact »). Au tap : `Linking.openURL(checkout_url)` (S8, zéro
dépendance) puis un bandeau « Revenez ici une fois le paiement effectué » avec un bouton
« Actualiser » qui rappelle `obtenirRecu()` (déjà l'unique façon dont l'écran sait qu'un reçu
existe — aucune route de statut supplémentaire).

### 9.5 Vecteurs obligatoires ajoutés à ceux du §4

10. **`estRegle()` avec une seule `FacturePatient` `A_REGLER` → `false`** ; avec une `PAYEE` → `true`
    (§9.1). Les vecteurs B1 existants, qui ne construisent que du `PAYEE`, passent sans changement.
11. **Retaper « Payer en ligne » deux fois → une seule `FacturePatient`, un seul `factureId` envoyé
    à Java** (réutilisation, pas duplication).
12. **`ouvrirPaiementEnLigne()` sur un RDV déjà réglé (reçu existant) → refuse**, même message que
    `payer()`.
13. **`confirmerReglementEnLigne()` rejouée deux fois (webhook dupliqué) → une seule `Paiement`, un
    seul `RecuRdv`, la facture reste `PAYEE`** (idempotence, sous verrou).
14. **`disponibiliteEnLigne` sur un établissement sans `identifiant_national` → `false`, zéro appel
    réseau au microservice.**
15. **Un paiement de rendez-vous en ligne déclenche une commission** — même garde que B4-a, vecteur
    en miroir pour dire que ce n'est pas un oubli.

### 9.6 Ce que ce plan NE fait PAS (limites annoncées d'avance)

- **Aucune expiration automatique** d'une `FacturePatient` `A_REGLER` abandonnée (checkout ouvert,
  jamais payé) : elle reste `A_REGLER` indéfiniment, rejouable (§9.5.11). Une tâche planifiée de
  bascule vers `EXPIREE` est un sujet séparé (`relance_envoyee_le` existe déjà pour un autre usage,
  R18) — non traité ici, et le dire évite de laisser croire à une hygiène qui n'existe pas.
- **Aucun remboursement**, comme au niveau du lot (§5.2).
- **Le plancher de 5 000 FCFA n'est PAS dupliqué côté Laravel** : le bouton « Payer en ligne »
  reste affiché même sous ce montant, et un tap sous le plancher relaie le message **exact** que
  Java renvoie (422, §GestionErreurs) plutôt qu'un message inventé à partir d'une valeur recopiée
  qui pourrait diverger de la vraie configuration Java.
- **Le portail ne gagne aucune action** — seulement une lecture (§9.3, S13).

---

## 10. G1 validé (« je valide ») — exécution, écart trouvé, G3, G2 live

**Validé par le propriétaire le 2026-09-04** (« je valide »), en réponse directe au G1 du §9
ci-dessus.

### 10.1 Écart trouvé EN LISANT LE CODE JAVA, pas au G2 — avant d'écrire le moindre test

Le §9 prescrivait un `factureId` **opaque**, dérivé d'un hachage de l'id Laravel, jamais résolu
contre une vraie `Facture` du domaine facturation Java (P5.2a) — au motif que
`ServiceGeniusPay::executer()` ne le résout contre aucun dépôt à l'**ouverture** du checkout.
**C'est exact, mais incomplet** : `ServiceWebhookGeniusPay::appliquer()`, sur un succès, appelle
**inconditionnellement** `ServiceFacturation::enregistrerReglement(factureId, montant)` dès que
`factureId` n'est pas nul (ligne 360, motif écrit dans le code : « GeniusPay aurait été le SEUL
canal à encaisser sans solder ce qu'il encaisse, là où la carte, le wallet et le mobile money le
font tous »). Or `enregistrerReglement()` fait `trouver(factureId)`, qui **lève** si aucune
`Facture` ne porte cet id — **dans la MÊME transaction `@Transactional`** que
`paiement.setStatut(SUCCESS, …)`, le point d'accroche unique du canal interne (lot 6). Une
exception ici annule TOUT, y compris la transition : **aucune notification ne serait jamais
partie vers Laravel**, silencieusement, sur le tout premier paiement réel.

**Correction, avant tout code de test** : `ouvrirPaiementEnLigne()` crée une vraie `Facture` Java
minimale (`POST /api/v1/invoices`, une ligne, TVA 0 % — la simulation RDV n'en a jamais porté)
**avant** d'appeler le checkout, et utilise son id réel comme `factureId`. Nouvelle colonne
`factures_patient.facture_geniuspay_id` (nullable, migration additive) : posée une seule fois,
**réutilisée** aux appels suivants — retaper « Payer en ligne » ne doit jamais fabriquer une
seconde facture Java, exactement comme il ne fabrique jamais une seconde `FacturePatient`.
`identifiantJavaPour()` (le hachage opaque) est **retirée**, code mort.

**Ce que ça change, et ce que ça ne change pas** : `correlationId` reste
`'facture-patient:{id}'` (générique, réutilisable par B3-d) ; `patientRef` reste
`'membre:{id}'` ; le reste du §9 (S1→S13) est inchangé. La création de la Facture Java n'est
**pas** le socle à vide qu'ADR-030/P6.3-D3 refusent : elle est lue (`enregistrerReglement`), elle
n'est pas un objet mort.

### 10.2 G3 — résultats

Suite Laravel complète **1732/1732, 17 949 assertions, 0 échec** (2 exécutions, avant et après la
correction du §10.1 — aucune régression). **79 vecteurs dédiés** : `RecuRdvPaiementEnLigneTest`
(disponibilité, ouverture, réutilisation FacturePatient **et** Facture Java, refus déjà réglé/sans
tarif/sans identifiant national/marchand non configuré/refus microservice relayé tel quel,
anti-IDOR, confirmation idempotente, confirmation isolée de la garde de reçu, parsing du préfixe
de corrélation), extension de `CanalInternePaiementTest` (dispatch réel du contrôleur vers
`RecuRdvService::confirmerReglementEnLigne()`, silence sur `correlationId` étranger, aucun
règlement sur `FAILED`), régression `estRegle()` dans `RecuRdvPaiementTest`, deux vecteurs de
rendu Blade dans `DispoRdvPortailTest` (badge « en attente » affiché/absent). Pint propre sur tous
les fichiers touchés (baseline établie contre `HEAD` : `RecuRdvService.php` échoue **déjà** 5
fixers d'alignement manuel, non reformaté). **Mutation : 9 tueuses + 1 témoin volontairement
vert** — statut d'`estRegle()`, garde « déjà réglé », garde établissement sans identifiant, garde
marchand non configuré, réutilisation de la `FacturePatient`, idempotence de
`confirmerReglementEnLigne()`, préfixe de corrélation, garde canal/statut du contrôleur,
**réutilisation de la Facture Java** — chaque mutation assertée appliquée, arbre restauré et
vérifié par comparaison octet à octet. **Deux survies corrigées en cours de campagne** (famille
« le vecteur prouve autre chose », déjà rencontrée dans ce projet) : la garde « déjà réglé »
survivait parce que le test, sans `Http::fake()` pour `estConfigure()`, retombait sur un refus
« marchand non configuré » identique en code HTTP — isolée en fakant explicitement le marchand
configuré ; le préfixe de corrélation survivait parce que `ctype_digit()` rejetait déjà la chaîne
de test choisie — remplacée par une chaîne dont la partie après le (faux) préfixe est
numérique, seule façon d'exercer réellement `str_starts_with()`. Mobile : `tsc --noEmit` propre,
`expo-doctor` 17/18 (le check en échec appelle l'API Expo en TLS, seuil déjà connu de ce projet).

### 10.3 G2 — live, réel, résultats

Après un **second arrêt d'environnement** pendant la session (Docker Desktop et `php artisan
serve` tous deux tombés — même famille que le premier gap de B4-a), les deux services ont été
redémarrés (Docker Desktop, `docker compose up -d` dans `services/payment`, `php artisan serve`),
sans reconstruction d'image (conteneurs préexistants). Base MySQL réelle **sauvegardée** avant
toute migration (`mysqldump --routines --triggers`), migration
`facture_patient_geniuspay_facture_id` **rejouée sur la vraie base** (conséquence de déploiement,
comme `commission_frais_connus` l'avait montré pour B4-a).

**Réutilisation de l'établissement de B4-a** (`CI-ETS900010`, id 18, marchand déjà configuré côté
Java — confirmé par un VRAI appel `GET /marchands/CI-ETS900010` → `configure:true`) : un secret
webhook **redéposé** (je le choisis, `POST /marchands/{ref}/secret-webhook` l'accepte sans
condition d'unicité), un `ServiceEtablissement` réel créé (tarif 12 000 FCFA), un patient réel
(`User`+`MembreFamille`), un `RendezVous` réel (id 2, statut `confirme`).

**Chaque brique nouvelle prouvée avec des données réelles, jamais simulées à l'intérieur du
test** :
- `GET /paiement-en-ligne` (disponibilité) → **`disponible:true` réel**, sur le compte Sanctum du
  patient réel.
- `POST /paiement-en-ligne` → **checkout GeniusPay RÉELLEMENT ouvert en bac à sable**
  (`https://geniuspay.ci/checkout/SANDBOX_8IRYJ3BRC1WGMUET`), contre le compte sandbox déjà
  configuré. `FacturePatient` réelle vérifiée en base : `statut=A_REGLER`,
  `facture_geniuspay_id` renseigné.
- **Une vraie `Facture` Java créée** (`POST /api/v1/invoices` réel, vérifiée directement dans
  Postgres) : `FCT-CIETS900010-2026-000004`, `EMISE`, `montant_ttc=12000`,
  `reste_a_payer=12000` — exactement le tarif du service, exactement l'écart du §10.1.
- **Un webhook `payment.success` réellement signé** avec le VRAI secret redéposé (HMAC-SHA256 hex
  sur `horodatage + "." + corps brut`), POST réel sur
  `/api/v1/paiement-webhooks/geniuspay/{slug réel}` → `200`, transition
  `EN_ATTENTE→REUSSIE` réelle en base, `frais_passerelle=150`, `montant_net=11850`,
  `canal=orange_money` (extrait du champ `payment_provider` du webhook, réellement).
- **`enregistrerReglement()` a RÉELLEMENT réussi** : `Facture` Java passée à `PAYEE`,
  `montant_regle=12000` — la garantie même du §10.1, prouvée en direct et non supposée.
- **Notification réellement relayée** Java→Laravel (`notifications_outbox`, statut `ENVOYEE`,
  1 tentative) : `Paiement` (Laravel) créé `mode=geniuspay`/`statut=paye`, `FacturePatient`
  `PAYEE`, `RecuRdv` créé — vérifiés directement en base MySQL réelle.
- **`estRegle()` réellement corrigé** : `true` sur ce RDV après règlement (la régression que le
  §9.1 du plan visait à empêcher).
- **Commission B4-a réellement déclenchée en conséquence** (S14, assumé au G1) : `montant_brut=
  12000`, `frais_passerelle=150` (RÉEL, pas 0 — contrairement au bac à sable de B4-a qui rendait
  toujours `fees=0`), `frais_connus=true`, `montant_commission=300` (12000×250bps, exact) —
  **deuxième commission réelle du projet**, distincte de celle de B4-a (`montant_brut=18000`) sur
  le MÊME établissement, aucune confusion.
- **Reçu réel relu par l'endpoint mobile** (`GET /recu`) : `mode:"geniuspay"`,
  `transaction_ref` = l'id réel du paiement Java, QR de check-in généré.
- **Idempotence prouvée à DEUX niveaux, tous deux réels** : rejeu exact du webhook signé → `200`
  Java (dédoublonnage par `evenement_id`, aucun retraitement) ; **rejeu direct de la notification
  à Laravel** (même `paiementId`, principal signé minté manuellement) → `200`, **aucun second
  `Paiement`, aucun second `RecuRdv`, aucune seconde commission** — la garde de `estRegle()`/
  `confirmerReglementEnLigne()` et celle de `CommissionService` tiennent toutes les deux,
  vérifiées séparément en base.
- **Refus réel** : retenter `POST /paiement-en-ligne` sur ce RDV désormais réglé →
  **422 réel** « Ce rendez-vous a déjà un reçu. ».

**Données de test conservées en base de développement** (décision par défaut, même précédent que
B4-a) : `RendezVous` id 2, `User` id 121 (`g2-b4b-patient@example.test`), `MembreFamille` id 39,
`ServiceEtablissement` id 28, `FacturePatient` id 1 (`FPA-2026-04QABJ`), `CommissionTransaction`
id 2. Nommées et scopées sans ambiguïté. Java et Laravel laissés **démarrés** pour le G4.

**G3 et G2 sont faits.**

---

**✅ VALIDÉ (G5, 2026-09-05)** — G4 propriétaire OK (« G4 validé »), G5 « c'est bon pour le G5 ». **B4-b clos ; le lot B4 est COMPLET (a, b).**

---

# PLAN 4 : Le circuit du laboratoire (B5, CDC_11 §8.1 · CDC_09 §7.4 · CDC_04 §109)

> **Étape 9 de l'ordre CDC_11 §12** (« Laboratoire, puis Radiologie »). Les huit étapes précédentes
> sont closes. **G1 VALIDÉ PAR LE PROPRIÉTAIRE** (« je valide le G1 de B5 », 2026-09-05) — le
> périmètre intégral décrit en §10 (arbitrage du 2026-09-05) fait foi. **B5-a et B5-b VALIDÉS
> (G5, 2026-09-05)**, **B5-c reste**, dans l'ordre B5-a → B5-b → B5-c (§9/§10).
>
> Ce plan couvre le **laboratoire seul**. La radiologie (§8.2) suppose DICOM et PACS : elle n'entre
> pas ici, et le registre des documents signables de P6.5b le dit déjà mot pour mot.

---

## 1. Ce que le corpus demande

**CDC_11 §8.1** — « Gestion des demandes d'examens (`Médecin → Demande → Laboratoire → Résultat →
Dossier patient`), processus (réception du prélèvement, analyse, **validation biologiste**,
publication du résultat), connexion aux **automates biologiques**, catalogue national des analyses,
traçabilité des prélèvements par code-barres/QR (CDC_09 §7.4). »

**CDC_09 §7.4** — chaque prélèvement reçoit un **identifiant unique** permettant de suivre tout son
cycle de vie, en huit étapes :

```text
1. Prescription médicale
2. Enregistrement du prélèvement
3. Étiquetage par code-barres ou QR Code
4. Transport vers le laboratoire
5. Réception et validation
6. Analyse
7. Validation biologique
8. Transmission sécurisée du résultat dans le dossier patient
```

« Cette chaîne réduit les risques d'erreur d'identification et facilite les audits qualité. »

**CDC_04 §109** nomme six tables : `demandes_analyses`, `prelevements` (identifiant unique,
code-barres/QR, cycle de vie), `analyses`, `resultats_analyses`, `validations_biologiques`,
`automates`.

**Ce que le corpus ne dit PAS, et qu'il faut décider** : qui a le droit de valider biologiquement, ce
qu'il advient d'un examen prescrit hors catalogue, et si le laboratoire ouvre le dossier du patient
pour y déposer son résultat. Les trois sont tranchés en §3.

---

## 2. G0 — dix constats, vérifiés en base réelle et dans le code

**K1 — Aucune des quatre tables du circuit n'existe.** Vérifié en base `ivoirsante` : seules
`analyses` (catalogue P6.7a), `analyse_references`, `laboratoire_analyses` (P6.7b) et
`resultats_analyses` (Module 2) sont là. **`demandes_analyses`, `prelevements`,
`validations_biologiques`, `automates` : absentes.** Le circuit du §7.4 n'existe à aucun degré.

**K2 — Le blocage que P6.5b avait nommé est LEVÉ, et c'est le code lui-même qui posait la
condition.** `RegistreDocumentsSignables::NON_BRANCHES['prescription_biologique']` dit
textuellement : « Entité inexistante — et ce n'est pas un document mais une DEMANDE qui ouvre un
circuit (médecin → laboratoire → résultat, §7.4). **Sans le catalogue national des analyses
(étape 7), elle prescrirait des examens en texte libre.** » L'étape 7 est faite depuis le
2026-08-14. La condition est remplie, et brancher un type reste ce que le registre annonce : **une
classe et une ligne**. B5 lèverait donc la **troisième des six entités non branchées** de la PKI.

**K3 — `resultats_json` est `encrypted:array`.** Les résultats ne sont pas interrogeables en SQL, et
le lien au catalogue de P6.7a vit **par ligne, à l'intérieur du blob chiffré**. C'est la question que
B3-a a tranchée pour les ordonnances (« ce qui identifie un produit n'est pas ce qui décrit un
traitement ») ; elle se repose ici, et la réponse ne peut pas être recopiée : **une valeur biologique
est elle-même une donnée de santé**, là où un nom de médicament est une identité de produit.

**K4 — Le résultat existe, mais il n'a ni émetteur ni circuit.** `resultats-analyses` est une section
du carnet que le patient, un délégué (au brouillon) ou un soignant (P7-D0) remplit. Rien ne relie un
résultat à une demande. `medecin_prescripteur_id` et `laboratoire_id` sont des **déclarations**
facultatives que P6.7b fait vérifier — jamais le produit d'un circuit.

**K5 — `source = 'structure'` est une valeur d'ENUM SANS AUCUN ÉMETTEUR, et elle est déclarable par
le client.** Vérifié : `EcritureSoignantService` pose `medecin`, `ContributionCarnetService` force
`patient`, et **personne n'écrit `structure`** depuis le Module 2 — nouvelle clé dormante, après
`PREVALIDE_SECRETAIRE` (B1-a), `honore` (B1-d), `hybride` (P10c-3-ii), `A_REGLER` (B4-b). Or
`AntecedentController`, `OrdonnanceController` et `ResultatAnalyseController` la déclarent tous trois
dans leurs règles (`'source' => ['nullable', 'in:patient,medecin,structure']`) et `source` est
`$fillable` : **sur le chemin patient direct, un client peut la poser lui-même.**

**Ce n'est pas un défaut actif aujourd'hui, et il faut le dire exactement** : le seul consommateur
qui se fie à `source` est `FicheVitaleService`, et il ne la lit **que sur les vaccinations**, dont le
contrôleur — vérifié — **n'accepte pas** `source` du client. Le commentaire de P6.8b (« un signal que
le serveur garantit et que le client ne peut pas falsifier ») dit donc vrai là où il est écrit.

**Mais B5 rendrait ce défaut actif.** Si ce lot fait de `source = 'structure'` la marque d'un
résultat validé par un biologiste, un patient pourra poser ce signal sur un résultat qu'il a saisi
lui-même — *c'est-à-dire refaire, transposé, le défaut exact que P6.8b a passé un incrément entier à
refermer* (le bouclier coché couvrait une case cochée par l'intéressé). **La porte se ferme avant
qu'on s'appuie sur le signal, pas après.**

**K6 — La base de développement est vide côté catalogue** : `analyses` **0 ligne**,
`laboratoire_analyses` **0**, `resultats_analyses` **0**. `CatalogueAnalysesSeeder` et
`masante:analyses:backfill` existent mais n'ont pas été rejoués après une restauration — même
famille que `MaladieSeeder` en B2-b, où le contrôle qualité refusait la publication faute de codes
nationaux. **Prérequis de déploiement**, à écrire avant le G2 et non à découvrir pendant.

**K7 — Un seul laboratoire en base** (`Laboratoire BIOSMOSE`, id 8) et son `type_laboratoire` — la
colonne ajoutée par P6.7b — est **NULL**. La typologie n'a jamais été renseignée sur cette base.

**K8 — Le laborantin a des capacités mais aucune porte, et il n'existe pas de biologiste.** P11.0 lui
a donné `qr.scan`, `triage.view`, `dossier.ecrire` avec sa raison écrite (« `resultats-analyses`
figure dans la liste blanche des sections ouvertes au soignant, donc la capacité existe réellement »)
— mais **aucune zone**, aucun écran. Et parmi les onze rôles fixés en P11.0 il y a `laborantin` et
`radiologue` ; **il n'y a pas de `biologiste`**.

**K9 — Le code-barres de B3-c n'est pas réutilisable ici.** `ReglesCodeBarres::estGtin()` valide un
**GTIN** : l'identifiant d'un *produit de fabricant*, avec sa clé de contrôle GS1. Un identifiant de
prélèvement est celui d'une **instance que nous créons nous-mêmes**. La classe pure reste un *motif*,
pas un composant à appeler.

**K10 — Les patrons à transposer existent et sont éprouvés.** `ordonnances.jeton_partage`
(`varchar(64)` UNIQUE, hors `$fillable`, `$hidden`, comparaison en temps constant, 404 jamais 403) ·
`ordonnance_lignes` (identité **en clair**, traitement **chiffré**) · et surtout le commentaire de
route de B3-a : « **aucune session de dossier : le pharmacien atteint l'ordonnance par son JETON et
ne voit qu'elle. Ce n'est pas une garde qu'on vérifie, c'est une porte qui n'existe pas.** »

---

## 3. Les décisions de conception (L1 → L12)

### L1 — La demande d'examen est l'analogue de l'ordonnance, et le circuit celui de la délivrance

Ce projet a déjà construit ce circuit une fois, de bout en bout : **B2-c** fait produire une pièce par
un praticien qui la signe, **B3-a** la fait lire par un professionnel d'un autre métier **par un
jeton, sans ouvrir le dossier**, lequel enregistre son acte. Le laboratoire est le même parcours avec
d'autres acteurs.

*On ne le réinvente pas, on le transpose* — et ce n'est pas une commodité d'écriture : c'est ce qui
garantit qu'un laboratoire ne lira pas les antécédents d'un patient pour faire une numération formule
sanguine. Minimisation (loi 2013-450), déjà tenue par P7-D2 et B3-a.

| Ordonnance / pharmacie | Demande d'examen / laboratoire |
|---|---|
| `ordonnances` + `ordonnance_lignes` | `demandes_analyses` + `demande_analyse_lignes` |
| `jeton_partage` ouvre l'ordonnance seule | `jeton_partage` ouvre la demande seule |
| `delivrances` + `delivrance_lignes` | `prelevements` + le résultat publié |
| `traces_dispensation` (registre national) | *(hors périmètre — voir L11)* |

### L2 — Le lien au catalogue est facultatif, relu, figé, et l'écart est COMPTÉ

Le corpus veut la normalisation (§7.3 : « les résultats sont interprétés de manière cohérente, quel
que soit le laboratoire »). L'imposer serait pourtant une faute : le catalogue est un **jeu de
démonstration de huit analyses** honnêtement étiqueté (P6.7a), et *refuser de prescrire un examen
absent de notre liste serait une décision médicale prise par une machine* (CDC_00 §4).

Donc **facultatif, mais relu et figé** quand il est fourni — code national, libellé et **unité**
repris du catalogue, jamais du client (patron P6.6b / P6.7a / P6.7b / B2-c). Une ligne hors catalogue
est **acceptée, signalée et comptée** à l'écran : troisième application du motif E4 de P6.8c, et ici
pour la raison « notre référentiel est incomplet » — donc **l'écart doit tendre vers zéro**, comme en
P6.8d.

### L3 — Le laboratoire écrit le résultat SANS session de dossier, et le jeton est le consentement

C'est le point de conception central, et il n'a pas de précédent exact : B3-a n'écrivait **rien** dans
le carnet (une délivrance vit dans sa propre table), alors que le §7.4 étape 8 exige littéralement la
« transmission sécurisée du résultat **dans le dossier patient** ».

Trois voies étaient possibles, deux sont écartées avec leur raison :

- **Une septième voie d'accès au dossier** (`labo_scan`) : c'est exactement ce que B3-a a refusé pour
  le pharmacien — un élargissement durable pour un besoin ponctuel, qui ouvrirait antécédents,
  vaccinations et ordonnances à un technicien de laboratoire.
- **Faire scanner le QR du patient** : le plus court et le plus disproportionné.
- **Retenu** : le laboratoire écrit **par le circuit**, et son écriture est bornée par construction —
  il ne peut poser qu'un **résultat**, et seulement celui de **la demande qu'il détient**. Le jeton
  remis par le patient est l'acte de consentement, au moins aussi explicite qu'un scan de QR.

*La porte n'est pas gardée, elle n'existe pas* : aucun point d'entrée ne permet à un compte de
laboratoire de lire autre chose. **Vecteur central du lot, comme en B3-a : servir une demande ne crée
aucune ligne dans `acces_dossier`, parce qu'aucun accès au dossier n'a lieu.**

### L4 — `source = 'structure'` trouve son premier émetteur, et la porte se ferme d'abord

Un résultat publié par le circuit porte `source = 'structure'` : c'est le fait qui le distingue d'un
compte rendu recopié par le patient.

**Mais K5 impose l'ordre des opérations** : `source` sort des règles de validation
d'`AntecedentController`, `OrdonnanceController` et `ResultatAnalyseController` **avant** que ce lot
s'appuie dessus. Un client ne déclare pas la provenance de ce qu'il écrit — quatrième application
d'une règle déjà tenue pour `source` (P7-C), `obligatoire` (P6.8b), `provenance` (P6.8d) et `origine`
(P10c-1). Retrait **additif et sans perte** : les trois chemins d'écriture posent déjà la valeur
juste, aucun appelant légitime ne l'envoie.

### L5 — Le jeton et l'étiquette du prélèvement sont deux choses différentes, et les confondre serait grave

- Le **jeton de la demande** est un **secret d'accès** : 64 caractères, hors `$fillable`, `$hidden`,
  comparé en temps constant, **404 jamais 403** (patron B3-a / P10a).
- L'**identifiant du prélèvement** est une **étiquette** : imprimée, collée sur un tube, elle circule
  physiquement d'un service à l'autre. Elle n'ouvre rien.

Mettre un secret d'accès sur une étiquette collée sur un tube reviendrait à distribuer la clé du
dossier avec l'échantillon. **Deux valeurs, deux natures, deux durées de vie.** L'étiquette est
opaque et non séquentielle (patron `DEM-` de P11.1) — *un compteur laisserait deviner le volume
d'analyses d'un laboratoire et énumérer les prélèvements de la veille*.

### L6 — Le cycle de vie : six états, dont un facultatif, et aucun état inatteignable

Les huit étapes du §7.4 ne sont pas huit états : la 1 est la création de la demande (pas un état du
prélèvement) et les 2 et 3 sont **un seul acte** — l'identifiant *est* l'étiquette.

```text
preleve → [expedie] → recu → en_analyse → valide → publie
```

`expedie` (étape 4) est **facultatif et le dit** : un prélèvement effectué au laboratoire même passe
directement de `preleve` à `recu`, et prétendre le contraire ferait saisir un transport qui n'a pas
eu lieu. Chaque état est atteint par un **acte explicite** — aucun n'est posé par déduction, aucun
n'est inatteignable (règle tenue depuis P10c-3-ii sur `hybride`). `publie` est **terminal**, et c'est
lui qui écrit dans le carnet (L3).

### L7 — La validation biologique est le verrou, et elle a sa propre permission

§7.4 étape 7 et §8.1 la nomment tous deux. Sa conséquence est structurelle : **un résultat non validé
ne part jamais au dossier du patient.** Ce n'est pas un statut d'affichage, c'est une garde.

Le métier distingue celui qui exécute (technicien) de celui qui valide (biologiste). Le projet a onze
rôles fixés en P11.0 et **n'en crée pas un douzième pour un écran** : on crée **deux permissions**,
`analyse.executer` et `analyse.valider`.

- `analyse.executer` va au rôle **`laborantin`**, qui existe et dont c'est le métier.
- **`analyse.valider` n'est portée par aucun rôle** — nouvelle occurrence du motif, et sa raison est
  ici la plus directe de la série : *un résultat biologique validé engage la responsabilité d'un
  biologiste nommé*. Elle s'accorde nominativement, comme `professionnel.habiliter`, `dossier.ecrire`
  ou les cinq permissions de validation de P10b-1.

**Pas d'interdiction de cumul** : le corpus ne l'exige pas, et *un garde-fou plus strict que sa propre
règle est un défaut* (P6.8c). Mais le circuit **nomme** qui a exécuté et qui a validé, séparément —
posture exacte de P10b-1 sur ses cinq validations.

### L8 — La demande est signable, et cela lève la troisième entité de P6.5b

`DocumentPrescriptionBiologique implements DocumentSignable` : une classe et une ligne dans
`RegistreDocumentsSignables::SIGNABLES`, et son entrée disparaît de `NON_BRANCHES`. Le contenu
canonique signe **ce dont la modification changerait le sens de la prescription** — les analyses
demandées, leur code national figé, le prescripteur, la date — et **jamais les rattachements** ni
l'état du circuit, sinon *chaque changement d'état ferait passer la demande pour altérée* (leçon
B2-c, où `medecin_id` avait été délibérément tenu hors du contenu signé).

La signature reste **facultative** : un praticien sans certificat doit pouvoir prescrire (P6.5b).

### L9 — Ce qui est en clair, ce qui est chiffré : la question est REPOSÉE, pas recopiée

B3-a a tranché pour les ordonnances : « ce qui identifie un produit n'est pas ce qui décrit un
traitement ». La transposition littérale serait fausse ici — **une valeur biologique est elle-même une
donnée de santé**, là où « Paracétamol 500 mg » est une identité de produit.

- `demande_analyse_lignes` : **identité de l'examen en clair** (`analyse_id`, `code_national`,
  `libelle`, `unite`) — sans quoi ni le laboratoire ne sait quoi analyser, ni le catalogue ne sert à
  rien. Les **conditions de prélèvement** et les renseignements cliniques restent **chiffrés**.
- **Les valeurs mesurées** restent dans `resultats_json` **chiffré**, où elles sont déjà (K3). *Ce lot
  n'ouvre pas ce blob* : le rendre interrogeable serait une décision de gouvernance des données de
  santé qui dépasse un circuit de laboratoire, et elle n'a pas été prise.

**Conséquence dite d'avance** : les « statistiques nationales » d'analyses, analogues de ce que B3-c a
construit pour les médicaments, **ne sont pas possibles dans ce lot** — écrit comme limite, pas
déguisé.

### L10 — Aucun automate, et c'est un refus motivé

CDC_11 §8.1 nomme la « connexion aux automates biologiques », CDC_04 §109 la table `automates`. **Nous
n'en avons vu aucun** — même position qu'ADR-030 (« aucune API de logiciel hospitalier ivoirien n'a
été vue ») et que P11.2, qui l'a écrite noir sur blanc.

Ce qui est dit à la place, et qui est vrai : **le chemin existe déjà**. P11.2 a livré une API
d'ingestion partenaire signée (clé + HMAC) dont l'ajout d'un flux est « une classe et une ligne de
route ». Un automate — ou plus vraisemblablement le middleware du laboratoire — pousserait par là.
**Point d'extension nommé, pas construit** (classement ADR-014).

### L11 — Pas de registre national des analyses dans ce lot

B3-c a construit `traces_dispensation` parce que le §7.6 le demande **pour les médicaments** (« lutte
contre les falsifiés, suivi de consommation, statistiques nationales »). Le corpus ne demande rien
d'équivalent pour la biologie, et L9 montre qu'il n'en existe de toute façon pas le support. *On ne
construit pas par symétrie décorative* (refus déjà opposé en P6.4a au journal de non-réutilisation).

### L12 — Écrans en Blade, et le registre de zones n'accueille rien

Décision K1 de P6.4d, tenue depuis par B1, B2 et B3 : compléter en Blade **sans investir dans le
design**, la migration du portail restant un module identifié. Le laboratoire suit. Le registre de
zones de P11.0 (`apps/web/src/lib/zones.ts`) reste à quatre entrées — *y inscrire une zone dont la
page vit en Blade afficherait un lien vers une page absente*.

---

## 4. Schéma exact

### `demandes_analyses` — la pièce produite par le médecin

| Colonne | Type | Raison |
|---|---|---|
| `membre_id` | FK `membres_famille` cascade | le patient concerné |
| `consultation_id` | bigint **sans contrainte** | ADR-042 D1 — un identifiant, pas une relation vivante |
| `medecin_id` / `structure_id` | FK `nullOnDelete` | patron B2-c (`ordonnances`) |
| `medecin_nom` / `structure_nom` | varchar(200) | **figés**, posés par le serveur |
| `date_demande` | date | |
| `renseignements_cliniques` | text **chiffré** | ce que le médecin dit au biologiste — donnée de santé (L9) |
| `jeton_partage` | varchar(64) UNIQUE, hors `$fillable`, `$hidden` | patron B3-a |
| `statut` | enum(`emise`,`servie`,`annulee`) | `servie` **dérivée** d'un prélèvement publié, jamais posée à la main |
| `source` / `added_by` | enum | réécrits par le serveur |

### `demande_analyse_lignes` — l'identité de chaque examen, en clair (L9)

`demande_id` (cascade) · `libelle` · `analyse_id` (`nullOnDelete`) · `code_national` · `unite` ·
`conditions_prelevement` (**chiffré**) · `rang` · `UNIQUE(demande_id, analyse_id)` quand `analyse_id`
n'est pas nul.

### `prelevements` — l'échantillon et son cycle

`demande_id` (cascade) · `identifiant` varchar(20) **UNIQUE** (l'étiquette, L5) ·
`laboratoire_structure_id` · `statut` (les six états de L6) · `preleve_le` · `preleve_par_nom` ·
`expedie_le` · `recu_le` · `analyse_le` · `execute_par_user_id` · `valide_le` · `valide_par_user_id` ·
`valide_par_nom` · `publie_le` · `resultat_analyse_id` (**identifiant sans contrainte** — le patient
peut supprimer la ligne de son carnet, le prélèvement doit y survivre : ADR-042 D1, et B3-c a payé le
prix d'une clé étrangère `nullOnDelete` sur une table append-only).

**Gardes du moteur** (déclencheurs dans les deux dialectes, patron constant depuis P6.3 — `CHECK`
impossible, colonnes sous action référentielle, erreur 3823) :

1. `valide` exige `valide_par_user_id` **et** `valide_le` ;
2. `publie` exige `resultat_analyse_id` **et** `publie_le` ;
3. un `identifiant` vide est refusé ;
4. les états ne remontent pas.

### `validations_biologiques` — journal, pas colonne

CDC_04 §109 la nomme. Elle porte le **verdict et son auteur** (`prelevement_id`, `user_id`, `nom`,
`verdict` ∈ {`valide`, `rejete`}, `motif` **obligatoire sur un rejet**, `cree_le`), **append-only**
(modèle + déclencheurs, patron `protocole_applications`). Un rejet renvoie le prélèvement en
`en_analyse` : *on ne supprime pas une validation, on en écrit une autre* — et les deux se lisent.

**Pas de chaîne de hachage** : ADR-042 a montré ce que coûte une chaîne, et *on ne durcit pas par
symétrie décorative* (B3-c a pris la même décision, en la motivant).

---

## 5. Classes et fonctions — noms retenus

| Nom | Rôle |
|---|---|
| `App\Services\Analyse\ServiceDemandeAnalyse` | le médecin prescrit (depuis la consultation) |
| `App\Services\Analyse\ProjecteurLignesDemande` | projette les lignes — patron `ProjecteurLignesOrdonnance` |
| `App\Services\Analyse\ServiceCircuitPrelevement` | les six transitions, le verrou, les gardes |
| `App\Services\Analyse\ServiceValidationBiologique` | le verdict, le journal, la publication |
| `App\Services\Pki\DocumentPrescriptionBiologique` | L8 — une classe et une ligne |
| `App\Support\StatutPrelevement` | enum PHP, **miroir `@masante/shared`** + garde anti-divergence (patron B1-a / B3-d) |
| `App\Services\Analyse\GenerateurIdentifiantPrelevement` | L5 — opaque, non séquentiel |

---

## 6. Ce qui change dans l'existant

| Fichier | Changement | Nature |
|---|---|---|
| `AntecedentController`, `OrdonnanceController`, `ResultatAnalyseController` | `source` **retirée** des règles de validation (L4) | correction, additive sans perte |
| `RegistreDocumentsSignables` | `prescription_biologique` passe de `NON_BRANCHES` à `SIGNABLES` | une ligne |
| `PortailRolesSeeder` | `analyse.executer` → `laborantin` ; `analyse.valider` → **aucun rôle** | additif |
| `packages/shared/src/enums` | `StatutPrelevement` + les deux permissions | source unique |
| `ResultatAnalyse` | relation vers le prélèvement d'origine (lecture seule) | additif |
| `Consultation` | relation `demandesAnalyses()` | additif |

**Ce qui NE change pas** : `EcritureSoignantService`, `SessionDossierService`,
`CarnetSectionController` et les six voies d'accès au dossier. *Si l'une d'elles devait bouger, c'est
que L3 serait faux.*

---

## 7. Ce qu'il faudra prouver

**Le vecteur central est une absence** : servir une demande d'examen ne crée **aucune ligne** dans
`acces_dossier` — vérifié en SQL direct au G2 live, comme en B3-a.

Puis : un examen hors catalogue est **accepté et compté**, jamais bloqué (L2) · un client posant
`source: 'structure'` sur son propre résultat est **ignoré** (L4, deux couches, deux vecteurs —
patron P6.6b) · le code national et l'unité envoyés par le client sont **ignorés** au profit du
catalogue · un prélèvement ne peut **pas** être publié sans validation biologique (L7) · un
prélèvement ne **remonte pas** son cycle (garde du moteur) · un rejet **écrit une seconde ligne** et
n'efface pas la première · l'identifiant de prélèvement n'ouvre **rien** · un jeton faux rend **404,
jamais 403** · une demande signée avant un changement d'état reste **INTÈGRE** (L8) · un laboratoire
d'une autre structure reçoit **404** sur un prélèvement qui n'est pas le sien.

**Campagne de mutation** sur chaque garde, avec un **témoin volontairement vert**.

**Prérequis de déploiement à écrire AVANT le G2** (K6) : `CatalogueAnalysesSeeder` puis
`masante:analyses:backfill`, sinon le catalogue est vide et rien ne peut être prescrit — le G2 le
découvrirait sinon en direct, comme B2-b l'a vécu avec `MaladieSeeder`.

---

## 8. Limites qui seront annoncées

- **Aucun automate** (L10) — point d'extension nommé, le chemin P11.2 existe.
- **Aucune statistique nationale d'analyses** (L9 / L11) — les valeurs restent chiffrées.
- **Catalogue = jeu de démonstration de huit analyses**, honnêtement étiqueté depuis P6.7a ; le
  charger pour de vrai est **de la donnée, zéro code**, et tant que ce n'est pas fait **ce n'est pas
  un catalogue national**.
- **Aucune radiologie** — DICOM / PACS hors périmètre (seconde moitié de l'étape 9).
- **Aucun écran mobile** : le patient verra sa demande dans son carnet, il ne suivra pas le cycle du
  prélèvement.
- **Le transport n'est pas géolocalisé** : `expedie` est une déclaration, pas un suivi.
- **La validation biologique n'est pas signée cryptographiquement** — la PKI signe la *demande* (L8),
  pas le verdict ; le journal nomme son auteur, ce qu'un litige discutera.
- **Écrans Blade** (L12), le registre de zones Next reste à quatre entrées.

---

## 9. Ordre d'exécution proposé

**B5-a — la demande d'examen.** Fermeture de la porte `source` (L4) · `demandes_analyses` +
`demande_analyse_lignes` · `ServiceDemandeAnalyse` + projection + lien catalogue · jeton ·
`DocumentPrescriptionBiologique` (L8) · écrans : prescription depuis la consultation, demande visible
au carnet du patient · G3 + G2 live.

**B5-b — le prélèvement, sa validation, la publication du résultat.** `prelevements` +
`validations_biologiques` + les quatre gardes du moteur · `ServiceCircuitPrelevement` +
`ServiceValidationBiologique` · les deux permissions · écran laboratoire (lecture de la demande par
jeton, enregistrement du prélèvement, cycle, validation, publication) · G3 + G2 live.

**Pourquoi deux et pas un** : le circuit complet en une fois serait le plus gros incrément du projet.
**Pourquoi B5-a n'est pas un socle à vide** : une demande d'examen est utile seule — le patient
l'emporte chez le laboratoire de son choix, exactement comme une ordonnance papier, et elle referme
déjà la prescription en texte libre que P6.5b avait nommée.

**Le découpage, comme le périmètre, attend l'arbitrage du propriétaire.**

---

## 10. Élargissement du périmètre — arbitrage du propriétaire (2026-09-05)

> « on ne va rien abandonner, on va ajouter tout ce qui manque, on va donc construire le circuit en
> entier avec les quatre tables absentes : `demandes_analyses`, `prelevements`,
> `validations_biologiques`, `automates`. On va garder l'intégralité du cahier des charges :
> Demandes d'examens · prélèvements avec scan code-barres/QR · saisie et import des résultats ·
> validation biologiste · publication vers le dossier patient · catalogue des analyses · connexion
> aux automates biologiques. On aura la traçabilité du laborantin, tout comme le journal d'audit du
> médecin au carnet de santé. Après publication vers le dossier, le patient doit recevoir une
> notification. »

**Les sections 1 à 9 restent valables, sauf sur les points amendés ci-dessous.** Rien n'y est effacé :
L10 est réécrit, L11 est confirmé avec sa raison, et quatre décisions s'ajoutent (L13 → L16). Un
onzième constat de G0, trouvé en instruisant cette demande, **aggrave K5** et le fait passer de
latent à actif.

### K11 — LE DÉFAUT DE K5 EST ACTIF, ET LE G0 L'AVAIT SOUS-ESTIMÉ

En cherchant où la traçabilité du laborantin devait s'inscrire, un **second consommateur** de `source`
est apparu : `ServiceFicheParcours::autresEntrees()` filtre sur
`whereIn('source', ['medecin', 'structure'])`, et son commentaire dit littéralement **« Ce sont des
faits : leur provenance est `medecin` ou `structure` »**.

Or K5 a établi qu'un client peut poser `source: 'structure'` lui-même sur un antécédent, une
ordonnance ou un résultat d'analyse. **Donc la saisie personnelle d'un patient peut aujourd'hui
apparaître dans le bloc « autres entrées médicales » de sa fiche de parcours, présentée comme un fait
de professionnel** — lue par ses délégués et par le second responsable de famille, sur le document
que P7-D2 décrit comme « un support à l'appel téléphonique ».

Ce n'est donc plus « un défaut que B5 rendrait actif », c'est **un défaut réel, présent, que B5
referme** (L4, inchangé dans son principe, renforcé dans sa justification). La modestie de son
exploitation — il faut un appel API direct — ne change pas sa nature.

### L10 RÉÉCRIT — Les automates entrent, et ce qui est construit est dit exactement

Le registre `automates` et le flux d'ingestion sont **construits**. Ce qui ne peut pas l'être est
nommé, et la frontière est technique, pas rhétorique :

- **Ce qui est construit** : `automates` (le registre déclaré par un laboratoire : libellé, marque,
  modèle, numéro de série, `client_api_id`, `actif`, `dernier_message_le`) · le **flux d'ingestion
  réel** — `ClientApi::DOMAINES` gagne `resultats_laboratoire` à côté de `stock_officine`, et P11.2
  avait écrit que l'ajout d'un flux est « une classe et une ligne de route » : c'est vérifié, la
  liste blanche est fermée et tient en une entrée · l'authentification par clé et **HMAC sur le corps
  brut**, la fraîcheur ±5 min, l'anti-rejeu atomique, l'`Idempotency-Key` et le journal
  `journal_ingestion` **existent déjà et ne sont pas réécrits**.
- **Ce qui n'est pas construit, et pourquoi ce n'est pas un renoncement** : un automate de biologie
  ne parle pas HTTP. Il parle **ASTM E1381/E1394** ou **HL7 v2 (LIS2-A2)**, sur un port série ou une
  socket TCP, dans un local technique. Le composant qui traduit cela vit **physiquement chez le
  laboratoire** — c'est un *driver*, et c'est l'architecture réelle du marché : automate → middleware
  du laboratoire → API. **Notre moitié du contrat est celle que nous construisons ici** ; l'autre
  moitié suppose un appareil que nous n'avons pas vu, et en écrire le protocole reviendrait à
  l'inventer (position ADR-030, tenue par P11.2 : « aucun partenaire réel consulté »).

**LA GARDE QUI COMPTE, ET ELLE N'EST PAS NÉGOCIABLE : un automate ne valide jamais.** Un résultat
importé entre au cycle en `en_analyse` et attend **la validation biologique humaine** (L7). Sans
cette garde, une machine publierait un résultat dans le dossier d'un patient sans qu'aucun biologiste
ne l'ait vu — ce que CDC_00 §4 interdit littéralement. *Le flux d'import accélère la saisie, il ne
déplace jamais la décision.*

**Le rattachement se fait par l'étiquette du tube** (L5) : l'automate renvoie l'identifiant de
prélèvement qu'il a lu, et c'est cette valeur — non un identifiant interne, non un rapprochement par
nom de patient — qui désigne le prélèvement. *Rapprocher un résultat d'un patient par ressemblance
serait l'erreur d'identification que le §7.4 existe pour supprimer.* Un identifiant inconnu est
**refusé et nommé**, jamais deviné (patron D2 de P11.2, où le serveur ne devine jamais une référence
produit).

### L11 CONFIRMÉ — toujours pas de registre national des analyses, et ce n'est pas un abandon

La demande du propriétaire énumère sept éléments ; **le registre national de consommation n'en fait
pas partie**, et le corpus ne le demande que pour les médicaments (§7.6). L9 montre par ailleurs qu'il
n'en existe pas le support : les valeurs vivent dans un blob chiffré, et l'ouvrir est une décision de
gouvernance des données de santé qui dépasse ce lot. **Ce n'est pas retranché du périmètre demandé —
ce n'en a jamais fait partie.**

### L13 — La traçabilité du laborantin : `journal_laboratoire`, et rien dans `acces_dossier`

La demande est explicite : « la traçabilité du laborantin, **tout comme** le journal d'audit du
médecin au carnet de santé ». Le mot qui compte est *comme* : le **niveau d'exigence** est le même,
le mécanisme ne peut pas l'être — `acces_dossier` journalise **l'ouverture d'une fenêtre sur un
dossier**, et L3 pose qu'un laboratoire n'en ouvre aucune.

**Vérifié plutôt que supposé** : `ServiceFicheParcours` répartit les lignes d'`acces_dossier` en
ouvertures et clôtures selon `duree_minutes !== null`. Une ligne de dépôt isolée y serait donc classée
comme **ouverture sans clôture** et s'afficherait « consultation non clôturée » — *une phrase fausse,
dans le document même qui existe pour dire au patient ce qui s'est passé*.

Donc :

1. **`journal_laboratoire`** — append-only (modèle **et** déclencheurs), nominatif, horodaté. Il trace
   **tous** les actes du circuit, y compris ceux qui ne touchent aucun carnet : consultation d'une
   demande par jeton, enregistrement du prélèvement, expédition, réception, mise en analyse, **import
   d'un automate**, validation, **rejet**, publication. C'est *plus* que ce que `acces_dossier` trace
   pour un médecin, parce qu'un circuit de laboratoire a plus d'étapes qu'une consultation.
   `prelevement_id`, `client_api_id` et `user_id` y sont des **identifiants sans contrainte** (ADR-042
   D1 — un journal ne se laisse pas modifier par la suppression d'un compte, et B3-c a payé le prix de
   l'oubli inverse). **Aucune valeur clinique** n'y entre : quel acte, sur quel prélèvement, par qui,
   quand.
2. **Le patient, lui, voit le dépôt sans qu'on ajoute quoi que ce soit** — et c'est l'élégance de L4 :
   une fois `source = 'structure'` réellement écrite par le circuit, le résultat publié apparaît **de
   lui-même** dans le bloc « autres entrées médicales » de la fiche de parcours (P7-D2), qui filtre
   déjà sur cette valeur (K11). *Le mécanisme existant devient exact au lieu d'être doublé.* Le
   laboratoire d'origine est déjà porté par la ligne (`laboratoire_nom`, `laboratoire_code`, P6.7b).

**Conséquence heureuse et à dire** : `ServiceFicheParcours` et `acces_dossier` **ne sont pas
modifiés**, donc P7-D2 et les six voies d'accès restent intacts — et le vecteur central de L3 tient
mot pour mot : *servir une demande ne crée aucune ligne dans `acces_dossier`, parce qu'aucun accès au
dossier n'a lieu*.

### L14 — La notification, et ce qu'elle ne dira jamais

Type neuf `RESULTAT_ANALYSE_PUBLIE`, émis **à la publication seule** — jamais à la validation, jamais
à la réception : *le patient n'a pas à suivre le trajet de son tube, il attend son résultat*.

**Un type dédié plutôt que `CARNET_ENRICHI`**, et c'est la leçon de B1-d (« le mot avant le
mécanisme ») : un résultat d'analyse est attendu, un enrichissement de carnet ne l'est pas ; les
confondre noierait dans le flux général la seule notification que le patient guette.

**Contenu : l'événement, jamais le résultat.** « Un résultat d'analyse a été déposé dans votre carnet
par le Laboratoire X » — **ni le nom de l'analyse, ni la moindre valeur**. La règle inviolable de
P7-D1 s'applique intégralement, et elle mord ici plus fort qu'ailleurs : *un push s'affiche sur un
écran verrouillé, et le nom d'une analyse désigne une pathologie* — « sérologie VIH » sur l'écran
d'accueil d'un téléphone posé sur une table est une divulgation. Même posture qu'en P6.8b, où la
notification dit qu'une vaccination est due **et jamais laquelle**. Le test anti-fuite dédié, qui
cherche la donnée clinique dans toute la charge utile et casse le build, est **rejoué sur ce type**.

**Destinataires** : le titulaire **et les délégués en lecture** — mêmes destinataires que
`carnetEnrichi()` (P7-D0), puisque c'est littéralement la même nature d'événement : un professionnel
vient d'écrire dans ce carnet.

### L15 — Saisie et import : deux entrées, UN SEUL service

Le résultat entre par deux chemins — la **saisie** du laborantin au portail, et l'**import** d'un
automate (L10) — et tous deux appellent **`ServiceValidationBiologique`/`ServiceResultatAnalyse`, le
même**. Les écrire deux fois les laisserait diverger, *et ça diverge toujours du côté qu'on regarde le
moins* — ici l'import, qu'aucun humain n'ouvre jamais. C'est la décision D2 de P11.1 (« un seul chemin
de création, extrait avant d'être partagé ») et D3 de P11.2 (« contrat d'échange, jamais un second
chemin d'écriture »), appliquées une troisième fois.

La seule différence que l'import ajoute est **sa provenance** — `origine ∈ {saisie, automate}` sur le
résultat du prélèvement, **décidée par le serveur** et jamais déclarée par l'appelant (patron E6 de
P10c-1, `source` de P7-C, `provenance` de P6.8d). *Un résultat ne doit jamais mentir sur d'où il
vient*, et un biologiste qui valide doit savoir s'il relit une machine ou un collègue.

### L16 — L'étiquette : Code 128 en SVG pur, zéro dépendance

Le §7.4 étape 3 demande « code-barres **ou** QR Code » — le corpus laisse le choix, et les deux
branches n'ont pas le même coût :

- **Vérifié** : il n'existe **aucune** bibliothèque de code-barres ni de QR côté PHP dans ce dépôt
  (ni `composer.json`, ni `vendor/`). Un QR exige un encodage Reed-Solomon qu'on n'écrit pas à la
  main, donc il imposerait une **dépendance** (§2.6, accord écrit requis).
- **Code 128** s'écrit en SVG pur en quelques dizaines de lignes : un alphabet de largeurs et une clé
  de contrôle modulo 103, tous deux publics et déterministes — donc **prouvables par vecteurs**, comme
  `ReglesCodeBarres` (B3-c) ou l'algorithme mod-97 du NIS (P6.1), et **sans une dépendance de plus**.
- **Et c'est aussi le choix juste métier** : une étiquette de tube de prélèvement est un code-barres
  linéaire dans tous les laboratoires réels ; un QR sur un tube de 13 mm se lit mal.

`ReglesCode128` sera donc une **classe pure** de plus, au même titre que `ReglesCodeBarres`, avec son
rendu SVG imprimable — et K9 reste vrai : on ne réutilise pas le GTIN, on écrit l'autre algorithme.

**Le scan**, lui, ne demande rien : un lecteur de comptoir USB se comporte comme un clavier, un champ
texte le reçoit (patron E6 de B3-c, déjà éprouvé au comptoir de l'officine). La caméra du portail
reste un confort optionnel sur le CDN `html5-qrcode` déjà présent — **dette existante, ni aggravée ni
refermée ici**.

### Découpage révisé — trois sous-lots

Le périmètre a doublé : le circuit complet, les automates, un journal d'audit et une notification.

| | Contenu |
|---|---|
| **B5-a** | Fermeture de la porte `source` (L4, K11) · `demandes_analyses` + `demande_analyse_lignes` · lien catalogue · jeton · `DocumentPrescriptionBiologique` (L8) · écran de prescription depuis la consultation · la demande au carnet du patient |
| **B5-b** | `prelevements` · `ReglesCode128` + étiquette imprimable (L16) · scan · le cycle à six états et ses gardes du moteur (L6) · `journal_laboratoire` (L13) · écran laboratoire |
| **B5-c** | Résultats : **saisie et import** par un seul service (L15) · `automates` + domaine `resultats_laboratoire` (L10) · `validations_biologiques` et le verrou (L7) · publication au carnet en `source = 'structure'` · **notification `RESULTAT_ANALYSE_PUBLIE`** (L14) |

**Pourquoi cette coupure-là** : chaque sous-lot est prouvable seul et laisse le système cohérent —
une demande d'examen est utile sans laboratoire (le patient l'emporte, comme une ordonnance papier) ;
un prélèvement suivi et étiqueté est utile sans résultat publié (c'est déjà la traçabilité que le
§7.4 réclame) ; et B5-c est le seul qui touche le carnet du patient, donc le seul qui exige le
vecteur anti-fuite et la campagne de mutation sur la garde de validation.

**Aucun des sept éléments demandés n'est reporté** : ils sont tous dans le tableau ci-dessus.

### Ce que ces amendements ajoutent aux preuves exigées (§7)

Aux vecteurs déjà listés s'ajoutent : un client posant `source: 'structure'` sur son propre antécédent
est ignoré **et n'apparaît pas dans la fiche de parcours** (K11 — deux vecteurs, un par couche) · un
automate ne peut **pas** publier sans validation humaine (L10, la garde la plus importante du lot) ·
un identifiant de prélèvement inconnu envoyé par un automate est **refusé et nommé**, jamais rapproché
· un résultat importé porte `origine = 'automate'` **même si l'appelant déclare le contraire** (L15) ·
la notification ne contient **ni nom d'analyse ni valeur** (L14, test anti-fuite rejoué) · le journal
du laboratoire **refuse** modification et suppression, au niveau du modèle **et** du moteur (L13) · un
Code 128 se relit — vecteurs sur l'alphabet et la clé modulo 103 (L16).

### Limites, après élargissement

Celles de §8 tiennent, **sauf « aucun automate »** qui disparaît, remplacée par : **le driver
ASTM/HL7 côté laboratoire n'est pas fourni** — notre moitié du contrat l'est, la sienne suppose un
appareil que nous n'avons pas vu. S'y ajoutent : le QR n'est pas proposé, seul le Code 128 l'est
(L16) · l'import ne couvre pas les **fichiers plats** déposés à la main (un troisième chemin
d'écriture non demandé) · le journal du laboratoire **n'est pas une chaîne de hachage** (ADR-042 a
montré ce que coûte une chaîne ; *on ne durcit pas par symétrie décorative*, décision déjà prise et
motivée par B3-c).

**Ce périmètre élargi est VALIDÉ par le propriétaire (« je valide le G1 de B5 », 2026-09-05).
Exécution engagée dans l'ordre du découpage révisé ci-dessus : B5-a → B5-b → B5-c.**

---

## 11. Exécution — B5-a, ce qui a réellement changé par rapport au plan

**✅ VALIDÉ (G5, 2026-09-05)** — G4 propriétaire OK. Détail complet : ADR-057, `CLAUDE.md`
(entrée B5-a), guide `GUIDE_TEST_APPLICATIONS_METIER.md` partie 17.

Les décisions D2→D7 (schéma, réutilisation de `RegistreSectionsCarnet`, fermeture de `source`,
lien au catalogue, jeton, signature) ont été suivies sans écart de fond. **Deux points ont dû
être précisés pendant l'exécution, ni l'un ni l'autre anticipés par ce plan** :

1. **La colonne `structure_nom` a dû être renommée `structure_sanitaire`.** Le plan schématique
   (§4) ne nommait pas explicitement cette colonne comme devant correspondre à un nom déjà pris
   ailleurs. Or `EcritureSoignantService::ecrire()` — le mécanisme générique dont D2 annonce la
   réutilisation « sans code neuf » — vérifie le nom de colonne **littéral** `structure_sanitaire`
   (celui d'`antecedents` et `ordonnances`) pour savoir QUAND réécrire l'établissement depuis la
   fiche du soignant. Une colonne nommée différemment aurait laissé ce mécanisme muet, sans
   erreur : `medecin_nom` se serait posé, `structure_sanitaire` (sous son ancien nom) serait
   toujours resté vide. Trouvé par `test_une_demande_du_soignant_designe_sa_fiche_et_son_etablissement`,
   pas par la relecture.
2. **La garde de `ProjecteurLignesDemande::projeter()` comparait un enum à une chaîne.**
   `$demande->statut !== StatutDemandeAnalyse::EMISE->value` — `statut` étant casté en enum
   (`StatutDemandeAnalyse::class`), cette comparaison était **toujours vraie**, quel que soit
   l'état réel de la demande : aucune ligne n'était jamais projetée, sur aucune demande, dans
   aucun scénario. Trouvé par la même campagne de tests (`test_deux_examens_produisent_deux_lignes`
   et les suivants), avant tout G2. Un second défaut lié : le modèle ne portait pas de valeur
   `statut` **en mémoire** avant le premier `save()` — Eloquent ne relit jamais un défaut SQL
   après un INSERT — donc même une comparaison juste aurait lu `null`. Corrigé en ajoutant
   `statut` aux `$attributes` par défaut du modèle, à côté de `source` (patron déjà en place).

Aucun autre écart. Les seize décisions L1→L16 du §10 restent la référence pour B5-b et B5-c ; L3,
L6, L7, L10→L15 et L16 n'ont pas encore de code — B5-a n'a livré que D2→D7 (L1/L2/L4/K5/K11/L5
partiel/L8/L9 partiel).

## 12. Exécution — B5-b, ce qui a réellement changé par rapport au plan

**✅ VALIDÉ (G5, 2026-09-05)** — G4 propriétaire OK (« G4 validé, c'est pour le G5 »). Détail
complet : ADR-058, `CLAUDE.md` (entrée B5-b), guide `GUIDE_TEST_APPLICATIONS_METIER.md` partie 18.

Livre L3, L5, L6 (cycle jusqu'à `en_analyse` ; `valide`/`publie` restent déclarés dans l'ENUM mais
**inatteignables** — aucune transition n'y mène, elles supposent un résultat que B5-c seul
construit), **L7 partiel** (`analyse.executer` donnée au `laborantin` ; `analyse.valider` reste
une chaîne nommée dans les commentaires, sans permission créée — elle appartient au contrôleur de
validation biologique de B5-c, l'écrire ici aurait été une permission sans porte, la préparer sans
elle a aucun sens), L13, L16. **L6/L7/L10/L14/L15 pour le reste (automates, validation,
publication, notification) restent entièrement à B5-c**, exactement comme annoncé.

**QUATRE ÉCARTS AU PLAN, AUCUN DE FOND, TOUS TROUVÉS EN CONSTRUISANT — PAS AU G0** :

1. **`StatutPrelevement` reste un enum PHP-only, PAS un enum partagé `@masante/shared`.** Le plan
   G1 disait « miroir @masante/shared » sans le justifier davantage. Écrit le fichier, la décision
   a été reconsidérée par analogie avec `StatutConsultation`/`StatutDemandeAnalyse` (P10b-1/B5-a) :
   ces deux précédents récents sont restés backend-only, et L12 fait de ce lot un lot **Blade
   seul** (aucun consommateur Next/mobile). Promouvoir l'enum aurait recréé exactement ce que
   P6.4d appelle une « clé qui n'attend personne ». Documenté comme **ÉCART ASSUMÉ AU PLAN G1**
   dans le docblock de la classe elle-même, pas seulement ici.
2. **`ReglesCodeBarres` de B3-c (GTIN) n'a PAS été réutilisée pour l'identifiant du prélèvement.**
   K9 l'avait déjà anticipé au G0, mais l'implémentation l'a confirmé concrètement : un GTIN
   identifie un *produit fabricant* mondial (13 chiffres, clé de contrôle EAN), un identifiant de
   prélèvement identifie une *instance créée par nous* (`PRE-`+10 caractères aléatoires, patron
   `DEM-` de P11.1) — les deux n'ont ni la même forme ni la même autorité de nommage. Une classe
   neuve (`GenerateurIdentifiantPrelevement`) plutôt qu'un détournement.
3. **Un DÉFAUT RÉEL DANS B5-a, trouvé EN CONSTRUISANT B5-b, PAS AU G0 DE B5-b** : le garde-fou de
   `ProjecteurLignesDemande::projeter()` comparait `statut` (un ENUM) à `EMISE` (une chaîne) — donc
   toujours vrai, quel que soit l'état réel — et il **manquait en plus toute vérification
   relationnelle** : rien n'empêchait de reprojeter les lignes d'une demande déjà prélevée, ce qui
   aurait désynchronisé silencieusement le tube physique (dont l'identité biologique est figée à
   l'enregistrement) de la liste d'examens que le carnet affiche. Corrigé par une seconde garde
   `if ($demande->prelevements()->exists()) { return -1; }`, documentée dans le fichier lui-même
   sous le titre « CORRECTION TROUVÉE EN CONSTRUISANT B5-b, PAS AU G0 » — B5-a ne pouvait pas la
   trouver, aucun `prelevements` n'existait encore pour la prouver.
4. **Le journal d'accès (`acces_dossier`) devait rester à zéro tout au long du cycle, pas
   seulement à l'enregistrement.** Le plan ne le disait qu'implicitement (L3) ; vérifié
   explicitement à CHAQUE étape du G2 live (consultation, enregistrement, réception, mise en
   analyse) : **une seule ligne dans `acces_dossier` du début à la fin**, celle du référent qui
   avait ouvert le dossier pour écrire la demande — zéro venant du laboratoire.

**PROUVÉ G3** : `CircuitPrelevementTest`, 34 vecteurs dédiés, 167 assertions ; suite complète
**1826/1826, 18 263 assertions, 0 échec** (confirmée après la campagne de mutation). **Mutation
manuelle : 4 mutations tueuses + 1 témoin volontairement vert**, chacune assertée appliquée avant
interprétation, chaque fichier restauré et vérifié par `diff` :
- M1 — `assertAppartientAuLaboratoire()` neutralisée (`abort_if(false, 404)`) → tue exactement
  `test_un_laboratoire_d_une_autre_structure_ne_peut_pas_transitionner` **et**
  `test_un_laboratoire_d_une_autre_structure_recoit_404_en_http` (2 vecteurs, direct-call **et**
  HTTP réel — la garde vaut sur les deux chemins).
- M2 — la garde SQLite de non-régression du rang (`WHEN (1=0) AND (...)`) → tue exactement
  `test_le_moteur_refuse_qu_un_etat_remonte`.
- M3 — la garde relationnelle neuve de `ProjecteurLignesDemande` (`if (false) { return -1; }`) →
  tue exactement `test_une_demande_prelevee_n_est_plus_reprojetee`.
- M4 — `assertHabilite()` neutralisée (`if (false)`) → tue exactement
  `test_un_laborantin_sans_habilitation_est_refuse`.
- Témoin — réordonnancement de six affectations indépendantes dans `enregistrer()` (aucune ne
  dépend d'une autre) → **34/34 restent verts** : le harnais ne tue pas indistinctement.

**PROUVÉ G2 LIVE MySQL, réel de bout en bout** (base sauvegardée `mysqldump --routines --triggers`
avant migration, stderr redirigé séparément — piège de B5-a évité — puis migration réelle, deux
laboratoires réels, un médecin réel désigné référent d'un membre réel, une demande réelle créée par
HTTP avec `medecin_nom`/`structure_sanitaire`/`source` falsifiés par le client et **tous ignorés**
[`Dr Kablan Koffi`/`CHU de Cocody`/`medecin` posés à la place], deux lignes projetées dont une liée
au catalogue [`ANA000001`/`g/dL` figés] et une hors catalogue) :
- Jeton faux → **404** ; vrai jeton → demande affichée avec ses deux lignes, **`acces_dossier`
  reste à 1** (la seule ligne du référent, posée avant B5-b).
- Enregistrement réel du prélèvement (`PRE-K9ISKBUEKO`) → **`acces_dossier` toujours à 1**.
- Mise en analyse tentée avant réception → refusée, statut inchangé, message affiché à l'écran.
- Réception directe depuis `preleve` (transport sauté) → `recu`, confirmant L6 (`expedie`
  facultatif) **en réel**, pas seulement en test.
- Mise en analyse → `en_analyse`, `execute_par_user_id` posé ; **`acces_dossier` toujours à 1** au
  terme du cycle complet — vecteur central vérifié en direct, pas seulement en SQLite.
- Étiquette SVG réelle récupérée (`Content-Type: image/svg+xml`, rectangles Code 128 rendus).
- **Anti-IDOR réel** : le laborantin du second laboratoire reçoit **404** sur le détail, **404**
  sur l'étiquette, **404** sur `recevoir` (après avoir obtenu un jeton CSRF valide, pour écarter
  un faux positif de 419) — statut du prélèvement inchangé, **zéro ligne** journalisée pour son
  laboratoire.
- **Les quatre gardes du moteur refusent chacune par leur motif exact**, en SQL direct contre la
  base réelle : `valide` sans valideur ni date, `publie` sans résultat ni date, identifiant vide à
  l'insertion, régression de rang (`en_analyse` → `preleve`) — `ERROR 1644` sur les quatre,
  statut final inchangé.
- **`journal_laboratoire` refuse `UPDATE` et `DELETE` au niveau du moteur**, en SQL direct : les
  quatre lignes existantes (une par étape) restent identiques, non modifiées.
- **La garde relationnelle du défaut (3) ci-dessus, vérifiée en direct** : appeler
  `ProjecteurLignesDemande::projeter()` sur la demande déjà prélevée renvoie `-1`, les deux lignes
  restent inchangées.

**Prérequis de déploiement retrouvé une fois de plus** : `PortailRolesSeeder` n'avait pas encore
été rejoué sur cette base après le seeder de B5-a — `analyse.executer` était absente du rôle
`laborantin` jusqu'à ce qu'il soit relancé (conséquence de déploiement, famille B1-c/B1-d/B4-b).

**Base restaurée avec un piège relevé et corrigé pendant la restauration elle-même** : le
`mysqldump` pris **avant la migration** ne connaît pas les tables `prelevements`/
`journal_laboratoire` (elles n'existaient pas encore) — sa réimportation restaure les DONNÉES des
145 tables préexistantes mais **ne supprime pas** les deux tables créées après coup ; il a fallu un
`DROP TABLE` explicite des deux pour revenir à un état réellement pré-migration (147 → 145 tables,
migration redevenue `Pending`, cohérent). *Restaurer un dump ne défait que ce que le dump
connaissait — une leçon pour tout futur G2 live qui créerait des tables neuves.* Vérifié
compte par compte : `users` et `structures_sanitaires` revenus à leurs comptes exacts d'avant
test, fiche médecin n°1 avec `user_id` de nouveau `NULL`, `demandes_analyses`/`acces_dossier`
revenus à 0, `.env` inchangé.

**Aucune dépendance nouvelle** (SVG en chaînes PHP pures, aucune bibliothèque de code-barres).

---

## 13. Compléments de conception — B5-c (avant exécution, 2026-09-05)

Le périmètre reste celui validé au §10 (L7, L10 réécrit, L13→L16). Ce qui suit comble ce que ces
décisions de haut niveau ne tranchaient pas encore — trouvé en instruisant l'implémentation, écrit
avant d'écrire le code de service, comme l'exige la règle des trois fichiers.

**M1 — Les résultats bruts sont mis en attente HORS du carnet, sur `prelevements` lui-même.**
`resultats_bruts_json` (chiffré, même structure que `resultats_analyses.resultats_json` :
`{parametre, valeur, unite, analyse_id, code_national, ...}`) et `resultats_bruts_origine`
(`saisie`|`automate`), additifs sur `prelevements`. **Pourquoi pas une ligne `resultats_analyses`
créée tout de suite et cachée** : `CarnetSectionController::index()` liste sans filtre de statut —
il n'existe aucune notion de brouillon dans le carnet. Créer la ligne avant validation la rendrait
visible au patient AVANT que L7 ne l'autorise. La table qui porte le brouillon n'est donc pas celle
qui porte le résultat définitif : deux natures, deux tables.

**M2 — Saisie et import écrivent CE MÊME COUPLE DE COLONNES, par UN SEUL service (L15).**
`ServiceValidationBiologique::saisir()` (laborantin, portail, exige `analyse.executer`) et
`::importer()` (automate, API, authentifié par clé+HMAC, aucune notion d'utilisateur portail)
appellent une méthode privée commune qui : (a) exige `statut === EN_ANALYSE` — garde
**applicative**, dite comme telle, jamais déguisée en garantie du moteur (elle porte sur une
colonne texte, pas sur une somme comme B3-b, mais le principe de l'honnêteté reste : le moteur ne
la vérifie pas, le service si, et c'est écrit) ; (b) résout le lien au catalogue par
`ServiceLienAnalyse::resoudre()` — **réutilisé**, jamais réécrit ; (c) écrit
`resultats_bruts_json`/`_origine` ; (d) journalise (`resultat_saisi` / `resultat_importe`).

**M3 — Resaisir avant validation REMPLACE le brouillon ; après validation, la garde applicative
refuse.** Une correction avant relecture n'est pas une falsification. Après `valide`, il faut
d'abord un rejet pour rouvrir la porte.

**M4 — Le rejet EFFACE le brouillon, journalise, et NE CHANGE PAS LE STATUT** (il est déjà
`en_analyse` au moment où rejeter a un sens : des valeurs existent, le biologiste les juge
mauvaises). `rejeter()` écrit une ligne `validations_biologiques` (verdict=`rejete`, motif
**obligatoire, sans défaut** — précédent commission P5.5a, révocation P11.2, motif de rejet
P11.1 : un rejet sans motif ne dit à personne, dans six mois, pourquoi une saisie a été jetée) et
remet `resultats_bruts_json`/`_origine` à `null`.

**M5 — Un prélèvement `en_analyse` SANS brouillon ne peut pas être validé.** `valider()` exige
`resultats_bruts_json` non vide, sinon « aucun résultat à valider ».

**M6 — Une demande est CONSOMMÉE par sa publication : `DemandeAnalyse::estOuverte()` (posée par
B5-a, jamais câblée) devient une garde réelle.** `enregistrer()` (B5-b) ne vérifiait rien sur
l'état de la demande — invisible tant que `servie` était inatteignable. C'est un défaut réel que
B5-b avait laissé ouvert **sans pouvoir le savoir** (aucune transition ne menait encore à `servie`
au moment où B5-b a été écrit et prouvé). B5-c ajoute `assertOuverte()` : un nouveau prélèvement
contre une demande déjà `servie` ou `annulee` est refusé, nommant l'état. **Une demande = un
cycle** : si un examen exige un second prélèvement après publication, c'est une nouvelle
prescription (une nouvelle `DemandeAnalyse`), miroir du monde réel où une ordonnance honorée ne se
réutilise pas. Plusieurs prélèvements AVANT publication restent possibles (échantillon insuffisant,
reprise) — rien ne les interdit, seule la publication ferme la porte.

**M7 — La publication AGRÈGE et passe par le MÊME point d'accroche que les trois autres chemins
d'écriture du carnet.** `publier()` construit `type_analyse='biologique'` (hors radiologie,
périmètre B5), `intitule` = libellés des lignes de la demande joints, `date_analyse` = date du
prélèvement (`preleve_le`), `resultats_json` = le brouillon déjà résolu, `medecin_prescripteur_id`
= `demande.medecin_id`, `medecin_prescripteur` = `demande.medecin_nom` (repli), `laboratoire_id` =
`prelevement.laboratoire_structure_id` — puis fait passer ce tableau par
`RegistreSectionsCarnet::controleur('resultats-analyses')->preparerDonnees()`, **exactement le
point d'accroche qu'utilisent le patient, le délégué et le soignant** (motif
`EcritureSoignantService` : « une garantie qui ne vaudrait que sur l'un des chemins n'en serait pas
une » — désormais QUATRE chemins). `source='structure'` et `origine` sont posés APRÈS, hors de la
portée de `preparerDonnees()` — ce sont les valeurs que K5/K11 viennent justement de fermer au
client, jamais un choix qu'on lui laisse.

**M8 — `resultats_bruts_json` SURVIT à la publication, gelé.** C'est la pièce médico-légale de ce
que le laboratoire a réellement validé et transmis. `resultats_analyses.resultats_json` (dans le
carnet) reste modifiable par le patient — droit déjà acquis sur toutes les sections, non retiré
ici. Les deux copies peuvent donc un jour diverger, et **c'est attendu, pas un défaut** : la
divergence dirait qu'un patient a changé sa copie, au même titre qu'une signature qui casse dit
qu'un document a été modifié (P6.5b) — sans qu'aucune loi n'interdise à un patient de tenir son
propre carnet.

**M9 — Les automates sont DÉCLARÉS PAR COMMANDE, pas par écran** (`masante:laboratoire:automate`),
même raisonnement qu'`EmettreClientApiCommand` (P11.2) : déclarer un appareil qui écrira dans des
dossiers patients est un acte d'exploitation vérifié hors du système, pas une saisie de routine
qu'un gestionnaire ferait seul. `automates.client_api_id` (nullable, `nullOnDelete`) trace SOUS
QUELLE CLÉ cet appareil pousse — **il n'authentifie rien lui-même** : l'authentification reste
entièrement portée par le HMAC (`AuthentificationClientApi`, inchangé) ; l'`automate_id` porté par
la charge ne fait que désigner, pour le journal, quel appareil parle, et le serveur vérifie qu'il
appartient à LA MÊME structure que le client authentifié — anti-usurpation d'un automate par la clé
d'un autre laboratoire.

**M10 — Le groupe de routes `laboratoire.*` s'ouvre à DEUX permissions**
(`permission:analyse.executer|analyse.valider`, patron déjà en place :
`rdv.prevalider|rdv.validate` en B1-a, `protocole.valider.clinique|protocole.valider.reglementaire`
en P10b-1) : un biologiste qui n'exécute jamais de prélèvement doit pouvoir ouvrir la fiche pour la
valider. **La garde qui compte reste dans le service**, jamais seulement le middleware (piège P4) :
`valider()`/`rejeter()`/`publier()` exigent `analyse.valider` ; `enregistrer()`/`expedier()`/
`recevoir()`/`mettreEnAnalyse()`/`saisir()` exigent `analyse.executer` — jamais l'inverse, jamais
un cumul supposé (L7 : pas d'interdiction de cumul, mais chaque acte nomme la permission qui
l'autorise).

**M11 — La notification `RESULTAT_ANALYSE_PUBLIE` (L14) suit exactement le patron
`carnetEnrichi()`/`rendezVousTermine()`** : destinataires = titulaire + délégués en lecture, corps
= « Un résultat d'analyse a été déposé dans votre carnet par le Laboratoire {nom}. », **jamais**
l'intitulé de l'analyse. Émise uniquement par `publier()`, jamais par `valider()`/`saisir()`.

**M12 — Extension additive de `journal_laboratoire.action`** (`->change()`, patron P11.2 §Aug-30,
dual dialecte prouvé en G2 live) : `resultat_saisi`, `resultat_importe`, `validation`, `rejet`,
`publication`. Aucune valeur retirée, aucune ligne existante réinterprétée (précédent constant
depuis `triages.niveau`, P10b-1).

Ce complément suffit à couvrir la totalité du périmètre §10 restant : résultats (saisie ET import,
M1-M5), `automates` (M9), validation biologiste et son verrou (M4-M5, L7), publication en
`source='structure'` (M6-M7), notification (M11).

---

## 14. Exécution — B5-c, ce qui a réellement changé par rapport au plan

**✅ VALIDÉ (G5, 2026-09-06)** — G3/G2 menés le 2026-09-05, G4 propriétaire OK et mot du G5 donnés
le 2026-09-06. **Le lot B5 (le circuit du laboratoire) est
COMPLET (a, b, c) — l'étape 9 de l'ordre CDC_11 §12 est achevée.** Détail complet : ADR-059,
`CLAUDE.md` (entrée B5-c), guide `GUIDE_TEST_APPLICATIONS_METIER.md` partie 19.

Les décisions M1→M12 du §13 ont été suivies **sans écart de fond**, à une exception near : **M7 a
été implémentée différemment de ce que sa première formulation laissait entendre**, et c'est une
correction trouvée pendant l'écriture du service, pas au G0.

1. **M7, corrigé pendant l'implémentation** : le complément annonçait que `publier()` ferait passer
   le tableau reconstruit par `RegistreSectionsCarnet::controleur('resultats-analyses')->preparerDonnees()`
   — le même point d'accroche que les trois autres chemins d'écriture. En écrivant le code, ce choix
   s'est révélé **faux** : `ResultatAnalyseController::preparerDonnees()` appelle
   inconditionnellement `ServiceLienAnalyse::resoudre()` sur `resultats_json` s'il est présent — donc
   passer par ce composite aurait **re-résolu** le brouillon contre le catalogue **au moment de la
   publication**, sur des valeurs déjà figées à la saisie. Si le catalogue change entre les deux
   instants (une unité corrigée sous quatre-yeux), le résultat publié aurait changé silencieusement,
   sans qu'aucun biologiste ne l'ait revu — exactement le risque que `ProjecteurLignesDemande` (B5-a)
   existe pour fermer sur les lignes de la demande. Corrigé : `publier()` appelle `ServiceLienResultat`
   **directement** (il est de toute façon injecté indépendamment du contrôleur), et copie
   `resultats_json` **verbatim** depuis `resultats_bruts_json`. Un vecteur dédié
   (`test_un_changement_du_catalogue_apres_saisie_ne_change_pas_le_resultat_publie`) et une mutation
   qui force la ré-résolution (tuée) prouvent cette décision. **Documenté comme ÉCART ASSUMÉ AU PLAN
   du complément**, dans le docblock de `ServiceValidationBiologique` lui-même.
2. **`assertOuverte()` (M6) ajoutée à `ServiceCircuitPrelevement::enregistrer()`**, pas seulement à
   `ServiceValidationBiologique`, puisque c'est là que la garde doit vivre (la même classe qui pose
   déjà `assertHabilite()`/`assertLaboratoire()`). Le complément le disait déjà correctement ; noté
   ici pour mémoire de l'emplacement exact.
3. **Défaut de migration réel, trouvé par la suite complète et non par les 42 vecteurs dédiés à
   B5-c** : voir ADR-059 §3 pour le détail complet — `journal_laboratoire.action` étendu par
   `->change()` faisait disparaître, **sous SQLite seulement**, les deux gardes append-only posées
   par B5-b (la reconstruction de table qu'exige ce dialecte pour un tel changement supprime les
   déclencheurs attachés à la table qu'elle recrée). **Vérifié que MySQL n'est jamais concerné**
   (`SHOW TRIGGERS` après la vraie migration sur la vraie base). Corrigé par
   `reconstituerGardesJournalLaboratoire()`, appelée après chaque `->change()` sur cette table dans
   `up()` **et** `down()` — no-op sous MySQL, recrée les deux gardes sous SQLite.
4. **`ServiceCircuitPrelevement::travailPour()` corrigé** (défaut hérité de B5-b, décrit dans ADR-058
   §5, refermé ici comme prévu) : la liste s'arrêtait à `en_analyse`, un prélèvement `valide` en
   attente de publication en disparaissait sans qu'aucun biologiste ne puisse le retrouver. Étendue à
   `valide`.
5. **`analyse.valider` ajoutée à `PortailRolesSeeder::PERMISSIONS`, `packages/shared/src/enums/permissions.ts`
   et au corps du seeder comme QUINZIÈME permission volontairement orpheline** — le complément
   l'annonçait sans en préciser l'emplacement exact dans la liste numérotée du seeder ; insérée à la
   suite d'`ia_triage.valider`, avec sa propre justification écrite (un résultat biologique validé
   engage la responsabilité d'un biologiste nommé).
6. **Le groupe de routes `laboratoire.*` passe de `permission:analyse.executer` à
   `permission:analyse.executer|analyse.valider`** (M10), patron déjà en place ailleurs
   (`rdv.prevalider|rdv.validate`, B1-a ; `protocole.valider.*`, P10b-1) — conforme au complément,
   noté ici pour la trace du fichier modifié (`routes/web.php`).

Aucun autre écart. Les décisions L1→L16 du plan initial (§10) sont désormais toutes livrées : L3,
L5, L6, L7, L8, L13, L16 par B5-b ; L1, L2, L4, K2/K5/K11, L9 (partiel, demande) par B5-a ; L9
(partiel, résultat), L10 réécrit, L11 (confirmé), L14, L15 par B5-c.

**PROUVÉ G3** : `ResultatBiologiqueTest`, 42 vecteurs dédiés, 94 assertions ; suite complète
**1868/1868, 18 357 assertions, 0 échec** (deux exécutions indépendantes, confirmées propres).
**Mutation manuelle : 8 tueuses + 1 témoin volontairement vert**, chacune assertée appliquée avant
interprétation, chaque fichier restauré et vérifié par `diff` :

- M1 — garde applicative `en_analyse` (`enregistrerBrouillon`, `if (false)`) → tue 2 vecteurs
  (avant mise en analyse, après validation).
- M2 — brouillon requis pour valider (`assertPeutJugerLeBrouillon`, `if (false)`) → tue 1 vecteur.
- M3 — permission `analyse.valider` (`assertHabiliteValidation`, `if (false)`) → tue 1 vecteur.
- M4 — garde `VALIDE` de `publier()` (`if (false)`) → tue 1 vecteur.
- M5 — `assertOuverte()` (`ServiceCircuitPrelevement`, `if (false)`) → tue 1 vecteur.
- M6 — anti-IDOR (`assertAppartientAuLaboratoire`, `abort_if(false, 404)`) → tue 2 vecteurs (404
  direct, refus de publier).
- M7 — motif de rejet obligatoire (`if (false)` sur le service) → **tue 1 vecteur, mais PAR LA
  GARDE DU MOTEUR** : le service neutralisé, le déclencheur `trg_validation_bio_insert` intervient
  seul et lève une `QueryException` au lieu de la `ValidationException` attendue — la mutation
  reste tuée, par une couche différente. Défense en profondeur **observée en pratique**, pas
  seulement conçue.
- M8 — anti-usurpation d'un automate d'un autre laboratoire (`importer()`, `if (false)`) → tue 1
  vecteur.
- M9 — **la mutation la plus importante du lot** : forcer `publier()` à ré-résoudre
  `resultats_json` via `ServiceLienAnalyse::resoudre()` au lieu de la copie verbatim → tue
  exactement le vecteur qui prouve la décision corrigée du point 1 ci-dessus (l'unité publiée passe
  de `g/L` à `mmol/L`, la valeur du catalogue modifié après coup).
- Témoin — réordonnancement de deux affectations indépendantes dans `enregistrerBrouillon()`
  (`resultats_bruts_origine` avant `resultats_bruts_json`, aucune ne dépend de l'autre) → **42/42
  restent verts**.

Pint propre sur tous les fichiers touchés ; baseline établie avant tout formatage
(`ResultatAnalyse.php` gardait une seule ligne mal alignée, préexistante à B5-c, non reformatée).

**PROUVÉ G2 LIVE MySQL réel**, en trois temps, détaillés dans ADR-059 §4 :

1. Schéma et déclencheurs (migration réelle, `mysqldump --routines --triggers` avant, stderr
   redirigé séparément) — les deux `trg_journal_labo_*` de B5-b vérifiés **intacts** après
   l'extension de l'ENUM (§3 ci-dessus, `SHOW TRIGGERS`) ; garde de motif et append-only de
   `validations_biologiques` vérifiées en SQL direct (`ERROR 1644`).
2. Cycle complet par le SERVICE contre la vraie connexion MySQL : brouillon vérifié **chiffré en
   base** par requête SQL directe (aucun texte en clair) ; **catalogue modifié entre la saisie et
   la publication → l'unité publiée reste celle de la saisie** (preuve directe de la décision
   corrigée, en réel, pas seulement en test) ; `acces_dossier` reste à **0** de bout en bout ;
   demande `servie` puis nouveau prélèvement refusé en nommant l'état ; `travailPour()` incluant un
   prélèvement `valide`.
3. Cycle complet par le VRAI portail (sessions et CSRF réels) : laborantin réel connecté saisit un
   vrai formulaire de résultat ; biologiste réel (**rôle `laborantin` + permission nominative
   `analyse.valider`** — aucun rôle `biologiste` n'existe dans ce projet, D4 d'ADR-059) voit le
   brouillon, valide, publie ; page finale confirmée ; **anti-IDOR réel** : laborantin d'un second
   laboratoire → 404 ; **ingestion automate réelle signée** (client PHP autonome imitant un vrai
   middleware de laboratoire, HMAC calculé à la main) : envoi accepté (200), **rejeu refusé** (401
   réel), **identifiant de prélèvement inconnu refusé et nommé** dans la réponse JSON réelle,
   signature fausse refusée (401) ; vérifié en base réelle que le prélèvement importé par l'automate
   reste `en_analyse` (`resultat_analyse_id` NULL) et que le corps de la notification ne contient
   que le nom du patient et du laboratoire.

**Base restaurée compte pour compte** : dump réimporté, les quatre tables neuves (`prelevements`,
`journal_laboratoire`, `validations_biologiques`, `automates` — les deux premières héritées de
B5-b, jamais recréées par le dump pris avant leur propre migration) explicitement supprimées
(leçon retenue de B5-b : « restaurer un dump ne défait que ce que le dump connaissait »),
migrations `2026_09_05_000003`/`2026_09_05_000004` revenues à `Pending`, 145 tables, `.env`
inchangé, structures/utilisateurs/demandes/résultats vérifiés revenus à leurs effectifs exacts
d'avant test.

**Aucune dépendance nouvelle.**
