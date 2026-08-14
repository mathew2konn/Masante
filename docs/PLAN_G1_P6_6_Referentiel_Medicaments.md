# Plan G1 — P6.6 Référentiel National des Médicaments (CDC_09 §6)

Étape **6** de l'ordre CDC_09 §14. Suit P6.5 (professionnels + PKI), précède P6.7 (laboratoires).

**Trois décisions du propriétaire (2026-08-14)** :

1. **Le lien « une ordonnance désigne un médicament du référentiel » fait partie de P6.6.**
2. **Les interactions sont une donnée du référentiel, consultable explicitement** — le moteur
   d'analyse reste au `interaction-service` de CDC_05.
3. **La pharmacovigilance §6.5 (propagation d'un retrait) est un incrément séparé.**

---

## 1. G0 — ce que le code et le corpus disent réellement

### A1 — Une ordonnance ne désigne aucun médicament du référentiel

`OrdonnanceController::regles()` valide `medicaments_json.*.nom` en `required|string|max:200`.
C'est du **texte libre**, et rien ne relie une prescription à la table `medicaments`.

C'est le **miroir exact** du défaut que P6.5 vient de refermer sur `ordonnances.medecin_nom` : là,
n'importe qui pouvait porter le nom de n'importe quel médecin ; ici, une ordonnance peut nommer
n'importe quelle molécule, sous n'importe quelle orthographe. §6.1 dit précisément l'inverse :
« éviter les incohérences de nommage et garantir une prescription fiable ».

### A2 — L'exemple imposé au §6.3 n'est pas représentable aujourd'hui

| §6.3 impose | Colonne existante |
|---|---|
| `Code : MED000458` | **aucune** |
| `DCI : Paracétamol` | `nom_generique` ✅ |
| `Nom commercial : Doliprane®` | `nom_commercial` ✅ |
| `Dosage : 500 mg` | **aucune** |
| `Voie : Orale` | **aucune** |
| `Classe : Analgésique` | `categorie` ✅ |

La table a **7 colonnes** ; **8 des 15 données** de §6.2 manquent : code national, laboratoire
fabricant, forme pharmaceutique, dosage, voie d'administration, indications, contre-indications,
**interactions**, effets secondaires (et le statut générique/princeps n'est qu'un booléen
« un générique existe », ce qui n'est pas la même affirmation).

### A3 — `medicaments` n'est pas gouverné

`RegistreReferentiels` contient `seuils_mesure`, `symptomes_triage`, `etablissements`,
`professionnels`. **Pas `medicaments`** — alors qu'ADR-025 §2.6 réserve la liste blanche à ce qui
porte de vraies règles, et qu'un référentiel portant **interactions**, **contre-indications** et
**prix homologué** en porte autant que des seuils cliniques.

### A4 — Le problème de P6.4a ne se reproduit PAS ici *(vérifié, pas supposé)*

En P6.4a, `note_moyenne` était recalculée à chaque avis : versionner la table aurait rendu
l'instantané divergent en permanence. J'ai vérifié le cas des médicaments plutôt que de recopier la
conclusion : **aucune écriture automatique sur `medicaments`**. Les prix relevés par les citoyens et
les ruptures signalées vont dans `prix_pharmacie`, une **table séparée**. La ligne de médicament est
donc **entièrement** de la donnée d'autorité — la projection peut la prendre en entier.

### A5 — Les allergies sont du texte libre

`antecedents.type = 'allergie'` avec une `description` libre. §6.4 attend un « signalement des
allergies connues du patient » : il n'y a **rien à rapprocher** tant qu'une allergie ne désigne pas
une DCI. Hors périmètre ici, mais à nommer — sinon §6.4 restera partiellement irréalisable.

### A6 — Un commentaire promet déjà les interactions

`FicheVitaleService` : « *un secouriste doit savoir ce que le patient prend déjà (interactions
médicamenteuses)* ». Le service ne fournit **aucune** interaction. Quatrième occurrence du motif
« un commentaire promet plus que le code » (P6.4c, P6.5b, les trois de L1/L2).

### A7 — Le corpus tranche lui-même la frontière du moteur

CDC_05 §2 liste **`interaction-service`** : « interactions médicamenteuses, contre-indications,
adaptation de doses ». Le moteur d'analyse ne relève donc **pas** de P6.6 — même partage qu'avec la
fraude (ADR-017). P6.6 fournit la **donnée** ; §6.4 (alternatives, génériques, adaptation de doses
selon l'âge, le poids, l'insuffisance rénale) reste à CDC_05.

### A8 — L'existant est un module de prix, pas un référentiel

`MedicamentController` (recherche publique, relevé de prix citoyen, signalement de rupture),
`PrixMedicamentService`, `StockPharmacieController`, écrans mobiles `MedicamentsEcran` et
`ComparateurEcran` : c'est **FN7/FN8**, validé, avec **20 lignes seedées**. L'appeler « référentiel
national » aujourd'hui serait faux. P6.6 l'enrichit — **additivement** (ADR-024).

---

## 2. Découpage — deux incréments

**P6.6a — le référentiel** : schéma complet §6.2, code national `MED`, interactions, mise sous
gouvernance ADR-025, contrôles qualité, backfill, écrans du portail.

**P6.6b — ce qui s'en sert** : le lien ordonnance → référentiel, et la consultation des interactions.

**Pourquoi ce découpage ne reproduit pas le « socle à vide » refusé en P6.3 (D3)** : contrairement à
un certificat sans document à signer, le référentiel enrichi a **déjà des consommateurs vivants** dès
P6.6a — la recherche publique, le comparateur de prix, le stock des pharmacies. Il n'attend pas
P6.6b pour servir.

---

## 3. Décisions de conception

| # | Décision | Pourquoi |
|---|---|---|
| **M1** | Code national **`MED` + 6 chiffres, littéral, sans clé de contrôle** | Précédents `ETS` (P6.4a) et `PRO` (P6.5a). §3.2 impose un checksum **au NIS** et pas ici ; en ajouter un rendrait l'exemple imposé `MED000458` **invalide**. |
| **M2** | `UNIQUE(pays_code, code)`, hors `$fillable` | Le pays qualifie, il ne s'écrit pas dans la valeur (P6.4a). Hors `$fillable` : un client ne choisit pas son numéro national. |
| **M3** | Interactions dans une **table dédiée**, jamais une colonne JSON par médicament | Une interaction est une **relation entre deux DCI**, pas une propriété de l'une d'elles. Deux colonnes JSON diraient deux fois la même chose et pourraient diverger — *deux vérités*. |
| **M4** | **Un seul référentiel gouverné**, dont l'instantané porte les médicaments **et** leurs interactions | Publier les deux séparément permettrait qu'une interaction publiée désigne un médicament absent de la version en vigueur : la référence serait **irrésoluble**. |
| **M5** | La projection prend la ligne **entière** | A4 : rien n'y est recalculé. Le critère de P6.4a est **refait, pas recopié** (précédent P6.5a). |
| **M6** | Le lien est **additif** : `medicament_id` **facultatif** dans chaque entrée de `medicaments_json`, le `nom` libre **conservé** | Un patient qui photographie une vieille ordonnance ne choisit pas dans une liste. Rendre le lien obligatoire ferait des **lacunes du référentiel** (20 lignes) un blocage clinique. |
| **M7** | Quand `medicament_id` est fourni, **le serveur résout et recopie** code national, DCI et dosage ; il ne les croit jamais du client | Miroir de P6.5a, où `nom`/`prenom` sont refusés du client et repris du compte. |
| **M8** | Les valeurs recopiées sont **figées à la prescription** | Le nom commercial d'un médicament peut changer ; une ordonnance **signée** doit continuer de dire ce qui a été prescrit. Précédent : `etablissement` copié à l'écriture en P7-D2. |
| **M9** | Un médicament **retiré** est **signalé, jamais bloqué** à la prescription | Refuser serait une décision médicale prise par le serveur (CDC_00 §4). On rapporte ce que le référentiel affirme. |
| **M10** | Statut de commercialisation en **donnée** : `autorise` / `suspendu` / `retire` | Prépare §6.5 sans le livrer, et évite un `if` en dur (précédent `villes.affiche_communes`). |

### La signature d'ordonnance (P6.5b) — vérification obligatoire

`DocumentOrdonnance::contenuCanonique()` signe `medicaments_json` **en entier**. Ajouter des clés
dans les entrées **change donc le contenu signé** des ordonnances futures — ce qui est correct — mais
**ne doit rien changer aux ordonnances déjà signées**, dont le JSON reste tel quel.

**Un vecteur dédié le prouvera** : une ordonnance signée avant P6.6b reste `INTÈGRE` après la
migration et après l'ajout du champ. Sans lui, on découvrirait le problème sur une ordonnance réelle,
et *une signature qui casse toute seule ne prouve plus rien, et pire, elle accuse* (P6.5b).

---

## 4. Ce qui est construit

### P6.6a

- Migration **additive** sur `medicaments` : `code`, `pays_code`, `laboratoire`, `forme`, `dosage`,
  `voie_administration`, `indications`, `contre_indications`, `effets_secondaires`, `statut_marche`,
  `statut_generique` (générique/princeps), tout **nullable** — *l'absence se dit, une valeur inventée
  serait fausse* (P6.4a).
- Table `interactions_medicamenteuses` : couple **ordonné** de médicaments, `niveau`
  (`precaution` / `association_deconseillee` / `contre_indication`), description, source.
- `GenerateurCodeMedicament` + commande idempotente `masante:medicaments:backfill --dry-run`.
- `SourceMedicaments` (interface `SourceReferentiel`) + une ligne dans `RegistreReferentiels` —
  *ajouter un référentiel = une classe et une ligne* (ADR-025 §2.6).
- Contrôles qualité : DCI absente, dosage sans forme, interaction pointant un médicament inconnu,
  couple d'interaction dupliqué, auto-interaction (un médicament avec lui-même).
- Écrans portail sous `medicament.manage` (la permission existe déjà).

### P6.6b

- `medicament_id` résolu et figé dans `medicaments_json` ; `nom` libre conservé.
- `GET /medicaments/interactions?dci[]=…` — **lecture du référentiel**, aucune décision, aucun
  blocage ; réponse citant la **version** du référentiel qui l'affirme (L2 est faite, on s'en sert).
- Le champ `medicaments` du formulaire mobile (`registre.ts`) gagne la recherche au référentiel.

---

## 5. Preuves attendues

**G3** — vecteurs dédiés dont, en miroir : un médicament **retiré** peut être prescrit (signalé, non
bloqué) · un `medicament_id` inconnu est **refusé** · le client qui envoie `dci` ou `code` les voit
**ignorés** au profit du référentiel · une interaction déclarée dans les deux sens **ne compte
qu'une fois** · un médicament ne peut pas interagir avec lui-même · **une ordonnance signée avant la
migration reste INTÈGRE**. Plus : suite complète, typecheck ×3, **vérification par mutation** des
gardes (la leçon de L1/L2 : un vecteur peut être vert sans rien vérifier).

**G2 live MySQL** — backfill `MED000001…`, unicité refusée par le moteur, CI et SN partageant un
code, publication gouvernée du référentiel (deux agents), `UPDATE` direct sans effet sur le diffusé,
consultation d'interactions, prescription liée puis renommage du médicament **sans réécriture de
l'ordonnance**, base restaurée.

---

## 6. Limites qui seront annoncées

1. **Pharmacovigilance §6.5 non livrée** : le statut `retire` existe, sa **propagation** aux
   pharmacies et aux prescripteurs est un incrément séparé.
2. **Le moteur d'interactions n'est pas là** (CDC_05 `interaction-service`) : P6.6 rapporte ce que le
   référentiel déclare, il n'analyse pas, ne propose pas d'alternative et n'adapte aucune dose.
3. **Les allergies restent du texte libre** (A5) → §6.4 « signalement des allergies connues » reste
   partiellement irréalisable, et il faudra le dire.
4. **Le contenu du référentiel reste un jeu de démonstration** (20 lignes) : charger la base réelle
   DPM/CENAME est **de la donnée, zéro code** — même situation que le découpage sanitaire partiel de
   P6.4a, et il ne faut pas la présenter autrement.
5. **Le lien reste facultatif** (M6) : tant que le référentiel est incomplet, l'imposer ferait de ses
   lacunes un blocage clinique.
