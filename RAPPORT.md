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

---

### F2.11 — Contacts d'urgence par membre

- **Schéma** `contacts_urgence` : `membre_id` FK CASCADE, `nom`, `lien_parente` NULL, `telephone`,
  `telephone_secondaire` NULL, `email` NULL (option, notifs Module 5), `est_principal` BOOL DEFAULT false, timestamps,
  index (membre_id, est_principal). **Règle applicative** : au plus un `est_principal = true` par membre (géré au
  FormRequest/Service, transaction).
- **Backend** : modèle `ContactUrgence` (relation `membre()`), `StoreContactUrgenceRequest` (validation téléphone CI,
  format E.164), `ContactUrgenceController` (CRUD, Policy cloisonnement membre), route `apiResource`.
- **Frontend** : `src/api/contactsUrgence.ts` (CRUD), écran liste + formulaire ; le contact `est_principal` alimente la
  future carte vitale d'urgence (Module 5).

---

### F2.12 — Notes & observations médicales

- **Schéma** `notes_observations` : `membre_id` FK CASCADE, `contenu` TEXT **chiffré AES-256** (cast `encrypted`),
  `auteur_type` ENUM('patient','medecin'), `auteur_user_id` FK users nullOnDelete NULL, `triage_id` FK triages
  nullOnDelete NULL, `created_at` **seul** (append-only), `deleted_at` (softDeletes, rétractation tracée),
  index (membre_id, created_at). `auteur_agent_id` **différé** au Module 3/4 (table `agents_garde` absente).
- **Backend** : modèle `NoteObservation` (`const UPDATED_AT = null`, cast `contenu`→`encrypted`, `use SoftDeletes`,
  relations `membre()`, `auteur()`), FormRequest, contrôleur (create/list/soft-delete, **inscription au journal d'audit FT6**),
  Policy cloisonnement.
- **Frontend** : `src/api/notesObservations.ts`, fil chronologique horodaté + attribution auteur, saisie patient.

---

### F2.13 — Traçabilité de la provenance
Colonne `source ENUM('patient','medecin','structure') DEFAULT 'patient'` sur `antecedents`, `ordonnances`,
`resultats_analyses` (voir décision transverse). Côté UI : badge de provenance (ex. « Hôpital » vs « Auto-déclaré »).

---

## Décisions en attente de validation (couche données)
1. **Portée de `categorie`** : superset à 8 (`certificat_medical, fiche_sortie, compte_rendu, imagerie, resultat_labo,
   assurance, ordonnance_externe, autre`) **ou** 6 (§2.2). Le superset couvre les documents *importés* anciens/externes
   (distincts des tables structurées F2.5/F2.6).
2. **Extras de robustesse** : `hash_sha256`, `softDeletes`, `email` contact — à inclure (recommandé) ou non.
3. **Mise en œuvre** : j'édite les 4 migrations déjà présentes (non commitées) vers ce schéma robuste, puis
   `rollback` + `migrate` (base de dev). Aucune commande destructive sans accord.
