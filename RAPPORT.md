# RAPPORT D'IMPLÉMENTATION — MaSanté (IVOIRSANTÉ)

> **Objet.** Journal de conception & d'implémentation, **une section par fonctionnalité**, tenu jusqu'à la fin du projet.
> Pour chaque fonctionnalité : analyse approfondie → meilleures solutions comparées → solution retenue → **détail complet
> d'implémentation (fonctions backend ET frontend, flux de données, sécurité)**. Priorité à la **robustesse** (données
> médicales sensibles, loi n°2013-450 / ARTCI, OWASP ASVS/MASVS), pas au minimalisme.
>
> **Statut d'une section** : `PROPOSÉ` (en attente de validation) · `VALIDÉ` · `IMPLÉMENTÉ` · `TESTÉ`.

---

## Module 2 — Carnet de santé familial — Incrément F2.10 → F2.13

**Statut : couche données IMPLÉMENTÉE & TESTÉE le 2026-07-02** (migré, rollback+re-migré, FK vérifiées).
Contrôleurs/routes/frontend documentés ici, à implémenter à l'étape suivante.

**Décisions validées** : `categorie` = superset à **8 valeurs** ; niveau **robuste complet** (toutes colonnes d'audit,
intégrité, antivirus, softDeletes). `source` distinct de `added_by` (axes orthogonaux).

### Décision transverse F2.13 — `source` vs `added_by`

`added_by` (qui a **saisi**) et `source` (d'**où vient** la donnée) sont **deux axes orthogonaux** : un patient peut importer
un document produit par une structure (added_by = patient, source = structure). On conserve donc **une colonne `source`
distincte** — `ENUM('patient','medecin','structure') DEFAULT 'patient'` — sur `antecedents`, `ordonnances`,
`resultats_analyses`, sans supprimer `added_by`. (Option 2, retenue par analyse.)

---

### F2.10 — Documents médicaux importés (import universel sécurisé)

#### Objectif
Permettre au patient (ou à un médecin via accès dossier) d'importer tout document de soin (certificat, fiche de sortie,
compte rendu, imagerie, assurance, etc.), **tous formats via liste blanche large**, catégorisé, daté, rattaché à un membre,
avec **validation MIME réelle + antivirus + chiffrement au repos**.

#### Schéma retenu — table `documents_medicaux`
| Colonne | Type | Rôle / robustesse |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `membre_id` | FK → membres_famille, CASCADE | Propriété du dossier |
| `uploaded_by_user_id` | FK → users, **nullOnDelete**, nullable | **Audit** (loi 2013-450) : auteur de l'import, ≠ membre |
| `categorie` | ENUM | Voir décision « portée catégories » |
| `titre` | VARCHAR(200) | Libellé lisible |
| `nom_fichier_original` | VARCHAR(255) | Nom réel (affichage + `Content-Disposition`) |
| `fichier_url` | VARCHAR(500) | Chemin du blob **chiffré** (nom = UUID, anti path-traversal) |
| `mime_type` | VARCHAR(150) | Type MIME **réel** (déterminé serveur via `finfo`) |
| `extension` | VARCHAR(20) | Dérivée du MIME (icône, filtre) |
| `taille_octets` | BIGINT UNSIGNED | Quota, UI, gestion 3G |
| `hash_sha256` | CHAR(64) NULL | **Intégrité** + détection doublon |
| `statut_antivirus` | ENUM('en_attente','sain','infecte') DEFAULT 'en_attente' | Verrou de téléchargement |
| `source` | ENUM('patient','medecin','structure') DEFAULT 'patient' | Provenance F2.13 |
| `date_document` | DATE NULL | Date du document (≠ date d'import) |
| `triage_id` | FK → triages, nullOnDelete, NULL | Rattachement à un épisode de triage |
| `created_at`/`updated_at` | timestamps | |
| `deleted_at` | softDeletes | **Rétention médicale** (pas de suppression dure) |
| **Index** | (membre_id, categorie), (membre_id, date_document), (statut_antivirus) | |

#### Sécurité (points de contrôle)
1. **Liste blanche MIME** (validation `mimetypes:` Laravel) : PDF, JPEG/PNG/HEIC, DOC/DOCX, CSV/XLS/XLSX, JSON, DICOM… + **taille max** par fichier.
2. **Vérif MIME réel serveur** : `UploadedFile::getMimeType()` (finfo), jamais l'extension cliente.
3. **Nom de stockage = UUID** (`Str::uuid()`), le nom original n'est jamais utilisé comme chemin.
4. **Chiffrement au repos** : contenu chiffré AES-256 (APP_KEY) avant écriture disque privé (jamais dans `public/`).
5. **Antivirus** : job asynchrone ClamAV ; téléchargement bloqué tant que `statut_antivirus !== 'sain'`.
6. **Autorisation** : Policy — seul le propriétaire du compte du membre (ou accès dossier autorisé) voit/télécharge.

#### Backend — implémentation détaillée
- **Migration** : `create_documents_medicaux_table` (schéma ci-dessus).
- **Modèle** `DocumentMedical` : `$fillable`, `casts` (`taille_octets`→int, `date_document`→date), `use SoftDeletes`,
  relations `membre()` (belongsTo), `uploader()` (belongsTo User), `triage()` (belongsTo). Accessor `est_telechargeable`
  (= `statut_antivirus === 'sain'`).
- **FormRequest** `StoreDocumentRequest::rules()` : `fichier` → `['required','file','max:N','mimetypes:...']` ;
  `categorie` → `Rule::enum` ; `titre`, `date_document` → nullable/date ; `authorize()` délègue à la Policy.
- **Service** `DocumentStorageService` :
  - `storeEncrypted(UploadedFile $f, MembreFamille $m): array` → génère UUID, calcule `hash('sha256', ...)`,
    `Crypt::encryptString(contents)` → `Storage::disk('documents')->put("$m->id/$uuid.enc", $cipher)` → renvoie
    `[chemin, mime, ext, taille, hash]`.
  - `retrieveDecrypted(DocumentMedical $d): resource|string` → lit + `Crypt::decryptString`.
- **Job** `ScanDocumentJob::handle()` : si `config('masante.antivirus.enabled')` → appel ClamAV (socket/`clamdscan`),
  sinon (dev) marque `sain` (pattern « simulé en dev », comme l'OTP). Met à jour `statut_antivirus`.
- **Contrôleur** `DocumentMedicalController` :
  - `index(MembreFamille $m)` → liste (Policy `viewAny`), groupée par `categorie`.
  - `store(StoreDocumentRequest $r, MembreFamille $m)` → `storeEncrypted` → crée l'enregistrement (`en_attente`) →
    `ScanDocumentJob::dispatch($doc)` → 201.
  - `show(MembreFamille $m, DocumentMedical $d)` → Policy `view` + garde `est_telechargeable` → `streamDownload`
    (décrypté) avec `Content-Type: mime_type` et `Content-Disposition` = `nom_fichier_original`.
  - `destroy(...)` → soft delete (Policy `delete`).
- **Policy** `DocumentMedicalPolicy` : `view/create/delete` = `$user->id === $doc->membre->user_id` (réutilise le
  cloisonnement de `MembreFamille`).
- **Routes** (`routes/api.php`, `/api/v1`) : `apiResource('membres.documents', ...)` (index/store/show/destroy).
- **Config** `config/masante.php` : `antivirus.enabled`, `upload.max_ko`, `upload.mimetypes` (liste blanche).

#### Frontend (Expo SDK 54) — implémentation détaillée
- **Libs** (`npx expo install`) : `expo-document-picker`, `expo-image-picker`, `expo-image-manipulator` (compression 3G),
  `expo-file-system` (téléchargement/cache chiffré).
- **`src/api/documents.ts`** :
  - `listDocuments(membreId)` → `GET /v1/membres/{id}/documents`.
  - `uploadDocument(membreId, asset, meta, onProgress)` → `FormData` multipart (`fichier`, `categorie`, `titre`,
    `date_document`) → `POST` avec `onUploadProgress`.
  - `downloadDocument(doc)` → `FileSystem.createDownloadResumable` vers cache app, ouverture via `Sharing`/viewer.
  - `deleteDocument(id)`.
- **Compression** : pour les images, `ImageManipulator.manipulateAsync(uri, [{resize:{width:1600}}], {compress:0.7})`
  avant upload (contexte réseau 3G).
- **Écrans** : `documents/index.tsx` (liste par catégorie + badge `statut_antivirus`), `documents/nouveau.tsx`
  (choix source : appareil photo / galerie / fichier, formulaire catégorie+titre+date, barre de progression),
  visionneuse (PDF/image).
- **États** : `en_attente` → spinner « analyse en cours » ; `infecte` → bloqué + message ; `sain` → ouverture permise.

#### Séquence bout-en-bout
`Sélection fichier (mobile)` → `compression si image` → `POST multipart` → `validation MIME+taille (serveur)` →
`chiffrement + stockage UUID` → `enregistrement statut=en_attente` → `ScanDocumentJob (ClamAV/stub)` → `statut=sain|infecte`
→ `liste rafraîchie` → `téléchargement (déchiffré, si sain)`.

#### Backend — IMPLÉMENTÉ (étape A, 2026-07-03)
- **Config** `config/masante.php` : `antivirus.enabled` (défaut `false`, stub dev), `upload.max_ko` (20 Mo),
  `upload.mimetypes` (liste blanche 12 types). **Disque privé** `documents` (`config/filesystems.php`,
  `storage_path('app/documents')`, `throw`) — hors `public/`, jamais servi directement.
- **Service** `DocumentStorageService` : `storeEncrypted` (UUID, `Crypt::encryptString`, `hash_sha256`, MIME finfo,
  extension dérivée du MIME), `retrieveDecrypted`, `deleteBlob` (nettoyage d'orphelin).
- **FormRequest** `StoreDocumentRequest` : `authorize()` → `MembreFamillePolicy::update` (anti-IDOR) ;
  `fichier` = `required|file|max|mimetypes:` (MIME réel) ; `categorie` `Rule::in`, `date_document`,
  `source`, `triage_id`. Constantes `DocumentMedical::CATEGORIES` / `::SOURCES` (source unique).
- **Job** `ScanDocumentJob` (transport de l'ID) : dev → `sain` ; prod → `clamdscan` (flux stdin) ;
  **fail-closed** (indispo/erreur → reste `en_attente`, journalisé).
- **Contrôleur** `DocumentMedicalController` : `index` (Policy `view`), `store` (dispatch **sync** en dev / **async** en prod,
  `uploaded_by_user_id` serveur, blob nettoyé si l'insert échoue), `show` (téléchargement déchiffré,
  **423** si `!== sain`), `destroy` (soft-delete, blob conservé = rétention). **Pas de route `update`** (document immuable).
- **Routes** (`membres/{membre}/documents`) : `GET|POST` + `GET|DELETE .../{id}`.
- **Écart assumé vs spec** : pas de `DocumentMedicalPolicy` dédiée — l'autorisation passe par `MembreFamillePolicy`
  sur le membre parent (cohérent avec les 6 sections du carnet ; évite un doublon de logique).
- **Tests** : `DocumentMedicalTest` (8) — chiffrement au repos, auteur serveur, liste blanche MIME, enum catégorie,
  téléchargement déchiffré, verrou 423 (`en_attente`/`infecte`), soft-delete + rétention du blob, IDOR. **Suite : 56/56 (178 assertions).**

#### Frontend (Expo SDK 54) — IMPLÉMENTÉ (étape B, 2026-07-03)
- **Dépendances** (`npx expo install`) : `expo-document-picker`, `expo-image-picker`, `expo-image-manipulator`,
  `expo-file-system`, `expo-sharing`. Plugin `expo-image-picker` (permissions caméra/galerie) dans `app.config.ts`.
  **Correctif d'arbre** : `react-dom` réaligné 19.2.7 → **19.1.0** (accord SDK 54, débloque `expo install`).
- **`src/api/documents.ts`** : `listerDocuments`/`supprimerDocument` (axios), `importerDocument`
  (**multipart via `createUploadTask` legacy** = boundary correct + **progression** 0→1), `telechargerDocument`
  (**nouvelle API `File.downloadFileAsync`** avec en-têtes Bearer → URI cache).
- **`src/documents/selection.ts`** : photo / galerie / fichier ; **compression 3G** (nouvelle API
  `ImageManipulator.manipulate().resize(1600).renderAsync().saveAsync(0.7 JPEG)`, seulement si l'image dépasse 1600 px) ;
  permissions demandées à l'action (`PermissionRefusee` → message clair).
- **Écrans** (`src/screens/`) : `DocumentsEcran` (liste **groupée par catégorie**, badge de statut antivirus
  `sain`/`en_attente`/`infecte`, ouverture via `expo-sharing` si `sain`, suppression confirmée, état vide) ;
  `ImportDocumentEcran` (3 sources, dropdown catégorie, titre optionnel, **barre de progression**). Routes
  `app/(app)/membres/documents/[id].tsx` + `.../documents/importer/[id].tsx` ; ligne dédiée dans la fiche membre.
- **Date du document** volontairement non saisie ici : réservée au futur **sélecteur jour/mois/année** uniforme
  (item différé du plan) ; le champ reste supporté côté API.
- **Vérifs** : `tsc --noEmit` OK, `expo install --check` OK, **expo-doctor 18/18**.

---

### F2.11 — Contacts d'urgence par membre

- **Schéma** `contacts_urgence` : `membre_id` FK CASCADE, `nom`, `lien_parente` NULL, `telephone`,
  `telephone_secondaire` NULL, `email` NULL (option, notifs Module 5), `est_principal` BOOL DEFAULT false, timestamps,
  index (membre_id, est_principal). **Règle applicative** : au plus un `est_principal = true` par membre (géré au
  FormRequest/Service, transaction).
- **Backend — IMPLÉMENTÉ (étape A, 2026-07-02)** : `ContactUrgenceController extends CarnetSectionController`
  (`relation()='contactsUrgence'`, `regles()` : nom, lien_parente, téléphone **CI** `+225`+10 chiffres,
  téléphone_secondaire, email, est_principal). **CRUD complet** générique. L'invariant « un seul principal par
  membre » vit dans le **modèle** (`ContactUrgence::booted()` → hook `saved`, sans récursion). Sécurité anti-IDOR :
  `MembreFamillePolicy` (`view`/`update`) + requêtes scopées à la relation. Endpoints (auth Bearer) :
  `GET|POST /v1/membres/{membre}/contacts-urgence`, `GET|PUT|PATCH|DELETE .../contacts-urgence/{id}`.
- **Frontend — IMPLÉMENTÉ (étape B, 2026-07-02)** : **intégré au moteur générique de sections du carnet**
  (`src/carnet/registre.ts`), pas de fichier API dédié — le contrat CRUD est identique aux autres sections, donc
  `src/api/carnet.ts` suffit (choix DRY, source unique). Section `contacts-urgence` : champs nom / lien_parenté /
  téléphone / téléphone secondaire / e-mail / contact principal ; **nouveau type de champ `format: 'telephone'|'email'`**
  (clavier adapté + validation **miroir du backend** : `+225`+10 chiffres, e-mail), indicatif `+225` prérempli.
  Résumé liste : badge « Principal » si `est_principal`. `est_principal` alimente la future carte vitale (Module 5).
  La section apparaît automatiquement dans la fiche membre (map `SECTIONS`).

#### Révision « exactement 2 contacts » (`modification.txt`, backend étape A le 2026-07-03)

Décision produit : un membre a **exactement 2 contacts** (un **principal** + un **secondaire**), le rôle découlant de
l'**ordre de création** (jamais choisi par le client) ; **l'e-mail est retiré** ; le **lien de parenté** devient une
**liste fermée**. Conservé **par membre** (F2.11) — cohérent avec la note *Continuité d'accès* (§5.2 bris de glace) et
la note *Verrou applicatif* (§3.2) qui citent explicitement « contacts d'urgence **du membre** ». UX : on **garde le
moteur générique** du carnet (pas d'écran sur-mesure) avec un plafond d'items.

- **Migration** `2026_07_03_000001_drop_email_from_contacts_urgence` : `dropColumn('email')`, réversible (`down()` la
  recrée). Aucune donnée de production (dev).
- **Modèle** `ContactUrgence` : `email` retiré du `$fillable` ; nouveau hook `deleted` → **promotion** : si le principal
  est supprimé, le contact restant est promu principal (jamais de secondaire orphelin). Le hook `saved` (un seul
  principal) est conservé.
- **Un seul numéro par contact** (2026-07-03) : migration `..._000002_drop_telephone_secondaire_from_contacts_urgence`
  (réversible) + retrait du `$fillable` et de la règle. Un contact = nom + lien + **un** téléphone.
- **Contrôleur** `ContactUrgenceController` : `regles()` = nom, `lien_parente` **`Rule::in(LIENS_PARENTE)`** (15 valeurs :
  papa, maman, epouse, epoux, frere, soeur, cousin, cousine, tante, oncle, tuteur, grand_mere, grand_pere, ami, autre),
  téléphone CI. **`est_principal` n'est plus accepté du client.** `store()` surchargé : plafond
  **`MAX_CONTACTS=2`** (3e → 422 `contact`), **unicité du `telephone`** entre les 2 (→ 422 `telephone`), rôle attribué
  par ordre (1er → `est_principal=true`). `update()` surchargé : interdit de reprendre le téléphone de l'autre contact.
  `reglesPour()` de la base passée en `protected` (réutilisée à l'`update`).
- **Tests** : `ContactUrgenceTest` (7) — rôle par ordre, refus du 3e, distinction des numéros, enum lien_parenté,
  promotion à la suppression, client ne peut forcer le rôle, isolation IDOR. **Suite : 48/48 (148 assertions).**
- **Plafond famille** `StoreMembreRequest::MAX_MEMBRES` **5 → 15** (F2.2 révisé) ; test membre mis à jour.
- **Frontend — ÉCRAN DÉDIÉ (étape B, redessinée le 2026-07-03)** : après revue visuelle, l'utilisateur a **abandonné
  le moteur générique** pour les contacts au profit d'un **écran sur-mesure** (maquette fournie), tout en gardant le
  Design System. `ContactsUrgenceEcran` (`src/screens/`) + route `app/(app)/membres/contacts-urgence/[id].tsx` ;
  la fiche membre pointe une **ligne dédiée** vers cet écran (les contacts sortent de `SECTIONS` dans `registre.ts`).
  - **2 blocs dépliables** : « Premier contact » (= principal) et « Second contact » (= secondaire), révélé par
    « Passer au second contact ». Le rôle reste **déduit de l'ordre** côté serveur (backend inchangé).
  - **Lien de parenté = menu déroulant** (Modal, défaut **Papa**), plus la grille de puces ; libellés `LIEN_PARENTE`
    exportés depuis `registre.ts` (miroir des 15 valeurs backend).
  - **Indicatif `+225` masqué** : saisie **locale 10 chiffres** ; `versE164`/`versLocal` ajoutent/retirent `+225`
    (jamais affiché). **Astérisques `*` retirés** des libellés.
  - **Photo par contact** : emplacement **désactivé** (« Bientôt disponible ») — sera branché sur le stockage
    sécurisé de **F2.10** (décision : ne pas dupliquer l'infra d'upload).
  - **Enregistrement** : un seul bouton « Ajouter » (footer collant) → POST/PUT du 1er puis du 2e (2e facultatif),
    numéros distincts vérifiés côté client (miroir backend). Réutilise l'API `carnet.ts` par `chemin`.
  - **Un seul numéro par contact** (demande utilisateur) : `telephone_secondaire` supprimé côté écran (et backend).
  - **Aperçu sur la fiche membre** : sous la ligne « Contacts d'urgence », les 1-2 contacts (nom + numéro sans `+225`
    + badge **Principal**/**Secondaire**), chargés en best-effort et rechargés au focus → l'utilisateur revoit ses
    contacts sans rouvrir l'écran.
  - Plafond carnet **`MAX_MEMBRES` 5 → 15** (`src/types/membre.ts`). Retour au moteur générique nettoyé
    (`maxItems` retiré, inutilisé). `tsc --noEmit` OK.

---

### F2.12 — Notes & observations médicales

- **Schéma** `notes_observations` : `membre_id` FK CASCADE, `contenu` TEXT **chiffré AES-256** (cast `encrypted`),
  `auteur_type` ENUM('patient','medecin'), `auteur_user_id` FK users nullOnDelete NULL, `triage_id` FK triages
  nullOnDelete NULL, `created_at` **seul** (append-only), `deleted_at` (softDeletes, rétractation tracée),
  index (membre_id, created_at). `auteur_agent_id` **différé** au Module 3/4 (table `agents_garde` absente).
- **Backend — IMPLÉMENTÉ (étape A, 2026-07-02)** : `NoteObservationController extends CarnetSectionController`
  (`relation()='notesObservations'`, `regles()` : `contenu` req/max:5000 (chiffré par le cast), `triage_id` nullable exists).
  **Append-only** : la route `update` n'est PAS exposée ; `store()` est surchargé pour **injecter l'auteur côté serveur**
  (`auteur_type='patient'`, `auteur_user_id = user()->id`), jamais depuis le client. `destroy` = **soft-delete**.
  Endpoints (auth Bearer) : `GET|POST /v1/membres/{membre}/notes-observations`, `GET|DELETE .../notes-observations/{id}`.
  **Journal d'audit FT6 des écritures : documenté, non implémenté** (décision : cohérence avec les autres sections du
  carnet ; à traiter avec le module d'audit global). Auteur médecin (via QR) différé Modules 3/4.
- **Frontend — IMPLÉMENTÉ (étape B, 2026-07-02)** : **intégré au moteur générique** via un drapeau
  **`appendOnly`** ajouté au registre (`src/carnet/registre.ts`) et exploité par `CarnetSectionListe`. Effets :
  le **tap-édition est désactivé** (aucun PUT possible côté backend → 405), seules la **création** et la
  **suppression** (= rétractation tracée / soft-delete) restent. Fil chronologique : la liste est déjà triée
  `latest()` par le backend ; chaque note affiche **date+heure** (titre) + **contenu** + **auteur** (« Vous » pour
  le patient) en badge. Auteur injecté serveur (jamais depuis le client). Pas de fichier API dédié (contrat carnet).

---

### F2.13 — Traçabilité de la provenance

**Couche données** : colonne `source ENUM('patient','medecin','structure') DEFAULT 'patient'` sur `antecedents`,
`ordonnances`, `resultats_analyses` (voir décision transverse). `documents_medicaux` la porte nativement (F2.10) ;
les notes (F2.12) portent l'équivalent `auteur_type`.

#### Backend runtime (étape A, 2026-07-04)
Constat : la colonne existait mais les 3 modèles de dossier ne l'exposaient pas en écriture (hors `$fillable`,
aucune règle) ; les lectures la renvoyaient déjà (modèle sérialisé, non masqué).
- **Modèles** `Antecedent` / `Ordonnance` / `ResultatAnalyse` : `source` ajouté à `$fillable` **+
  `protected $attributes = ['source' => 'patient']`** — la réponse de **création** porte déjà la provenance
  (sans ce défaut modèle, `create()` renvoie `source:null` car le défaut BDD ne se matérialise qu'au re-fetch).
- **Contrôleurs** `AntecedentController` / `OrdonnanceController` / `ResultatAnalyseController` : règle
  `source` → `['nullable','in:patient,medecin,structure']` (miroir de l'ENUM ; hérite du `sometimes` en update).
- **Axe orthogonal préservé** : `added_by` (`patient|medecin`, auteur de saisie) distinct de `source`
  (`patient|medecin|structure`, provenance) — cf. décision transverse F2.13.
- **Phase actuelle** : seul le patient écrit → `source='patient'` par défaut ; le chemin d'écriture
  `medecin`/`structure` viendra avec la session QR médecin (M3/M4). Rendre `source` validée permet de **semer**
  des données non-patient pour démontrer l'affichage.
- **Tests** : `CarnetSectionTest` +3 (défaut `patient` exposé à la création **et** en liste ; `source` non-patient
  acceptée/persistée sur antécédents & ordonnances ; valeur hors ENUM → 422, rien en base). **Suite : 59/59 (192 assertions).**

#### Frontend (étape B, 2026-07-04)
Pastille de provenance sur les cartes de dossier — **les 3 origines affichées**, `patient` atténué,
`medecin`/`structure` mis en avant (source de vérité). Décision validée : afficher les 3 (pas seulement non-patient).
- **`carnet/registre.ts`** : `PROVENANCE_SOURCE` exporté (miroir de l'ENUM `source`) — `patient` → « Auto-déclaré »
  (icône `person-outline`, atténué), `medecin` → « Médecin » (`medkit-outline`, officiel), `structure` →
  « Structure » (`business-outline`, officiel).
- **`CarnetSectionListe.tsx`** : la carte reçoit `item.source` ; pastille rendue **uniquement si `source` existe**
  → naturellement limitée aux 3 sections porteuses (antécédents, ordonnances, résultats). Vaccinations, rappels
  et notes (`auteur_type` déjà affiché) n'ont pas de colonne `source` → pas de pastille. Badge sémantique + pastille
  cohabitent dans une rangée qui s'enroule (`badgesRangee`). Tons : officiel = bleu (`blue[100]/700`),
  atténué = neutre (`surfaceMuted`/`ink[500]`) — **tokens DS, aucune couleur en dur**.
- **`DocumentsEcran.tsx`** : même pastille à côté du badge de statut antivirus (documents portent `source` nativement).
- **Aucun champ `source` dans le formulaire de création** : une entrée saisie par le patient reste `patient`
  (défaut serveur) ; `medecin`/`structure` proviendront du chemin d'écriture médecin (QR, M3/M4).
- **Vérifs** : `tsc --noEmit` OK.

---

## Module 2 — F2.3 Carte CMU numérique (couche de présentation)

**Statut : backend étape A IMPLÉMENTÉ & TESTÉ le 2026-07-04.** Modification isolée (doc
`Modification_F2-3_Carte_CMU_Numerique_MaSante.md`), inspirée du certificat électronique d'assurance chinois,
adaptée à la CMU/CNAM. **Le règlement/remboursement temps réel est hors périmètre** (pas d'API CNAM) : on
dématérialise seulement la carte (« présenter son téléphone au lieu de la carte physique »).

### Analyse — état de départ
Champs déjà présents sur `membres_famille` (`cmu_numero` chiffré AES, `cmu_statut`, `cmu_validite`) → **aucune
migration**. **Écart de sécurité corrigé** : `cmu_numero` était chiffré au repos mais renvoyé **en clair** par l'API
(pas dans `$hidden`) — contraire au §5.2 (exposition minimale, jamais dans le QR).

### Décisions validées
- **Numéro toujours masqué** : le numéro complet **ne quitte jamais le serveur**. `cmu_numero` passe en `$hidden` ;
  accessor `cmu_numero_masque` (`•••• •••• 1234`, 4 derniers) ajouté via `$appends`. Édition : re-saisie pour changer,
  champ non touché = inchangé (étape B).
- **Code de présentation = QR CMU signé dédié** (autonome), **pas** le token QR de dossier : assertion HMAC-SHA256
  du **statut déclaré** (`{v,typ:cmu,ref,st,val,exp}`), **sans numéro ni matricule**, n'accordant **aucun accès dossier**.
  Vérifiable hors-ligne (tolérant réseau, §3.2). Secret à **séparation de domaine** (`hmac('carte-cmu', APP_KEY)`,
  distinct du secret QR dossier). La **vérification par l'agent** (contrôle signature côté structure) est **différée
  au Module 3** (`agents_garde` inexistants) — ici on n'ÉMET que.

### Backend (étape A)
- **`config/masante.php`** section `cmu` : `exiger_palier_verifie` (défaut **`false` en dev** = stub présentable ;
  `true` en prod = exige le palier vérifié), `code_ttl_minutes` (10), `alerte_expiration_jours` (30).
- **`User::compteEstVerifie()`** : palier « vérifié » = `compte_verifie_at !== null` (auth/OTP à venir → toujours
  `null` en dev, d'où le flag stub).
- **`MembreFamille`** : `cmu_numero` masqué (`$hidden` + accessor `cmu_numero_masque` + `$appends`).
- **`CarteCmuService`** : vue carte (`titulaire`, `cmu_numero_masque`, `cmu_statut`, `cmu_validite`,
  `expiration_proche`, `disponible`, `code_presentation`, `code_expire_dans`). `disponible`/code **gated** par le palier.
- **`CarteCmuController::show`** + route `GET /api/v1/membres/{membre}/carte-cmu` (Policy `view`, anti-IDOR).
- **Audit FT6** : documenté, **non implémenté** (cohérent F2.12 — module d'audit global).
- **Tests** `CarteCmuTest` (6) : numéro complet jamais sérialisé (masqué), carte expose statut/validité, code signé
  **sans numéro ni matricule** (signature vérifiée), **palier gate le code** (non vérifié → `disponible:false`, code
  `null` ; vérifié → présentable), `expiration_proche`, IDOR 403. **Suite : 65/65 (218 assertions).**

### Frontend (étape B, 2026-07-04)
- **`types/membre.ts`** : `cmu_numero` → **`cmu_numero_masque`** dans `Membre` (le numéro complet n'existe plus
  côté client) ; type `CarteCmu` (réponse `carte-cmu`). **`api/membres.ts`** : `obtenirCarteCmu(id)`.
- **`src/screens/CarteCmuEcran.tsx`** (+ route `app/(app)/membres/carte-cmu/[id].tsx`) : vue « carte » (fond bleu DS,
  titulaire, n° masqué, **badge de statut** = élément principal actif/expiré/non-inscrit, validité, bandeau
  « expire bientôt » si `expiration_proche`). Bouton **« Présenter ma carte »** → QR du `code_presentation` en grand
  avec **décompte** (TTL) et régénération à expiration. Si `disponible=false` (palier non atteint) : carte consultable
  mais **carte de présentation remplacée par un message** « identité à confirmer » (aucun QR).
- **Fiche membre** (`[id].tsx`) : numéro → **masqué** (`cmu_numero_masque`) + entrée « Carte CMU numérique ».
- **`MembreForm.tsx`** : le numéro n'étant plus renvoyé, l'édition part **vide** (placeholder = n° masqué actuel) et
  n'envoie `cmu_numero` **que si l'utilisateur en saisit un nouveau** (sinon conserve l'existant — pas d'écrasement).
- **Vérifs** : `tsc --noEmit` OK (aucune dépendance ajoutée — `react-native-qrcode-svg` déjà présent).

**Assurances privées (hors CMU)** : déjà couvertes par F2.10 (catégorie `assurance` = photo de la carte). Rien à faire ici.

---

## Décisions en attente de validation (couche données)
1. **Portée de `categorie`** : superset à 8 (`certificat_medical, fiche_sortie, compte_rendu, imagerie, resultat_labo,
   assurance, ordonnance_externe, autre`) **ou** 6 (§2.2). Le superset couvre les documents *importés* anciens/externes
   (distincts des tables structurées F2.5/F2.6).
2. **Extras de robustesse** : `hash_sha256`, `softDeletes`, `email` contact — à inclure (recommandé) ou non.
3. **Mise en œuvre** : j'édite les 4 migrations déjà présentes (non commitées) vers ce schéma robuste, puis
   `rollback` + `migrate` (base de dev). Aucune commande destructive sans accord.
