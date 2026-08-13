# GUIDE DE TEST — Référentiel national des professionnels de santé (P6.5)

**Corpus** : CDC_09 §5 · CDC_10 §4 · CDC_11 §3.4 · CDC_04 §5.2
**Plan G1** : [docs/PLAN_G1_P6_5_Professionnels_PKI.md](docs/PLAN_G1_P6_5_Professionnels_PKI.md) · **ADR-031**

| Partie | Incrément | Objet |
|--------|-----------|-------|
| **1** | **P6.5a** | Référentiel professionnel : numéro national, profession, ordre, autorisation d'exercer, lieux d'exercice, rôle `medecin` au portail |
| **2** | **P6.5b** | PKI, certificats X.509, signature de l'ordonnance, les 5 contrôles §5.4, journal chaîné |

---

# Partie 1 — P6.5a · Référentiel professionnel

## 1. Périmètre, et ce que cet incrément ne fait pas

**Ce qui est livré.** La fiche `medecins`, qui portait neuf colonnes de vitrine, porte désormais une
identité professionnelle : **numéro national** `PRO` + 6 chiffres, **profession** parmi les onze
métiers du §5.1, **ordre professionnel** et numéro d'inscription, **autorisation d'exercer** avec son
statut et ses dates, **lieux d'exercice multiples**, contacts et éléments de CDC_11 §3.4. Le
référentiel entre sous la gouvernance versionnée de P6.3. Le rôle `medecin`, créé en P1 et utilisable
nulle part, ouvre enfin le portail.

**Ce qui n'est PAS livré — à dire avant de tester, pour ne pas chercher ce qui n'existe pas :**

- **Aucune signature électronique, aucun certificat, aucune PKI.** C'est P6.5b. Tant qu'il n'est pas
  livré, `ordonnances.medecin_nom` reste **un texte libre saisi par le client** : une ordonnance peut
  encore porter le nom de n'importe quel médecin. Cet incrément construit l'identité à laquelle la
  signature s'attachera ; il ne referme pas encore le trou.
- **Les spécialités restent un texte libre.** La table de référence est l'**étape 8** du corpus
  (décision propriétaire P4). En attendant, deux orthographes de « Cardiologie » sont deux
  spécialités.
- **Dix des onze métiers du §5.1 n'ont pas de rôle de portail.** Seul `medecin` a été ouvert ; un
  infirmier ou un pharmacien ne se connecte pas encore sous son propre rôle.
- **Un établissement ne peut pas revendiquer le praticien d'un autre.** Déclarer un lieu d'exercice
  supplémentaire exige `professionnel.habiliter`. Le circuit de rattachement inter-établissements
  n'existe pas.
- **Le référentiel publié n'est branché sur aucun écran** — limites **L1/L2 d'ADR-025**, comme pour
  les établissements. Après un `UPDATE` direct, l'annuaire de P3/P4 change mais pas
  `/referentiels/professionnels`. **Ce n'est pas un bug.**
- **Aucun écran mobile.** La gouvernance passe par le portail et l'API, comme le paiement.

---

## 2. Prérequis

```bash
# Backend
cd services/api
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan migrate
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan db:seed --class=PortailRolesSeeder
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan serve --host=127.0.0.1 --port=8000
```

**Sauvegardez la base avant de commencer** — ce guide écrit en base :

```bash
C:/wamp64/bin/mysql/mysql8.4.7/bin/mysqldump.exe -h 127.0.0.1 -u root -p --single-transaction \
  ivoirsante > sauvegarde_avant_test.sql
```

**Comptes** — la permission `professionnel.habiliter` n'est portée par **aucun rôle** : elle
s'accorde nominativement.

| Rôle | Compte | Ce qu'il doit pouvoir faire |
|------|--------|------------------------------|
| Gestionnaire | à créer, rattaché à un établissement | décrire ses praticiens |
| Gestionnaire **habilité** | le même + `givePermissionTo('professionnel.habiliter')` | déclarer l'autorisation d'exercer et les lieux d'exercice |
| Médecin | rôle `medecin`, `structure_id` renseigné | se connecter au portail |

```php
// artisan tinker
$u = App\Models\User::where('email','gestionnaire@exemple.ci')->first();
$u->givePermissionTo('professionnel.habiliter');
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
```

---

## 3. Scénarios front — portail (libellés exacts)

### 3.1 La liste des praticiens

**Portail → Praticiens.** La première colonne s'appelle **« N° national »**.

- [ ] Avant tout backfill, elle affiche **« Non attribué »** en gris — l'absence est **dite**, pas
      comblée par un tiret ambigu.
- [ ] Après backfill, elle affiche `PRO000001`, `PRO000002`… en police à chasse fixe.
- [ ] Sous le nom du praticien apparaît sa **profession** en petit gris (« Médecin spécialiste »),
      seulement si elle est renseignée.
- [ ] Le champ de recherche accepte **le numéro national** : taper `PRO000003` retrouve la fiche.

### 3.2 Le formulaire — vu par un gestionnaire NON habilité

**Portail → Praticiens → Nouveau praticien.**

- [ ] Sections visibles : les champs d'identité, puis **« Formation et contacts »**.
- [ ] **La section « Ordre professionnel et autorisation d'exercer » est ABSENTE.** C'est le point
      central de cet incrément : un hôpital décrit ses praticiens, il ne déclare pas leur droit
      d'exercer.
- [ ] Le menu **« Profession »** propose les onze métiers du §5.1, de « Médecin généraliste » à
      « Kinésithérapeute ».
- [ ] Enregistrer → le bandeau vert annonce **« Praticien ajouté à l'annuaire — numéro national
      PRO0000xx. »** Le numéro est attribué **à la création**, pas au prochain backfill.

### 3.3 Le formulaire — vu par un compte HABILITÉ

- [ ] La section **« Ordre professionnel et autorisation d'exercer »** apparaît, précédée de
      « Réservé aux comptes habilités. Ces informations conditionneront la signature électronique
      des ordonnances. »
- [ ] Le menu « Statut de l'autorisation » propose : **Autorisation d'exercer valide** ·
      **Autorisation suspendue** · **Autorisation retirée**.
- [ ] Saisir « Délivrée le **2030-01-15** » et « Expire le **2024-01-15** » → **refus à l'écran**,
      message sous le champ : **« L'autorisation ne peut pas expirer avant d'avoir été délivrée. »**
      La fiche **n'est pas créée**. *(Interdiction au formulaire, pas détection après coup.)*

### 3.4 Les lieux d'exercice

**Portail → Praticiens → Modifier.** Un bloc **« Lieux d'exercice »** suit le formulaire, dans une
carte séparée.

- [ ] Le badge du numéro national s'affiche à côté du titre, avec l'infobulle « Numéro national —
      attribué par la plateforme, non modifiable ».
- [ ] L'établissement principal porte un badge bleu **« Principal »** et **n'a pas de bouton
      Retirer**.
- [ ] Compte **non habilité** : aucun formulaire d'ajout, seulement la phrase « Déclarer un lieu
      d'exercice supplémentaire relève d'un compte habilité : un établissement ne se rattache pas
      seul le praticien d'un autre. »
- [ ] Compte **habilité** : le formulaire « Ajouter un établissement » apparaît. Ajouter le **même**
      établissement → **« Ce praticien exerce déjà dans cet établissement. »**
- [ ] Ajouter un autre établissement → il apparaît **sans** badge « Principal », avec un bouton
      **Retirer** qui fonctionne.

### 3.5 Le rôle `medecin` entre au portail

- [ ] Créer un compte de rôle `medecin` avec un `structure_id`, se connecter → **le portail
      s'ouvre**. Avant P6.5a, ce compte était refusé (« Ce compte n'a pas accès au portail »).
- [ ] Ce compte **ne voit pas** la file des rendez-vous ni la gestion des disponibilités : il porte
      le soin, pas l'accueil.

---

## 4. Scénarios backend

### 4.1 Attribution du numéro national

```bash
# Le dry-run annonce, il n'écrit pas
XDEBUG_MODE=off php artisan masante:professionnels:backfill --dry-run
#   N professionnel(s) recevraient un numéro national (pays CI).
#   N exercice(s) principal/principaux seraient reportés.

# Vérifier qu'il n'a effectivement rien écrit
php artisan tinker --execute="echo DB::table('medecins')->whereNotNull('numero_professionnel')->count();"
#   0

# Le passage réel
XDEBUG_MODE=off php artisan masante:professionnels:backfill
#   PRO000001  ←  Dr Aya Koffi
#   …
#   N numéro(s) attribué(s), N exercice(s) principal/principaux reporté(s).

# Le rejeu — idempotent
XDEBUG_MODE=off php artisan masante:professionnels:backfill
#   Tous les professionnels ont un numéro national et un exercice principal — rien à faire.
```

- [ ] Les numéros sont **consécutifs** à partir de `PRO000001`, sans trou.
- [ ] Le rejeu n'attribue rien **et ne consomme pas la séquence**.

### 4.2 Deux pays peuvent partager le même numéro

```php
// artisan tinker
$sn = App\Models\Medecin::create([
    'structure_id' => 1, 'service_id' => 1, 'titre' => 'Dr',
    'prenom' => 'Moussa', 'nom' => 'Diop', 'specialite' => 'Pédiatrie', 'actif' => true,
]);
$sn->forceFill(['pays_code' => 'SN'])->save();
app(App\Services\Professionnel\AttributeurNumeroProfessionnel::class)->attribuer($sn);
echo $sn->fresh()->numero_professionnel;   // PRO000001
```

- [ ] Le professionnel sénégalais reçoit **`PRO000001`**, comme le premier ivoirien.
- [ ] `professionnel_compteurs` porte **deux lignes** : `CI` et `SN`, chacune avec sa séquence.
- [ ] **Aucun doublon n'est signalé** par le contrôle qualité — c'est le défaut trouvé au G2 et
      corrigé (§8).

### 4.3 Les deux vecteurs en miroir de la projection gouvernée

C'est le contrôle le plus important du référentiel : il doit être **insensible à la vitrine** et
**sensible à l'identité**. Aucun des deux ne suffit seul.

```php
// artisan tinker
use App\Models\Medecin;
use App\Services\Referentiel\{EmpreinteReferentiel, SourceProfessionnels};

$src = app(SourceProfessionnels::class);
$e0  = EmpreinteReferentiel::duContenu($src->extraire());

// A — la VITRINE change
Medecin::whereKey(1)->update(['tarif_consultation' => 99000, 'biographie' => 'test', 'telephone' => '+2250700000000']);
$e1 = EmpreinteReferentiel::duContenu($src->extraire());
var_dump($e0 === $e1);   // true  → INCHANGÉE

// B — l'IDENTITÉ change
Medecin::whereKey(1)->update(['numero_ordre' => 'ONM-9999']);
$e2 = EmpreinteReferentiel::duContenu($src->extraire());
var_dump($e1 === $e2);   // false → DIVERGE

// C — l'autorisation est retirée
Medecin::whereKey(1)->update(['autorisation_statut' => 'retiree']);
var_dump($e2 === EmpreinteReferentiel::duContenu($src->extraire()));   // false → DIVERGE
```

- [ ] **A : tarif + biographie + téléphone → empreinte INCHANGÉE.** Si elle change, la projection ne
      sépare pas l'identité professionnelle de la vitrine, et corriger un tarif deviendrait une
      décision nationale.
- [ ] **B : numéro d'ordre → empreinte DIFFÉRENTE.**
- [ ] **C : retrait d'autorisation → empreinte DIFFÉRENTE.** C'est exactement le fait qu'un
      référentiel national doit publier.

### 4.4 Contrôles qualité (§10)

```php
$src = app(App\Services\Referentiel\SourceProfessionnels::class);
print_r($src->controlerQualite($src->extraire()));
```

Chaque anomalie a son vecteur. Provoquez-la, vérifiez le message, puis remettez la donnée en état.

| Provoquer | Message attendu (extrait) |
|---|---|
| `numero_professionnel = NULL` | `aucun numéro professionnel` |
| `profession = NULL` | `profession absente` |
| `autorisation_statut = NULL` | `aucune autorisation d'exercer` |
| délivrée `2030-01-15`, expire `2024-01-15` | `mais expirant le` |
| statut `valide`, expire `2020-01-15` | `déclarée valide alors qu'elle a expiré` |
| même `numero_ordre` dans le même ordre | `est porté deux fois` |
| `annee_diplome` = année+5 | `année de diplôme dans le futur` |
| supprimer la ligne d'exercice principal | `n'apparaît pas dans ses lieux d'exercice` |

- [ ] **Sur des données complètes et cohérentes, la liste est VIDE.** Un contrôle qui refuserait
      tout serait aussi inutilisable qu'un contrôle qui n'attrape rien.

### 4.5 La garde centrale, par la chaîne HTTP réelle

Le test automatisé traverse les routes mais pas le CSRF. **Rejouez-le par curl**, sinon vous ne
prouvez pas la chaîne (piège de P6.4d).

```bash
B=http://127.0.0.1:8000
rm -f jar.txt
TOKEN=$(curl -s -c jar.txt "$B/portail/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b jar.txt -c jar.txt -o /dev/null -w "login=%{http_code}\n" -X POST "$B/portail/login" \
  -d "_token=$TOKEN" -d "email=gestionnaire@exemple.ci" -d "password=…"

PAGE=$(curl -s -b jar.txt -c jar.txt "$B/portail/medecins/creer")
echo "$PAGE" | grep -c 'autorisation_statut'      # 0 pour un NON habilité
TOKEN=$(echo "$PAGE" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')

curl -s -b jar.txt -c jar.txt -o /dev/null -w "store=%{http_code}\n" -X POST "$B/portail/medecins" \
  -d "_token=$TOKEN" -d "titre=Dr" -d "prenom=Vecteur" -d "nom=Habilitation" \
  -d "specialite=Cardiologie" -d "profession=medecin_specialiste" -d "service_id=<ID>" \
  -d "autorisation_statut=valide" -d "autorisation_numero=AUT-FAUSSE" \
  -d "numero_ordre=FAUX-1" -d "numero_professionnel=PRO999999"
```

```php
$p = App\Models\Medecin::where('prenom','Vecteur')->first();
echo $p->numero_professionnel;      // PRO0000xx  ← PAS PRO999999
var_dump($p->autorisation_statut);  // NULL       ← PAS 'valide'
var_dump($p->numero_ordre);         // NULL
echo $p->profession;                // medecin_specialiste  ← champ autorisé, bien repris
```

- [ ] Les champs d'habilitation reviennent **NULL** : le refus est un **silence**, pas un 403.
- [ ] Le numéro national envoyé par le client est **ignoré** (hors `$fillable`, précédent P6.4d).
- [ ] Un champ **autorisé** (`profession`) est bien enregistré — la garde ne mange pas tout.
- [ ] Accorder `professionnel.habiliter` au même compte, recommencer → **les champs sont
      enregistrés**. Sans ce miroir, on ne saurait pas si la garde discrimine ou refuse tout.

---

## 5. Invariants base de données

```sql
-- I1 — l'unicité porte sur le COUPLE (pays, numéro)
SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), NON_UNIQUE
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA='ivoirsante' AND TABLE_NAME='medecins' AND INDEX_NAME='uq_professionnel_numero'
 GROUP BY INDEX_NAME, NON_UNIQUE;
--   uq_professionnel_numero | pays_code,numero_professionnel | 0

-- I2 — le moteur refuse le doublon DANS un pays
UPDATE medecins SET numero_professionnel='PRO000001' WHERE numero_professionnel='PRO000002';
--   ERROR 1062 : Duplicata du champ 'CI-PRO000001' pour la clef 'medecins.uq_professionnel_numero'

-- I3 — tous distincts, aucun trou
SELECT COUNT(*) fiches, COUNT(DISTINCT numero_professionnel) distincts,
       MIN(numero_professionnel), MAX(numero_professionnel) FROM medecins;

-- I4 — LA REDONDANCE ASSUMÉE NE DÉRIVE PAS : `medecins.structure_id` (lu par P3/P4, validés G5)
--      et l'exercice principal doivent toujours concorder. Résultat attendu : 0.
SELECT COUNT(*) AS incoherences
  FROM professionnel_etablissement pe JOIN medecins m ON m.id = pe.medecin_id
 WHERE pe.est_principal = 1 AND pe.structure_id <> m.structure_id;

-- I5 — un exercice principal par fiche, et un seul
SELECT medecin_id, SUM(est_principal) n FROM professionnel_etablissement
 GROUP BY medecin_id HAVING n <> 1;
--   (aucune ligne)

-- I6 — l'énumération porte bien les onze métiers du §5.1
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA='ivoirsante' AND TABLE_NAME='medecins' AND COLUMN_NAME='profession';
```

---

## 6. Commandes de qualité (G3)

```bash
cd services/api && XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan test
#   attendu : 590 tests / 14 870 assertions, 0 échec

XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan test --filter=ReferentielProfessionnelsTest   # 29
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan test --filter=PortailProfessionnelTest        # 16

cd ../.. && pnpm typecheck    # shared + web + mobile
```

**Vérifier qu'une garde MORD**, et pas seulement qu'elle passe (leçon de P6.4c, où dix tests
passaient sans rien vérifier) : remplacez `if ($this->peutHabiliter()) {` par `if (true) {` dans
`MedecinController::valider()`, relancez
`--filter=test_un_gestionnaire_ne_peut_pas_declarer_l_autorisation_d_exercer` →
**le test doit ÉCHOUER** (`Failed asserting that 'valide' is null`). Restaurez ensuite.

---

## 7. Checklist de clôture

- [ ] Migration jouée, 3 tables neuves, 21 colonnes ajoutées, **aucune fiche perdue**
- [ ] Backfill : dry-run muet → réel → rejeu sans effet
- [ ] Numéros consécutifs, tous distincts, compteur aligné
- [ ] `PRO000001` coexiste en CI et en SN, **sans doublon signalé**
- [ ] Doublon dans un même pays refusé par le moteur (1062)
- [ ] **Vecteur A** : tarif/biographie/téléphone → empreinte inchangée
- [ ] **Vecteur B** : numéro d'ordre → empreinte différente
- [ ] **Vecteur C** : retrait d'autorisation → empreinte différente
- [ ] Les 8 contrôles qualité se déclenchent, et **aucun sur des données saines**
- [ ] Chaîne HTTP réelle : bloc absent · champs ignorés · champ autorisé repris · miroir habilité OK
- [ ] Dates d'autorisation incohérentes → **message d'écran**, fiche non créée
- [ ] Exercice principal non retirable · doublon refusé · exercice d'un autre praticien → 404
- [ ] Compte de rôle `medecin` : **entre au portail**, sans les écrans d'accueil
- [ ] `professionnel.habiliter` portée par **aucun rôle**
- [ ] I1 à I6 vérifiés
- [ ] G3 vert · **base restaurée et vérifiée compte par compte**

---

## 8. Pièges rencontrés

**Le contrôle de doublon était plus strict que le moteur — trouvé au G2 live, pas par les tests.**
`controlerQualite` comparait les numéros **sans le pays**. Il signalait donc `PRO000001` comme
dupliqué alors que la base autorise `CI-PRO000001` **et** `SN-PRO000001` : le référentiel serait
devenu **impubliable dès le premier pays ajouté**. Corrigé — la clé porte le pays, comme l'index. Un
vecteur dédié a été ajouté à la suite (`test_le_meme_numero_dans_deux_pays_n_est_pas_un_doublon`) :
c'est le G2 qui a dû trouver ce que les tests auraient dû dire.

**`curl -L` après un POST ne montre pas le message d'erreur.** Il ré-émet la requête et atterrit
ailleurs (869 Ko de page sans le formulaire). Procédez **en deux temps** : lire `%{redirect_url}`,
puis faire un `GET` dessus avec le même bocal de cookies. Et **ne relisez pas la page deux fois** :
le premier `GET` consomme les erreurs flashées de la session.

**`grep -c` qui renvoie 0 sort en code 1** et interrompt une chaîne `&&`. Ajoutez `|| true` quand
l'absence est justement le résultat attendu.

**La permission n'existe qu'après le seeder.** `givePermissionTo('professionnel.habiliter')` lève
`PermissionDoesNotExist` tant que `PortailRolesSeeder` n'a pas été rejoué. Et après attribution,
`forgetCachedPermissions()` — spatie met le cache en base.

**Le rôle ne suffit pas pour entrer au portail.** `AuthController` exige un rôle **de sa liste** ET
`actif = true` ET un compte non patient. Un compte `medecin` sans `structure_id` entre, mais la
gestion des praticiens lui répond 403 (« Compte non rattaché à un établissement »).

**Après restauration de la base, les établissements perdent leur `identifiant_national`** (il avait
été attribué pendant le G2 de P6.4a, puis restauré). Le contrôle qualité signale alors 28 fois
« un lieu d'exercice désigne un établissement sans identifiant national » — **c'est un signal juste,
pas un défaut** : lancez `masante:etablissement:backfill`, l'anomalie se referme.

---

# Partie 2 — P6.5b · PKI et signature électronique

## 1. Périmètre, et ce que cet incrément ne fait pas

**Ce qui est livré.** Une autorité de certification racine, un certificat X.509 par praticien, la
**signature électronique des ordonnances**, les **cinq contrôles obligatoires du §5.4** avant chaque
signature, un **journal chaîné** où les refus figurent autant que les succès, et la vérification
d'intégrité. Le trou du G0 est refermé : **`ordonnances.medecin_nom` n'est plus saisi par le
client** sur le chemin du soignant.

**Ce qui n'est PAS livré — à lire avant de tester :**

- **Aucun HSM** (CDC_10 §4.3 en exige un). La clé de l'autorité est protégée par une phrase de
  passe d'environnement, celles des praticiens par leur secret. Point d'extension documenté,
  classé « conçu ».
- **L'autorité est AUTO-SIGNÉE.** Aucune autorité de certification nationale ivoirienne n'a été
  consultée. Un navigateur ne reconnaîtra pas ces certificats, et c'est normal : ils lient une
  prescription à un praticien **dans cette plateforme**, ils ne valent pas confiance publique.
- **Aucun horodatage qualifié** (pas de TSA) : l'heure est celle du serveur.
- **La signature n'est pas obligatoire.** Une ordonnance non signée reste licite — P7-D0 est validé
  G5 et un praticien sans certificat doit continuer d'écrire. Ce qui est inconditionnel, c'est le
  **nom du prescripteur**.
- **Un seul des sept types de CDC_10 §4.5 est signé** : l'ordonnance. L'écran « Ma signature » les
  nomme tous les sept avec l'état de chacun.
- **Pas de CRL publiée, pas d'OCSP** : la révocation est une colonne, la vérification est locale.

---

## 2. Prérequis

```bash
cd services/api
# La phrase de passe de l'autorité — JAMAIS dans le dépôt (documentée sans valeur dans
# .env.example). Sans elle, la création de l'autorité échoue, et c'est voulu.
echo 'PKI_CA_PASSPHRASE="votre-phrase-longue"' >> .env

XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan migrate
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan db:seed --class=PortailRolesSeeder
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan masante:professionnels:backfill
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan masante:pki:autorite
```

**Sauvegardez la base ET votre `.env`** — ce guide écrit dans les deux.

Un compte de rôle `medecin`, relié à une fiche professionnelle **dont l'autorisation d'exercer est
`valide`** (déclarée par un compte portant `professionnel.habiliter`, partie 1 §3.3).

---

## 3. Scénarios front — portail

### 3.1 L'autorité de certification

```bash
XDEBUG_MODE=off php artisan masante:pki:autorite
```

- [ ] Le tableau affiche nom, série, empreinte SHA-256, dates de validité.
- [ ] Le message final rappelle que l'autorité est **auto-signée** et ne vaut pas confiance publique.
- [ ] **Relancer la commande → refus explicite**, rien n'est créé : « La régénérer invaliderait TOUS
      les certificats émis, donc toutes les signatures déjà posées. »
- [ ] Sans `PKI_CA_PASSPHRASE` → échec **bruyant** nommant la variable. Aucune valeur par défaut
      n'existe : ce serait un secret dans le dépôt.

### 3.2 « Ma signature »

**Portail → Ma signature** (tuile visible pour un compte portant `document.signer`).

- [ ] Bloc **Identité professionnelle** : nom, N° national, autorisation d'exercer avec sa date.
- [ ] Sans fiche reliée : « Ce compte n'est relié à aucune fiche professionnelle. »
- [ ] Bloc **Certificat numérique** : l'avertissement apparaît **avant** la saisie —
      « Il n'est stocké nulle part et ne peut pas être retrouvé. »
- [ ] Deux secrets différents → **« Les deux secrets saisis ne correspondent pas. »**
- [ ] Secret confirmé → bandeau vert, et le certificat s'affiche avec série, échéance, empreinte.
- [ ] Bandeau vert : **« Vous pouvez signer vos prescriptions »** listant les cinq contrôles.
- [ ] Bloc **Ce qui est signable aujourd'hui** : les **sept** types du §4.5, un seul en
      « Signable », les six autres en « Pas encore » **avec leur raison écrite**.

### 3.3 Le praticien sans autorisation d'exercer

Mettez `autorisation_statut` à `NULL` sur la fiche.

- [ ] L'écran affiche **« Aucun certificat ne peut être émis »** suivi du motif exact.
- [ ] La note explique que l'autorité ne certifie que ce que le référentiel affirme déjà.
- [ ] Poster quand même le formulaire → refusé, **aucun certificat créé**, et le refus est **au
      journal**.

### 3.4 Signer une prescription

Ouvrez un dossier par **Scan carnet** (voie consentie), section **Ordonnances**.

- [ ] Le formulaire **ne demande plus le nom du médecin** — il vient de votre fiche.
- [ ] Un encadré gris **« Secret de signature (facultatif) »** apparaît en bas.
- [ ] Laissé vide → « Ajouté au carnet… » ; l'ordonnance est enregistrée **non signée**.
- [ ] Rempli correctement → le message se termine par **« Prescription signée électroniquement. »**
- [ ] Secret erroné → **« Mais la signature a été refusée : Secret de signature incorrect. »** —
      et **l'ordonnance reste au carnet**. L'annuler priverait le patient de sa prescription pour
      une raison qui ne le concerne pas.

### 3.5 Vérifier

`/portail/signature/ordonnance/{id}`

- [ ] Ordonnance signée intacte → **« Signature valide — le document est intègre. »**
- [ ] Après modification d'un dosage → **« Signature invalide. Le document a été modifié depuis sa
      signature. »**
- [ ] Ordonnance jamais signée → **« Document non signé »** en gris, avec la phrase « Ce n'est pas
      une anomalie ». *(Non signé n'est pas invalide.)*
- [ ] Le bloc « Ce que la signature affirme » montre le contexte **figé** au jour de la signature.

### 3.6 Révocation

- [ ] Révoquer avec un motif → « Certificat révoqué. Les prescriptions déjà signées restent
      vérifiables. »
- [ ] L'écran affiche ensuite **« Votre dernier certificat a été révoqué le … »**, et **non**
      « aucun certificat » — la seconde phrase serait fausse.
- [ ] **Mes certificats** : le révoqué reste listé, avec sa date et son motif.
- [ ] Tenter de signer → refus **pour révocation**, et le journal porte `controle: revocation`.

---

## 4. Scénarios backend

### 4.1 Le serveur seul ne peut pas signer — le vecteur qui tient la promesse

```php
// artisan tinker
$coffre = app(App\Services\Pki\CoffreCleProfessionnel::class);
$scelle = $coffre->sceller('CLE-PRIVEE', 'mon-secret', 'SERIE-1', 7);

// Bon secret → ouvre
$coffre->ouvrir($scelle['cle_privee_chiffree'], $scelle['nonce'], $scelle['sel_kdf'], 'mon-secret', 'SERIE-1', 7);

// Un caractère de différence → RuntimeException « Coffre illisible. »
$coffre->ouvrir($scelle['cle_privee_chiffree'], $scelle['nonce'], $scelle['sel_kdf'], 'mon-secreT', 'SERIE-1', 7);

// Même secret, mais déplacé vers un AUTRE certificat → échoue aussi (l'AAD lie le cryptogramme).
$coffre->ouvrir($scelle['cle_privee_chiffree'], $scelle['nonce'], $scelle['sel_kdf'], 'mon-secret', 'SERIE-1', 99);
```

- [ ] Sans le secret, **rien** ne s'ouvre. C'est ce qui distingue une non-répudiation réelle d'une
      non-répudiation décorative.
- [ ] Une clé recopiée d'une ligne vers une autre **échoue** au lieu d'attribuer silencieusement la
      clé d'un médecin à un autre.

### 4.2 Le chiffrement au repos ne casse pas la signature

```php
$ordo  = App\Models\Ordonnance::find($id);
$avant = $ordo->getRawOriginal('medicaments_json');

// Rechiffrement du MÊME clair (comme le ferait une rotation de clé).
DB::table('ordonnances')->where('id', $id)->update([
    'medicaments_json' => Crypt::encryptString(json_encode($ordo->medicaments_json)),
]);

$apres = App\Models\Ordonnance::find($id)->getRawOriginal('medicaments_json');
var_dump($avant !== $apres);   // true : le cryptogramme a bien changé
app(App\Services\Pki\ServiceSignature::class)->verifier('ordonnance', $id)['integre'];   // true
```

- [ ] Le cryptogramme **change**, la signature **tient**. On signe le sens, jamais les octets
      stockés — signer le cryptogramme aurait cassé la signature au premier rechiffrement, sans
      qu'aucune donnée n'ait bougé.

### 4.3 Un champ hors signature ne casse rien

- [ ] Ajouter `photo_url` sur une ordonnance signée → **toujours intègre**. Une signature qui
      casserait au moindre geste du patient n'apprendrait plus rien à personne.

### 4.4 Le prescripteur vient du serveur

```php
app(App\Services\EcritureSoignantService::class)->ecrire(
    $comptePraticien, $membre, 'qr_scan', 'ordonnances',
    ['medecin_nom' => 'Dr Quelquun dAutre', 'structure_sanitaire' => 'Clinique inventee',
     'date_prescription' => '2026-08-13', 'medicaments_json' => [['nom' => 'Paracetamol']]],
)->medecin_nom;
```

- [ ] Renvoie **le nom de la fiche**, pas celui envoyé. C'est le trou du G0 refermé.
- [ ] Le chemin **patient** n'est pas touché : `$membre->ordonnances()->create([...])` garde le nom
      saisi. Un patient qui recopie une ordonnance papier doit nommer le médecin qui la lui a remise.

### 4.5 Les cinq contrôles du §5.4

Chacun a son vecteur. Provoquez, vérifiez le motif **et** le `controle` journalisé.

| Provoquer | Contrôle attendu |
|---|---|
| compte sans fiche professionnelle | `identite` |
| aucun certificat émis | `certificat` |
| certificat d'un autre praticien | `certificat` |
| certificat hors chaîne de l'autorité | `certificat` |
| certificat révoqué | `revocation` |
| certificat expiré | `expiration` |
| autorisation suspendue ou retirée | `autorisation_exercer` |
| autorisation « valide » mais échue | `autorisation_exercer` |
| profession non prescriptrice (kiné) | `habilitation_document` |

- [ ] Un certificat **révoqué ET expiré** est refusé pour **révocation**, pas pour expiration :
      l'ordre des contrôles est délibéré, et le motif journalisé doit être celui qui compte en litige.
- [ ] Sur un état sain, **aucun** contrôle ne se déclenche.

### 4.6 Le journal (§5.4 « l'échec est journalisé »)

```php
$journal = app(App\Services\Pki\JournalSignature::class);
var_dump($journal->premierMaillonRompu());   // null : chaîne intacte

// Réécrire le NOM de l'acteur suffit à rompre la chaîne.
DB::table('signature_journal')->where('id', 1)->update(['acteur_nom' => 'Systeme']);
var_dump($journal->premierMaillonRompu());   // 1
```

- [ ] `acteur_nom` entre dans l'empreinte : sans lui, réécrire le nom d'un agent ne romprait rien —
      or c'est ce nom-là qu'un humain lit dans un audit (leçon P6.3).
- [ ] `SELECT * FROM signature_journal` → **aucun nom de médicament, aucune posologie, aucun
      secret**. Le journal prouve, il ne recopie pas — et il n'est pas chiffré, lui.

---

## 5. Invariants base de données

```sql
-- I1 — un document n'est signé qu'une fois (attendu : aucune ligne)
SELECT type_document, document_id, COUNT(*) n FROM signatures_electroniques
 GROUP BY type_document, document_id HAVING n > 1;

-- I2 — au plus un certificat ACTIF par praticien (garantie applicative, sous verrou)
SELECT medecin_id, COUNT(*) n FROM certificats_numeriques WHERE statut='actif'
 GROUP BY medecin_id HAVING n > 1;

-- I3 — aucune clé privée en clair (attendu : 0)
SELECT COUNT(*) FROM certificats_numeriques WHERE cle_privee_chiffree LIKE '%BEGIN%';

-- I4 — tout maillon du journal porte son empreinte (attendu : 0)
SELECT COUNT(*) FROM signature_journal WHERE empreinte IS NULL OR empreinte = '';

-- I5 — un certificat révoqué n'est jamais supprimé : les signatures le référencent
SELECT s.id, c.statut FROM signatures_electroniques s
  JOIN certificats_numeriques c ON c.id = s.certificat_id;
```

---

## 6. Commandes de qualité (G3)

```bash
cd services/api && XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan test
#   attendu : 641 tests / 14 981 assertions, 0 échec

artisan test --filter=SignatureElectroniqueTest    # 39
artisan test --filter=PrescripteurSignatureTest    # 11

cd ../.. && pnpm typecheck
```

**Vérifier qu'une garde MORD** : dans `ServiceSignature::signer()`, remplacez `dernierCertificat`
par `certificatActif`, relancez
`--filter=test_signer_avec_un_certificat_revoque_est_refuse_POUR_REVOCATION` → **le test doit
ÉCHOUER**. C'est exactement le défaut trouvé au G2 (§8).

---

## 7. Checklist de clôture

- [ ] Migration jouée : 4 tables neuves, aucune donnée perdue
- [ ] Autorité créée · rejeu refusé · sans phrase de passe → échec bruyant
- [ ] Certificat émis : chaîne vérifiée, sujet portant le N° national
- [ ] Clé privée, nonce, sel et hachage du secret **absents du JSON**
- [ ] **Coffre** : bon secret ouvre · secret voisin échoue · déplacé vers un autre certificat échoue
- [ ] Ordonnance signée → **intègre** · dosage modifié → **altérée** · `photo_url` → **intègre**
- [ ] Rechiffrement du même clair → cryptogramme différent, **signature tient**
- [ ] **Prescripteur imposé par le serveur** · chemin patient intact · soignant sans fiche écrit
- [ ] Les 9 contrôles du tableau §4.5 se déclenchent, aucun sur un état sain
- [ ] Révoqué **et** expiré → refusé pour **révocation**
- [ ] Verrou temporaire après N échecs · compteur remis à zéro au succès
- [ ] Journal : refus **et** succès · chaîne intacte → altérée → rétablie
- [ ] **Zéro contenu clinique, zéro secret, zéro clé privée** dans le journal et dans les logs
- [ ] Signature posée **reste valide** après révocation du certificat
- [ ] I1 à I5 vérifiés
- [ ] G3 vert · **base ET `.env` restaurés**

---

## 8. Pièges rencontrés

**PHP ne peut pas générer de clé RSA sans `openssl.cnf`.** Au G0, `openssl_pkey_new` échouait avec
« configuration file routines::no such file » : `OPENSSL_CONF` n'est pas défini et PHP ne trouve
rien. WAMP en livre un (`bin/php/…/extras/ssl/openssl.cnf`) mais s'y fier ferait dépendre la PKI
d'un chemin propre à un poste. On embarque **`config/pki/openssl.cnf`** et on le passe en `config`
à chaque appel X.509.

**Le contrôle de révocation était inatteignable — trouvé au G2, pas par les tests.** Le service
interrogeait `certificatActif()` ; après une révocation elle ne renvoie plus rien, les règles
concluaient « **aucun certificat n'a été émis** » (faux), et le journal enregistrait
`controle: certificat` au lieu de `revocation`. Mon premier test ne vérifiait que « ça refuse »,
pas « ça refuse **pour la bonne raison** » — il passait donc malgré le défaut. Corrigé par
`dernierCertificat()` : le service rassemble l'état, **les règles jugent**.

**`membre_id` n'est pas `$fillable` sur `Ordonnance`.** `Ordonnance::create(['membre_id' => …])`
l'écarte silencieusement et la ligne est refusée par la base. Passez par la relation
`$membre->ordonnances()->create([...])` — c'est la garde anti-IDOR, la contourner dans un test
l'aurait masquée.

**Eloquent ne réécrit pas une valeur qu'il juge inchangée.** Pour prouver qu'un rechiffrement ne
casse pas la signature, `update(['medicaments_json' => $meme_tableau])` ne produit aucun `UPDATE`.
Forcez l'écriture par `DB::table(...)->update(['medicaments_json' => Crypt::encryptString(...)])`.

**Le secret ne doit traverser que ce qu'il doit traverser.** `secret_signature` est retiré de la
requête **avant** la validation : il en serait de toute façon écarté, mais il aurait transité par un
tableau susceptible d'être journalisé en cas d'erreur.

**L'ordre des routes.** `signature/{type}/{id}` est déclarée **en dernier**, sinon elle capte
`signature/journal` et `signature/historique` — le piège déjà rencontré en P7-D0 avec
`dossier/fermer` avant `dossier/{section}`.
