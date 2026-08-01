# Handoff — MaSanté (IVOIRSANTÉ)

> Point de reprise du travail en cours. Dernière mise à jour : **2026-07-04**. Branche : `master`.

## Objectif

Ajouter au **Module 2 (Carnet de santé familial)** l'incrément **F2.10 → F2.13**, de façon **robuste**
(données médicales sensibles, loi 2013-450, OWASP). Méthode imposée : pour chaque fonctionnalité, analyse
approfondie → proposition → discussion/validation → implémentation → **documentation dans `RAPPORT.md`** →
barrières de validation backend (étape A) puis frontend (étape B).

Périmètre de l'incrément :
- **F2.10** — Documents médicaux importés (import universel sécurisé).
- **F2.11** — Contacts d'urgence par membre.
- **F2.12** — Notes & observations médicales.
- **F2.13** — Traçabilité de la provenance (`source`) sur les tables de dossier existantes.

## État actuel

| Lot | État |
|---|---|
| Couche données F2.10–F2.13 (migrations + modèles) | ✅ **Implémentée, testée, COMMITÉE** (`bf6bc42`) |
| Backend runtime **F2.11 / F2.12** (contrôleurs + routes) | ✅ **Validé & COMMITÉ** (`24a8938`, le 2026-07-02) |
| Frontend **F2.11 / F2.12** (étape B, moteur générique carnet) | ✅ **Validé & COMMITÉ** (`0a24ba4`, le 2026-07-02) |
| **Révision F2.11** (2 contacts + 15 membres) — backend étape A | ✅ **Validé & COMMITÉ** (`799cd9d`, le 2026-07-03) |
| **Révision F2.11** — frontend étape B (**écran dédié** 2 blocs) | ✅ **« #1 validé » & COMMITÉ** (`7fe1eca`, le 2026-07-03 ; delta backend `drop_telephone_secondaire` inclus) |
| **F2.10** backend étape A (upload/chiffrement/antivirus/download) | ✅ **Validé & COMMITÉ** (`7001aea`, le 2026-07-03 ; suite 56/56) |
| **F2.10** frontend étape B (Expo : picker/compression/liste/visionneuse) | ✅ **« F2.10 validé » & COMMITÉ** (`bb59205`, le 2026-07-04 ; tsc/doctor OK) |
| **F2.13** provenance `source` (backend A + frontend B, pastille 3 origines) | ✅ **« F2.13 validé » & COMMITÉ** (`7435471`, le 2026-07-04 ; suite 59/59, tsc OK) — **clôt l'incrément F2.10→F2.13** |
| **F2.3** carte CMU numérique (backend A + frontend B) | ✅ **« F2.3 validé » & COMMITÉ** (`4377440`, le 2026-07-04 ; suite 65/65, tsc OK). N° CMU masqué (`$hidden`+accessor), QR CMU signé autonome, palier vérifié stubbé (flag dev) |

Tests locaux F2.11/F2.12 (via curl/HTTP sur `http://localhost:8000`, tous ✅) :
création, **invariant « un seul contact principal »**, validation téléphone CI (422), **note append-only** (PUT → 405),
**auteur injecté serveur** (le client ne peut pas usurper l'auteur), **chiffrement du `contenu`** en base,
**soft-delete** (liste vide après suppression), **anti-IDOR** (autre compte → 403). Données de test nettoyées.

## Ce qui a changé

### Commité (`bf6bc42` — couche données)
- Migrations : `2026_07_02_000001_create_documents_medicaux_table.php`,
  `..._000002_create_contacts_urgence_table.php`, `..._000003_create_notes_observations_table.php`,
  `..._000004_add_source_to_dossier_tables.php` (colonne `source` sur `antecedents`/`ordonnances`/`resultats_analyses`).
- Modèles : `DocumentMedical`, `ContactUrgence`, `NoteObservation` + relations `hasMany` sur `MembreFamille`.
- `RAPPORT.md` (racine) + les 2 docs de spec (`docs/Specification_Module2_F2-10_a_F2-13_MaSante.md`, `docs/Analyse_Delta_RDV_MaSante.md`).

### Commité (`799cd9d` — révision F2.11 « 2 contacts » + 15 membres, backend étape A)
- **Nouveaux** : migration `2026_07_03_000001_drop_email_from_contacts_urgence.php`, `tests/Feature/ContactUrgenceTest.php` (7 tests).
- **Modifié** : `ContactUrgence` (email hors `$fillable`, hook `deleted` = promotion du secondaire) ;
  `ContactUrgenceController` (`lien_parente` `Rule::in` 15 valeurs, `est_principal` non client, `store()` plafond 2 +
  téléphone distinct + rôle par ordre, `update()` distinct) ; `CarnetSectionController::reglesPour` → `protected` ;
  `StoreMembreRequest::MAX_MEMBRES` 5→15 (+ test membre) ; `RAPPORT.md`. **Suite 48/48 (148 assertions).**

### Commité (`7fe1eca` — révision F2.11 frontend étape B, « #1 validé » le 2026-07-04)
Écran **sur-mesure** (maquette) qui remplace le moteur générique pour les contacts, DS conservé.
- **Nouveaux** : `src/screens/ContactsUrgenceEcran.tsx` (2 blocs dépliables Premier/Second contact, menu déroulant
  lien de parenté via Modal défaut « Papa », `+225` masqué = saisie locale 10 chiffres, 1 bouton « Ajouter » collant,
  POST/PUT 1er puis 2e) ; `app/(app)/membres/contacts-urgence/[id].tsx` (route).
- **Modifié** : `registre.ts` (contacts hors `SECTIONS` ; `LIEN_PARENTE` exporté) ; fiche membre (ligne dédiée) ;
  `types/membre.ts` + `api/membres.ts` (`MAX_MEMBRES` 5→15). **Delta backend inclus** : migration
  `..._000002_drop_telephone_secondaire_from_contacts_urgence` + `$fillable` + règle (un seul numéro/contact).
- Photo contact **désactivée « bientôt »** → arrive avec F2.10 (ne pas dupliquer l'infra d'upload).

### Commité (`7001aea` — backend F2.10 documents importés, étape A, le 2026-07-04)
- **Config** : `config/masante.php` (`antivirus.enabled` défaut `false`, `upload.max_ko`=20 Mo, `upload.mimetypes` liste
  blanche 12 types) ; **disque privé** `documents` dans `config/filesystems.php` (`storage_path('app/documents')`, hors `public/`).
- **Service** `DocumentStorageService` (chiffrement `Crypt` au repos, nom UUID, `hash_sha256`, MIME finfo).
- **FormRequest** `StoreDocumentRequest` (`authorize` → `MembreFamillePolicy::update` ; `mimetypes:` réel + `max`).
- **Job** `ScanDocumentJob` (stub `sain` en dev ; `clamdscan` en prod ; **fail-closed** = reste `en_attente` si indispo).
- **Contrôleur** `DocumentMedicalController` : `index/store/show/destroy` ; `uploaded_by_user_id` serveur ; **download 423**
  si `!= sain` ; `destroy` = soft-delete (rétention, blob conservé) ; **pas de route `update`**. Dispatch **sync** en dev.
- **Routes** `membres/{membre}/documents` (dans `routes/api.php`). **Écart assumé** : pas de Policy dédiée (portée par le membre).
- Constantes `DocumentMedical::CATEGORIES` / `::SOURCES`. Tests `DocumentMedicalTest` (8). **Suite 56/56 (178 assertions).**

### ⏳ NON COMMITÉ — frontend F2.10 étape B (à figer au « F2.10 validé »)
Vérifs vertes : **`tsc` 0 · `expo install --check` OK · `expo-doctor` 18/18**.
- **Nouveaux** : `src/types/document.ts` (types, `CATEGORIES` libellés+icônes, `STATUT_PRESENTATION`) ;
  `src/api/documents.ts` (liste/suppr axios ; **import multipart via `createUploadTask` legacy** + progression ;
  **download `File.downloadFileAsync`** nouvelle API + en-têtes Bearer → URI cache) ;
  `src/documents/selection.ts` (photo/galerie/fichier + **compression 3G** `ImageManipulator.manipulate()...`, si >1600 px) ;
  `src/screens/DocumentsEcran.tsx` (liste **groupée par catégorie**, badges statut, ouverture via `expo-sharing`, suppr) ;
  `src/screens/ImportDocumentEcran.tsx` (3 sources, dropdown catégorie, titre, **barre de progression**) ;
  `src/utils/fichiers.ts` (format taille) ; routes `app/(app)/membres/documents/[id].tsx` + `.../importer/[id].tsx`.
- **Modifié** : fiche membre `[id].tsx` (ligne « Documents médicaux ») ; `app.config.ts` (plugin `expo-image-picker`) ;
  `RAPPORT.md`. **`package.json`** : +5 paquets (`expo-document-picker`, `expo-image-picker`, `expo-image-manipulator`,
  `expo-file-system`, `expo-sharing`) **et `react-dom` réaligné 19.2.7 → 19.1.0** (le 1er `expo install` l'avait cassé →
  bloquait les installs suivantes ; réalignement sur la version SDK 54).
- **Date document non saisie** dans l'import : réservée au futur **sélecteur jour/mois/année** uniforme (item différé) ;
  champ toujours supporté par l'API.
- ⚠️ Au commit, inclure **aussi** `package-lock.json`. Guide de test Expo Go fourni dans le dernier message avant `/clear`.

### Décisions actées
- **F2.13** : colonne `source` **distincte** de `added_by` (axes orthogonaux : provenance ≠ auteur de saisie).
- **Robustesse** : schéma « robuste complet » retenu (audit `uploaded_by`, `hash_sha256`, `statut_antivirus`,
  `softDeletes`, etc.) ; `categorie` documents = **superset à 8 valeurs**.
- **F2.12** : append-only + auteur serveur ; **journal d'audit FT6 des écritures documenté, non implémenté**
  (cohérence avec les autres sections ; sera fait avec le module d'audit global).
- **Différé Modules 3/4** : `auteur_agent_id` (notes) — table `agents_garde` inexistante.

## Endpoints livrés (auth Bearer `auth:sanctum`)
- `GET|POST /api/v1/membres/{membre}/contacts-urgence` · `GET|PUT|PATCH|DELETE .../contacts-urgence/{id}`
- `GET|POST /api/v1/membres/{membre}/notes-observations` · `GET|DELETE .../notes-observations/{id}` (pas de `PUT/PATCH`)
- `GET|POST /api/v1/membres/{membre}/documents` · `GET|DELETE .../documents/{id}` (pas de `PUT` ; `GET/{id}` = **download déchiffré**, 423 si `!= sain`)

## Environnement / commandes utiles (PowerShell)
- PHP : **toujours** `C:\wamp64\bin\php\php8.3.28\php.exe` avec `$env:XDEBUG_MODE="off"` (jamais le `php` global 8.5).
- Serveur local : `& "C:\wamp64\bin\php\php8.3.28\php.exe" artisan serve --host=127.0.0.1 --port=8000`
- Générer un token de test : `... artisan tinker --execute="echo App\Models\MembreFamille::find(1)->user->createToken('local')->plainTextToken;"`
- Membre de test présent : `id=1` (propriétaire = user `id=2`).
- Mobile (`ivoirsante-mobile/`) : `npx expo start -c` (cache vidé après changement URL/`app.config`) ;
  `npx tsc --noEmit` ; `npx expo-doctor` (doit rester 18/18) ; deps **uniquement** via `npx expo install <pkg>`.
- Test F2.10 sur téléphone : Ngrok requis (`localhost` injoignable depuis Expo Go), URL reportée dans les **deux** `.env`.

## Plan validé (2026-07-03) — nouveaux docs de modification intégrés

Le `/clear` a apporté 4 docs nouveaux dans `docs/` (`modification.txt`, `Modification_F2-3_CMU`,
`Securite_IVOIRSANTE_2.docx`, `Note_Continuite_Acces_Dossier.docx`). Après cartographie, l'utilisateur a
**validé l'ordre suivant**.

**Phase A — finir le Module 2 (MAINTENANT, dans cet ordre) :**

1. ✅ **TERMINÉ** — Réviser F2.11 contacts d'urgence (`modification.txt`) : e-mail retiré, **exactement 2 contacts
   distincts** (1er = principal auto), **liste déroulante lien de parenté**, un seul numéro/contact, **famille 15 max**.
   Backend `799cd9d` + frontend écran dédié `7fe1eca`.
2. ✅ **TERMINÉ** — **F2.10 Documents importés** : backend étape A (`7001aea`) + frontend étape B
   (`bb59205`, « F2.10 validé » le 2026-07-04).
3. ✅ **TERMINÉ** — **F2.13 badge provenance** : backend A + frontend B (`7435471`, le 2026-07-04). `source`
   inscriptible/validée sur les 3 sections (défaut modèle `patient`) ; pastille 3 origines (patient atténué,
   medecin/structure officiels) sur cartes de dossier + documents. **Incrément F2.10→F2.13 CLOS.**
4. ✅ **TERMINÉ** — **Carte CMU numérique F2.3** (`4377440`, le 2026-07-04). N° masqué (jamais exposé),
   QR CMU signé autonome (sans n°/matricule, n'ouvre pas le dossier), palier vérifié stubbé (flag dev).
5. **Profil enrichi (item optionnel)** — audit fait : schéma `membres_famille` porte déjà tous les champs.
   - **A. Sélecteur de date uniforme** : ✅ **« A validé » & COMMITÉ** (`cdb3310`, le 2026-07-05). Wheel picker
     custom (`DateWheelPicker` + `DateField`), molette 3 colonnes, ScrollView natif + Animated 2D (scale/opacité,
     **pas de rotateX** = trop lourd sur entrée de gamme). Câblé MembreForm/CarnetSectionForm/ImportDocument.
     `datetimepicker` testé puis retiré (net nul). tsc OK.
   - **B. Photo de profil du membre** : ✅ **« B validé » & COMMITÉ** (backend étape A + frontend étape B,
     le 2026-07-06). Photo chiffrée au repos (disque privé `avatars`, chaîne F2.10 sans antivirus) ; `photo_url`
     `$hidden`+hors `$fillable`, accessor booléen `a_photo` ; routes `POST|GET|DELETE /membres/{membre}/photo`
     (show déchiffré, `no-store`) ; front : avatar photo/initiales + badge appareil, menu Prendre/Choisir/Supprimer,
     recadrage carré ~512 px. Suite **71/71** (245 assertions), tsc OK. **Clôt la Phase A du Module 2.**

**Phase B — EN COURS (cadrage validé) :**

- **B1 — Auth durci : ✅ TERMINÉ & COMMITÉ le 2026-07-07.** Backend étape A (mot de passe oublié OTP 3 étapes +
  preuve durcie date de naissance / branche CMU dormante + changement connecté + politique MDP forte unifiée
  `PasswordPolicy` + `NotCompromisedPassword` fail-open + révocation tokens ; suite 80/80). Frontend étape B
  (barre de force `MotDePasseForce`, écrans oublié/réinitialiser, changer MDP ; tsc OK). Décisions : MDP fort,
  DDN, bcrypt. **+ 2 correctifs annexes commités** : 401 JSON invité (`bootstrap/app.php`) et photo membre
  robuste Android (`telechargerPhoto` via téléchargement authentifié au lieu de `<Image headers>`).
- **B2 — Verrou applicatif : ✅ TERMINÉ & COMMITÉ le 2026-07-07** (frontend seul). PIN 6 chiffres haché SHA-256+sel
  (secure-store), biométrie `expo-local-authentication`, grâce 2 min, re-verrouillage arrière-plan, anti-force brute
  5→30 s/1 min/5 min. `VerrouGate` sur fiches membres + « Mes rendez-vous » ; écran Sécurité (opt-in). +2 deps
  (expo-local-authentication, expo-crypto). tsc OK, doctor 18/18.
- **B3 — Délégation d'accès : ✅ TERMINÉ & COMMITÉ le 2026-07-07.** Backend étape A `68cf008` (table `delegations`,
  4 endpoints inviter/accepter/révoquer/lister, `generateQr` ouvert au délégué actif, trace `genere_par_delegue_id`,
  gate titulaire vérifié par flag ; suite 94/94). Frontend étape B `59dbfde` (écran « Délégués » titulaire, écran
  « Partages reçus » délégué + génération QR sous verrou B2 ; + fix route `parametres/_layout.tsx`).
- **→ Phase B COMPLÈTE (B1 + B2 + B3).**
- **Bloqués (NE PAS anticiper)** : bris de glace (M4/M5), rappels/notes source « médecin » (session QR médecin M3/M4).

**Dette technique :** ~~`SafeAreaView` déprécié~~ → ✅ **RÉSOLU le 2026-07-07** (`96f7cda`) : migration vers
`react-native-safe-area-context` (`Screen.tsx` + `carte.tsx` + `itineraire.tsx`), `edges={['top','bottom']}`,
compensation Android manuelle supprimée. Re-test visuel validé.

**Reste à cadrer AVEC LE DIRECTEUR avant tout code (rappel) :**

- **Auth** : refonte inscription (barre de force MDP), mot de passe oublié (OTP 3 étapes) + récupération durcie
  (`Securite_2` : OTP + date naissance / fragment CMU-CNI), **verrou applicatif** biométrie/PIN sur sections sensibles.
- **Délégation d'accès** (table `delegations`, 4 endpoints) — `Note_Continuite`, prio MOYENNE après le socle.
- **Bris de glace** (accès urgence, RBAC, audit) — livrable Module 4/5.
- **Rappels source « système »/« médecin » + notes médecin** — dépendent de la **session QR médecin en écriture** (M3/M4).

Tenir `RAPPORT.md` à jour à chaque fonctionnalité (méthode de travail imposée).

## Attention / pièges
- Ne **pas** lancer deux `artisan migrate` en parallèle (course déjà rencontrée). Commandes destructives
  (`migrate:fresh`, etc.) : **jamais sans accord explicite**.
- `artisan serve` est mono-thread : les séquences curl longues peuvent dépasser les timeouts (fractionner).
- Ne pas toucher au frontend tant que le backend n'est pas validé (barrières du workflow).
- **`expo install` en échec ERESOLVE** : cause rencontrée = `react-dom` monté en 19.2.7 (exige react 19.2.7) alors que
  react est en 19.1.0. Corrigé en réalignant `react-dom` sur **19.1.0** (`npx expo install react-dom <autre-pkg>`).
  Si ça se reproduit, vérifier `react`/`react-dom` avant d'ajouter des paquets.
