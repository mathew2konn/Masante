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

## Module 2 — Profil enrichi & sélecteur de date (item optionnel)

**Audit du schéma `membres_famille`** (préalable imposé) : une seule migration, **aucune altération ultérieure**.
Tous les champs profil **existent déjà** (`date_naissance`, `sexe`, `groupe_sanguin`, `photo_url`, CMU) → **aucune
migration**. Manques réels : (A) les dates sont des **champs texte AAAA-MM-JJ** (source d'erreurs) ; (B) la **photo**
n'est captée nulle part. Décision : traiter **A puis B**, séquencés (chacun sa barrière).

### Sous-item A — Sélecteur de date uniforme (frontend seul, 2026-07-04→05)
Choix retenu (révisé) : **wheel picker custom** reproduisant la maquette « molette » fournie (3 colonnes
jour/mois/année, effet 3D, fondus, bande centrale), **sans dépendance d'animation externe**. Le picker natif
`@react-native-community/datetimepicker` d'abord installé a été **retiré** (dépendance + config plugin) au profit
de ce composant, plus fidèle et uniforme.
- **`components/DateWheelPicker.tsx`** : portage RN fidèle du composant web (framer-motion → RN) — `ScrollView`
  natif (inertie + `snapToInterval`) piloté par `Animated` (interpolations `rotateX`/`scale`/`opacity` sur le
  défilement, `useNativeDriver`), fondus haut/bas via `expo-linear-gradient` (déjà présent), bande de sélection
  centrale. Logique jour/mois/année : mois en `Intl` (fr-FR, capitalisé), jour **replafonné** (28-31) au changement
  de mois/année, années décroissantes bornées `minYear`/`maxYear`. Tokens DS (aucune couleur en dur sauf le voile
  modal, cohérent avec l'existant).
- **`components/DateField.tsx`** : ouvre le `DateWheelPicker` dans une **feuille modale** (identique Android/iOS)
  avec **Annuler / Valider**. Valeur E/S **AAAA-MM-JJ** ; `min`/`max` bornent l'année **et** replafonnent la valeur
  finale ; icône calendrier + croix d'effacement si facultatif.
- **`utils/dates.ts`** : `dateInputVersDate` / `dateVersDateInput` (conversion **par composants locaux**, pas d'UTC,
  pour éviter le décalage de fuseau ; rejette les dates qui « glissent » type 31 février).
- **Câblage** : `MembreForm` (naissance : bornée passé/~120 ans, obligatoire ; validité CMU : facultative),
  `CarnetSectionForm` (toutes les dates ; `max` = aujourd'hui si `futurInterdit` ; la contrainte `apresChamp`
  reste vérifiée à la soumission), `ImportDocumentEcran` (**date du document** enfin saisie — champ jusque-là différé ;
  l'API F2.10 la supportait déjà).
- **Vérifs** : `tsc --noEmit` OK, `expo install --check` « up to date » ; `expo-doctor` 16-17/18 (seul le check
  « schéma de config » échoue faute de réseau vers l'API Expo — environnemental, pas un défaut de config).

### Sous-item B — Photo de profil du membre
`photo_url` existait déjà (nullable) mais rien ne le renseignait ; l'avatar affichait les initiales.
Décision validée : **photo chiffrée au repos** ; gestion **depuis l'avatar de la fiche** (le membre a un id).

#### Backend (étape A, 2026-07-05)
Même chaîne de sécurité que F2.10, sans antivirus (image de l'utilisateur, affichée à lui seul) :
- **Disque privé `avatars`** (`config/filesystems.php`, hors `public/`) ; **`config/masante.php` → `photo`**
  (liste blanche MIME réels, `max_ko` 5 Mo).
- **`MembreFamille`** : `photo_url` **retiré de `$fillable`** (jamais posé par le client) **et `$hidden`**
  (chemin interne jamais exposé, comme `matricule_ivs`) ; accessor booléen **`a_photo`** ajouté à `$appends`.
  `photo_url` retiré aussi des règles `Store/UpdateMembreRequest`.
- **`PhotoMembreService`** : `store` (chiffrement `Crypt` AES, nom UUID, remplace l'ancien blob), `retrieve`
  (déchiffré + **MIME réel via finfo** sur les octets), `delete` (blob + colonne).
- **`StorePhotoRequest`** : `authorize` → Policy `update` ; `mimetypes:` (finfo réel) + `max`.
- **`PhotoMembreController`** + routes `POST|GET|DELETE /membres/{membre}/photo` : `show` sert l'image
  **déchiffrée** (content-type réel, `Cache-Control: no-store`), 404 si aucune ; `store`/`destroy` renvoient le membre.
- **Tests** `PhotoMembreTest` (6) : upload chiffré + `a_photo` exposé **sans** le chemin, GET sert l'image, 404 sans
  photo, type non-image → 422, suppression (blob + 404 ensuite), IDOR 403. **Suite : 71/71 (245 assertions).**

#### Frontend (étape B, 2026-07-05)
- **`api/photo.ts`** : `urlPhotoAbsolue` (URL de l'endpoint pour `<Image>`), `televerserPhoto` (upload multipart
  via `createUploadTask`, champ `photo`, renvoie le membre), `supprimerPhoto` (axios).
- **`types/membre.ts`** : `photo_url` → **`a_photo: boolean`** (miroir de l'API).
- **`documents/selection.ts`** : `prendrePhotoProfil` / `choisirPhotoProfilGalerie` — **recadrage carré imposé**
  (`allowsEditing` + `aspect [1,1]`) + réduction ~512 px (JPEG 0.8), réutilisant l'infra image de F2.10.
- **Fiche membre** (`[id].tsx`) : l'avatar affiche la **photo** (`<Image>` avec en-tête **Bearer** + `?v=` anti-cache)
  si `a_photo`, sinon les **initiales** ; **badge appareil photo** ; tap → menu **Prendre / Choisir / Supprimer**
  (Supprimer si photo présente) ; indicateur d'activité pendant l'envoi ; le membre renvoyé rafraîchit `a_photo`.
- **Vérifs** : `tsc --noEmit` OK.

---

## Décisions en attente de validation (couche données)
1. **Portée de `categorie`** : superset à 8 (`certificat_medical, fiche_sortie, compte_rendu, imagerie, resultat_labo,
   assurance, ordonnance_externe, autre`) **ou** 6 (§2.2). Le superset couvre les documents *importés* anciens/externes
   (distincts des tables structurées F2.5/F2.6).
2. **Extras de robustesse** : `hash_sha256`, `softDeletes`, `email` contact — à inclure (recommandé) ou non.
3. **Mise en œuvre** : j'édite les 4 migrations déjà présentes (non commitées) vers ce schéma robuste, puis
   `rollback` + `migrate` (base de dev). Aucune commande destructive sans accord.

---

# Phase B — B1 : Authentification durcie

Source : `docs/modification.txt` (« Mot de passe oublié », inscription minimale) + `docs/Securite_IVOIRSANTE_2.docx`
(chap. 4 : récupération durcie face à la menace « téléphone en main »). Décisions validées le 2026-07-07 :
**politique MDP forte unifiée · preuve durcie = date de naissance (branche CMU/CNI dormante) · hachage bcrypt**.

## Étape A — Backend (2026-07-07)

L'auth de base préexistait (register / verify-otp / login / logout / me, tables `codes_otp`, `tokens_qr`, Sanctum) :
B1 la **complète**, sans la refaire, en réutilisant `OtpService` (OTP haché, 5 min, max 5 tentatives, quota 5/h).

### Mot de passe oublié — flux OTP 3 étapes (séparées à dessein)
- `POST /api/v1/auth/password/forgot` `{telephone}` — génère un OTP `recuperation` **uniquement si le compte
  existe**, mais renvoie une **réponse identique** dans tous les cas (anti-énumération, §modification.txt étape 1).
- `POST /api/v1/auth/password/verify-otp` `{telephone, code, date_naissance?}` — vérifie l'OTP **puis la preuve
  durcie**, et délivre un **jeton de réinitialisation à usage unique (~10 min)**, haché SHA-256 en base
  (`password_reset_grants`). La séparation empêche de changer le MDP dans la requête qui saisit le code.
- `POST /api/v1/auth/password/reset` `{reset_token, password, password_confirmation}` — applique la politique MDP,
  ré-hache, puis **révoque TOUS les tokens Sanctum** du compte (les sessions volées deviennent inertes).

### Changement volontaire (connecté)
- `POST /api/v1/auth/password/change` `{current_password, password, password_confirmation}` (`auth:sanctum`) — vérifie
  l'ancien MDP (pas d'OTP), puis **révoque les AUTRES sessions** en conservant la session courante.

### Preuve de récupération durcie (note Securite_2, chap. 4)
Graduée par palier, dans `PasswordResetService::verifierPreuveDurcie()` :
- **Palier base** → **date de naissance** exacte du titulaire (nouvelle colonne `users.date_naissance`, nullable).
- **Palier vérifié** → 4 derniers du n° CMU/CNI : **branche codée mais DORMANTE** — aucun flux de vérification
  d'identité ne pose encore `compte_verifie_at` ni ne stocke ce n° sur `users` (s'activera avec le module Identité).
- **Compte sans donnée de preuve** (profil incomplet) → l'OTP fait foi. **Limitation assumée** : la menace
  « téléphone en main » n'est pleinement couverte qu'une fois la date de naissance renseignée ; l'app incitera à
  compléter le profil.

### Politique de mot de passe — source unique
`App\Rules\PasswordPolicy::regles()` (partagée inscription + reset + change) : **≥8, lettres, MAJ+min, chiffres,
symboles**, + **non-compromis (HIBP)**. Le contrôle HIBP est confié à `App\Rules\NotCompromisedPassword`
(k-anonymat) plutôt qu'à `Password::uncompromised()`.

> ⚠️ **Piège environnemental résolu** : `Password::uncompromised()` appelle `api.pwnedpasswords.com` à chaque
> validation ; sur ce WAMP (pas de bundle CA → `cURL error 60`) l'appel lève une exception **non catchée** →
> `register`/`reset`/`change` renverraient **500**. `NotCompromisedPassword` est **fail-open** : une panne réseau
> est journalisée et laissée passer (jamais de verrouillage patient), les autres critères MDP restant appliqués.

### Inscription
`RegisterRequest` : **e-mail retiré** (inscription minimale nom/prénom/téléphone/MDP) ; politique MDP alignée.

### Schéma & fichiers
- Migrations : `add_date_naissance_to_users`, `create_password_reset_grants_table`.
- Nouveaux : `PasswordController`, `PasswordResetService`, modèle `PasswordResetGrant`, règles `PasswordPolicy`
  + `NotCompromisedPassword`, requests `Forgot/VerifyResetOtp/Reset/ChangePasswordRequest`.
- Modifiés : `User` (`date_naissance` fillable+cast), `RegisterRequest`, `AuthController::register`, `routes/api.php`.
- **Notification e-mail + audit FT6** : journalisés en stub (mailer non branché en dev ; journal d'audit global à venir).

### Tests
`PasswordResetTest` (9) : anti-énumération, parcours complet + révocation des tokens, preuve DDN incorrecte/manquante,
dégradation sans DDN, jeton usage unique, jeton expiré, MDP faible rejeté, changement connecté (ancien MDP + révocation
des autres sessions). **Suite : 80/80 (269 assertions).** `composer audit` : 0 avis. HIBP faké (offline) en test.

## Étape B — Frontend (2026-07-07)

Réutilise le client axios unique et les composants du Design System ; aucune dépendance ajoutée.

- **Barre de force** (`src/auth/motDePasse.ts` + `components/MotDePasseForce.tsx`) : miroir exact de
  `PasswordPolicy` (≥8, MAJ+min, chiffre, symbole ; le HIBP est purement serveur). 4 segments colorés +
  checklist « ce qui manque », affichée au fil de la saisie (modification.txt §1).
- **Inscription** : barre de force sous le champ ; « Continuer » désactivé tant que la politique locale n'est
  pas verte. Aucun champ e-mail.
- **Connexion** : lien « Mot de passe oublié ? ».
- **Mot de passe oublié** (`app/(auth)/mot-de-passe-oublie.tsx` → `reinitialiser.tsx`) : téléphone → (code OTP +
  **date de naissance** via la molette `DateField`) → nouveau mot de passe + barre de force + confirmation. Le
  `reset_token` reste **en mémoire** (jamais en paramètre de navigation).
- **Changer mon mot de passe** (`app/(app)/parametres/mot-de-passe.tsx`, accès depuis l'onglet Carnet) : ancien +
  nouveau + confirmation ; conserve la session courante.
- **API/types** : `passwordForgot/VerifyOtp/Reset/Change` dans `api/auth.ts` ; types `Forgot/VerifyReset/Message`.

**Vérifs** : `tsc --noEmit` OK ; routes typées régénérées ; aucune dépendance ajoutée. **« B1 validé » le 2026-07-07.**

### Correctifs de robustesse annexes (mêmes tests)
- **401 JSON invité** (`bootstrap/app.php`, `redirectGuestsTo(fn () => null)`) : un invité sur `api/*` reçoit un
  401 JSON, plus un 500 `Route [login] not defined` (déclenché quand un client omet `Accept: application/json`).
- **Photo membre robuste Android** (`api/photo.ts` + `membres/[id].tsx`) : la photo est **téléchargée avec le token**
  vers le cache (`telechargerPhoto`) puis affichée en fichier local, au lieu de `<Image source={{ headers }}>` —
  l'en-tête Bearer n'étant pas fiable sur le loader natif Android (Fresco) → 401.

---

# Phase B — B2 : Verrou applicatif

Source : note `Securite_IVOIRSANTE_2.docx`, chap. 3 (seconde barrière LOCALE contre la menace « téléphone en
main »). Décisions validées le 2026-07-07 : **opt-in · périmètre = fiches membres + « Mes rendez-vous »
(onglet Carnet libre) · PIN 6 chiffres haché · biométrie + repli PIN**. **Frontend uniquement** (aucun backend,
aucune table : le PIN et l'état vivent dans `expo-secure-store`, chiffré matériellement).

## Étape unique — Frontend (2026-07-07)

- **Dépendances** ajoutées via `npx expo install` : `expo-local-authentication` (biométrie), `expo-crypto` (SHA-256
  du PIN). react/react-dom inchangés (19.1.0) ; `expo-doctor` 18/18. Plugin Face ID dans `app.config.ts`.
- **`src/auth/verrou.ts`** : secret + politique. PIN 6 chiffres **haché SHA-256 + sel aléatoire** (jamais en clair),
  stocké en secure-store. Anti-force brute : **5 tentatives** puis délais **30 s / 1 min / 5 min**. Biométrie via
  `hasHardwareAsync`/`isEnrolledAsync`/`authenticateAsync` (`disableDeviceFallback` : on gère notre propre repli PIN).
- **`src/auth/VerrouContext.tsx`** : période de **grâce 2 min** (un déverrouillage ouvre les sections sensibles sans
  re-demande en navigation active) ; **re-verrouillage immédiat en arrière-plan** (écoute `AppState`).
- **`components/VerrouGate.tsx`** : enveloppe une zone sensible ; si verrou actif + grâce expirée → écran de
  déverrouillage (biométrie auto si activée + repli PIN, blocage avec décompte, « PIN oublié » → déconnexion +
  reconnexion). `components/SaisiePin.tsx` : 6 pastilles + champ masqué.
- **`app/(app)/parametres/securite.tsx`** (depuis l'onglet Carnet) : activer/désactiver, définir/changer le PIN
  (saisie + confirmation), bascule biométrie si disponible.
- **Câblage** : `VerrouProvider` sous `SessionProvider` (`app/_layout.tsx`) ; `VerrouGate` sur
  `membres/_layout.tsx` (toute la pile fiches) et sur `structures/mes-rendez-vous.tsx`. Onglet Carnet, Triage,
  Carte, SOS restent **libres** (chap. 3.2).

**Ce qui n'est PAS fait** (fidélité aux docs) : aucun backend, pas de sync multi-appareils du PIN, le verrou ne
remplace pas l'auth (couches indépendantes). **Vérifs** : `tsc --noEmit` OK ; `expo-doctor` 18/18.

---

# Phase B — B3 : Délégation d'accès (voie 3)

Source : note `Note_Continuite_Acces_Dossier_MaSante.docx`, chap. 4. Le titulaire désigne un adulte de confiance
(délégué, ayant son propre compte) autorisé **uniquement à générer le QR** d'un membre — jamais à modifier le
dossier ni le compte. Décisions validées le 2026-07-07 : **gate « titulaire vérifié » = flag config (défaut off en
dev)** · **trace via colonne `tokens_qr.genere_par_delegue_id` + notif stub**.

## Étape A — Backend (2026-07-07)

- **Migrations** : `delegations` (schéma §4.3 : titulaire/délégué/membre, `droits` ENUM, invitee/acceptee/revoquee_at,
  `UNIQUE(delegue_user_id, membre_id)`, FK cascade) ; `genere_par_delegue_id` (nullable, FK users) sur `tokens_qr`.
- **Modèle `Delegation`** + `estActive()` / scope `active()` / `actifPour(delegue, membre)`.
- **`DelegationController`** :
  - `GET /api/v1/delegations` — `{ accordees, recues }` (projection minimale du membre : id/prénom/nom).
  - `POST /api/v1/membres/{membre}/delegations` — invite par téléphone. Règles : membre au titulaire (Policy),
    **flag titulaire vérifié**, délégué existant + tél vérifié + ≠ titulaire, pas de doublon actif/en attente
    (réutilise une ligne révoquée via `updateOrCreate`).
  - `POST /api/v1/delegations/{delegation}/accepter` — le délégué accepte (`acceptee_at`).
  - `DELETE /api/v1/delegations/{delegation}` — révocation (titulaire) / refus (délégué) → `revoquee_at`.
- **Autorisation QR** : `MembreFamillePolicy::generateQr` = propriétaire **OU** délégué actif. `QrController::generer`
  détecte le générateur ; si délégué → notif titulaire (log stub) + `QrTokenService::generer($membre, $delegueId)`
  écrit `genere_par_delegue_id` (prêt pour `type_acces='delegation'` au scan, M3).
- **Config** : `masante.delegation.exiger_titulaire_verifie` (défaut `false`).
- **Tests `DelegationTest` (14)** : invitation (numéro inconnu, délégué non vérifié, anti-self, anti-IDOR sur membre
  d'autrui, doublon, gate vérifié), acceptation (seul le délégué), révocation, **QR par délégué actif = 201 +
  trace / non-accepté / révoqué / étranger = 403**, index séparé accordées/reçues. **Suite : 94/94 (296 assertions)**,
  `composer audit` 0 avis.

## Étape B — Frontend (2026-07-07)

Réutilise le client axios unique, les composants DS et **l'écran QR existant** (le délégué génère via la même
route, sous le verrou B2). Aucune dépendance ajoutée.

- **`api/delegations.ts` + `types/delegation.ts`** : `listerDelegations` (`{accordees, recues}`), `inviterDelegue`,
  `accepterDelegation`, `revoquerDelegation`. Membre projeté a minima (jamais le dossier).
- **Titulaire** — `app/(app)/membres/delegues/[id].tsx` (accès depuis la fiche membre, « Gérer les délégués ») :
  invite par téléphone, liste avec statut (en attente / actif), révocation.
- **Délégué** — `app/(app)/partages.tsx` (accès depuis l'onglet Carnet, « Partages reçus ») : accepter/refuser une
  invitation, puis « Générer le QR » → écran QR existant (le délégué passe par le `VerrouGate` en entrant dans la
  pile membres).
- **Câblage** : pile `partages` (route feuille) enregistrée `href:null` dans le Tabs racine.
- **Correctif de route annexe** : ajout de `app/(app)/parametres/_layout.tsx` (Stack) — sans lui, `parametres`
  n'était pas une route unique (warning « No route named parametres »). Aligne `parametres/` sur `membres/`/`structures/`.

**Vérifs** : `tsc --noEmit` propre hormis les 2 littéraux de routes typées (`.expo/types` généré, gitignoré) qui se
régénèrent au premier `npx expo start` — confirmé côté utilisateur. **« B3 validé » le 2026-07-07. Phase B COMPLÈTE.**

---

# Module 3 — F3.5 : Choix du médecin au rendez-vous

Source : `Analyse_Delta_RDV_MaSante.md` **N5** (« choix du médecin selon 3 modes : patient choisit / hôpital
attribue / mixte »). Décisions validées le 2026-07-08 : **mode à 2 valeurs** (le « mixte » = préférence patient
réassignable par l'agent au Module 4, pas de 3ᵉ état stocké) · **1 médecin rattaché à 1 service** (FK, cascade
naturelle) · **`tarif_consultation` indicatif à l'affichage, AUCUNE logique de paiement** (le bloc Mobile Money
FT5/N1 reste hors périmètre du prototype).

## Étape A — Backend (2026-07-08)

- **Migration `medecins`** : `structure_id` + `service_id` (FK cascade), `titre` (Dr/Pr), `nom`, `prenom`,
  `specialite` (libellé), `tarif_consultation` (unsigned nullable, FCFA), `actif`. Annuaire **professionnel public,
  non sensible** (aucun chiffrement, cohérent avec `structures`/`services`).
- **Migration `rendez_vous`** : `medecin_id` **nullable** (FK `nullOnDelete`) + `mode_attribution`
  enum `patient_choisit` / `etablissement_attribue` (défaut `etablissement_attribue`).
- **Modèle `Medecin`** (+ relations `structure`/`service`) ; `ServiceEtablissement::medecins()` ; `RendezVous::medecin()`.
- **Exposition** : `StructureService::fiche()` imbrique `services.medecins` **actifs** (triés par nom) → l'écran RDV,
  qui charge déjà la fiche via `GET /v1/structures/{id}`, reçoit les médecins **sans round-trip supplémentaire**.
  Lecture publique (aucun endpoint dédié ajouté).
- **`RendezVousController::store()`** : `medecin_id` nullable validé ; s'il est fourni, garde anti-incohérence
  (**doit appartenir au `service_id` ET à la `structure_id` choisis**, sinon 422) ; `mode_attribution` **déduit
  côté serveur** (jamais piloté par le client). `index()` charge le médecin (projection `id,titre,nom,prenom,specialite`).
- **Seeder** : 1–2 praticiens par service de consultation (officines/labos exclus), noms ivoiriens, tarif calé sur
  le type de structure. **Nécessite `migrate:fresh --seed`** pour peupler les médecins.
- **Tests `RendezVousMedecinTest` (5)** : médecins actifs exposés en public sous les services (inactif masqué) ;
  RDV avec médecin → `patient_choisit` ; RDV sans médecin → `etablissement_attribue` ; médecin d'un autre
  service → 422 ; médecin d'une autre structure → 422. **Suite : 99/99 (308 assertions)**, `composer audit` 0 avis.

## Étape B — Frontend (2026-07-08)

Réutilise le client axios unique, les composants DS (`Chip`, `Card`) et l'écran RDV existant. **Aucune dépendance
ajoutée.**

- **`types/structure.ts`** : interface `Medecin` (`titre/nom/prenom/specialite/tarif_consultation`) ; `Service.medecins?`
  (présent sur la fiche détaillée) ; `RendezVous.medecin` + `mode_attribution` (`ModeAttribution`) ; `medecin_id?`
  optionnel sur `RendezVousPayload`.
- **`rendez-vous-nouveau.tsx`** : nouvelle carte **« Médecin »** affichée sous le service **choisi** (masquée si le
  service n'a aucun praticien). Chips = **« Peu importe »** (défaut) + un chip par médecin (`Dr Prénom Nom`). Changer
  de service réinitialise le médecin. Tarif indicatif affiché sous les chips si présent (`formatFcfa`, séparateur de
  milliers compatible Hermes). `medecin_id` transmis **uniquement** s'il est choisi → sinon l'établissement attribue.
- **`mes-rendez-vous.tsx`** : ligne médecin (`Dr Prénom Nom`) ou « Médecin attribué par l'établissement » selon le cas.

**Vérifs** : `tsc --noEmit` **OK** ; aucune dépendance ajoutée. Les praticiens n'apparaissent dans l'app que si la
base est seedée (`migrate:fresh --seed`, destructif).

---

# Module 3 — F3.1 : clustering des marqueurs (carte)

Source : CdC §5.3 **F3.1** (« Cluster automatique des marqueurs en cas de zoom arrière »). Comble le seul manque
de F3.1 ; le reste de la carte (OSM/Leaflet, GPS utilisateur, pastilles de dispo) était déjà livré (3B.2).

## Étape unique — Frontend (2026-07-08)

`src/components/MapWebView.tsx` uniquement. **Aucune dépendance npm** (le plugin est chargé dans la WebView).

- **Plugin `leaflet.markercluster@1.5.3`** chargé depuis **unpkg** (CSS `MarkerCluster.css` + `MarkerCluster.Default.css`
  + JS) — hôte **déjà dans l'allowlist** `HOSTS_AUTORISES` → **Sécurité §8 inchangée**, aucune nouvelle origine.
- **Regroupement** : les marqueurs de structures passent dans un `L.markerClusterGroup` (`maxClusterRadius: 55`,
  `showCoverageOnHover: false`, `spiderfyOnMaxZoom: true`) → clusters au dézoom, éclatement au zoom (comportement F3.1).
- **Itinéraire (F3.7) + marqueur « Vous êtes ici » restent hors cluster** (couche `L.layerGroup` distincte).
- **Marqueur clusterisable** : `L.circleMarker` remplacé par `L.marker` + **`L.divIcon` pastille colorée** (`.dot`,
  couleur = palette de dispo **contrôlée**, jamais de donnée utilisateur → pas d'injection). Popups toujours en
  `textContent`. `fitBounds` inchangé.

**Vérifs** : `tsc --noEmit` OK ; aucune dépendance ajoutée. La carte charge le plugin depuis le web (comme Leaflet/tuiles)
→ device online requis (déjà le cas).

---

# Module 3 — F3.7 : modes d'itinéraire (à pied / voiture)

Source : CdC §5.3 **F3.7** (« Modes : à pied, en voiture, en transport commun »). L'itinéraire existait déjà (3B.3)
mais en **voiture seule**. Frontend seul.

## Étape unique — Frontend (2026-07-08)

- **Changement de fournisseur de routage** : `router.project-osrm.org` (démo, **voiture uniquement**) → **`routing.openstreetmap.de`**
  (le routeur public d'openstreetmap.org), qui expose des instances OSRM séparées **`routed-car`** (voiture) et
  **`routed-foot`** (à pied). **Gratuit, sans clé.** Hôte **fixe**, interpolation **numérique seule** → Sécurité §8
  respectée. L'appel se fait en RN (axios), **hors** allowlist WebView → `HOSTS_AUTORISES` inchangé.
- **Limite assumée (conflit CdC signalé)** : le **transport commun** n'a pas de routage transit public gratuit fiable
  → **non implémenté**, documenté comme limite (migration Google/transit en prod). Décision prise le 2026-07-08.
- **`api/itineraire.ts`** : type `ModeItineraire = 'voiture' | 'pied'` ; `OSRM_BASE` mappe le mode → l'instance ;
  `calculerItineraire(depart, arrivee, mode = 'voiture')`.
- **`structures/itineraire.tsx`** : sélecteur 2 boutons (« En voiture » / « À pied », icônes `car`/`walk`) dans
  l'en-tête ; le changement de mode **recalcule le tracé avec la position déjà obtenue** (pas de re-demande GPS) via
  `calculerPour(position, mode)` ; libellé de durée dynamique (« min à pied » / « min en voiture ») ; repli appli
  externe avec le **même** moteur (`fossgis_osrm_foot`/`_car`).

**Vérifs** : `tsc --noEmit` OK ; aucune dépendance ajoutée.

---

# Module 3 — F3.2 : filtre par tarif (budget)

Source : CdC §5.3 **F3.2** (« Filtrage simultané par : … tarif approximatif »). Dernier manque de F3.2 (les autres
filtres — spécialité, dispo, type, commune — existaient déjà). **Aucune migration** : `tarif_min_cfa` / `tarif_max_cfa`
sont déjà sur `structures_sanitaires`.

## Étape A — Backend (2026-07-08)

- **`StructureController::index`** : nouveau paramètre `tarif_max` validé (`nullable|integer|min:0|max:1000000`).
- **`StructureService::appliquerFiltres`** : si `tarif_max` fourni → `whereNotNull('tarif_min_cfa')` +
  `where('tarif_min_cfa', '<=', tarif_max)`. Sémantique = **structures dont la consultation DÉBUTE dans le budget**.
  **Choix assumé** : les structures **sans tarif renseigné** (officines/labos, `tarif_min_cfa` NULL) sont **exclues**
  quand le filtre budget est actif (un filtre de prix ne s'applique qu'aux consultations tarifées).
- **Test `test_filtre_par_tarif_max_budget`** : budget 25 000 → seule la structure « Abordable » (min 5 000) ressort ;
  « Chère » (min 30 000) et « Officine » (NULL) exclues. **Suite : 100/100**.

## Étape B — Frontend (2026-07-08)

`carte.tsx` (onglet Carte) + `types/structure.ts`. **Aucune dépendance ajoutée.**

- **`types/structure.ts`** : `tarif_max?` sur `FiltresStructure` ; constante **`BUDGETS`** (paliers « Tous tarifs »,
  « ≤ 5 000 », « ≤ 10 000 », « ≤ 25 000 », « ≤ 50 000 »).
- **`carte.tsx`** : state `tarifMax` (null = tous) ; ajouté à la requête `rechercherStructures` (`if (tarifMax !== null)`)
  et aux dépendances du chargement debouncé ; **3ᵉ rangée de puces « budget »** (même composant `FiltreChip` que
  type/commune), libellé suffixé « FCFA » sauf « Tous tarifs ».

**Vérifs** : `tsc --noEmit` OK ; aucune dépendance ajoutée. **→ Clôt les trous CdC géoloc du Module 3 (F3.1, F3.2, F3.7).**

---

# Module 3 — RDV enrichi N1/N2/N3 : paiement (simulé) + reçu + QR de check-in

Source : `Analyse_Delta_RDV_MaSante.md` **N1** (paiement), **N2** (reçu numérique), **N3** (QR de RDV distinct du QR
carnet). Version **complète avec paiement** retenue par l'utilisateur le 2026-07-08 (aval directeur confirmé).

**⚠️ Paiement SIMULÉ** : aucune passerelle Mobile Money réelle (Orange/MTN/Moov) n'est accessible (FT5 « simulé en
dev » + limite CNAM). On **modélise** l'encaissement : statut `paye` d'emblée, `transaction_ref` factice (`SIM-…`).
Jamais présenté comme un règlement réel. **Hors périmètre / Module 4** : encaissement par un agent, rôle Caisse (N7),
et le **scan/validation du QR à l'accueil**.

## Étape A — Backend (2026-07-08)

- **Migrations** : `paiements` (`rendez_vous_id`, `montant`, `mode` [mobile_money/especes/carte], `statut`
  [en_attente/paye/echoue, défaut paye], `transaction_ref`) ; `recus_rdv` (`rendez_vous_id` **unique** = 1 reçu/RDV,
  `paiement_id` nullable, `reference` unique, `statut` [reserve/paye/confirme/utilise/annule/expire], `expires_at`).
- **Modèles** `Paiement` (const `MODES`) / `RecuRdv` + relations `RendezVous::recu()` (hasOne) / `paiements()` (hasMany).
- **`RecuRdvService`** : `payer()` (montant serveur, paiement + reçu sous transaction, idempotent via unicité) ;
  `vue()` (reçu présentable + code). **Montant serveur** = tarif du **médecin** choisi (F3.5) sinon `tarif_min_cfa`
  de la structure ; **422** si aucun tarif. `expires_at` = fin de journée du RDV.
  **QR de check-in (N3)** = token **signé HMAC autonome** `base64url({v,typ:'rdv',ref,rdv,exp}).sig`, **secret
  cloisonné** `hmac('recu-rdv', app.key)` **distinct** du QR carnet (`QrTokenService`) et du code CMU
  (`CarteCmuService`) → **aucune donnée médicale, n'ouvre pas le dossier** (exigence sécurité §3 du doc), régénéré à
  chaque affichage (TTL 15 min).
- **`RecuRdvController`** : `POST rendez-vous/{rdv}/paiement` (anti-IDOR ; refus si RDV annulé/refusé ; mode validé)
  et `GET rendez-vous/{rdv}/recu` (anti-IDOR ; 404 sans reçu). Routes sous `auth:sanctum`.
- **Tests `RecuRdvPaiementTest` (8)** : montant médecin / repli structure / **422 sans tarif** / anti-IDOR 403 /
  **double paiement 422** / RDV annulé 422 / **code signé & sans donnée médicale** (signature HMAC vérifiée, clés du
  payload limitées, noms membre/médecin absents du token) / reçu 404 sans paiement. **Suite : 108/108**, audit 0.
- **Décision assumée** : le statut de paiement vit sur `recus_rdv`/`paiements`, on **ne mute pas** `rendez_vous.statut`
  (propriété du flux agent Module 4) — léger écart avec N1 « statut RDV intègre payé », au profit d'un cloisonnement
  clair des responsabilités.

## Étape B — Frontend (2026-07-08)

Réutilise le client axios unique, les composants DS et `react-native-qrcode-svg` (déjà installé pour le QR carnet).
**Aucune dépendance ajoutée.** Aucun changement backend (l'écran reçu fait `GET recu` → 404 = propose de payer).

- **`types/structure.ts`** : `ModePaiement` (`mobile_money`/`especes`/`carte`), interface `RecuRdv`,
  `LIBELLE_MODE_PAIEMENT`.
- **`api/rendezvous.ts`** : `payerRendezVous(id, mode)` (POST paiement) ; `obtenirRecu(id)` (GET reçu, 404 si non payé).
- **`app/(app)/structures/recu/[id].tsx`** (route feuille) : au montage, `GET recu` → si **404**, écran **paiement**
  (choix du mode + bandeau « paiement de démonstration, aucun débit réel ») ; sinon écran **reçu** avec le **QR de
  check-in** (`QRCode value={recu.code}`), référence, statut, patient/structure/service/médecin/date, montant + mode,
  `transaction_ref` (simulée), et la mention **« ne donne pas accès à votre dossier médical »**.
- **`mes-rendez-vous.tsx`** : action **« Reçu / paiement »** sur chaque RDV non annulé/refusé → navigue vers l'écran reçu.

**Vérifs** : `tsc --noEmit` OK ; aucune dépendance ajoutée.

---

# Module 4 — Portail administratif (web Blade)

Sources : **CdC §5.4** (portail web, workflow établissement→gestionnaire→agents, 3 rôles) ; **Sécurité §4**
(RBAC via **`spatie/laravel-permission`**, 3 rôles, middleware) ; §3 (activation par lien, sessions). Nouvelle
surface : **site web à sessions** (guard `web`), **distinct** de l'API mobile stateless (Sanctum). Découpage :
4.1 socle · 4.2 établissements · 4.3 services+agents · 4.4 dispo+validation RDV · 4.5 scan QR · 4.6 modération.

## 4.1 — Socle portail (backend + Blade, 2026-07-08)

- **Dépendance** : `spatie/laravel-permission ^8.3` (composer, audit 0). Migration publiée + migrée. Trait
  `HasRoles` sur `User`.
- **Décision** : les comptes **staff** vivent dans la **même table `users`** (email+password, `telephone` NULL),
  distingués par leur **rôle** ; les patients n'ont aucun rôle portail. Login portail = email+password (web),
  login mobile = téléphone+OTP (Sanctum) — inchangé.
- **`PortailRolesSeeder`** : 11 permissions + 3 rôles (guard `web`) — `admin_ivoirsante` (tout),
  `gestionnaire_etablissement` (service/agent/stats/rdv/dispo), `agent_garde` (dispo/rdv/qr/triage). Idempotent.
  Crée l'**admin de bootstrap** `admin@masante.ci` / `Admin@2026!` (sans lui, personne ne pourrait démarrer).
- **Auth web** (`Portail\AuthController`) : `showLogin`/`login`/`logout` (sessions, `session()->regenerate()`).
  **Cloisonnement** : un compte sans rôle portail est **refusé** même avec des identifiants valides. Anti-bruteforce
  via le limiteur `login` (`throttle:login`).
- **`bootstrap/app.php`** : alias middleware spatie (`role`/`permission`/`role_or_permission`) ; `redirectGuestsTo`
  affiné → **API = 401 JSON** (inchangé), **web = redirection vers `/portail/login`**.
- **Vues Blade** (Bootstrap 5 via CDN — pas de CSP qui bloque) : `layout` (navbar de marque, badge rôle, logout),
  `auth/login`, `dashboard` (cartes **filtrées par permission** via `@can`, marquées « Bientôt » — écrans en 4.2→4.6).
- **Routes** `/portail` (`login`, `login.attempt`, `dashboard`, `logout`).
- **Tests `PortailAuthTest` (7)** : login page publique, invité redirigé, admin connecté, dashboard admin (cartes),
  **compte sans rôle refusé**, mauvais mot de passe, déconnexion. **Suite : 115/115**, audit 0.

## 4.2 — Établissements + gestionnaire (backend + Blade, 2026-07-08)

Workflow **CdC §5.4.1** (établissement → gestionnaire) et **§5.4.2** (droits par profil). Réservé à l'**admin
IVOIRSANTÉ** (`permission:etablissement.manage`). Point de sécurité clé du CdC : « **le mot de passe temporaire
n'existe pas** » → le compte gestionnaire naît **sans mot de passe** et l'active lui-même via un **lien à usage
unique (24h)**.

- **DB** : `users.structure_id` (FK nullable → cloisonnement staff↔établissement, NULL pour patients/admin) +
  `users.actif` ; `structures_sanitaires.actif` (désactivation, cf. infra) ; table **`activations_portail`**
  (seul le **hash** du jeton est stocké, `expires_at`, `used_at` = usage unique) ; **`users.password` rendu
  nullable** (compte inactivable tant que non activé — `Auth::attempt` reste faux sur un hash nul).
- **`EtablissementController`** : `index` (recherche nom/commune + filtre type, pagination, statut activation du
  gestionnaire), `create`/`store` (crée l'établissement **et** son gestionnaire en transaction + émet le lien),
  `edit`/`update` (champs établissement), `toggleActif`, `regenererLien` (si compte non encore activé).
  Spécialités saisies en texte « ORL, Cardiologie » → tableau JSON. Les tarifs valident `max ≥ min`.
- **Décision — désactiver plutôt que supprimer** : la structure est référencée par RDV/avis/services
  (intégrité + historique médical). `actif=false` la retire de l'annuaire public et **suspend en cascade ses
  comptes staff** (`users.actif`). Réversible. Écart assumé vs. « supprimer » du §5.4.2, signalé.
- **`ActivationController`** (PUBLIC, le titulaire n'a pas de mot de passe) : `show`/`activate`, mot de passe
  soumis à la **politique unique** du projet (`PasswordPolicy`), consommation du jeton en transaction (usage
  unique + expiration). `AuthController` durci : un compte **désactivé** est refusé au login.
- **Vues Blade** : `etablissements/{index,create,edit,_form}`, `activation/set-password` ; lien d'activation
  affiché dans le `layout` (dev sans passerelle mail — simulé comme l'OTP) ; carte « Établissements » du
  dashboard désormais **cliquable**.
- **Routes** `/portail/etablissements` (CRUD + `actif` + `lien`) sous `permission:etablissement.manage` ;
  `/portail/activation/{token}` (public, `throttle:login`).
- **Tests `EtablissementPortailTest` (7)** : liste admin, **gestionnaire interdit (403)**, création établissement +
  gestionnaire **sans mot de passe** + lien, **activation → connexion**, **rejeu de jeton refusé**, désactivation
  **suspend le gestionnaire**, **compte désactivé refusé au login**. **Suite : 122/122**, audit 0.
- **Outil DEV** `php artisan portail:gestionnaires-demo [--base=URL]` : rattache un gestionnaire à chaque
  établissement et affiche les liens d'activation (test du flux sans passerelle mail). Génération du jeton
  extraite dans `ActivationPortail::genererPour()` (source unique contrôleur + commande).

## 4.3 — Services + agents de garde (backend + Blade, 2026-07-09)

Le **gestionnaire** configure SES services et crée SES agents (CdC §5.4.1 étapes 4-5, §5.4.2). **Cloisonnement
strict** : toute action est bornée à `structure_id` = l'établissement du gestionnaire connecté ; l'admin (sans
établissement) est refusé sur ces écrans (403). L'agent est affecté à **un service** (décision retenue :
`users.service_id`, conforme à « accès limité à son service »).

- **DB** : `users.service_id` (FK nullable → `services_etablissement`, `nullOnDelete`) — renseigné pour les
  **agents** seulement (NULL pour patients/admin/gestionnaire).
- **`ServiceController`** (`permission:service.manage`) : CRUD des services de MON établissement + `toggleActif`.
  `structureId()` (403 si compte non rattaché) et `servicePossede()` (404 sur accès croisé). Le code `specialite`
  est validé `^[a-z_]+$` (cohérence matching triage F1.5) ; datalist des spécialités déjà en base.
- **`AgentController`** (`permission:agent.manage`) : liste/création/édition/`toggleActif`/`regenererLien`. Création
  d'agent = **même flux que le gestionnaire** (compte sans mot de passe + lien d'activation à usage unique via
  `ActivationPortail::genererPour`). Le `service_id` choisi est **validé comme appartenant à MON établissement**
  (`Rule::exists(...)->where('structure_id', …)`). Un agent ne peut pas gérer services/agents (pas la permission).
- **Vues Blade** : `services/{index,create,edit,_form}`, `agents/{index,create,edit,_form}` ; garde-fou « créez
  d'abord un service » avant d'ajouter un agent ; cartes **« Mes services »/« Mes agents »** du dashboard
  activées et **masquées pour l'admin** (compte sans établissement).
- **Routes** `/portail/services` (`service.manage`) et `/portail/agents` (`agent.manage`), sous `auth`.
- **Tests `ServiceAgentPortailTest` (8)** : gestionnaire ne voit **que** ses services, création scoped,
  **édition d'un service tiers = 404**, **admin sans établissement = 403**, création d'agent **sans mot de passe**
  (avec lien, rôle et service), **affectation à un service tiers rejetée**, **agent ne gère ni services ni agents**,
  désactivation service. **Suite : 130/130**, audit 0.
- **Correctif** : à l'activation d'un compte, la session en cours est fermée (sinon, lien ouvert dans le
  navigateur du gestionnaire → `showLogin` redirigeait vers SON dashboard au lieu de l'écran de connexion).

## 4.4 — Disponibilité + validation des RDV (backend + Blade, 2026-07-09)

Usage quotidien du portail (CdC §5.4.1 étape 6, §5.4.2 ; F3.3 dispo, F3.6 RDV). **Conflit de documents
tranché avec le porteur** : le seeder 4.1 donne `disponibilite.manage` + `rdv.validate` au gestionnaire, là
où le CdC §5.4.2 les réserve à l'agent → décision **agent + gestionnaire** (gestionnaire = superviseur).
Périmètre unifié `User::servicesGeresIds()` : **agent = son seul service**, **gestionnaire = tous les services
de son établissement**.

- **`DisponibiliteController`** (`permission:disponibilite.manage`) : `index` (sélecteur de date, table des
  services du périmètre + statut du jour), `edit`/`update` = **upsert** `updateOrCreate(service_id+date)` (ligne
  unique par jour), `updated_by_agent_id` = auteur. Statuts `disponible / disponible_apres_14h / complet / ferme` ;
  date bornée à aujourd'hui minimum. `serviceEnPerimetre()` → 404 sur accès hors périmètre.
- **`RendezVousController`** (portail, `permission:rdv.validate`) : `index` (filtre par statut, onglets),
  `show` (détail + fiche de triage jointe si présente), `confirmer` (date/heure définitive `after_or_equal:today`,
  **assignation médecin optionnelle validée comme appartenant au service du RDV**, message), `refuser` (**motif
  obligatoire**). `assertTraitable()` → **409** si le RDV n'est plus `en_attente` ; `rdvEnPerimetre()` → 404 hors
  périmètre. Réutilise les statuts existants du cycle patient (Module 3), sans les altérer.
- **Vues Blade** : `disponibilites/{index,edit}`, `rdv/{index,show}` ; cartes **« Disponibilité »/« Rendez-vous »**
  du dashboard activées (masquées pour l'admin).
- **Routes** `/portail/disponibilites` (`disponibilite.manage`) et `/portail/rendez-vous` (`rdv.validate`).
- **Tests `DispoRdvPortailTest` (8)** : agent met à jour SA dispo (upsert), **dispo d'un autre service = 404**,
  gestionnaire voit **tous** ses services, agent **confirme** un RDV, **refus sans motif rejeté**, **RDV d'un
  autre service = 404**, **reconfirmation d'un RDV traité = 409**, **médecin d'un autre service rejeté**.
  **Suite : 139/139**, audit 0.

## 4.5 — Scan QR à l'accueil (backend + Blade, 2026-07-10)

Cœur différenciateur du projet (CdC §4.3 « Flux du système QR Code dynamique » ; Sécurité §5 et §10 ;
Analyse_Delta_RDV N3/N6). Le portail expose enfin la **consommation** du token QR, dont la logique
serveur existait depuis le Module 2 (`QrTokenService::validerEtConsommer`, verrou pessimiste, usage unique).

### Deux flux volontairement séparés

L'Analyse_Delta_RDV (§46) avertit : « confondre les deux serait une faille ». On livre donc **deux écrans,
deux routes, deux secrets de signature** :

| Flux | Écran | Ce qu'il fait | Ce qu'il ne fait jamais |
|---|---|---|---|
| **QR carnet** | « Scanner le carnet » | Consomme un token à usage unique (10 min), ouvre le **dossier médical** pour 30 min, journalise | — |
| **QR reçu de RDV** | « Accueil patient » | Enregistre l'**arrivée physique** (`checked_in_at`, N6) | N'ouvre **aucun** dossier ; ne porte aucune donnée médicale |

### Qui peut scanner — décision

`qr.scan` reste **réservée à `agent_garde`** (CdC §5.4.2 ; Sécurité §4.1), contrairement au périmètre
unifié agent+gestionnaire retenu en 4.4 pour la dispo et les RDV : ouvrir un dossier médical est un acte
plus sensible que valider un rendez-vous. Le **gestionnaire ne scanne pas**. L'**admin**, qui hérite pourtant
de toutes les permissions, est **refusé (403)** faute d'établissement de rattachement — son accès à un dossier
relèverait de la voie « admin » du §4.4, exceptionnelle et hors périmètre de 4.5.

### Session dossier de 30 minutes

`SessionDossierService` porte la fenêtre dans la **session web** de l'agent : elle meurt avec sa déconnexion,
et **l'identifiant du membre n'apparaît jamais dans l'URL** — un agent ne peut pas atteindre un autre dossier
en modifiant l'adresse (**anti-IDOR par construction**, OWASP A01). Le middleware `dossier.actif` referme la
fenêtre à l'échéance ; la vue affiche un compte à rebours.

### Audit : deux lignes plutôt qu'une réécriture

Le journal `acces_dossier` est **immuable** (§10.2 : `updating`/`deleting` → 403), mais `duree_minutes` et
`sections_consultees` ne sont connus **qu'à la fermeture**, alors que la ligne est écrite **au scan**. Plutôt
que d'affaiblir l'immuabilité par une exception de clôture, on écrit **deux lignes en ajout seul**, liées par
le même `token_qr_id` :

1. **ouverture** — écrite par `QrTokenService::consommer()` au scan (durée et sections nulles) ;
2. **clôture** — écrite à la fermeture (manuelle, expiration des 30 min, ou déconnexion), portant la
   **durée réelle** (bornée à 30) et les **sections effectivement consultées**.

La promesse « rien n'est jamais réécrit » reste donc entière. `validerEtConsommer()` conserve sa signature
(le Module 2 l'utilise) et délègue désormais à `consommer()`, qui renvoie la ligne d'ouverture.

### Minimisation (loi n°2013-450)

L'agent consulte **en lecture seule** : fiche vitale, antécédents, vaccinations, ordonnances, analyses, notes,
contacts d'urgence, documents et fiche de triage. Les **documents et la photo sont listés mais non
téléchargeables** depuis le portail (le déchiffrement des blobs reste réservé à l'API mobile, pour le
titulaire) ; le **matricule** et le **numéro CMU complet** ne sont jamais affichés.

### Livrables

- **`ScanController`** (`permission:qr.scan`, `throttle:20,1` sur les POST — un token est un secret) :
  `index`/`scanner` (carnet) et `indexRdv`/`checkIn` (reçu). Les échecs 404/409/410 sont traduits en
  **messages d'accueil** (« QR expiré, demandez-en un nouveau ») plutôt qu'en pages d'exception : à l'accueil,
  un QR périmé est un cas courant, pas un bug.
- **`DossierController`** (lecture seule, 9 sections, aucune section dans l'URL hors clé connue → 404).
- **`SessionDossierService`** + middleware **`dossier.actif`** ; clôture aussi déclenchée au **logout**.
- **`RecuRdvService::verifierCode()`** : signature HMAC vérifiée en **temps constant** (`hash_equals`, pas
  d'oracle de timing), type de payload, expiration, puis cloisonnement `structure_id` → **404** (on ne confirme
  pas l'existence du reçu d'un autre établissement). Check-in **idempotent** ; le reçu passe à `utilise`.
- **Migration** `checked_in_at` + `checked_in_by_agent_id` sur `rendez_vous` (N6 : champ horodaté plutôt qu'une
  valeur d'énumération, pour conserver l'historique — le RDV reste `confirme`).
- **Vues Blade** `scan/{index,rdv,_lecteur}` et `dossier/show` ; lecteur caméra **html5-qrcode** (CDN) avec
  **saisie manuelle de secours** (`getUserMedia` exige une origine sécurisée : https/Ngrok ou localhost).
  Badge « patient présent » sur la fiche RDV. Deux cartes au dashboard.
- **Écart assumé** : la **notification push** au patient (CdC §4.3 étape 6, FT3) est une trace `Log::info` —
  Firebase n'est pas intégré au projet ; à brancher au module Notifications.
- **Tests `ScanQrPortailTest` (9)** : scan valide → dossier + audit, **rejeu refusé**, **QR expiré refusé**,
  **clôture journalisant durée + sections**, **expiration à 30 min**, **dossier inaccessible sans scan**,
  **gestionnaire 403 / admin 403**, check-in nominal (**sans aucune ligne d'audit dossier**), et check-in refusé
  pour RDV non confirmé / **code falsifié** / **autre établissement (404)**. **Suite : 148/148**, audit 0.

## 4.6 — Modération des avis et signalements (backend + Blade, 2026-07-10)

Dernière étape du Module 4 (CdC §5.4.2 ; F3.9 avis, F3.10 signalements). Réservée à l'**admin IVOIRSANTÉ**
(`permission:moderation.manage`) : le gestionnaire ne modère pas, **pas même les avis portant sur son propre
établissement** — il serait juge et partie.

### Trois décisions

1. **Valider ≠ publier.** Le schéma porte deux colonnes distinctes (`statut`, `visible_publiquement`) et on
   les garde distinctes : valider répond à « le fait est-il avéré ? », publier à « faut-il l'afficher sur la
   fiche de la structure ? ». Un signalement de **pot-de-vin** peut ainsi être reconnu et traité en interne
   **sans être publié** — afficher une accusation nominative sur la fiche d'un hôpital n'est pas anodin. Un
   signalement **rejeté est automatiquement dépublié** s'il l'avait été par erreur.
2. **Traçabilité des décisions.** Le CdC §8.6 porte l'*état* de modération, pas la *décision* qui l'a produit.
   Migration ajoutant `modere_par_user_id` + `modere_at` + `motif_moderation` aux deux tables. **Motif
   obligatoire** pour toute décision défavorable (masquer un avis, rejeter un signalement), facultatif pour
   une décision favorable (rétablir) — même logique que le motif de refus d'un RDV en 4.4.
3. **Masquer, jamais supprimer.** Un avis modéré bascule `visible=false` et reste en base : la modération est
   réversible, et l'auteur peut contester. Le drapeau `signale` (levé par la détection automatique de mots
   interdits au dépôt) retombe : la décision humaine remplace l'alerte machine.

### Le piège de la note dénormalisée

`structures_sanitaires.note_moyenne` et `nb_avis` résument les avis **visibles**. Ils étaient recalculés par une
méthode **privée** d'`AvisController` (API mobile). Masquer un avis depuis le portail sans refaire ce calcul
aurait laissé la fiche publique afficher une moyenne sans rapport avec les avis affichés. Le recalcul est donc
extrait dans **`NoteStructureService`**, appelé par les deux chemins (dépôt d'avis et modération) : une seule
source de vérité.

### Confidentialité

L'**anonymat du signalant** est préservé jusque dans le portail : `user_id` reste `$hidden`, le modérateur
tranche sur le seul contenu. Symétriquement, l'identité du **modérateur** et le **motif** sont `$hidden` : ils
sont tracés en base mais ne sont jamais renvoyés à l'API publique. Tous les textes libres (commentaires,
descriptions) sont rendus par Blade, qui échappe par défaut — **aucun `{!! !!}`** (Sécurité §A03, XSS).

### Livrables

- **`ModerationController`** : `index` (deux onglets avec compteurs, filtres par état, pagination),
  `basculerAvis` (masquer/rétablir + recalcul de la note), `trancher` (valider/rejeter), `basculerPublication`
  (publier/retirer, **422 si le signalement n'est pas validé**).
- **`NoteStructureService`** ; `AvisController` (API) refactoré pour l'utiliser.
- **Migration** `modere_par_user_id` / `modere_at` / `motif_moderation` sur `avis` et `signalements`.
- **Modèles** : champs de modération `$fillable` mais `$hidden`, relation `moderateur()`, `Signalement::estTraite()`.
- **Vue Blade** `moderation/index` ; carte « Modération » du dashboard activée.
- **Tests `ModerationPortailTest` (7)** : **gestionnaire et agent 403**, masquage **recalculant la note**
  (5★ + 1★ → moyenne 3 puis 5) sans supprimer la ligne, **motif exigé pour masquer mais pas pour rétablir**,
  **valider ne publie pas**, **publication réservée aux signalements validés** (bascule aller-retour),
  **rejet exigeant un motif et dépubliant**, **anonymat du signalant**. **Suite : 155/155**, audit 0.
- **Correctif 4.5 en passant** : `SessionDossierService` arrondissait la durée de session avec `ceil`, ce qui
  comptait 6 minutes pour une session de 5 min + quelques centaines de millisecondes de traitement (test
  intermittent). Remplacé par un arrondi au plus proche, plancher de 1 minute.

### Étape B — Historique public des signalements (mobile, 2026-07-10)

**Manque constaté au test de 4.6** : l'endpoint public `GET /v1/structures/{id}/signalements` existait depuis le
Module 3, mais **aucun écran mobile ne le consommait** — le mobile savait déposer un signalement, jamais lire
l'historique. Le bouton « Publier » du portail n'avait donc aucun effet observable côté patient. Complété ici
(dette F3.10, partie lecture) :

- `types/structure.ts` : `SignalementPublic` (4 champs seulement : `id`, `type`, `description`, `created_at`).
- `api/structures.ts` : `getSignalementsStructure()`.
- Fiche structure `[id].tsx` : chargement en parallèle des trois ressources ; nouvelle section
  **« Signalements vérifiés (n) »** entre les avis et le bouton de signalement, avec pastille de type
  (tokens `warning` du DS, aucune couleur en dur). La section est **absente s'il n'y a aucun signalement** :
  un bloc « aucun signalement » laisserait croire à un problème latent.
- **Anonymat vérifiable de bout en bout** : ni auteur, ni motif, ni modérateur ne transitent — le contrôleur
  d'API sélectionne explicitement les quatre colonnes. `tsc` OK, aucune dépendance ajoutée.

## 4.7 — Comptes du portail (backend + Blade, 2026-07-10)

CdC §5.4.2, « gérer tous les comptes ». **Périmètre volontairement restreint au STAFF** : admin, gestionnaires
et agents. Les comptes **patients n'y figurent pas** — ils portent des carnets de santé, et donner prise dessus
à l'administration contredirait la règle des trois voies d'accès au dossier (Sécurité §4.4), où la voie « admin »
reste exceptionnelle et auditée. Le portail n'est pas un annuaire des patients.

- **`CompteController`** (`permission:compte.manage`, admin) : `index` (filtres rôle / établissement /
  recherche nom-prénom-email, pagination), `toggleActif`, `regenererLien`. La **création** reste là où elle a du
  sens : un gestionnaire se crée depuis Établissements (4.2), un agent depuis son établissement (4.3).
- **Deux garde-fous** : l'admin ne peut pas **se** désactiver (il se verrouillerait dehors), et le **dernier
  admin actif** ne peut pas être suspendu — sinon plus personne ne pourrait administrer la plateforme, pas même
  pour le réactiver. `staff()` → **404** si la cible est un compte patient (aucun rôle).
- **États affichés** : Actif · Suspendu · **En attente d'activation** (compte créé, `password` encore nul :
  le lien d'activation à usage unique n'a jamais été consommé). Le lien est régénérable depuis cet écran.

## 4.8 — Statistiques (backend + Blade, 2026-07-10)

CdC §5.4.2 et §5.4.1 (« consulte les statistiques de rendez-vous et d'avis »). Le CdC n'énumère aucun indicateur :
retenus, des **agrégats d'activité**, cloisonnés par permission.

- **`StatistiqueController::global()`** (`stats.global`, admin) : établissements actifs / total, comptes par rôle,
  RDV par statut, triages par niveau, avis publiés + note moyenne, file de modération, scans QR du mois.
- **`StatistiqueController::etablissement()`** (`stats.etablissement`, gestionnaire, **cloisonné `structure_id`**,
  403 si non rattaché — ce qui exclut l'admin) : ses RDV par statut, **taux de confirmation**, services ouverts,
  note moyenne et signalements publiés sur sa fiche. Le taux ne porte que sur les demandes **tranchées**
  (confirmées + refusées) : les demandes en attente n'y entrent pas, et le dénominateur nul renvoie 0 %.
- **Minimisation (loi n°2013-450)** : les triages portent des données de santé. On en compte la **répartition
  par niveau de gravité** ; aucun triage individuel n'est consultable, aucun indicateur n'est rattachable à un
  patient. « 22 triages urgents ce mois-ci » n'est pas une fuite ; une liste nominative en serait une.
- **Graphiques** : Chart.js par CDN (comme Bootstrap et html5-qrcode). Données injectées via `@json` — JSON
  échappé par Blade, jamais d'interpolation de chaîne dans le `<script>` (Sécurité §A03).
- **Dashboard** : les cartes « Comptes » et « Statistiques » sont activées, et une carte **« Mes statistiques »**
  sert enfin la permission `stats.etablissement`, en sommeil depuis 4.1. Plus aucune carte « Bientôt ».
- **Tests `ComptesStatsPortailTest` (10)** : **patients absents de la liste** et **404 si on tente de les
  suspendre**, **auto-désactivation refusée**, **dernier admin protégé** (en tenant compte de l'admin de
  bootstrap du seeder), lien d'activation régénéré, **gestionnaire 403 sur les comptes et sur les stats
  globales**, **stats du gestionnaire cloisonnées** (3 RDV chez lui, 5 ignorés chez le voisin ; taux 67 %),
  **taux à 0 % sans division par zéro**, **admin 403 sur les stats d'établissement**, **note moyenne globale
  ignorant les avis masqués**. **Suite : 165/165**, audit 0.

---

# Module 5 — Santé publique & urgences

Périmètre validé : **FN1 → FN8 + bris de glace** (voie 4). Découpage en 8 sous-étapes :
5.1 carte vitale (FN2) · 5.2 SOS (FN1) · 5.3 bris de glace · 5.4 alertes épidémiques (FN3) ·
5.5 suivi grossesse (FN4) · 5.6 maladies chroniques (FN5) · 5.7 don de sang (FN6) ·
5.8 comparateur de prix + ruptures (FN7/FN8).

**Conflits de documents tranchés d'emblée**, avant tout code :

1. **SAMU : le CdC écrit « (15) »** — c'est le numéro français. En Côte d'Ivoire, le SAMU est le **185**
   (numéro vert), déjà appliqué par `TriageService` depuis le Module 1. On maintient **185**.
2. **FN2 « affichage depuis l'écran verrouillé du téléphone »** — c'est une fonction du système
   d'exploitation (Medical ID iOS, Informations d'urgence Android), hors de portée d'une application Expo,
   et en tension avec le verrou applicatif B2. **Décision** : la carte vitale s'ouvre depuis l'écran de
   connexion, **sans compte ni PIN**, depuis un cache chiffré local. Le verrou continue de protéger le
   dossier complet. Écart assumé et documenté.
3. **FN1 « fonctionne offline via SMS »** — le projet n'a aucune passerelle SMS (l'OTP est simulé). Mais le
   téléphone en a une. **Décision** : liens natifs `tel:` et `sms:` pré-remplis — c'est l'appareil qui émet,
   pas notre serveur. Fonctionne sans données mobiles, ce qui est précisément le cas visé.
4. **FN5 « partage automatique avec le médecin référent »** — la voie « référent » figure dans l'enum
   `type_acces` mais n'a jamais été implémentée. À traiter en 5.6, ou à documenter comme différée.

## 5.1 — Fiche vitale d'urgence · Étape A backend (2026-07-10)

**`FicheVitaleService` est la source unique du « sous-ensemble vital minimal »**, partagée par les trois
usages qui doivent voir exactement le même périmètre : la carte vitale du secouriste (5.1), le corps du SMS
d'urgence (5.2) et le bris de glace du service d'urgences (5.3). Un seul endroit à auditer.

**Le périmètre se dérive du carnet existant**, sans nouvelle table : les allergies et les maladies chroniques
sont déjà des **types d'antécédents** (`allergie`, `maladie_chronique`), et les vaccinations portent un drapeau
`obligatoire`. Inclus (Note_Continuite §5.2) : identité, âge, sexe, groupe sanguin, allergies, maladies
chroniques **avec le traitement en cours** (interactions médicamenteuses), vaccinations obligatoires
effectivement faites, contacts d'urgence. Exclus : consultations, documents importés, notes libres, autres
membres — et jamais le **matricule** ni le **numéro CMU** : une fiche vitale sert à soigner, pas à identifier.

Cette sévérité n'est pas décorative : **ces données seront lues sans authentification** par qui tient le
téléphone. Tout champ ajouté au service est un champ exposé au premier venu qui ramasse un appareil.

- **`FicheVitaleService::pour()`** (fiche complète) et **`::resume()`** (une ligne, pour le SMS de 5.2).
- **`FicheVitaleController::show()`** + `GET /api/v1/membres/{membre}/fiche-vitale` (auth, Policy `view`).
  L'endpoint reste protégé : seul le titulaire (ou un délégué actif) **constitue** le cache local. Pas de
  `no-store` — contrairement à la photo ou à la carte CMU, cette fiche est **faite** pour être conservée.
- **Tests `FicheVitaleTest` (5)** : contenu vital exact ; **exclusion** vérifiée d'un antécédent chirurgical,
  d'un vaccin facultatif, d'un vaccin non fait et d'une note médicale ; **403 sur le membre d'autrui** ;
  résumé SMS d'une seule ligne ; membre au carnet vide → fiche vide sans erreur. **Suite : 170/170**, audit 0.

## 5.1 — Carte vitale d'urgence · Étape B mobile (2026-07-10)

**Le point délicat du module.** Le CdC veut la carte vitale « depuis l'écran verrouillé, sans
déverrouillage ». C'est une fonction du système d'exploitation, pas de l'application. On tient la promesse
au plus près : la carte s'ouvre **depuis l'écran de connexion**, sans compte, sans mot de passe et sans PIN.
Un secouriste qui prend le téléphone atteint la fiche en deux touches, sans rien connaître du patient.

Cela impose un **cache local** : l'API exige un token, or il n'y a pas de session à ce moment-là. Le cache
vit dans `expo-secure-store` (Keychain iOS / Keystore Android), chiffré par le matériel, jamais dans
AsyncStorage. Une fiche par membre (les valeurs de SecureStore doivent rester petites), plus un index.

**Ce que cela expose, et pourquoi c'est acceptable.** Qui tient le téléphone déverrouillé lit les fiches
activées — c'est le but même de FN2. Ce sont exactement les données que le CdC destine aux secouristes.
Le dossier complet reste derrière le verrou applicatif B2. Garde-fous : **rien n'est activé par défaut**,
le titulaire choisit **membre par membre**, l'écran de gestion l'avertit explicitement que la carte sera
lisible sans mot de passe, et désactiver un membre **efface immédiatement** sa fiche de l'appareil.

- **`src/urgence/carteVitale.ts`** : `activer` / `desactiver` / `lireCache` / `rafraichir` / `membresActives`.
  `lireCache()` **ignore une entrée corrompue** au lieu d'échouer : en urgence, deux fiches sur trois valent
  mieux qu'une page d'erreur.
- **`src/screens/CarteVitaleEcran.tsx`** : lecture seule, **ne touche jamais l'API**. Hiérarchie visuelle
  voulue — groupe sanguin (encart rouge) et allergies (bloc bordé de rouge) en tête : ce sont les deux
  informations qui changent un geste de secours dans les premières secondes. Contacts d'urgence appelables
  d'une touche (`tel:`), bouton SAMU **185**.
- **Deux routes, un seul écran** : `(auth)/carte-vitale` (secouriste, hors session) et
  `(app)/parametres/apercu-carte-vitale` (le titulaire vérifie ce qu'il expose). Même composant, mêmes
  données : l'aperçu ne peut pas mentir.
- **`(app)/parametres/carte-vitale`** : interrupteur par membre, « Mettre à jour depuis mon carnet »
  (le carnet a pu changer : nouvelle allergie, nouveau contact).
- **Points d'entrée** : lien rouge « Carte vitale d'urgence » sous le formulaire de connexion, hors du bloc
  d'authentification ; bouton dans l'onglet Carnet.
- **Existant réutilisé** : `SosButton` (SAMU 185) était déjà là depuis le Module 1 — il n'appelle que le
  SAMU. Son enrichissement (GPS + SMS au contact d'urgence) est l'objet de 5.2.
- `tsc` OK, aucune dépendance ajoutée (`expo-secure-store` était déjà présent pour le token et le PIN).

## 5.2 — Bouton SOS · Étape A backend (2026-07-10)

**L'alerte ne passe pas par le backend, et c'est délibéré.** FN1 exige un fonctionnement « offline via SMS
si pas de data ». L'appel au SAMU **185** et le SMS au contact d'urgence partent donc du **téléphone**
(liens `tel:` / `sms:`), sans passerelle et sans réseau de données. Le backend n'est jamais sur le chemin
critique de l'alerte.

Ce qu'il fait : un **enregistrement a posteriori, best-effort**. Le mobile appelle `POST /api/v1/sos` après
avoir déclenché l'alerte, et **ignore l'échec** de cet appel. Le CdC ne prévoit aucune table pour FN1 ; on
en ajoute une pour la revue a posteriori et les statistiques, comme le portail trace déjà les accès au dossier.

**Décisions.**

- **Cible du SOS** : le SOS lira le **cache de la carte vitale** (5.1) — il doit fonctionner sans réseau, il
  ne peut donc pas demander au serveur qui alerter. Le SMS part au **contact principal du membre** (F2.11).
  On n'ajoute donc **pas** `contact_urgence_nom` / `contact_urgence_tel` sur `users` comme le fait le schéma
  du CdC §8.1 : ce serait dupliquer, en moins bien, ce que la révision validée de F2.11 gère par membre.
- **Position GPS journalisée**, parce qu'elle est la trace la plus utile à une revue d'incident. C'est une
  **donnée personnelle sensible** (loi n°2013-450) : elle n'est enregistrée que parce que le patient a
  lui-même déclenché l'alerte, uniquement à cet instant. **Aucun suivi continu**, aucune position hors SOS.
- **`latitude` nullable** : GPS refusé, indisponible ou en intérieur. Un SOS sans position vaut mieux qu'un
  SOS refusé. Mais une latitude **sans** longitude est rejetée (`required_with`) : une trace à moitié
  renseignée est inexploitable.
- **Contact prévenu dénormalisé** (nom + téléphone copiés) : la trace doit rester exacte même si le contact
  d'urgence est modifié ou supprimé ensuite.

- **Table `alertes_sos`** + modèle `AlerteSos` (ajout seul, `timestamps = false`, `declenchee_le`).
- **`AlerteSosController`** : `store` (journalise) et `index` (transparence — le patient voit ce qui a été
  enregistré sur lui). `membre_id` validé comme **appartenant à l'appelant** (`Rule::exists(...)->where(
  'user_id')`) : on ne journalise pas une alerte au nom du membre d'autrui. `Log::warning` en trace
  applicative — c'est là que partirait, en production, la notification au CHU de garde (hors périmètre :
  ni Firebase ni intégration SAMU dans ce projet).
- **Tests `AlerteSosTest` (6)** : alerte complète journalisée ; **alerte sans position ni membre acceptée** ;
  position incomplète, hors bornes et canal inconnu **rejetés** ; **alerte au nom du membre d'autrui
  refusée** ; historique cloisonné au compte ; 401 sans authentification. **Suite : 176/176**, audit 0.

## 5.2 — Bouton SOS · Étape B mobile (2026-07-10)

**Décision d'ergonomie qui contredit la lettre du CdC.** FN1 dit « au tap : envoie la position GPS et la
fiche vitale au SAMU et à un contact d'urgence ». Un appel et un SMS **ne peuvent pas partir du même
geste** : lancer l'appel met l'application en arrière-plan, le SMS serait perdu. Le bouton ouvre donc un
écran d'urgence à **deux actions explicites** — appeler le 185, alerter le proche — sur une page où tout est
déjà prêt. L'esprit est tenu : deux touches, aucune saisie, aucune attente.

**Rien n'attend le réseau.** La position est cherchée en tâche de fond dès l'ouverture de l'écran ; si elle
n'est pas là au moment de l'appui, l'alerte part sans elle et le message le dit (« Position indisponible »).
`obtenirPositionUrgence()` tente d'abord la **dernière position connue** (< 2 min) avant d'interroger le GPS :
en urgence, une position approximative tout de suite vaut mieux qu'une position exacte dans trente secondes.
Les données du message viennent du **cache de la carte vitale** (5.1), jamais de l'API — demander au serveur
qui alerter supposerait le réseau que l'on n'a précisément pas.

- **`src/urgence/sos.ts`** : `contactAAlerter()` (contact principal, sinon le premier), `messageUrgence()`
  (identité, groupe sanguin, allergies, chroniques **avec traitements**, position + lien OpenStreetMap),
  `appelerSamu()`, `envoyerSmsUrgence()` (séparateur `&body=` sur iOS, `?body=` sur Android), `tracerAlerte()`.
- **L'utilisateur garde la main sur l'envoi du SMS** : aucune application ne peut envoyer un SMS à son insu,
  et c'est heureux. On pré-remplit, il valide.
- **`src/api/sos.ts`** : `journaliserSos()` **n'échoue jamais** (renvoie un booléen). Aucun appelant ne
  conditionne l'alerte à son résultat — hors ligne, il échoue, et c'est le cas nominal de FN1.
- **`src/screens/SosEcran.tsx`** + route `(app)/sos` (masquée de la barre d'onglets par `href: null`, sinon
  elle deviendrait un cinquième onglet). **Choix du membre** si plusieurs cartes vitales sont activées : le
  carnet est familial, ce n'est pas toujours le titulaire qui fait le malaise.
- **`SosButton`** n'appelle plus directement : il ouvre l'écran. Le composant reste le seul élément rouge
  proéminent de l'accueil (§5.1 DS).
- **`utils/geoloc.ts`** : le commentaire affirmait que la position « n'est jamais persistée ». Ce n'est plus
  vrai — corrigé, avec la mention explicite de l'exception SOS et de son unique mesure ponctuelle.
- **Dépendance au 5.1 assumée** : sans carte vitale activée, le SOS se réduit à l'appel SAMU, et l'écran
  explique comment y remédier. `tsc` OK, `expo-doctor` 18/18, aucune dépendance ajoutée.

### Complément — Historique des alertes SOS (mobile)

`GET /api/v1/sos` avait été livré pour la **transparence** (loi n°2013-450 : le patient doit pouvoir
consulter ce qui est conservé sur lui — ici une position GPS), mais **aucun écran ne le consommait** —
même situation qu'en 4.6 avec l'historique public des signalements. Complété :

- `api/sos.ts` : type `AlerteSos` + `listerAlertesSos()`.
- Écran `(app)/parametres/alertes-sos` : date et heure, membre concerné, canal réellement utilisé
  (appel / SMS / les deux), proche prévenu, et **position enregistrée** ouvrable sur OpenStreetMap.
  Consultation pure : le journal est en ajout seul. Pied de page rappelant que la position n'est
  enregistrée qu'au déclenchement d'un SOS, **jamais en suivi continu**.
- Point d'entrée : bouton « Mes alertes d'urgence » dans l'onglet Carnet, sous « Carte vitale d'urgence ».

**Défaut trouvé au passage (backend)** : `declenchee_le` a un défaut SQL (`useCurrent`), donc l'objet en
mémoire ne le portait pas après `create()` — la réponse du `POST /sos` renvoyait `declenchee_le: null`.
Corrigé par `->refresh()`, et **verrouillé par un test**. Suite 176/176.

## 5.3 — Bris de glace, la quatrième voie d'accès (portail, 2026-07-10)

Accès d'exception au dossier d'un patient **hors d'état de consentir**, quand ni le titulaire ni un délégué
n'est joignable (Note_Continuite §5, inspiré du mode bris de glace du DMP français). Cette voie était
**bloquée depuis le Module 2** : elle dépendait du RBAC et du journal d'audit, tous deux livrés au Module 4.

La règle d'accès de Sécurité §4.4 passe de **trois à cinq voies** : `qr_scan`, `referent`, `delegation`,
`bris_de_glace`, `admin`.

### Le problème que la note laisse ouvert : identifier le patient

Sans QR, l'agent doit désigner le membre. Une recherche par nom exposerait un **annuaire national des
malades** — un agent curieux pourrait parcourir le dossier d'une personnalité. Le numéro CMU, chiffré et sans
index, n'est de toute façon pas cherchable. **Décision** : correspondance **exacte sur trois critères** (nom,
prénom, date de naissance). Pas de `LIKE`, pas de recherche floue, pas de liste de résultats : la réponse est
un membre ou rien. On ne peut pas explorer, seulement **confirmer** une identité déjà connue — par les papiers
du patient ou un proche présent. Casse et espaces normalisés (refuser « KONE » pour « Koné » relèverait de
l'obstruction, pas de la sécurité). **Toute tentative infructueuse est journalisée** : un agent qui cherche à
tâtons laisse une trace.

### Les six garde-fous de la note §5.3, tous implémentés

1. **Permission `urgence.bris_de_glace` attribuée à AUCUN rôle** dans le seeder. Le gestionnaire l'accorde
   **individuellement**, et seulement à un agent d'un service de spécialité `urgences`. La permission ne
   suffit pas : la spécialité est **revalidée à chaque accès** — un agent habilité puis muté en ORL ne peut
   plus ouvrir de dossier.
2. **Justification obligatoire** (min. 20 caractères) : motif de l'urgence + mode d'identification du patient.
3. **Notification immédiate** au titulaire et aux contacts d'urgence — `Log::warning` avec la liste des
   destinataires réels (ni Firebase ni passerelle SMS dans le projet ; à brancher au module Notifications).
4. **Audit renforcé** : `motif_urgence` sur `acces_dossier`, immuable comme le reste du journal, reporté sur
   la ligne de clôture pour que les deux lignes d'un même accès portent le même motif. Consultable par le patient.
5. **Session de 15 minutes** (contre 30 pour un scan QR : le patient n'a rien consenti, la fenêtre doit être
   la plus étroite possible), **lecture seule**, périmètre limité au vital minimal.
6. **Revue a posteriori** : les bris de glace apparaissent dans les statistiques admin (compte du mois et
   total), en encart rouge dès qu'il y en a eu.

### Réutilisation plutôt que duplication

- Le périmètre exposé est **exactement** celui de `FicheVitaleService` (5.1) : la même définition du vital
  minimal sert la carte du secouriste, le SMS d'urgence et le bris de glace. Un seul endroit à auditer.
- `SessionDossierService` est **généralisé** : la durée devient un attribut de la session (30 ou 15 min) au
  lieu d'une constante figée, et le type d'accès y est mémorisé. L'audit en deux lignes (ouverture + clôture)
  fonctionne sans modification. Défense en profondeur : l'écran d'urgence **refuse d'afficher** une session
  ouverte par QR, et réciproquement.
- Anti-IDOR conservé : aucun identifiant de membre dans l'URL, le dossier consulté est celui de la session.

### Livrables

- Migration : enum `type_acces` à 5 valeurs + `motif_urgence`. **Piège rencontré** : SQLite *applique* bien
  une contrainte `CHECK` sur les enums de Laravel — un `ALTER TABLE ... MODIFY` réservé à MySQL laissait
  les tests échouer. Résolu par `->change()`, portable sur les deux moteurs.
- `BrisDeGlaceService` (identification, ouverture, notification), `BrisDeGlaceController` (formulaire, dossier
  vital, fermeture), `AgentController::toggleBrisDeGlace` (habilitation), vues `urgence/{bris-de-glace,dossier}`.
- Carte dashboard **bordée de rouge** avec la mention « Procédure justifiée et auditée » : une procédure
  d'exception ne doit pas se confondre avec une fonction ordinaire du portail. Le formulaire dit à l'agent ce
  qu'il engage **avant** qu'il ne le remplisse.
- `throttle:10,1` sur l'ouverture.
- **Tests `BrisDeGlacePortailTest` (10)** : agent non habilité **403** ; agent habilité **hors service
  d'urgences 403** (cas de la mutation) ; le gestionnaire ne peut habiliter qu'un agent des urgences (bascule
  aller-retour) ; **les trois critères sont exigés exactement** (un seul faux → aucun dossier, aucune trace) ;
  casse et espaces tolérés ; **justification trop courte refusée** ; ouverture journalisant le motif et
  exposant le vital minimal **sans** l'antécédent chirurgical ni la note médicale ; **expiration à 15 min**
  journalisant la durée et les sections ; fermeture manuelle ; l'accès figure au journal du patient.
  **Suite : 186/186**, audit 0.

## 5.4 — Alertes épidémiques · Étape A backend (2026-07-10)

Bulletins sanitaires locaux (CdC FN3, table `alertes_epidemiques` du §8). Deux facettes : la **gestion**
par l'admin (portail) et le **ciblage** par commune côté patient (API mobile).

**Décisions.**

- **L'admin publie**, depuis le portail (`permission:sante_publique.manage`), en reportant les bulletins
  OMS / Ministère de la Santé CI. Le CdC ne nommait aucun acteur ; c'est cohérent avec le rôle de pilotage
  national de l'admin. Le gestionnaire ne publie pas (OMS ≠ un hôpital).
- **Ciblage par commune de résidence** : l'API renvoie les alertes en vigueur dont la commune = celle du
  compte (`users.commune`, déjà présent), plus les alertes nationales (`commune = 'toutes_communes'`). FN3 :
  « alerte uniquement les utilisateurs de la commune concernée ». Un compte sans commune ne voit que le
  national. Pas de GPS : le CdC parle de commune de **résidence**, pas de position instantanée.

**En vigueur ≠ actif.** Le scope `enVigueur()` combine le drapeau `actif` ET la fenêtre de dates : une alerte
peut être `actif` mais programmée pour le mois prochain, ou expirée sans qu'on l'ait désactivée. Le mobile ne
voit que ce qui est réellement d'actualité.

- Migration `alertes_epidemiques` (schéma CdC §8) + modèle avec scopes `enVigueur()` / `pourCommune()`.
- API `GET /api/v1/alertes-epidemiques` (auth) : tri par gravité (alerte > vigilance > information) via `CASE`
  **portable** (pas `FIELD()`, propre à MySQL). Pas de push (ni Firebase ni FCM) : le mobile interroge et
  affiche une bannière ; la notification poussée reste à brancher.
- Portail `AlerteEpidemiqueController` (CRUD, admin) : formulaire portée commune/nationale, désactivation
  plutôt que suppression (historique des épisodes conservé), `date_fin ≥ date_debut`. Carte dashboard.
- **Pièges** : Laravel pluralise `AlerteEpidemique` en `alerte_epidemiques` (seul le dernier mot) → `$table`
  fixé explicitement. `actif` géré par `toggleActif` seul, pas par le formulaire (une case décochée serait
  absente de la requête, donc ambiguë en update).
- **Seeder de démo** `AlerteEpidemiqueSeeder` (paludisme Cocody, choléra national, dengue Yopougon).
- **Tests `AlerteEpidemiqueTest` (10)** : patient voit **sa commune + le national**, pas les autres communes ;
  compte sans commune → national seul ; **inactives / futures / expirées exclues** ; tri par gravité ; 401
  sans auth ; admin publie (communale et nationale avec sentinelle) ; `date_fin` antérieure refusée ; toggle
  sans suppression ; **gestionnaire 403**. **Suite : 196/196**, audit 0.

## 5.4 — Alertes épidémiques · Étape B mobile (2026-07-10)

Bannière sur l'accueil quand une alerte concerne la commune de l'utilisateur, écran de détail avec les
consignes. Le ciblage est entièrement côté serveur (5.4 A) : le mobile ne décide de rien, il affiche.

- **Bannière `BanniereAlerte`** sur l'accueil : teintée selon la gravité, elle montre la **plus grave** et
  annonce le nombre d'autres (« +2 autres alertes dans votre zone »). L'accueil n'est pas un mur d'alertes.
  Chargée à chaque focus, **échec silencieux** : une bannière est un plus, son absence ne doit pas gêner
  l'accueil (ni bloquer un utilisateur hors ligne).
- **Écran `AlertesEcran`** (`/(app)/alertes`, hors barre d'onglets) : liste complète, chaque alerte avec sa
  maladie, son niveau, la période, les **consignes** (l'essentiel — c'est ce qui protège) et la source.
- **`src/urgence/alertes.ts`** : `styleNiveau()`, source unique de la couleur / du libellé / de l'icône par
  niveau, partagée par la bannière et l'écran — pour qu'ils ne divergent jamais.
- **Vide géré** : « Aucune alerte en cours », avec l'invite à renseigner sa commune dans le profil.
- `tsc` OK, `expo-doctor` 18/18, aucune dépendance ajoutée.

## 5.5 — Suivi de grossesse (FN4) · Étape A backend (2026-07-12)

Calendrier prénatal des **8 contacts OMS/PSN-CI** (validés par l'utilisateur), suivi semaine par semaine,
consultations réalisées, conseils nutrition adaptés CI. Décisions arbitrées avant code (« ok tel quel ») :

**Décisions.**

- **`semaine_actuelle` jamais stockée** (écart assumé vs CdC, qui l'annote lui-même « calculée
  automatiquement ») : une colonne périmerait chaque semaine et exigerait un cron. Accessor calculé depuis
  la DDG — `jours ÷ 7 + 1`, borné 1→43, sérialisé via `$appends` : le mobile voit le champ du CdC, toujours
  exact. Même principe que l'âge déduit de la date de naissance. `null` une fois le suivi clos.
- **`consultations_json` conservé (forme CdC) mais verrouillé** : le client n'écrit JAMAIS le tableau —
  endpoint dédié `POST .../consultations` qui ajoute UNE consultation validée champ par champ (date réelle,
  ≥ DDG, ≤ aujourd'hui ; textes bornés), horodatée serveur (`enregistree_le`), plafond 30 entrées.
  Append-only, principe des notes F2.12. Le champ n'est pas dans `$fillable` : impossible à écraser.
- **Référentiel en base, pas en dur dans le mobile** : table `etapes_prenatales` (8 contacts : SA
  recommandée, objet du contact, conseils nutrition CI — TPI paludisme, moustiquaire, aliments locaux),
  seedée (`EtapePrenataleSeeder`, idempotent), modifiable sans redéploiement — pattern F1.3 (`symptomes`).
  Contenu médical à faire relire (mémoire).
- **Rappels CPN = table `rappels` existante (F2.7)**, pas un second mécanisme. FK nullable
  `rappels.suivi_grossesse_id` : seule marque fiable des rappels auto-gérés (un préfixe de titre serait
  fragile). Régénérés si la DDG est ajustée (datation échographique), **supprimés** à la clôture (la
  grossesse n'a plus aucun rendez-vous à venir, et un rappel est un pense-bête, pas une donnée médicale —
  l'historique médical reste dans `consultations_json`) ; les rappels créés à la main (FK `NULL`) ne sont
  jamais touchés. Pas de rappel rétroactif pour les contacts déjà dépassés à la déclaration.

**Règles produit.** Membre de sexe F uniquement ; **une seule grossesse `en_cours` par membre** (422 sinon) ;
DDG plausible (ni future, ni > 43 SA) ; **terme = DDG + 280 j, calculé serveur** (jamais accepté du client) ;
**aucune suppression** : clôture par `statut` (`termine`/`interruption`), un suivi clos est figé (rétention
médicale) et libère la déclaration d'une nouvelle grossesse. La fiche vitale (FN2) n'expose pas ce suivi.

- Migrations : `suivi_grossesse` (nom CdC, sans `semaine_actuelle`), `etapes_prenatales`,
  `add_suivi_grossesse_to_rappels` (FK nullable, `nullOnDelete`).
- Modèles `SuiviGrossesse` (accessor + constantes `DUREE_JOURS`/`SEMAINE_MAX`/`MAX_CONSULTATIONS`,
  `membre_id` hors `$fillable`) et `EtapePrenatale` (lecture seule côté API).
- `GrossesseController` : `GET` (suivi en cours + historique clos + **calendrier daté** — `date_estimee` =
  DDG + SA×7, `passee` ; calendrier renvoyé même sans grossesse : contenu éducatif), `POST` (déclaration +
  génération des rappels CPN à venir), `PUT` (ajuster DDG → terme et rappels recalculés ; clore),
  `POST .../consultations`. Routes nichées sous `membres/{membre}` (anti-IDOR par la Policy du membre).
- **Piège rencontré** : `statut` vient d'un défaut SQL → `refresh()` avant la réponse du `POST` (même
  correctif que `declenchee_le` du SOS 5.2).
- **Tests `GrossesseTest` (13)** : terme calculé + champs interdits ignorés à la création ; pas de rappel
  rétroactif (déclaration à 30 SA → 4 rappels) ; membre masculin refusé ; unicité de la grossesse en cours ;
  DDG future/invraisemblable refusée ; GET complet (semaine 22, contact 1 passé, contact 3 à venir) ;
  calendrier éducatif sans grossesse ; consultation append-only (bornes de date) ; le client ne peut pas
  réécrire le tableau ; ajustement DDG (rappels 8→6, rappel personnel intact) ; clôture (rappels CPN
  supprimés, rappel personnel conservé, suivi figé, nouvelle grossesse possible) ; anti-IDOR 403 ; 401 sans
  auth. **Suite : 209/209 (730 assertions)**, audit 0.

## 5.5 — Suivi de grossesse (FN4) · Étape B mobile (2026-07-12)

Écran dédié `GrossesseEcran`, accessible depuis la fiche d'un membre **féminin** (une ligne « Suivi de
grossesse » n'apparaît que si `sexe === 'F'` — le backend refuse un homme, une entrée serait un cul-de-sac).
La route vit sous `membres/`, donc **déjà derrière le VerrouGate** (biométrie/PIN, données sensibles).
Le mobile n'affiche que ce que le serveur décide : ni terme, ni semaine d'aménorrhée recalculés côté client.

**Un seul écran, deux visages** (selon la réponse de l'API) :

- **Sans grossesse en cours** : formulaire de **déclaration** (DateField DDG borné à 43 semaines en arrière,
  miroir de la règle serveur) + le **calendrier éducatif** des 8 contacts (non daté) + l'historique des
  grossesses clôturées (dates + issue accouchement/interruption).
- **Grossesse en cours** : **bandeau de tête** (semaine d'aménorrhée + terme prévu), **carte « signes de
  danger »** avec appel direct au **SAMU 185** (réutilise `appelerSamu` du SOS 5.2 — information de sécurité,
  aucune détection automatique, décision actée au cadrage M5), **timeline datée** des 8 contacts (pastille
  ✓ passé / n° à venir, description + conseils nutrition **dépliables** au tap comme les consignes 5.4),
  **consultations** (liste + ajout append-only, sans édition ni suppression), et la **gestion** (ajuster la
  DDG après échographie → terme et rappels recalculés serveur ; clôturer via `Alert` à choix
  Accouchement/Interruption, action **définitive**).

- **Nouveaux** : `src/types/grossesse.ts` (types miroir de l'API), `src/api/grossesse.ts` (obtenir/déclarer/
  ajuster/clôturer/ajouter-consultation, client axios unique), `src/screens/GrossesseEcran.tsx`,
  route `app/(app)/membres/grossesse/[id].tsx` (wrapper mince).
- **Modifié** : fiche membre `[id].tsx` (ligne « Suivi de grossesse » conditionnée au sexe F) ; `RAPPORT.md`.
- **Rappels CPN non dupliqués** : ils vivent déjà dans la section « Rappels » du carnet ; la timeline sert de
  calendrier visuel. À la déclaration, un message confirme le nombre de rappels ajoutés.
- **Piège** : la route typée n'existe qu'après régénération des types expo-router (`experiments.typedRoutes`) —
  Metro relancé une fois pour régénérer `.expo/types/router.d.ts`, puis `tsc`. **`tsc` OK**, **`expo-doctor`
  18/18**, aucune dépendance ajoutée.

## 5.6 — Maladies chroniques (FN5) + médecin référent (voie 2) · Étape A backend (2026-07-13)

Journal de bord quotidien (glycémie, tension…), alertes sur valeurs anormales, **partage automatique avec le
médecin référent** — et, du même coup, la **voie 2 d'accès au dossier** (Sécurité §4.4), annoncée « existante »
par la `Note_Continuite` mais **jamais implémentée** depuis le Module 2. Elle l'est ici. Décisions arbitrées
avant code (3 questions posées, 3 recommandations retenues) :

**Décisions.**

- **Le référent se choisit dans l'annuaire PUBLIC des médecins** (`medecins`, déjà exposé par la fiche d'une
  structure — F3.5), pas dans une liste des comptes du portail : on n'ouvre aucun annuaire nouveau, même refus
  qu'au bris de glace (5.3). Nouvelle colonne **`medecins.user_id`** (nullable, unique) reliant une fiche à un
  compte, lien posé par le **gestionnaire** depuis « Mes agents ». Sans lien, la fiche reste désignable mais
  `consulte_en_ligne = false` — le patient doit le savoir **avant** de désigner.
- **Table `referents` dédiée** (écart assumé vs CdC §8.1, qui prévoit une simple colonne
  `membres_famille.medecin_referent_id`) : une colonne ne dit ni qui a désigné, ni quand, ni ce qui a été
  révoqué — or §4.4 exige « révocable à tout moment » et la loi 2013-450 la traçabilité. Calquée sur
  `delegations` (voie 3). La règle « **un seul référent actif** » qu'imposait la colonne unique est appliquée
  côté service : désigner remplace, et la révocation reste à l'historique.
- **« Permanent » = le DROIT, pas la fenêtre.** Chaque ouverture par le référent crée une session de
  **30 minutes** et **deux lignes d'audit** (ouverture + clôture), exactement comme un scan QR, sans
  `token_qr_id` (CdC §8.4 : « NULL si médecin référent »). Le titulaire est notifié à chaque accès et voit tout
  dans son historique. Un droit permanent non tracé serait un angle mort.
- **Périmètre = dossier complet**, comme un scan (Note_Continuite §2 : « dossier du membre, en continu ») : le
  patient a explicitement désigné ce médecin. La qualité de référent est **revérifiée à l'ouverture**, pas
  seulement à l'affichage de la liste : une révocation faite à l'instant referme la porte (404, pas 403).
- **Seuils médicaux en base** (`referentiels_mesure`, seedé — pattern F1.3 comme `symptomes` et
  `etapes_prenatales`) : plausibilité de saisie (`valeur_min/max` : 500 g/L de glycémie est une **faute de
  frappe**, refusée avant écriture) ET normalité clinique (`normal_*`, `critique_*` → `statut_norme`).
  **Le statut est calculé serveur**, jamais reçu du client — même principe que le score de triage. Le mobile ne
  code aucune norme médicale : il reçoit le référentiel. Contenu à faire relire par un professionnel (mémoire).
- **La tension : une saisie, deux lignes.** L'ENUM du CdC impose `tension_systolique` + `tension_diastolique` ;
  le patient, lui, saisit « 12/8 ». On garde les deux lignes et on les relie par **`groupe_uuid`** (ajout au
  schéma CdC) : elles naissent dans une transaction et se suppriment ensemble — jamais de systolique orpheline.
- **Pas d'`update` sur une mesure** : c'est un fait daté. Une erreur se **supprime** (si `source = 'patient'`,
  F2.13) et se ressaisit ; une mesure enregistrée par une structure n'est pas au patient de l'effacer.
- **« Partage automatique » ≠ envoi de données.** Une valeur **critique** notifie le référent (stub journalisé —
  ni Firebase ni SMS dans le projet, comme le bris de glace) ; la notification dit **qu'il faut regarder**. Le
  référent lit ensuite le journal comme une **section du dossier** (`mesures`), dans une session tracée. Aucune
  donnée médicale ne quitte le serveur hors d'un accès audité.

**Sécurité.** Désignation gated par le palier **« compte vérifié »** (même règle que la délégation : partager le
dossier exige une identité confirmée) ; **révocation sans condition** (reprendre le contrôle doit toujours être
plus facile que de le céder). Nouvelle permission **`dossier.referent`** (rôle `agent_garde`) qui **n'ouvre rien
à elle seule** : il faut la permission ET le lien vers une fiche ET une désignation par le patient. `note`
chiffrée AES-256 (§6.1) ; `valeur` en clair, sans quoi ni courbe ni seuil.

**Fichiers.** 4 migrations (`medecins.user_id`, `referents`, `referentiels_mesure`, `mesures_sante`) ;
modèles `Referent`, `MesureSante`, `ReferentielMesure` (+ `Medecin`, `User`, `MembreFamille`) ; services
`ReferentService`, `MesureSanteService` ; API `MedecinController` (annuaire, public), `ReferentController`,
`Carnet/MesureSanteController` ; portail `MesPatientsController` + vue « Mes patients suivis », section
`mesures` du dossier, sélecteur de fiche praticien dans « Mes agents » ; `ReferentielMesureSeeder`.
Les routes `dossier/*` du portail **sortent du groupe `permission:qr.scan`** (elles n'y avaient rien à faire :
c'est la SESSION qui autorise, ouverte par un scan OU par la voie référent) et restent sous `dossier.actif`.

**Tests : 225/225** (16 nouveaux — `MesureSanteTest`, `MedecinReferentTest`), `composer audit` 0 avis.

## 5.6 — Journal de santé + médecin référent · Étape B mobile (2026-07-13)

Deux écrans, tous deux sous la pile `membres/` donc **déjà derrière le VerrouGate** (biométrie/PIN).
Le mobile **ne code aucune norme médicale** : unités, décimales, bornes de saisie, seuils et conseils viennent
tous du référentiel renvoyé par l'API. Corriger un seuil = un UPDATE en base, sans mise à jour de l'app.

**Journal de santé** (`MesuresEcran`, entrée « Journal de santé » dans les sections du carnet — ouvert à
**tous** les membres : le poids et la température ne concernent pas que les malades chroniques) :

- **Dernières mesures** en tête (une case par type, teintée par le statut serveur) ;
- **6 gestes de saisie** en chips (Glycémie · Tension · Poids · Température · Pouls · Saturation) — la
  **tension est un seul geste** (systolique + diastolique côte à côte) alors que la base écrit deux lignes ;
- **courbe d'évolution** (`CourbeMesure`, react-native-svg **déjà installé** — pas de librairie de graphes
  ajoutée pour une polyligne) : **bande verte = la norme, tracée d'après les seuils du serveur**, points bleus
  dans la norme, orange hors norme, rouges si critiques. L'échelle englobe toujours la bande normale, sans
  quoi un patient durablement hors norme la croirait invisible — donc normale ;
- **historique** du type suivi (tension regroupée « 150/95 »), **appui long = supprimer** (avec avertissement
  si la mesure vient d'une structure : elle n'est pas au patient de l'effacer). Pas de modification : une
  mesure est un fait daté ;
- **alerte valeur critique** : `Alert` portant le **conseil du référentiel** (texte de la base, oriente vers le
  SAMU 185) — l'app n'invente aucun texte médical.
- **Horodatage** : aujourd'hui → heure courante ; jour passé → midi (une mesure ne peut pas être future, et
  une heure fixe à 08:00 aurait basculé dans le futur pour une saisie matinale).

**Médecin référent** (`ReferentEcran`, dans le bloc **« Partage sécurisé »** de la fiche membre, à côté du QR
et des délégués — car c'est bien une **voie de partage**, la 2e des quatre, pas une section du carnet) :

- l'écran **dit franchement** ce qu'il engage : « ce médecin pourra consulter le dossier à tout moment, sans
  QR Code », avec les deux contreparties (journal d'accès, révocation immédiate) ;
- **recherche par nom** dans l'**annuaire public** (`GET /v1/medecins`) — le patient cherche le médecin qu'il
  connaît déjà ; un praticien sans compte relié est signalé **« Ne consulte pas encore en ligne »** (il peut
  être désigné, mais il ne verra rien : autant le savoir avant) ;
- **confirmation explicite** avant désignation (et mention du référent remplacé, le cas échéant), **révocation**
  en un geste, **anciens référents** listés avec leurs dates (la trace opposable, loi 2013-450).

- **Nouveaux** : `src/types/mesure.ts`, `src/types/referent.ts`, `src/api/mesures.ts`, `src/api/referent.ts`,
  `src/components/CourbeMesure.tsx`, `src/screens/MesuresEcran.tsx`, `src/screens/ReferentEcran.tsx`,
  routes `app/(app)/membres/mesures/[id].tsx` et `app/(app)/membres/referent/[id].tsx`.
- **Modifié** : fiche membre `[id].tsx` (ligne « Journal de santé » + bouton « Médecin référent ») ; `RAPPORT.md`.
- **Piège (déjà rencontré en 5.5)** : les routes typées n'existent qu'après régénération de
  `.expo/types/router.d.ts` — Metro relancé une fois. **`tsc` OK**, **`expo-doctor` 18/18**, **aucune
  dépendance ajoutée**.

## 5.6 — Correction : l'annuaire des praticiens n'était alimenté que par le seeder (2026-07-14)

**Symptôme remonté au test** : dans l'app, un médecin cherché « existe bien en base » mais ne remonte pas ; et
un agent créé au nom d'un praticien reste marqué « ne consulte pas encore en ligne ».

Deux causes distinctes, l'une superficielle, l'autre structurelle :

1. **Recherche trop littérale.** `GET /v1/medecins?q=` comparait la saisie ENTIÈRE à `nom`, puis à `prenom`.
   Un patient tape « Aya Kouamé » ou « Dr Kouamé » — aucune de ces chaînes n'est contenue dans un champ pris
   isolément, d'où « aucun médecin ne correspond ». La recherche est désormais **mot à mot** : chaque mot doit
   se retrouver dans le nom, le prénom OU la spécialité (les mots affinent, ils ne s'excluent pas) ; les titres
   « Dr »/« Pr » sont ignorés.

2. **Le vrai trou : aucun écran pour créer une fiche de praticien.** Le Module 3 avait posé la table `medecins`
   en notant « configuration par les gestionnaires : Module 4 » — et cet écran n'a jamais été construit.
   L'annuaire n'existait donc QUE pour les 12 structures seedées d'Abidjan. Conséquence : un établissement créé
   depuis le portail (ici la Clinique de Morofé) n'avait **aucune fiche**, donc rien à relier à un compte, donc
   **la voie 2 y était inutilisable** — et créer un agent homonyme n'y changeait rien (le lien se fait par
   `medecins.user_id`, jamais par le nom).

**Ajouté** : écran **« Mes médecins »** (gestionnaire, permission `medecin.manage`) — création, modification,
**désactivation** (jamais de suppression : la fiche est référencée par des RDV et peut l'être par des
désignations de référent), et **lien vers le compte du praticien** (`user_id`, UNIQUE : un compte = une fiche).
Cloisonnement strict par `structure_id`, comme les services (4.3). Le lien reste posable des deux côtés :
depuis la fiche (« compte du praticien ») ou depuis l'agent (« fiche de praticien ») — même colonne.

**Fichiers** : `Portail/MedecinController`, vues `portail/medecins/{index,create,edit,_form}`, route
`permission:medecin.manage`, carte « Mes médecins » au tableau de bord, permission ajoutée au rôle
`gestionnaire_etablissement`, recherche mot à mot dans `Api/V1/MedecinController`.
**Tests : 230/230** (5 nouveaux — `PortailMedecinTest`, dont la recherche multi-mots).

## 5.7 — Don de sang (FN6) · Étape A backend (2026-07-14)

Quatre besoins au CdC (§5.5.2) : localiser les centres, voir les groupes demandés, s'inscrire donneur, alerter
les donneurs compatibles en cas d'urgence. **Le CdC ne fournit AUCUNE table pour FN6** (contrairement à FN3 ou
FN5) : le schéma est entièrement conçu ici. Décisions arbitrées avant code (3 questions, 3 recommandations
retenues) :

**Décisions.**

- **Les centres de collecte n'ont AUCUN code.** Un centre = une structure de l'annuaire portant un **service de
  spécialité `don_sang`** (créé par le gestionnaire depuis « Mes services »). Le mobile les obtient par
  `GET /v1/structures?specialite=don_sang&lat=&lng=` — la recherche géolocalisée du Module 3, déjà triée par
  proximité. Une table `centres_collecte` aurait dupliqué la carte, les fiches, la géoloc et l'admin.
- **Le donneur est un MEMBRE du carnet**, pas le compte : c'est lui qui porte `groupe_sanguin` (indispensable au
  ciblage) et `date_naissance` (donc l'éligibilité par l'âge). Le compte reste le canal de contact. L'inscription
  est un **consentement explicite, membre par membre** : le groupe sanguin existait déjà au carnet, ce qui change
  c'est qu'il devient interrogeable POUR ALERTER.
- **La compatibilité ABO/Rhésus est FIGÉE dans le service PHP** — écart RAISONNÉ au pattern F1.3 (« les règles
  médicales vivent en base »). Les symptômes, étapes prénatales et seuils de mesure sont des politiques de santé
  révisables ; la compatibilité des groupes sanguins est de l'immunologie. La mettre en base laisserait croire
  qu'un gestionnaire pourrait décider qu'un A+ donne à un O−. **Une erreur ici tue** : la règle est figée, testée
  (O− donneur universel, AB+ receveur universel, le Rhésus compte), et relue.
- **Éligibilité et carence sont, elles, configurables** (`config/masante.php` : 18-65 ans, 90 jours entre deux
  dons — CNTS ivoirien) : ce sont des politiques de collecte, elles varient. Un donneur en carence est **au
  repos, pas désinscrit** : on ne le sollicite pas, et le retrait de consentement **conserve la date du dernier
  don** (sinon la carence se remettrait à zéro à volonté).
- **Deux niveaux de besoin.** Le `courant` s'affiche dans la liste publique des groupes demandés ; seule
  l'**urgence** alerte les donneurs compatibles. Si tout alertait, plus rien n'alerterait.
- **C'est l'ÉTABLISSEMENT qui publie** (permission `don_sang.manage`, gestionnaire) : lui seul sait qu'il manque
  de O− ce matin — le CdC dit « urgence signalée par un CHU ». L'admin MaSanté n'a aucune visibilité sur les
  stocks d'un hôpital.
- **MINIMISATION (loi 2013-450) — le point à défendre en soutenance** : le portail affiche un **COMPTEUR** de
  donneurs mobilisables, **jamais leur identité**. Aucun nom, aucun numéro, aucun export. Un hôpital n'a pas à
  repartir avec un fichier de porteurs de O−, et l'application n'a pas à devenir un annuaire de groupes sanguins.
  Les donneurs sont alertés chez eux et se présentent d'eux-mêmes : **donner reste une décision, pas une
  convocation**.
- **Ciblage 100 % serveur** (comme FN3) : `GET /v1/don-sang` ne renvoie que les urgences auxquelles les membres
  donneurs de CE compte peuvent réellement répondre — un O− est alerté pour une poche A+, un A+ ne l'est jamais
  pour une poche O−.

**Fichiers.** Migrations `donneurs_sang`, `besoins_sang` ; modèles `DonneurSang`, `BesoinSang` ; service
`DonSangService` (compatibilité, éligibilité, carence, ciblage, compteur) ; API `DonSangController` (besoins
publics, alertes ciblées, inscription/don/retrait) ; portail `BesoinSangController` + vues `portail/don-sang/*` ;
permission `don_sang.manage` (gestionnaire) ; carte « Don de sang » au tableau de bord ; config `masante.don_sang`.

**Piège rencontré** : `FIELD()` (tri par niveau) est propre à **MySQL** — la suite tourne sur **SQLite**.
Remplacé par un `CASE WHEN`. Même famille de piège que les CHECK d'enum du 5.5.

**Tests : 241/241** (11 nouveaux — `DonSangTest`), `composer audit` 0 avis.

## 5.7 — Don de sang (FN6) · Étape B mobile (2026-07-14)

Un écran (`DonSangEcran`, tuile **« Don de sang »** à l'accueil) + une **bannière d'urgence** sur l'accueil.
Le mobile **ne compare aucun groupe sanguin** : le ciblage vient du serveur (une erreur de compatibilité tue,
elle n'a rien à faire dans une app). Il n'affiche que ce que le serveur lui a dit le concerner.

Quatre blocs, dans l'ordre où ils comptent pour l'utilisateur :

1. **Urgences qui me concernent** — carte rouge : « le CHU de Cocody recherche du sang A+, **votre don peut
   convenir** (O−) ». Si elle s'affiche, c'est qu'un membre donneur du foyer peut réellement fournir la poche.
   Doublée d'une **bannière rouge à l'accueil** (`BanniereDonSang`, sœur de celle des alertes épidémiques).
2. **Donneurs de mon carnet** — inscription **membre par membre** (consentement explicite), retrait en un
   geste, et déclaration d'un don qui met le donneur **au repos** (« repos 80 j ») sans le désinscrire.
   Les règles affichées (18–65 ans, 90 jours) viennent de la config serveur, jamais codées ici.
3. **Groupes les plus demandés** (public) — urgences en tête, pastille du groupe teintée par le niveau.
4. **Centres de collecte** — bouton « Trouver les centres proches » : appelle `rechercherStructures` avec
   `specialite=don_sang` (Module 3), donc **distances et fiches existantes**, aucune carte refaite. La géoloc
   refusée n'empêche pas la liste : refuser sa position ne doit pas priver d'une information de santé publique.

- **Nouveaux** : `src/types/donSang.ts`, `src/api/donSang.ts`, `src/components/BanniereDonSang.tsx`,
  `src/screens/DonSangEcran.tsx`, route `app/(app)/don-sang.tsx`.
- **Modifié** : accueil `app/(app)/index.tsx` (tuile + bannière d'urgence ciblée) ; `RAPPORT.md`.
- **`tsc` OK**, **`expo-doctor` 18/18**, **aucune dépendance ajoutée**. Piège habituel : Metro relancé une fois
  pour régénérer `.expo/types/router.d.ts`.

## 5.8 — Comparateur de prix (FN7) + ruptures (FN8) · Étape A backend (2026-07-14)

Dernier lot du Module 5. Le CdC fournit ici **deux tables** (§8) : `medicaments` (catalogue CENAME/DPM) et
`prix_pharmacie` (relevés crowdsourcés). Décisions arbitrées avant code :

**Décisions.**

- **UNE seule table pour FN7 et FN8.** Le champ `disponible` de `prix_pharmacie` porte déjà la rupture : une
  rupture n'est rien d'autre qu'un relevé disant « ce médicament n'est pas en rayon ici, ce jour ». Une table
  `ruptures` séparée aurait créé deux mécanismes concurrents pour dire le même fait — et deux vérités possibles.
- **Écart CdC : `prix_cfa` devient NULLABLE.** On ne relève pas le prix d'un médicament absent des rayons.
  Le laisser NOT NULL rendait FN8 inapplicable — ou poussait à inventer un chiffre.
- **Le problème de FN7 n'est pas technique, il est ÉPISTÉMIQUE** : un prix rapporté par un inconnu n'a aucune
  garantie. Quatre défenses : (1) **hiérarchie des sources** — le pharmacien fait autorité sur SA officine
  (`pharmacie_portail`), à défaut on agrège les patients ; (2) **médiane, pas dernier relevé** — un plaisantin
  isolé ne déplace pas l'affichage, il faut convaincre la majorité ; (3) **plausibilité AVANT écriture** — un
  prix hors proportion avec la référence CENAME (bornes 0,2× à 5×, larges à dessein : on n'écarte que
  l'absurde) est refusé, comme la glycémie à 500 g/L du 5.6 ; (4) **fraîcheur affichée et fenêtre de 90 jours**
  — un prix sans date ne vaut rien.
- **Signaler exige un COMPTE ; lire est PUBLIC.** Un relevé anonyme ne se conteste pas, et un comparateur ouvert
  à l'anonymat s'empoisonne en une nuit. Mais savoir où trouver un médicament ne demande aucune identité.
- **Génériques moins chers** (FN7) : rapprochement par **DCI** (`nom_generique`), suggestion fondée sur le prix
  de RÉFÉRENCE officiel, jamais sur un prix crowdsourcé — on n'oriente pas un achat sur la foi d'un passant.
- **« Scan de reçu » : OCR AUTO-HÉBERGÉ (Tesseract), choix explicite de l'utilisateur.** Un reçu de pharmacie
  dit quels médicaments une personne identifiable a achetés : c'est une **donnée de santé** (loi n°2013-450).
  L'envoyer à Google Vision ou à un OCR en ligne l'exporterait chez un tiers étranger — même refus qu'au
  Module 3 (OSM contre Google Maps). Tesseract 5.4 installé, fichiers de langue **dans le projet**
  (`storage/app/tessdata`, fra+eng) pour ne pas dépendre d'un dossier système en prod. Aucune dépendance
  Composer ajoutée : le binaire est appelé via `symfony/process`, déjà présent.
- **L'OCR ne décide RIEN et l'image est DÉTRUITE.** Il PROPOSE des montants (heuristique explicable : les plus
  grands d'abord, les années écartées) ; le patient choisit, corrige, confirme. Un chiffre mal reconnu qui
  entrerait silencieusement dans le comparateur empoisonnerait la médiane de tous les autres. La photo n'est
  jamais écrite dans le stockage applicatif : elle vit le temps d'un appel à Tesseract, dans le dossier
  temporaire, et disparaît — ce qu'on garde, c'est un nombre, pas une image du ticket.
- **Portail « Prix & stock »** (permission `medicament.manage`) : le « modèle freemium » du CdC — la pharmacie
  tient ses prix et déclare ses ruptures, et gagne la source la plus fiable au comparateur. La permission dit
  le rôle ; l'écran se referme sur les établissements qui **ne sont pas une pharmacie** (revérifié à chaque
  accès, comme le bris de glace revérifie le service d'urgences).

**Bug trouvé par les tests (vrai bug, pas artefact) :** deux relevés créés dans la même seconde portent le même
horodatage → « le plus récent » devenait indéterminé, et une **rupture survivait au réapprovisionnement** qui
l'annulait. Départage désormais par l'identifiant, strictement croissant.

**Fichiers.** Migrations `medicaments`, `prix_pharmacie` ; modèles `Medicament`, `PrixPharmacie` ; services
`PrixMedicamentService` (médiane, plausibilité, génériques, ruptures agrégées), `RecuOcrService` (Tesseract,
image détruite) ; API `MedicamentController` (catalogue, comparateur, ruptures, relevé, rupture, lecture de
reçu) ; portail `StockPharmacieController` + vue ; `MedicamentSeeder` (18 essentiels CI, prix CENAME
indicatifs) ; config `masante.prix` et `masante.ocr`.

**Tests : 250/250** (9 nouveaux — `PrixMedicamentTest`, dont l'OCR réellement exécuté), `composer audit` 0 avis.

## 5.8 — Comparateur de prix + ruptures · Étape B mobile (2026-07-14) — DERNIÈRE ÉTAPE DU MODULE 5

Deux écrans, tuile **« Médicaments »** à l'accueil. Le mobile n'a **aucune règle de prix** : il affiche ce que
le serveur a retenu, avec sa provenance et sa date.

**`MedicamentsEcran`** — les **ruptures du moment en tête** (bandeau orange : « manquant dans 3 pharmacies ») :
c'est l'information qui fait faire demi-tour, raison d'être de FN8. Puis la **recherche au catalogue**, avec le
prix de référence CENAME en pastille.

**`ComparateurEcran`** — pour un médicament :

- le **prix de référence officiel** en tête : le repère qui permet de juger tous les autres ;
- **« Même molécule, moins cher »** (FN7) : les génériques de même DCI, cliquables — c'est là que le
  Doliprane à 1 200 F renvoie au Paracétamol à 300 F ;
- **prix par pharmacie**, du moins cher au plus cher, chacun portant **sa source** (« déclaré par la
  pharmacie » en vert vs « rapporté par des patients (3) » en bleu) et **sa date de relevé**. Un prix sans
  provenance ni fraîcheur serait une affirmation ; ici c'est un constat daté, que le patient peut pondérer ;
- **la contribution** : choisir la pharmacie (chips des officines proches), saisir le prix payé, ou **signaler
  une rupture**. Le bouton **« Photographier le reçu »** appelle l'OCR : les montants lus sont **proposés dans
  une Alert** (« choisissez le prix payé pour ce médicament »), jamais imposés — un ticket porte plusieurs
  lignes et un total, aucune machine ne peut deviner laquelle compte. Le texte de l'écran dit explicitement que
  la photo est **lue puis immédiatement détruite**.

Réutilise `prendrePhoto`/`choisirDansGalerie` de F2.10 (compression déjà gérée) : aucune brique nouvelle.

- **Nouveaux** : `src/types/medicament.ts`, `src/api/medicaments.ts`, `src/screens/MedicamentsEcran.tsx`,
  `src/screens/ComparateurEcran.tsx`, routes `app/(app)/medicaments/index.tsx` et `app/(app)/medicaments/[id].tsx`.
- **Modifié** : accueil `app/(app)/index.tsx` (tuile « Médicaments ») ; `RAPPORT.md`.
- **`tsc` OK**, **`expo-doctor` 18/18**, **aucune dépendance ajoutée**.
- **Piège** : tuer Metro pendant la génération de `.expo/types/router.d.ts` produit un fichier **corrompu**
  (routes parasites pointant vers `src/`). Supprimer le fichier et laisser Metro finir.

---

# Module 5 (P5) — Paiement · Incrément P5.1 : socle + prise en charge CNAM/assurance

**Décisions propriétaire (écrites) :** vrai **microservice Java Spring Boot maintenant** (ADR-013) et
premier incrément **socle + CNAM/assurance**. Le CDC_06 impose un domaine paiement indépendant du
cœur Laravel ; l'existant (table `paiements`/`recus_rdv`, écran reçu RDV mobile) est **conservé
intact** — P5.1 est **additif**. Rebranchement du flux RDV (Laravel = proxy, §10.4) = incrément
ultérieur.

⚠️ **Paiement SIMULÉ** : aucune passerelle Mobile Money réelle (Orange/MTN/Wave/Moov) n'est
accessible (FT5). On bâtit la **structure de domaine correcte** ; rien n'est débité.

**Nouveau service `services/payment/`** (Spring Boot 3.3 + PostgreSQL 16 + Redis 7, hors workspace
pnpm), build/run **Docker** (Gradle + JDK 21 embarqués, aucune dépendance à la chaîne d'outils de
l'hôte). Ce qu'il livre (CDC_06 §14 étapes 1-2 + §8) :

- **OCP** — interface `PasserellePaiement` + `AdaptateurSimule` + `RegistrePasserelles`. Ajouter un
  opérateur = ajouter un bean ; **aucun `if canal == …`**. Canal inconnu → 400.
- **Machine à états stricte** (§4.2) `INITIATED→PENDING→PROCESSING→SUCCESS ↘FAILED/CANCELLED`,
  `SUCCESS→REFUNDED`, enum **source unique** répliqué de `@masante/shared`. Toute transition passe par
  `MachineEtatsPaiement.verifier`, est horodatée et persistée (`payment_transitions`). Double
  remboursement → **409** (`REFUNDED → REFUNDED` interdit).
- **Idempotence** (§9.6) — en-tête `Idempotency-Key` **obligatoire** : verrou Redis + contrainte
  d'unicité PostgreSQL. Rejeu de la même clé → **200 + même paiement** (aucun doublon) ; clé absente →
  **400**.
- **Audit immuable** (§9.7) — journal append-only à **hachage chaîné** (ancre GENESIS posée par
  Flyway, verrou pessimiste sur la dernière entrée). `GET /audit/verify` recalcule toute la chaîne.
- **Moteur de prise en charge CNAM/assurance** (§8) — **frontière** : couverture, ticket modérateur,
  reste à charge calculés **uniquement ici** (`MoteurPriseEnCharge`, classe pure). Vecteurs imposés
  vérifiés : consultation 20 000 @ 70 % → patient 6 000 ; hospitalisation 250 000 @ 80 % → patient
  50 000 ; plafond → couverture bornée ; acte exclu → patient paie tout.
- Téléphone **masqué** en base et à l'affichage (`********89`).

**Gates.** **G0** audit du CDC_06 + existant (`Paiement`, `RecuRdv`, `RecuRdvService`, enums shared).
**G1** plan + stack + téléchargements validés par écrit. **G2** OpenAPI (`/v3/api-docs`, Swagger UI) +
collection Postman ; **prouvé en live** (pile Docker) : 12 contrôles verts (2 vecteurs CDC, plafond,
201 simulé, rejeu 200, clé manquante 400, transitions, audit chaîné, remboursement, double-rembours.
409, canal inconnu 400, intégrité chaîne `true`). **G3** `gradle build` (tests unitaires : moteur de
couverture, machine à états valides/invalides, chaîne d'audit) **vert dans l'image**. **G4** à faire
par le propriétaire : `docker compose up` puis Swagger UI http://localhost:8080/swagger-ui.html +
Postman. **G5** en attente de G4.

- **Nouveaux** : tout `services/payment/**` (build.gradle, Dockerfile, docker-compose.yml, migration
  Flyway `V1__init.sql`, domaine `gateway`/`statemachine`/`coverage`/`model`, services, contrôleurs,
  tests, `postman/…`, `README.md`) ; `docs/adr/README.md` (ADR-013) ; `RAPPORT.md`.
- **Modifié** : **aucun fichier de l'existant** (mobile/web/Laravel) — incrément strictement additif.
- **Piège** : `@Transactional` sur une méthode appelée via `this.` (auto-invocation) est ignoré par
  l'AOP Spring → l'orchestration `initier()→executer()` passe par une **auto-référence `@Lazy`** pour
  que la transaction s'applique et que le verrou d'idempotence soit relâché **après** le commit.
- **Piège run** : `postgres:16-alpine` non téléchargé + reset TCP transitoire de `auth.docker.io` →
  pré-`docker pull` puis `docker compose up -d --no-build` avec `image: masante-payment:test`.

---

# Module 5 (P5) — Paiement · Incrément P5.2a : Facturation (facture + PDF/QR + règlement)

**Décisions propriétaire (écrites) :** on clôture P5.1 d'abord (G4 OK → **P5.1 VALIDÉ G5 2026-08-02**),
puis **P5.2 Facturation** ; dépendances **OpenPDF + ZXing** approuvées ; périmètre = facture (calcul +
numérotation + PDF/QR) **+ lien règlement paiement↔facture**.

**G0.** Audit : les tarifs existants (`medecin.tarif_consultation`, `structure.tarif_min/max_cfa`) sont
**« purement indicatifs, AUCUNE logique de paiement »** ; aucune table facture, aucune TVA, aucun barème
d'actes. → Facturation construite proprement dans le microservice, additive, le portail fournissant les
lignes. **Frontière** : TVA et tarifs **jamais codés en dur** (interdit CDC_00 §4) — le taux de TVA est
une **donnée** portée par la ligne (défaut 0).

**Livré (`services/payment/`, migration Flyway `V2`) :**
- **Moteur de facturation** (`MoteurFacturation`, classe pure — frontière) : lignes → HT (qté×PU−remise)
  → **TVA (taux = donnée)** → remise globale → **réutilise le `MoteurPriseEnCharge` (P5.1)** pour
  appliquer CNAM/assurance sur le TTC → **reste à payer**. Invariant `couvert + reste = TTC`.
- **Numérotation unique par établissement/exercice** (§7.4) : compteur verrouillé (pessimiste) →
  `FCT-{ETAB}-{exercice}-{séquence}`. Séquence prouvée (000001, 000002, 000003…).
- **Facture électronique** (§7.4) : **PDF** (OpenPDF) avec **QR** (ZXing) encodant numéro + montants +
  hash d'intégrité. Signature PKI « prête à activer ».
- **Règlement paiement↔facture** (§7.3) : `POST /payments` avec `factureId` (+ `objet=FACTURE`) impute
  le montant et fait évoluer la facture `EMISE → PARTIELLEMENT_PAYEE → PAYEE`, dans la **même
  transaction** que le paiement (facture introuvable → rollback, aucun débit — simulé).
- **Enum `FactureStatut`** ajouté à `@masante/shared` (source unique) + réplique Java.
- Endpoints : `POST /invoices`, `GET /invoices/{id}`, `GET /invoices/{id}/pdf`.

**Gates.** **G2 prouvé live** : facture CNAM (TTC 20 000 / couvert 14 000 / reste 6 000), TVA 18 %
(HT 10 000 → TVA 1 800 → TTC 11 800), règlement → **PAYEE** (montantRegle 6 000), **PDF** `%PDF-`
4462 o (`Content-Type: application/pdf`), numérotation séquentielle, facture invalide → 400, **chaîne
d'audit toujours intègre** (`InvoiceIssued` ×3, `InvoicePaymentApplied` ×1). **G3** : tests Gradle verts
(`MoteurFacturationTest` : TVA, remises, remise globale, multi-lignes, prise en charge, invariant,
invalidations). **G4** propriétaire OK (Swagger) → ✅ **P5.2a VALIDÉ G5 (2026-08-02)**.

- **Nouveaux** : `V2__facturation.sql` ; domaine `billing/*` (moteur + records) ; modèles `Facture`,
  `FactureLigne`, `FactureCompteur`, `FactureStatut` ; dépôts ; `ServiceFacturation`,
  `ServiceFacturePdf`, `FactureController` + DTO ; test `MoteurFacturationTest`.
- **Modifié** : `build.gradle` (OpenPDF+ZXing) ; `packages/shared/.../enums` (FactureStatut, source
  unique) ; `Paiement`/`CommandePaiement`/`InitierPaiementRequete`/`PaiementReponse` (+`factureId`) ;
  `ServicePaiement` (règlement facture après succès) ; `GestionErreurs` ; Postman ; README ; RAPPORT.
- **Piège** : `RequeteCouverture` exige un montant, inconnu au moment de saisir la facture → introduction
  d'un record `ParametresPriseEnCharge` (type/taux/plafond/exclu, sans montant), le moteur combinant
  avec le TTC calculé. Évite un montant factice.
- **Piège run** : recharger le conteneur `payment` avec `docker compose up -d --no-build --force-recreate
  payment` après `docker build` (le tag pointe vers une nouvelle image) ; Flyway applique `V2` sur la
  base existante au démarrage.

---

# Module 5 (P5) — Paiement · Incrément P5.2b : Avoir + versionnage + signature

**Décisions propriétaire (écrites) :** avoir = **montant TTC complet** de la facture d'origine ;
signature **ON par défaut**. Aucune dépendance nouvelle (crypto JDK). Additif : rien de l'existant réécrit.

**G0.** L'existant P5.2a est propre (`ServiceFacturation.creer/enregistrerReglement`, hash SHA-256,
PDF/QR, `FactureStatut`, `UNIQUE(numero)`). P5.2b n'ajoute que des colonnes/tables et des endpoints.

**Livré (`services/payment/`, migration Flyway `V3`) :**
- **Versionnage** (§7.5) : `factures` gagne `version_numero`, `origine_facture_id`, `remplacee_par_id` ;
  `UNIQUE(numero)` → `UNIQUE(numero, version_numero)` ; statut **`REMPLACEE`** (ajouté à
  `@masante/shared` + Java). **Correction = nouvelle version immuable** ; l'ancienne passe `REMPLACEE`
  avec `remplacee_par_id`. Aucune facture modifiée en place.
- **Avoir / note de crédit** (§7.1) : table `avoirs` + `avoir_compteurs` (numéro `AV-{ETAB}-{exercice}-{seq}`
  sous verrou), PDF/QR dédié. Émis à chaque correction **et** annulation, montant = **TTC d'origine**.
- **Annulation** : `POST /invoices/{id}/annuler` → `ANNULEE` + avoir.
- **Signature** (§7.4 / CDC_10) : `ServiceSignature` **RSA-SHA256** (JDK, aucune dépendance). Clé privée
  **en mémoire** (substitut HSM/KMS — jamais dans le code ni en base, interdit CDC_00 §4) ; clé publique
  stockée par document → vérification même après redémarrage. Flag `SIGNATURE_ENABLED` (ON par défaut).
  Factures et avoirs signés à l'émission. `GET /invoices/{id}/verify-signature` recalcule le hash et
  vérifie la signature.
- **Endpoints** : `POST /invoices/{id}/corriger`, `POST /invoices/{id}/annuler`,
  `GET /invoices/{id}/versions`, `GET /invoices/{id}/credit-notes`, `GET /invoices/{id}/verify-signature`,
  `GET /credit-notes/{id}`, `GET /credit-notes/{id}/pdf`. Audit : `InvoiceCorrected`, `InvoiceCancelled`,
  `CreditNoteIssued`.

**Gates.** **G2 prouvé live** : facture v1 signée (`signatureValide:true`, SHA256withRSA) ; correction →
v2 (même numéro, version 2, TTC 25 000) + avoir `AV-…-000001` de 20 000 (TTC v1) ; v1 → `REMPLACEE`
(+`remplaceeParId`) ; lignée listée (v1 REMPLACEE + v2 EMISE) ; avoir signé + PDF `%PDF-` ; annulation v2
→ `ANNULEE` + avoir 25 000 ; correction d'une `REMPLACEE` → **409** ; **chaîne d'audit intègre**. **G3** :
tests Gradle verts (`ServiceSignatureTest` : signe/vérifie, altération détectée, désactivée = pas de sceau ;
versionnage/avoir prouvés live comme l'idempotence en P5.1). **G4** propriétaire OK (Swagger) → ✅ **P5.2b VALIDÉ G5 (2026-08-03)**.

- **Nouveaux** : `V3__avoir_versionnage_signature.sql` ; `ServiceSignature`, `SceauSignature`,
  `Avoir`, `AvoirCompteur`, leurs dépôts, `OperationFacture`, `VerificationSignature`,
  `AvoirIntrouvableException`, `AvoirController` ; DTO `Corriger/Annuler/AvoirReponse/`
  `VerificationSignatureReponse/OperationFactureReponse` ; `ServiceSignatureTest`.
- **Modifié** : `Facture` (+version/lignée/signature) ; `FactureStatut` (shared + Java, +REMPLACEE) ;
  `ServiceFacturation` (signe + corriger/annuler/versions/avoir/verify) ; `ServiceFacturePdf`
  (+avoir) ; `FactureController` ; `FactureReponse`, `FactureRepository` ; `GestionErreurs` ;
  `application.yml` (flag signature) ; Postman ; README ; RAPPORT.
- **Piège** : lambda `signer(...).ifPresent(s -> facture.apposerSignature(...))` puis
  `facture = repo.save(facture)` → « variable capturée non effectively final ». Ne pas réassigner :
  `repo.save(facture)` renseigne l'id sur l'instance (@UuidGenerator), la référence reste finale.

---

# Module 5 (P5) — Paiement · Incrément P5.3a : Wallet + double écriture

**Décision propriétaire (écrite) :** wallet core + double écriture **+ paiement d'une facture depuis le
wallet**. Aucune dépendance nouvelle. Additif.

**G0.** Aucun portefeuille n'existe (Laravel ni microservice) → construction propre dans `services/payment/`.

**Livré (`services/payment/`, migration Flyway `V4`) :**
- **Comptabilité en double écriture** (§6.3) : chaque opération produit **deux écritures** de somme nulle
  (`wallet_entries`, montant signé) ; le **solde n'est jamais stocké** = `SUM(montant)` des écritures.
  Comptes techniques `SYSTEME-CONTREPARTIE` (et wallet établissement) assurent la contrepartie des
  crédits/débits. **Invariant prouvé : somme globale des écritures = 0.**
- **Opérations** (§6.2) : `credit` (rechargement simulé), `debit` (refusé si insuffisant), `transfer`,
  `freeze`/`unfreeze` (§6.4). Écritures financières **idempotentes** (verrou Redis + unicité PG,
  `Idempotency-Key`) et **auditées** (`WalletCredited`/`WalletDebited`, chaîne P5.1).
- **Frontière** : contrôle de suffisance/état + calcul du solde = backend seul (`ReglesWallet` pur).
  Statut **`WalletStatut`** ajouté à `@masante/shared` (source unique).
- **Paiement d'une facture depuis le wallet** : `POST /wallets/{id}/pay-invoice` débite le patient,
  crédite le wallet de l'établissement et **solde la facture** (`enregistrerReglement`, EMISE→PAYEE)
  dans **une seule transaction**.
- Endpoints : `POST /wallets`, `GET /wallets/{id}` (+solde), `GET /wallets/{id}/entries`,
  `credit|debit|transfer|pay-invoice|freeze|unfreeze`.

**Gates.** **G2 prouvé live** : crédit 50 000 (rejeu même clé → toujours 50 000, aucun doublon), débit
20 000 → 30 000, débit>solde → **409**, gel → débit **409**, transfert 10 000 (source 20 000 / dest
10 000), **double écriture : solde wallet = somme écritures, total global = 0, 2 écritures/opération** ;
paiement facture depuis wallet → facture **PAYEE**, patient −10 000, établissement crédité, total global
resté 0 ; **audit intègre**. **G3** : tests Gradle verts (`ReglesWalletTest` : débit ok / insuffisant /
gelé / montant invalide ; double écriture prouvée live comme l'idempotence). **G4** propriétaire **OK
(Swagger)**. **G5 — module validé (2026-08-03).**

- **Nouveaux** : `V4__wallet.sql` ; `Wallet`, `WalletOperation`, `WalletEntry`, `WalletStatut`,
  `OwnerTypeWallet`, `TypeOperationWallet`, leurs dépôts ; `domain/wallet/` (`ReglesWallet` +
  exceptions) ; `ServiceWallet`, `DemandeOperationWallet`, `WalletIntrouvableException` ;
  `WalletController` + DTO ; `ReglesWalletTest`.
- **Modifié** : `packages/shared/.../enums` (WalletStatut) ; `GestionErreurs` ; RAPPORT.
- **Reporté P5.3b** : sécurité §6.4 (PIN/OTP/biométrie, **limites** par opération/jour/mois, gel sur
  suspicion), cashback/bonus, **rapprochement quotidien** automatique.

---

# Module 5 (P5) — Paiement · Incrément P5.3b-1 : Sécurité transactionnelle du Wallet

**Décisions propriétaire (écrites, AskUserQuestion 2026-08-04) :** P5.3b découpé en 4 sous-incréments,
**sécurité d'abord** ; hachage PIN via **BCrypt (`spring-security-crypto` seul — dépendance approuvée**,
pas `starter-security` qui verrouillerait l'auto-config) ; **biométrie côté device** (mobile patient),
« prête à activer » — le service ne gère que PIN/OTP/signature.

**G0.** Audit : `ReglesWallet` ne contenait que `verifierMontant`/`verifierDebit` ; `V4` sans PIN,
limites ni compteurs. Rien de sensible n'existait → construction additive.

**Frontière (réponse « aucune » au front).** PIN, OTP, **limites op/jour/mois** et signature sont
vérifiés **backend seul**. Le front transmet le secret, ne décide jamais.

**Livré (`services/payment/`, migration Flyway `V5`) :**
- **PIN wallet** (§6.4) : haché **BCrypt** (le clair n'est **jamais** stocké — interdit CDC_00 §4) ;
  définition/changement (`POST /wallets/{id}/pin`, ancien PIN exigé au changement) ; format 4–6 chiffres
  (`ReglesSecuriteWallet`). **Verrou temporaire** après `pin.max-essais` (défaut 3) pendant
  `pin.verrou-minutes` (défaut 15). **Piège corrigé** : le comptage des échecs est écrit par une
  transaction **propre `REQUIRES_NEW` qui retourne AVANT le `throw`** — sinon l'exception annulait
  l'incrément et le verrou ne s'accumulait jamais (bug vu au 1ᵉʳ test live : bon PIN après 3 échecs
  passait au lieu d'être 423).
- **OTP** (§6.4) : exigé au-delà de `otp.seuil` (défaut 100 000 FCFA) ; code à **usage unique** en
  **Redis** (TTL, haché) ; `POST /wallets/{id}/otp`. **Simulé (FT5)** : le code est renvoyé (SMS
  « prêt à activer »).
- **Limites de montant** (§6.4) : par **opération/jour/mois** = **données** (`wallet_limites`,
  surchargent les défauts de config ; `GET`/`PUT /wallets/{id}/limits`). Consommations jour/mois
  **dérivées du grand livre** (`WalletEntryRepository.debitsDepuis`) — aucun compteur stocké.
- **Signature d'opération** (§6.4) : chaque `wallet_operation` est signée (RSA-SHA256, réutilise
  `ServiceSignature` P5.2b) — colonne `signature`, « prête à activer ».
- **Câblage** : `debit`/`transfer`/`pay-invoice` passent par `ServiceSecuriteWallet.autoriserOperation`
  (PIN → limites → OTP) **avant** l'exécution ; le **rejeu idempotent ne redemande pas le PIN** (déjà
  autorisé). Crédit inchangé (entrant). PIN/OTP jamais loggés (`toString` masqué sur les DTO).

**Gates.** **G2 prouvé live** (curl) : débit sans PIN → **401** ; PIN posé → **204** ; crédit 200 000
sans PIN → **201** ; débit 6 000 bon PIN → **201, `signee:true`**, solde 194 000 ; mauvais PIN → **401** ;
**rejeu** (même clé, sans PIN) → **201 sans double débit** (solde inchangé) ; plafond opération 5 000 →
débit 6 000 → **422** ; **3 mauvais PIN → verrou, bon PIN ensuite → 423** ; OTP requis >100 000 sans OTP
→ **401**, OTP<seuil `requis:false`, débit 150 000 avec OTP valide → **201** ; **somme globale des
écritures = 0** (double écriture) ; nouvelles opérations **toutes signées** ; **audit intègre**.
**G3** : tests Gradle verts (`ReglesSecuriteWalletTest` : format PIN, limites op/jour/mois, seuil OTP,
plafond illimité). **G4** propriétaire **OK (Swagger)** → ✅ **P5.3b-1 VALIDÉ G5 (2026-08-04)**.

- **Nouveaux** : `V5__wallet_securite.sql` ; `WalletPin`, `WalletLimites` (+ dépôts) ;
  `ReglesSecuriteWallet` + exceptions (`Pin*`/`Otp*`/`LimiteDepassee`) ; `ServiceSecuriteWallet` ;
  DTO `DefinirPin`/`GenererOtp`/`Otp`/`Limites` ; `ReglesSecuriteWalletTest`.
- **Modifié** : `build.gradle` (spring-security-crypto) ; `application.yml` (défauts sécurité, données) ;
  `WalletOperation` (+signature) ; `WalletEntryRepository` (+`debitsDepuis`) ; `ServiceWallet` (câblage
  sécurité + signature) ; `WalletController` (+PIN/OTP/limites) ; `GestionErreurs` (401/423/422) ;
  DTO d'opérations (+pin/otp, `toString` masqué) ; `WalletOperationReponse` (+`signee`).
- **Reporté** : P5.3b-2 fraude + gel sur suspicion ; P5.3b-3 cashback/bonus ; P5.3b-4 rapprochement
  quotidien automatique.

---

# Module 5 (P5) — Paiement · Incrément P5.3b-2 : Détection de fraude + gel sur suspicion

**Décisions propriétaire (revue G1 écrite, 2026-08-04) :** architecture validée ; **corrections imposées
avant code** — **3 paliers** (pas un gel binaire ; contexte santé : une urgence médicale = paiements
rapprochés légitimes), **REQUIRES_NEW** pour gel/alerte/audit, **verrou pessimiste** englobant
évaluation+débit, anti-fuite du 409, motifs **JSONB** + snapshot des paramètres, auto-dégel **TTL**.

**Frontière.** Scoring + décision + gel = **backend seul**, par **règles déterministes** (pas d'IA qui
décide seule — CDC_00 §4). Seuils/poids = **données**. La détection **par IA** (géoloc, multi-comptes)
reste le futur `fraud-detection-service` (CDC_05) — **dette V1 assumée**.

**Contradiction technique résolue (le point délicat).** #1 (REQUIRES_NEW pour que le gel survive au
`throw`) et #2 (verrou pessimiste tenant la ligne wallet pendant l'évaluation) se **contredisent** : un
`REQUIRES_NEW` réécrivant la ligne verrouillée = **interblocage**. → **évaluation DANS la tx verrouillée**
de l'op (concurrence sérialisée, vélocité non contournable) ; sur GEL, **lever l'exception** (l'op
rollback, le verrou se relâche) puis **gel + alerte + audit dans une tx propre APRÈS** (catch hors tx,
`executerAvecFraude`). Même patron « écrire-après-throw » que le compteur PIN de 3b-1.

**Livré (migration `V6`) :**
- `ReglesDetectionFraude` **pur** : score = somme des poids des motifs déclenchés (`VELOCITE_ELEVEE`,
  `MONTANT_CUMULE_ANORMAL`, `ECHECS_PIN_REPETES`) → palier `NORMAL`/`ALERTE`/`CHALLENGE`/`GEL`.
- `ServiceDetectionFraude` : signaux **dérivés** (nb d'opérations sortantes/`compteSortantesDepuis`,
  cumul débité/`debitsDepuis`, échecs PIN/comptage d'audit `WalletPinEchec`) ; `evaluer` (readonly),
  `enregistrerAlerte` (ALERTE/CHALLENGE, même tx), `traiterSuspicion` (**REQUIRES_NEW** : gèle avec TTL,
  crée l'alerte, audit `FraudSuspected`+`WalletFrozen`, **idempotent** — pas d'alerte OUVERTE empilée).
- Câblage `ServiceWallet` : évaluation sous verrou après `verifierDebit` ; ALERTE → passe ; CHALLENGE →
  `securite.exigerOtp` si pas déjà exigé par le montant, puis passe ; GEL → `FraudSuspecteeException`.
- **Auto-dégel** : `wallets.gel_jusqu_a` (TTL, défaut 24 h) ; `verrouiller` auto-dégèle si expiré (audit
  `WalletUnfrozenAuto`). Gel manuel (admin) reste indéfini.
- **Anti-fuite** : `FraudSuspecteeException` → **409 générique**, `ChallengeRequisException` → **401
  générique** ; score/motifs uniquement en audit + `fraud_alertes`.
- **Alertes** : table `fraud_alertes` (score, `palier`, `motifs` JSONB, `parametres` JSONB **snapshot**,
  `montant_tente`, statut OUVERTE/REVUE) ; endpoints `GET /fraud-alerts`, `/fraud-alerts/wallet/{id}`,
  `POST /fraud-alerts/{id}/review` (revue ≠ dégel). **Index perf** : `wallet_operations(source,created_at)`,
  `audit_entries(ref_id,evenement,created_at)`.
- **Cumul** = opérations **abouties** seulement (une op bloquée/échouée ne crée aucune écriture).

**Gates.** **G2 prouvé live** : ALERTE (op3 passe + alerte) ; GEL (op4 **409 générique** + wallet GELE +
alertes GEL & ALERTE OUVERTE, op **non débitée**) ; CHALLENGE (401 sans OTP, OTP délivré, 201 avec OTP) ;
**auto-dégel** TTL expiré → op réactive (`WalletUnfrozenAuto`) ; revue OUVERTE→REVUE (ne dégèle pas) ;
somme globale des écritures **= 0** ; **audit intègre**. **Deux bugs corrigés au live** : (a) le garde
d'idempotence de `traiterSuspicion` skippait sur *toute* alerte OUVERTE → l'ALERTE de l'op3 empêchait le
GEL de l'op4 (corrigé : garde sur **wallet déjà GELE**) ; (b) l'auto-dégel, placé dans la tx de l'op, était
annulé quand l'op re-déclenchait un GEL (rollback) → **sorti en tx propre committée AVANT** l'op verrouillée
(`self.autoDegelSiExpire`). **G3** : `ReglesDetectionFraudeTest` (paliers, bornes strictes, motifs).
**G4** propriétaire **OK (Swagger)** → ✅ **P5.3b-2 VALIDÉ G5 (2026-08-04)**. Aucune dépendance nouvelle.

- **Nouveaux** : `V6__wallet_fraude.sql` ; domaine `fraud/` (`MotifFraude`, `PalierFraude`, `SignauxFraude`,
  `ParametresFraude`, `ResultatFraude`, `ReglesDetectionFraude`, `FraudSuspecteeException`) ;
  `ChallengeRequisException`, `StatutAlerteFraude`, `FraudAlerte` (+ dépôt) ; `ServiceDetectionFraude` ;
  `FraudController` + DTO (`FraudAlerteReponse` JSONB brut, `RevueAlerteRequete`) ; `ReglesDetectionFraudeTest`.
- **Modifié** : `application.yml` (seuils fraude = données) ; `Wallet` (+`gel_jusqu_a`, `gelerJusqua`,
  `gelExpire`) ; `WalletOperationRepository` (+`compteSortantesDepuis`) ; `EntreeAuditRepository`
  (+`compteEvenementDepuis`) ; `ServiceWallet` (éval sous verrou, `executerAvecFraude`, `autoDegelSiExpire`) ;
  `ServiceSecuriteWallet` (`exigerOtp`, `otpExigeParMontant`, `genererOtp` délivre toujours un code) ;
  `DemandeOperationWallet` (+otp/otpDejaVerifie, `toString` masqué) ; `GestionErreurs` (409/401 génériques).

- **Reporté** : P5.3b-3 cashback/bonus ; P5.3b-4 rapprochement quotidien ; fraude **IA** → CDC_05
  (`fraud-detection-service`) ; **multi-comptes** (N wallets → même bénéficiaire/device/IP) = dette CDC_05.

---

# Module 5 (P5) — Paiement · Incrément P5.3b-3 : Cashback (campagnes) + Bonus

**Décisions propriétaire (2 revues G1 écrites, 2026-08-04).** Cashback par **campagnes** (taux+période+
plafonds+budget) ; **pas d'ajustement** (cashback+bonus seulement). Revue 2 = 6 failles corrigées
**avant code** (voir [[cashback-reward-principes]] en mémoire) : rattachement campagne, **réversibilité**
(fuite payer→cashback→annuler), **anti-siphon** (plafonds par wallet + par jour), **acteur** de la
création monétaire, **résolution serveur** de la campagne, invariant solde. Revue 3 = 3 correctifs :
clé idempotence clawback **par remboursement**, en-tête acteur **non usurpable**, **arrondi** du clawback.

**Scope (Option A) :** bonus + moteur cashback complets livrés ; le **CRÉDIT** du cashback est **gaté OFF**
(`cashback.credit-enabled=false`) tant que le chemin de remboursement wallet (§11) qui déclenche le
clawback en même tx n'existe pas. OFF = **dry-run** (montant calculé, non crédité). Rien d'exploitable actif.

**Frontière.** Calcul (taux×base, plafonds) + résolution de campagne + décision = **backend seul**.
Taux (**bps entiers**, zéro flottant), période, plafonds, budget = **données** (campagnes). Le front
n'envoie que `operationSourceId` — il ne choisit ni la campagne ni la base.

**Livré (migration `V7`) :**
- `cashback_campagnes` (`type_operation_source`, `taux_bps`, `plafond_par_operation/_par_wallet/
  _par_wallet_par_jour` [0=illimité], `budget_total` [null=illimité], dates, `actif`, `cree_par`).
  **Index unique partiel** `UNIQUE(type_operation_source) WHERE actif` → **au plus une campagne active
  par type** (ambiguïté impossible ; conséquence : bascule = désactiver→créer).
- `wallet_operations` += `campagne_code` + `operation_source_id` (indexés). Types `CASHBACK`, `BONUS`,
  `CASHBACK_ANNULATION` ; contreparties `SYSTEME-CASHBACK`/`SYSTEME-BONUS` (double écriture).
- `ReglesCashback` pur : `calculer` (base×bps/10000 plancher, plafond, **rejette base<0**) ;
  `calculerClawback` (proportionnel, **Σ ≤ cashback d'origine**, le remboursement soldant reprend le
  **reliquat exact**).
- `ServiceRecompense` : campagnes (CRUD, acteur tracé) ; **bonus** actif (acteur obligatoire, audité) ;
  **cashback** gaté (résolution serveur par type d'op source, verrou pessimiste si budget/plafonds,
  contrôles budget/wallet/**jour keyé sur la date UTC de l'op source**, idempotence dérivée
  `cashback:{sourceId}`, dry-run si OFF) ; **clawback** (idempotence `cashback-annul:{remboursementId}`).
- Endpoints : `POST/GET /cashback-campaigns`, `/deactivate` ; `POST /wallets/{id}/cashback`,
  `/cashback/reverse` (acteur en-tête), `/bonus` (acteur en-tête + Idempotency-Key) ; `GET /rewards`
  (sous-soldes dérivés : cashback net, bonus).
- **Acteur** via en-tête `X-Acteur-Id` **posé par la passerelle** (jamais le corps ; absent → rejet 401) ;
  liage JWT « prêt à activer ».
- **Correctif invariant (#4)** : `debitsDepuis` & `compteSortantesDepuis` restreints aux types sortants
  **utilisateur** → un clawback (négatif) ne pollue ni les limites ni la fraude. Wallet en dette : ne
  peut pas dépenser (verifierDebit), un crédit ultérieur éponge d'abord (naturel via SUM).

**Gates.** **G3** : `ReglesCashbackTest` (calcul, plancher, plafond, base<0, clawback proportionnel/
reliquat/plafonné) + `CashbackFlagDefautTest` (défaut OFF). **G2 à prouver live** : campagnes+résolution+
dry-run OFF ; flag ON via env → crédit=taux×base, plafonds op/wallet/jour, budget épuisé→refus, clawback
proportionnel, **concurrence** (2 octrois parallèles fin de budget → total ≤ budget), non-pollution
fraude/limites, sous-soldes, somme=0, audit avec acteur. **Aucune dépendance nouvelle.**

**Dette** (`services/payment/DETTE_TECHNIQUE.md`) : activation crédit cashback = §11 (remboursement wallet
+ auto-clawback en même tx) ; #6 verrou inconditionnel ; #9 pas de CHECK sur `type`.

---

# Module 5 (P5) — Paiement · Incrément P5.3b-4 : Contrôle d'intégrité financière interne

**Recadrage propriétaire (revue G1 écrite, 2026-08-05).** Le CDC §6.3/§11 dit « rapprochement quotidien
automatique ». Mais un **rapprochement** confronte **deux sources indépendantes** ; sans passerelle
opérateur réelle (SIMULÉE) ni reversements (§11), il n'existe qu'**une** source (notre base). Ce qu'on
livre est donc un **auditeur d'intégrité INTERNE** (cohérence de la base avec elle-même) — nommé
honnêtement, pas « rapprochement » (voir [[controle-integrite-vs-rapprochement]] en mémoire). Le vrai
rapprochement 2 sources = **S11.x** : **aucun code opérateur écrit** (pas de branche contre un format
non vu = passif), seulement un **point d'extension documenté** (`docs/adr/ADR-014`).

**Frontière.** Tout le jugement = **règles pures** `ReglesControle` (backend seul) ; le rapport est une
**donnée**. **Lecture seule** sur les données financières ; **détection SEULE** — jamais de correction
(§11 : « écarts signalés, jamais corrigés »). **Snapshot** figé à un arrêté `T` (cut-off = fin de journée
UTC ; n'examine que `created_at < T`) → pas de faux positifs pendant l'écriture concurrente.

**Livré (migration `V8`) :**
- `controle_runs` (journée UTC **UNIQUE** → idempotent, arrêté T, statut OK/ECARTS, compteurs, durée) +
  `controle_ecarts` (type, sévérité, référence, montant attendu/constaté, `details` **JSONB** rejouable ;
  **aucune colonne « corrigé »** — le contrôle ne corrige jamais). FK `ON DELETE CASCADE`.
- `ReglesControle` pur (3 familles, 7 types d'écart) : **C1 double écriture** (op = 2 écritures Σ=0 ;
  Σ global = 0 ; solde ≥ 0 **sauf motif énuméré** `OwnerTypeWallet.SYSTEME` — comptes de contrepartie) ;
  **C2 facture↔règlement** (statut ↔ montant réglé **par rapport au reste à payer**, pas au TTC : une
  facture couverte est PAYEE dès le reste soldé ; + cross-grand-livre **directionnel** Σ paiements
  passerelle SUCCESS ≤ montant réglé — un règlement wallet ne crée pas de ligne `payments`) ;
  **C3 cashback** (consommé net ≤ budget ; Σ clawback ≤ origine).
- `RequetesControle` : agrégats **SQL natif** bornés `created_at < :avant`, `::bigint` sur les `sum()`
  (Postgres promeut en `numeric` sinon). `ServiceControleIntegrite` : orchestrateur read-only, purge
  idempotente par journée, persiste run+écarts, **audite** le run (chaîne de hachage existante).
- Déclenchement **automatique** : `@Scheduled` quotidien (horaire = **donnée** `INTEGRITE_PLANIF_CRON`,
  contrôle la veille) **+** endpoint manuel `POST /api/v1/integrity-checks/run?date=` (preuve).
  `GET /integrity-checks` (liste) + `GET /{runId}` (détail). Enums taxonomie **backend-only** (promotion
  `@masante/shared` quand l'écran admin les consommera — ADR-014).
- **Seedeur d'anomalies** (`POST .../dev/seed-anomalies`, **gaté OFF** `INTEGRITE_DEV_SEED`, **404** sinon) :
  injecte 6 anomalies isolées → **prouve que chaque contrôle détecte** (un run vert sur données saines ne
  prouve rien s'il peut être vert parce que vide).

**Monnaie / arrondi.** Montants **entiers XOF** (déjà `long`/`BIGINT` — pas de sous-unité ; pas de
migration). La double écriture porte la **même** valeur arrondie sur ses 2 jambes → **Σ=0 exact** par
construction (le seul arrondi, cashback `bps/10000`, est planché et identique des deux côtés).

**Gates.** **G3** : `ReglesControleTest` (chaque contrôle détecte son anomalie + reste vert sur sain ;
SYSTEME négatif toléré vs PATIENT écart ; PAYEE couverte OK vs sous-réglée écart) ; build image Gradle
vert (`gradle clean build` exit 0). **G2 live prouvé** (curl) : Flyway V8 appliqué ; run sur données
existantes = **OK, 0 écart** (vrai vert, données P5.1→P5.3b-3) ; après **6 anomalies injectées** =
**ECARTS, 7 écarts** (les 7 types, montants attendus/constatés exacts) ; **idempotent** (rejeu → 1 run/
journée, pas de doublon) ; détection seule. **Aucune dépendance nouvelle.**

**Dette / reporté (ADR-014).** Rapprochement **2 sources** (relevé opérateur ↔ base ↔ reversements) =
**S11.x** : format pivot settlement, point d'insertion « sources d'un run », taxonomie écarts future
(`MONTANT_DIVERGENT`/`MANQUANT_COTE_OPERATEUR`/`MANQUANT_COTE_PLATEFORME`/`DOUBLON`/`DECALAGE_DATE`).
Classé **« conçu, point d'extension documenté »**, PAS « prêt à activer ». Intégrité de la **chaîne
d'audit** = contrôle **sécurité** distinct (hors périmètre financier, frontière de test).

- **Reporté ensuite** : rapprochement 2 sources §11 (S11.x), cartes §5, reversements §11, fraude IA CDC_05.
