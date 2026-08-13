# PLAN G1 — P6.5 « Référentiel national des professionnels + PKI et signature électronique »

**Corpus** : CDC_09 §5 (référentiel des professionnels), CDC_10 §4 (PKI, HSM, signature), CDC_11 §3.3/§3.4 et §5.4 (fiche médecin, prescription), CDC_04 §5.2 (tables attendues).
**Position** : étape **5** de l'ordre CDC_09 §14, après les établissements (P6.4, complet).
**Statut** : G0 clos le 2026-08-13 · décisions propriétaire prises le 2026-08-13 · découpage recentré le
2026-08-13 sur demande du propriétaire · **G1 VALIDÉ par le propriétaire le 2026-08-13**.

---

## 1. G0 — audit réel

Sept constats, tous vérifiés dans le code, pas déduits.

### C1 — `medecins` est une fiche de vitrine, pas un référentiel professionnel
`database/migrations/2026_07_08_000001_create_medecins_table.php` : neuf colonnes utiles
(`titre`, `nom`, `prenom`, `specialite`, `tarif_consultation`, `actif` + `structure_id`, `service_id`, `user_id`).
Il manque **la totalité** du §5.2 : numéro professionnel, sexe, ordre professionnel, autorisation d'exercer,
diplômes, université, contacts, horaires, signature électronique, certificat numérique. Et la totalité de
CDC_11 §3.4 : photo, date de naissance, expérience, sous-spécialité, biographie, langues, consultation en
ligne / physique.

### C2 — Un praticien est rattaché à UN établissement et UN service
`structure_id` et `service_id` sont NOT NULL. Le corpus dit « établissement**s** d'exercice » (§5.2) et
CDC_04 §5.2 prévoit une table `professionnel_etablissement`. La table est référencée par
`rendez_vous.medecin_id` (P4, **validé G5**) et `referents.medecin_id` (Module 5) : ADR-024 interdit de la
remplacer.

### C3 — `specialite` est un texte libre, à deux endroits
Dans `medecins` (100 car.) et dans `services_etablissement` (100 car.). CDC_11 §3.3 exige une **table
séparée** et « une ou plusieurs » spécialités par médecin. P6.4d avait déjà consigné la dette de
`services.specialite` pour **P10** ; CDC_09 §14 place le référentiel des spécialités à l'**étape 8**.

### C4 — LE TROU CENTRAL : le nom du prescripteur est un texte libre saisi par le client
`OrdonnanceController::regles()` : `'medecin_nom' => ['required', 'string', 'max:200']`.
`EcritureSoignantService::ecrire()` réécrit `source` et `added_by` côté serveur — **mais pas le nom du
prescripteur**. Conséquence concrète, aujourd'hui, en production : **une ordonnance peut porter le nom de
n'importe quel médecin**, y compris écrite depuis le portail par un compte habilité. C'est exactement ce que
le §5.3 veut refermer (« les prescriptions deviennent juridiquement traçables »).

### C5 — Le rôle `medecin` existe et n'est utilisable nulle part
`RoleSeeder` le crée (guard `web`), mais `Portail\AuthController::ROLES_PORTAIL` n'accepte que
`admin_ivoirsante`, `gestionnaire_etablissement`, `agent_garde`. Le « médecin » qui écrit au carnet en P7-D0
est en réalité un compte **`agent_garde`** portant `dossier.ecrire`. Il n'existe donc **aucun porteur
d'identité professionnelle** à qui rattacher une clé, un certificat et une signature.

### C6 — Aucune PKI, aucun certificat, aucune signature côté Laravel
`certificats_numeriques` (CDC_04 §5.2) n'existe pas. La seule signature du projet est en **Java**
(P5.2b, factures, RSA-SHA256) : conception réutilisable, code non transposable.
Vérifié sur PHP 8.3.28 : `openssl`, `sodium`, `gmp`, `bcmath` sont **tous chargés** → X.509 + RSA
réalisables à **zéro dépendance nouvelle**. **Aucun HSM** n'existe, alors que CDC_10 §4.3 en exige un.

### C7 — Le socle P6.3 attend nommément `medecins`
`RegistreReferentiels` le réserve à P6.5 en commentaire. Les **limites L1/L2 d'ADR-025 s'appliqueront** :
P3 et P4 lisent la table en direct, le référentiel publié ne sera pas branché sur eux — même situation
qu'en P6.4a, et ce n'est pas un bug.

### C8 — Sur les 7 documents signables de CDC_10 §4.5, un seul existe
| Type | État réel |
|---|---|
| Ordonnances électroniques | ✅ `ordonnances` |
| Comptes rendus médicaux | ❌ inexistant (`notes_observations` est patient-seul, exclue de `SECTIONS_SOIGNANT`) |
| Certificats médicaux | ❌ inexistant (`documents_medicaux.categorie` est un **import**, pas une émission) |
| Prescriptions biologiques | ❌ inexistant (`resultats_analyses` porte le résultat, jamais la demande) |
| Rapports de radiologie | ❌ inexistant |
| Documents administratifs | ❌ inexistant comme entité produite |
| Factures | ⚠️ existent dans le microservice Java, **déjà signées** (P5.2b) |

---

## 2. Ce que le G0 change

Le module n'est pas « ajouter des colonnes à une fiche de médecin ». C4 dit qu'aujourd'hui **rien ne relie une
ordonnance à un professionnel identifié**. Tant que ce lien n'existe pas, un certificat X.509 signerait un
document dont l'auteur déclaré reste une chaîne de caractères libre : la signature garantirait l'intégrité
d'un mensonge. **L'ordre a→e ci-dessous est donc contraint** : l'identité professionnelle d'abord, la clé
ensuite, la signature en dernier.

---

## 3. Décisions du propriétaire (2026-08-13)

**P1 — La clé privée est chiffrée au repos et déverrouillée par un secret du professionnel.**
AES-256-GCM avec nonce + AAD + version de clé (motif exact des destinations de reversement, P5.5b-1). Le
secret n'est **jamais stocké** ; il est exigé à **chaque** signature. Conséquence assumée et écrite :
**le serveur seul ne peut pas signer.** L'HSM de CDC_10 §4.3 devient un **point d'extension documenté**,
classé « conçu » ≠ « prêt à activer » (classement ADR-014).

**P2 — Les exercices multiples se font par table de liaison additive.**
`professionnel_etablissement` (CDC_04 §5.2) s'ajoute ; `structure_id` demeure et devient l'exercice
**principal**. Zéro modification de P3, P4 et des référents. Conforme ADR-024.

**P3 — Les 7 types de CDC_10 §4.5 sont signables ; les 5 entités manquantes seront créées — dans un module
à part.**
Décision en deux temps. D'abord (après présentation du tableau C8) : les cinq entités absentes **seront
créées**, la signature ne se limitera pas aux ordonnances. Puis, à l'examen du découpage : elles **sortent de
P6.5**, qui se recentre sur l'étape 5 du corpus.

Ce qui a emporté la décision est une dépendance, pas un arbitrage de charge : **deux des cinq entités
supposent des référentiels qui n'existent pas.** Une *prescription biologique* n'est d'ailleurs pas un
document mais une **demande qui ouvre un circuit** (médecin → laboratoire → résultat, §7.4) ; sans le
**catalogue national des analyses** (étape 7), elle prescrirait des examens en **texte libre** — précisément
le défaut que P6.4 vient de refermer sur les établissements. Un *rapport de radiologie* suppose l'imagerie et
DICOM (§9.1). Les trois autres — compte rendu, certificat médical, document administratif — ne dépendent de
rien et sont écrivables tout de suite, mais les livrer séparés de leurs deux jumelles éclaterait le module
documentaire en deux endroits.

Elles forment donc un **module identifié** — précédent de la migration du portail (ADR-029) : « Documents
médicaux signés », placé **après P6.7 (laboratoires)**, avec les référentiels dont il dépend. L'engagement
tient, il est séquencé.

La **facture** n'est dans aucun des deux : elle existe, elle est déjà signée en P5.2b par le service Java, et
§10 l'attribue à « l'administration », pas au médecin — elle reste signée là où elle est.

**P4 — Le référentiel des spécialités est reporté à l'étape 8.**
`specialite` reste un texte libre en P6.5. La table de référence arrivera avec les référentiels transverses,
en même temps que `services.specialite` déjà consignée pour P10 — pour ne pas créer deux fois la même table.
**Limite annoncée, pas déguisée.**

---

## 4. Découpage proposé

**P6.5 = exactement l'étape 5 du corpus. Deux incréments, puis le module est COMPLET.**

| Inc. | Objet | Corpus |
|---|---|---|
| **P6.5a** | Référentiel professionnel : identité, numéro national, ordre, **autorisation d'exercer**, exercices multiples, rôle `medecin` ouvert au portail, mise sous gouvernance P6.3 — **avec ses écrans de saisie** | §5.1, §5.2, §10 · CDC_11 §3.4 |
| **P6.5b** | PKI (CA, certificats X.509, révocation) **+ signature de l'ordonnance + les 5 contrôles §5.4** — **avec ses écrans** (émission, signature, vérification) | §5.3, §5.4 · CDC_10 §4.1/4.3/4.5 |

**Pourquoi la PKI n'est pas un incrément à elle seule.** Un certificat émis sans rien à signer serait le
socle à vide que P6.3 avait explicitement refusé (décision D3 : « un socle à vide ne prouverait rien —
leçon P5.3b-4 »). Le certificat naît avec son usage, et C4 — le trou central — se referme dans le même
incrément.

**Pourquoi les écrans ne sont PLUS reportés à la fin.** P6.4 a reporté deux fois ses formulaires
(limites M1 puis O1) avant de les livrer en P6.4d. Un référentiel de trente champs qu'on ne peut remplir
que par un seeder n'est pas remplissable. Chaque incrément livre donc les siens.

Chacun est validé G5 avant le suivant, et a sa partie dans `GUIDE_TEST_PROFESSIONNELS.md`.

### Ce qui sort de P6.5 : le module « Documents médicaux signés »

Les cinq entités de la décision P3, **placées après P6.7 (laboratoires)**, avec les référentiels dont deux
d'entre elles dépendent :

| Entité | Dépend de |
|---|---|
| Compte rendu médical | rien |
| Certificat médical | rien |
| Document administratif | rien |
| Prescription biologique | **catalogue national des analyses** (étape 7) |
| Rapport de radiologie | **imagerie / DICOM** (§9.1) |

Elles se brancheront sur le moteur de signature livré en P6.5b : **une classe et une ligne au registre**,
le moteur ne bouge pas — même contrat que `SourceReferentiel` pour les référentiels.

---

## 5. Décisions de conception (précédents du projet, pas d'arbitrage requis)

- **La table `medecins` n'est pas renommée.** CDC_04 dit `professionnels_sante` ; P6.4a avait le même écart
  (`structures_sanitaires` vs `etablissements`) et a conservé le nom. Enrichissement additif (ADR-024).
- **`profession` : ENUM élargi aux 11 métiers du §5.1** (médecin généraliste, spécialiste, chirurgien,
  dentiste, sage-femme, infirmier, pharmacien, biologiste, radiologue, psychologue, kinésithérapeute).
  Miroir exact de l'élargissement de `type` en P6.4a, sans invalider l'existant.
- **`numero_professionnel` = `PRO` + 6 chiffres, littéral, sans clé de contrôle.** §3.2 impose un checksum
  pour le NIS et **pas ici** ; un professionnel se choisit dans une liste, jamais de tête. `UNIQUE(pays_code,
  numero_professionnel)` — le pays qualifie, il ne s'écrit pas dedans (P6.4a). **Hors `$fillable`** : un
  client ne choisit pas son numéro national.
- **Attribution sous verrou X dès le premier accès** (`UPDATE … dernier+1`, INSERT si 0 ligne affectée) +
  `DB::transaction(…, 3)`. Le motif `insertOrIgnore → SELECT … FOR UPDATE` **deadlocke sur MySQL** (erreur
  1213) — défaut trouvé au G2 de P6.1, on ne le réintroduit pas.
- **Backfill idempotent** en commande artisan avec `--dry-run` (P6.1, P6.4a).
- **RSA-2048 / SHA-256**, aligné sur la signature des factures P5.2b et sur X.509. Ni Ed25519 (hors X.509
  classique), ni clé plus courte.
- **Permissions `professionnel.manage`, `pki.emettre`, `document.signer` attribuées à AUCUN rôle métier** —
  quatrième occurrence du précédent `urgence.bris_de_glace` / `dossier.ecrire` / `referentiel.*`.
- **Habilitation vérifiée en service, pas par le middleware spatie** sur les routes Sanctum : les permissions
  sont sur le guard `web` (piège de P4 sur `rdv.validate`).
- **Verrouillage après N échecs du secret de signature**, N = donnée, jamais un littéral (P5.3b-1).
- **Tout refus de signature est journalisé** (§5.4, phrase explicite du corpus) dans la chaîne d'audit à
  hachage chaîné de P6.3, `acteur_nom` inclus dans l'empreinte.
- **Aucune règle médicale calculée.** Test de fin de module : « quelles règles métier ce module
  calcule-t-il ? » → **aucune**. Les 5 contrôles du §5.4 sont des contrôles d'habilitation, pas de médecine.

---

## 6. Ce que la signature signe, et pourquoi c'est la question centrale

`ordonnances.medicaments_json` est **chiffré AES-256 en base** (cast `encrypted:array`). Signer les octets
stockés serait faux : un rechiffrement produit un cryptogramme différent sans qu'aucune donnée n'ait bougé —
exactement le piège évité en P6.4c, où l'empreinte porte sur le **contenu** et non sur le chemin de stockage.

La signature portera donc sur une **canonicalisation déterministe du contenu en clair** (clés triées, encodage
figé), comme l'instantané de P6.3 et la liste triée des images de P6.4c.

Conséquence voulue, et c'est le **vecteur central du G2** : une entrée signée puis modifiée par le patient
fait dire à la vérification « **altérée** ». La signature ne verrouille pas la ligne, elle **atteste d'un
état**. C'est la définition de l'intégrité du §5.3, pas un effet de bord.

---

## 7. Preuves attendues (G2/G3, une garde = un vecteur)

- Numéro professionnel : unicité par pays, deux pays partagent `PRO000001`, refus du moteur (1062),
  concurrence parallèle → N numéros distincts, 0 deadlock.
- Autorisation d'exercer expirée → **signature refusée**, et le refus est **dans le journal**.
- Certificat révoqué → signature refusée. Certificat d'un autre professionnel → refusée.
- Secret erroné → refusée ; N échecs → verrou. **Le serveur ne peut pas signer sans le secret** : vecteur
  explicite prouvant qu'aucun chemin ne contourne le déverrouillage.
- Ordonnance signée → vérification « intègre » ; un champ modifié → « altérée ».
- Deux vecteurs en miroir sur la projection gouvernée : changer le **tarif de consultation** ne fait **pas**
  diverger le référentiel ; changer le **numéro d'ordre** ou l'**autorisation d'exercer**, si.
- Grep des logs : aucune clé privée, aucun secret, aucun contenu médical.
- Base de dev **restaurée à l'identique** après le G2, vérifiée compte par compte.

---

## 8. Limites qui seront annoncées

- **Un seul des 7 types de CDC_10 §4.5 est signé à la fin de P6.5** : l'ordonnance. Cinq attendent le module
  « Documents médicaux signés » (décision P3) ; la facture est signée ailleurs, par nature. Le registre des
  documents signables **les nommera tous les sept**, avec l'état de chacun — nommer un manque n'est pas le
  combler, mais un manque nommé ne s'oublie pas.
- **Aucun HSM** (CDC_10 §4.3) : clé chiffrée logicielle. Point d'extension documenté, classé « conçu ».
- **CA racine auto-signée** : aucune autorité nationale ivoirienne n'a été consultée, aucune chaîne de
  confiance réelle n'existe. Une PKI inventée qui a l'air officielle ne se fait pas corriger.
- **Aucune horodatage qualifié** (pas de TSA) : l'heure est celle du serveur, journalisée.
- **Spécialités** : texte libre jusqu'à l'étape 8 (décision P4).
- **L1/L2 d'ADR-025** s'appliquent : P3/P4 lisent `medecins` en direct.
- **Facture** : signée par le service Java (P5.2b), hors PKI professionnelle — par nature, pas par oubli.

---

## 9. Décisions complémentaires du propriétaire (2026-08-13)

**P5 — Le rôle `medecin` entre dans le portail.**
`Portail\AuthController::ROLES_PORTAIL` l'accueille ; le gestionnaire crée un compte de ce rôle et le relie à
la fiche par le `user_id` **qui existe déjà** depuis le Module 5. Le rôle créé en P1 cesse d'être mort.
Ce que cela oblige à dire, et qui sera écrit : **`medecin` ne reçoit pas les permissions d'accueil**
(`qr.scan`, `disponibilite.manage`, `rdv.validate` restent à `agent_garde`) ; il reçoit ce qui relève du soin
et de la signature. Les dix autres métiers du §5.1 restent hors portail dans cet incrément — **limite
annoncée** : un infirmier ou un pharmacien ne se connectera pas encore sous son propre rôle.

**P6 — Le secret de signature est dédié, distinct du mot de passe du portail.**
Haché en base (BCrypt, précédent du PIN wallet P5.3b-1), redemandé à **chaque** signature, servant à dériver
la clé qui déchiffre la clé privée. Verrouillage après N échecs, N = donnée. C'est ce choix, et lui seul, qui
rend la promesse P1 vérifiable : **même avec une session ouverte, le serveur ne peut pas signer.** Un vecteur
du G2 le prouvera explicitement.

Le MFA TOTP de P1 (exigé par §10 pour l'écriture en référentiel) reste **OFF** : `MFA_ENFORCE` n'est pas
activé, et l'exiger sur ce seul chemin serait une garantie partielle présentée comme complète. **Limite
annoncée.**

---

*Fin du plan G1 — P6.5.*
