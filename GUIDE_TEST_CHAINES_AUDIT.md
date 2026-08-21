# Guide de test — Chaînes d'audit (origine déclarée, identifiants non référentiels)

> Écrit avant le G4, conservé après le G5 comme procédure de non-régression.
> ADR-042 ; plan `docs/PLAN_G1_Chaines_Audit_Origine.md`.

Ce guide couvre les **quatre chaînes de hachage** du cœur Laravel :
`referentiel_journal` (P6.3), `protocole_journal` (P10b-1), `signature_journal` (P6.5b) et
`protocole_applications` (P10b-2).

---

## 1. Ce que cet incrément change, en une phrase

Une chaîne d'audit **déclare désormais son commencement** et **ancre sa tête**, et les identifiants
de compte qu'elle protège **ne sont plus des clés étrangères** — donc supprimer un compte, qui est
un droit, ne fait plus crier à la falsification.

### Ce qui se voit tout de suite

| Avant | Après |
|---|---|
| supprimer un compte → chaîne « rompue, CONTENU » | la chaîne reste intacte, `acteur_nom` survit |
| supprimer un médecin → chaîne des signatures rompue | intacte |
| un journal vidé de ses 97 premières entrées → **« intacte »** | `ORIGINE` : l'origine n'est pas déclarée |
| un journal vidé **puis réalimenté** → **« intacte »** | `ORIGINE` : la tête n'est plus celle qui a été ancrée |
| un journal vide → « intacte » | « intacte », **mais avec une origine déclarée et datée** (voir la nuance en §6) |
| pas de recommencement possible sans effacer | scellement **déclaré**, motivé, nominatif |

> **Le succès de cet incrément se mesure au fait qu'un voyant passe au ROUGE.** Il mentait. Un
> incrément qui aurait « tout mis au vert » aurait remplacé un silence par un autre.

---

## 2. Préparation

Aucune mise en vigueur, aucun seeder. Une seule migration :

```bash
cd services/api
XDEBUG_MODE=off "$PHP" artisan migrate
```

Elle crée `audit_chaines`, ajoute la colonne `chaine` aux quatre journaux, retire quatre contraintes
de clé étrangère, et **déclare l'origine des seuls journaux vides** au moment où elle passe. Les
journaux qui portent déjà des entrées restent « origine non déclarée » — *nous ne pouvons pas
affirmer que rien ne leur a été retiré en tête, et prétendre le contraire serait le silence même
qu'on corrige.*

---

## 3. Les vecteurs (curl / SQL / artisan)

### W1 — L'état AVANT migration, pour pouvoir comparer

```sql
SELECT COUNT(*) FROM protocole_journal;      -- attendu : des entrées
SELECT COUNT(*) FROM referentiel_journal;    -- attendu : 3 (ids 98→100)
SELECT COUNT(*) FROM signature_journal;      -- attendu : 0
SELECT AUTO_INCREMENT FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referentiel_journal';  -- attendu : 101
```

`GET /api/v1/protocoles/journal/integrite` → `intacte: false`, `CONTENU` sur #1.
La vérification du socle → **`intacte: true`** : c'est le mensonge à faire tomber.

### W2 — Après migration : le voyant menteur passe au rouge

Attendu :

- `protocole_journal` : toujours `intacte: false`, **toujours `CONTENU` sur #1** — la rupture réelle
  n'est ni masquée ni « réparée » — et `entrees: 34`, `origine_declaree: false` s'ajoutent à côté ;
- `referentiel_journal` : passe de **`intacte: true`** à **`intacte: false`, `ORIGINE`** ;
- `signature_journal` : **reste « intacte »**, parce qu'il était **vide** au moment de la migration
  et que son origine a donc été déclarée ce jour-là. *Ce n'est pas une contradiction, c'est la
  portée exacte du mécanisme* — voir §6 : il protège à partir du jour où il est installé et ne
  témoigne pas du passé.

### W3 — Les clés étrangères ont disparu

```sql
SELECT CONSTRAINT_NAME, COLUMN_NAME, TABLE_NAME
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE()
   AND REFERENCED_TABLE_NAME IS NOT NULL
   AND TABLE_NAME IN ('protocole_journal','referentiel_journal','signature_journal');
```

Attendu : **plus aucune ligne pour `acteur_id` ni `medecin_id`**.

### W4 — Supprimer un compte ne casse plus rien

Créer un compte, lui faire écrire une entrée de gouvernance, le supprimer, revérifier :
le verdict doit être **exactement le même qu'avant la suppression**, et `acteur_nom` doit être
intact en base.

### W5 — Le scellement, en simulation puis pour de vrai

```bash
"$PHP" artisan masante:audit:ouvrir-chaine protocole_journal --motif="Essai." --dry-run
"$PHP" artisan masante:audit:ouvrir-chaine protocole_journal \
       --motif="Comptes temporaires supprimés lors des G2 de P10b-1." --acteur="Exploitant"
```

Attendu : la simulation **n'écrit rien** ; le scellement crée la chaîne #2, et
`audit_chaines.verdict_scelle_json` porte **le verdict de la chaîne close tel quel** — `CONTENU`
sur #1. *On ne scelle pas en silence une chaîne cassée.*

### W6 — Rien n'a été réécrit

```sql
SELECT COUNT(*) FROM protocole_journal;          -- inchangé
SELECT id, LEFT(empreinte, 12) FROM protocole_journal ORDER BY id LIMIT 5;  -- inchangé
```

Comparer avec le relevé de W1 : **aucune empreinte ne doit avoir bougé**.

### W7 — La chaîne neuve repart de zéro et se vérifie

Après le scellement : `chaine_courante: 2`, `entrees: 0`, `intacte: true`, et
`chaines_scellees[0]` porte le numéro 1, son volume, son motif, son opérateur, son
`verdict_au_scellement` **et** un `verdict_actuel` recalculé.

Écrire ensuite une entrée de gouvernance : elle doit porter `chaine = 2` et
`empreinte_precedente = NULL`.

### W8 — Un scellement sans motif est refusé

```bash
"$PHP" artisan masante:audit:ouvrir-chaine protocole_journal
```

Attendu : **code de sortie 1**, message exigeant `--motif`, et **aucune ligne créée** dans
`audit_chaines`.

### W9 — Un journal hors liste blanche est refusé

```bash
"$PHP" artisan masante:audit:ouvrir-chaine users --motif="x"
```

Attendu : « Journal inconnu », suivi de la liste des quatre journaux gouvernés.

### W10 — Sceller une chaîne vide est refusé

Sur un journal sans entrée : refus explicite (« il n'y a rien à sceller »).

---

## 4. Ce qu'il faut vérifier même si tout semble marcher

1. **La rupture des protocoles n'a pas disparu.** Si elle a disparu, quelque chose a réécrit
   l'histoire — c'est un défaut, pas un succès.
2. **`acteur_nom` est intact** partout, y compris pour les comptes supprimés : c'est le seul nom
   qu'un humain lira dans un audit.
3. **Aucune entrée n'a été supprimée** : les comptes des journaux doivent être identiques à W1.

---

## 5. Limites de cet incrément, à ne pas prendre pour des défauts

1. **La rupture `CONTENU` de `protocole_journal` n'est pas réparée** et ne le sera jamais. Elle est
   scellable, datée et motivée. *On ne répare pas une chaîne de hachage.*
2. **97 entrées de gouvernance sont perdues**, sans sauvegarde.
3. **Forger une déclaration d'origine reste possible** à qui tient la base ; la **supprimer** ne
   l'est pas sans conséquence (la chaîne repasse au rouge). C'est tout ce qu'un journal peut opposer
   à celui qui possède le serveur.
4. **La chaîne `audit_entries` du paiement** (Java) n'est pas couverte : autre service, autre dépôt.
5. Aucune signature cryptographique du journal au-delà du hachage chaîné (HSM, ADR-032).

---

## 6. Checklist de clôture

> **Clôturée le 2026-08-21 — G5.** Les cases **W** ont été prouvées au G2 live ; base de
> développement sauvegardée avant, **restaurée compte pour compte** après (protocole_journal 34,
> referentiel_journal 3, signature_journal 0, users 8, protocoles 4, triages 2 ; empreintes de tête
> identiques ; colonne `chaine` absente). **G4 déclaré validé par le propriétaire.**

- [x] W1 — état avant relevé (34 / 3 / 0 entrées ; compteurs 35 / 101 / 6 ; 4 clés étrangères)
- [x] W2 — après migration : `referentiel_journal` passe de **« intacte »** à **`ORIGINE`** ;
      `protocole_journal` garde **exactement** sa rupture `CONTENU` #1
- [x] W3 — **0** clé étrangère restante sur `acteur_id` / `medecin_id`
- [x] W4 — compte supprimé → `acteur_id` **conservé**, `acteur_nom` intact, **verdict identique**
- [x] W5 — scellement : chaîne #2, **35 entrées scellées**, verdict `CONTENU` inscrit dans la
      déclaration
- [x] W6 — empreintes **inchangées octet pour octet**, aucune entrée disparue
- [x] W7 — chaîne neuve : `chaine = 2`, `empreinte_precedente = NULL`, intacte ; chaîne scellée
      rendue avec ses **deux** verdicts
- [x] W8 — scellement sans motif → refusé **par son motif**
- [x] W9 — journal hors liste blanche → refusé, liste affichée
- [x] W10 — chaîne vide → refusé **par son motif**
- [x] G3 — **1158 tests / 16 371 assertions**, 21 vecteurs dédiés ; 1 échec **non lié**
      (`PrixMedicamentTest`, Tesseract > 20 s sous charge, **9/9 isolément**) ; Pint ;
      **mutation 6/6**, arbre restauré et vérifié
- [x] G4 propriétaire *(déclaré validé le 2026-08-21)*

### Deux choses que le G2 a apprises, et qui ne se voyaient pas en test

1. **`entrees` comptait les tours de boucle**, laquelle s'arrête à la première rupture : une chaîne
   de 34 entrées rompue à la première s'annonçait « 1 entrée », et **le scellement inscrivait ce
   chiffre**. Invisible en test parce que les chaînes y sont courtes. Corrigé, vecteur ajouté.
2. **`signature_journal` était vide, donc son origine a été déclarée** — alors que son compteur
   d'auto-incrément (6) montre que cinq entrées ont existé. La déclaration d'installation acte que
   le journal **était vide ce jour-là**, elle ne prouve pas qu'il n'a jamais rien porté. Les
   distinguer supposerait de lire le compteur d'auto-incrément, que MySQL expose et SQLite non : la
   garantie serait plus forte en production qu'en test. **Le mécanisme protège à partir du jour où
   il est installé ; il ne témoigne pas du passé** — et son propre motif d'ouverture le dit.

### État de déploiement

La base de développement a été **restaurée avant la migration** : `migrate:status` affiche
`2026_08_21_000001_chaines_audit_origine .. Pending`. La mise en service consiste à lancer
`artisan migrate`, puis — si vous décidez de repartir d'une chaîne neuve — la commande de
scellement, une fois par journal concerné.
