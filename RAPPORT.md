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

**Reste (étape B, après « backend N2 validé »)** : depuis « Mes rendez-vous », action « Payer » (choix du mode) →
écran **Reçu** avec le QR de check-in (`react-native-qrcode-svg`), référence, montant, mode, statut, mention « à
présenter à l'accueil — ne donne pas accès au dossier ».
