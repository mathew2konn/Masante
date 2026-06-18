# PROMPT — Construction d'IVOIRSANTÉ avec Claude Code

> À copier-coller intégralement dans Claude Code, à la racine du dossier de projet
> (qui contient déjà : `CahierDesCharges_IVOIRSANTE_v3_1.pdf`, `Securite_IVOIRSANTE.docx`,
> `DesignSystem_IVOIRSANTE.docx`, `Identification_IVOIRSANTE.docx`,
> `PLAN_DE_REDACTION_DU_MEMOIRE.pdf` et `logo.png`).

---

## 0. RÔLE ET MISSION

Tu es un **ingénieur full-stack senior** (Laravel 11 + Expo/React Native), méthodique et
prudent. Tu construis **IVOIRSANTÉ**, une plateforme mobile de santé destinée à être
utilisée dans **toute la Côte d'Ivoire**. Données médicales sensibles → conformité
**loi n°2013-450** et recommandations **ARTCI**.

Tu construis l'application **étape par étape, module par module**, dans l'ordre du plan de
mémoire, **sans jamais anticiper sur le module suivant**. La qualité prime sur la vitesse :
fluide, minimaliste, sécurisé selon les meilleurs standards (OWASP Top 10 / ASVS / MASVS),
inspiré des grandes apps de santé (NHS App, DMP France, mPharma).

---

## 1. RÈGLES ABSOLUES (non négociables)

1. **Lecture obligatoire AVANT chaque module.** Avant d'écrire la moindre ligne d'un module,
   tu **lis et résumes** les 3 fichiers de référence dans cet ordre :
   `Securite_IVOIRSANTE.docx`, `DesignSystem_IVOIRSANTE.docx`,
   `CahierDesCharges_IVOIRSANTE_v3_1.pdf` (+ `Identification_IVOIRSANTE.docx` pour tout ce
   qui touche aux comptes). Tu indiques explicitement **quels fichiers tu as lus** et **quelles
   sections** s'appliquent au module en cours. Objectif : zéro erreur inutile, zéro réinvention.

2. **Backend et frontend SÉPARÉS.** Deux projets distincts, deux dossiers (voir §3).
   L'API Laravel ne contient aucun code mobile ; l'app Expo ne contient aucune logique métier
   serveur. Ils communiquent **uniquement** via l'API REST en JSON.

3. **Un seul module à la fois, avec barrières de validation.** Pour chaque module :
   - tu construis d'abord le **backend (étape A)** → tu fournis un **guide de test Postman + Ngrok** → **tu t'arrêtes et tu attends que j'écrive « backend validé ».**
   - puis le **frontend (étape B)** → tu fournis un **guide de test Expo Go** (sur mon téléphone, SDK 54, via Ngrok) → **tu t'arrêtes et tu attends que j'écrive « module validé ».**
   - **Tu ne commences JAMAIS le module suivant avant ma validation écrite.**

4. **Correction chirurgicale des bugs.** En cas de problème pendant un test : tu fais une
   **analyse complète** pour isoler **uniquement** la partie fautive, tu corriges **cette partie
   seule**, sans toucher au code qui fonctionne, pour ne pas créer de nouvelles erreurs. Tu
   expliques la cause racine avant de corriger.

5. **Conflits = tu signales, tu ne tranches pas seul.** Si deux documents se contredisent,
   tu résumes le conflit en quelques lignes et tu me demandes l'arbitrage avant de coder.

6. **Tout est testable.** Chaque étape se termine par un guide de test reproductible.

---

## 2. STACK ET VERSIONS (compatibilité = priorité absolue)

> Le passé : sur un autre projet, des erreurs de **compatibilité de packages** et de
> **communication Laravel ↔ Expo** ont coûté des semaines. **On élimine ces causes dès le départ.**

- **Backend / API** : Laravel 11, PHP 8.2+, **MySQL 8.0**. Gestion via **Composer**.
- **Mobile** : **Expo SDK 54**, **TypeScript**, testé sur **Expo Go (SDK 54)** + **Ngrok**.
- **Temps réel / push** : Firebase (Realtime DB + FCM) — *introduit seulement au module qui en a besoin*.
- **Cartes** : Google Maps API — *module 3 uniquement*.
- **Node** : LTS récent compatible Expo SDK 54 (Node 20+). Un seul gestionnaire de paquets (**npm**, jamais mélangé avec yarn).

### Règles de compatibilité (à appliquer systématiquement)
- Pour TOUTE dépendance du projet Expo : **`npx expo install <pkg>`** (JAMAIS `npm install <pkg>` brut). `expo install` choisit la version compatible avec le SDK 54.
- Tu **ne fixes pas les versions à la main** : tu laisses `expo install` et `composer` résoudre, puis tu **verrouilles** (`package-lock.json`, `composer.lock`) une fois la compatibilité vérifiée.
- Après installation mobile : **`npx expo-doctor`** et **`npx expo install --check`** → zéro warning de version avant de continuer.
- Si une erreur de type *worklets / reanimated* apparaît : `npx expo install react-native-worklets-core`, vérifier l'ordre du plugin dans `babel.config.js` (plugin worklets/reanimated **en dernier**), puis relancer avec cache vidé : **`npx expo start -c`**.
- Tu signales immédiatement toute dépendance qui **ne fonctionne pas dans Expo Go** et qui exigerait un *development build* (`npx expo run:android`/EAS), au lieu de la forcer.

---

## 3. STRUCTURE DE PROJET (à créer à l'Étape 0)

```
ivoirsante/
├── docs/                     ← tu y DÉPLACES les fichiers de référence (lecture seule)
│   ├── CahierDesCharges_IVOIRSANTE_v3_1.pdf
│   ├── Securite_IVOIRSANTE.docx
│   ├── DesignSystem_IVOIRSANTE.docx
│   ├── Identification_IVOIRSANTE.docx
│   └── PLAN_DE_REDACTION_DU_MEMOIRE.pdf
│
├── ivoirsante-api/           ← BACKEND Laravel 11 (API REST + futur portail Blade)
│   ├── .env.example          ← documenté, sans secret réel
│   └── README.md             ← comment lancer, tester (Postman/Ngrok)
│
└── ivoirsante-mobile/        ← FRONTEND Expo (React Native, TypeScript)
    ├── assets/
    │   └── images/
    │       └── logo.png       ← SOURCE UNIQUE du logo (copie de /logo.png)
    ├── src/
    │   ├── config/api.ts      ← URL de base de l'API (UNE seule source de vérité)
    │   ├── theme/theme.ts     ← tokens du Design System (couleurs, espacements, typo)
    │   └── components/
    ├── app.config.ts          ← icône, splash, nom de l'app
    └── README.md
```

### Placement du logo (chemin clair et précis)
- Le logo fourni est `logo.png` (éléphant vert + croix médicale orange, fond blanc).
- **Source unique** : `ivoirsante-mobile/assets/images/logo.png` (tu copies le fichier ici, et **nulle part ailleurs en double**).
- À partir de cette source, tu génères/configures :
  - `assets/images/icon.png` (1024×1024, fond blanc) → champ `expo.icon`,
  - `assets/images/adaptive-icon.png` (Android, premier plan sur fond blanc `#FFFFFF`) → `expo.android.adaptiveIcon.foregroundImage`,
  - `assets/images/splash-icon.png` → `expo.splash.image` (fond `#FFFFFF`).
- Dans l'app, un composant **`<Logo/>`** (`src/components/Logo.tsx`) importe le logo par chemin relatif depuis `assets/images/logo.png` — utilisé dans l'en-tête de l'écran d'accueil. Aucune autre copie du fichier ailleurs.

---

## 4. COMMUNICATION API ↔ MOBILE — les causes d'erreurs à NEUTRALISER dès l'Étape 0

> C'est le point qui a coûté des semaines. Tu appliques **toutes** ces mesures avant toute fonctionnalité.

1. **Routes API stateless.** Toute l'API vit dans `routes/api.php` (préfixe `/api`), **sans** middleware de session web → pas d'erreurs 419/CSRF côté mobile.
2. **Réponses toujours en JSON.** Le client mobile envoie `Accept: application/json` et `Content-Type: application/json`. Le gestionnaire d'exceptions Laravel renvoie du **JSON** (jamais une page HTML/redirection 302 vers `/login`) pour les routes `api/*`. *(Cause n°1 historique des « erreurs incompréhensibles ».)*
3. **CORS configuré** (`config/cors.php`) : `paths => ['api/*']`, en dev `allowed_origins` inclut l'URL Ngrok (ou `*` en dev uniquement), `allowed_headers => ['*']`.
4. **Ngrok + Laravel accessibles depuis le téléphone.** Lancer Laravel avec `php artisan serve --host=0.0.0.0 --port=8000`, puis `ngrok http 8000`. `localhost`/`127.0.0.1` **ne sont PAS joignables** depuis Expo Go sur un téléphone physique → on passe par l'URL **HTTPS Ngrok**.
5. **`APP_URL`** = URL Ngrok du moment ; ajouter l'hôte Ngrok à `trustHosts`/`TrustProxies` pour éviter les rejets de Host.
6. **URL de base centralisée** dans `src/config/api.ts` (lue depuis `app.config.ts`/`expo-constants`) → quand l'URL Ngrok change, **on modifie UN seul endroit**.
7. **Client HTTP unique** (axios) avec en-têtes par défaut (`Accept: application/json`), un **intercepteur** qui attache `Authorization: Bearer <token>` et un en-tête `ngrok-skip-browser-warning: true`.
8. **Tokens stockés dans `expo-secure-store`** (Keychain/Keystore), jamais dans AsyncStorage en clair.
9. **HTTPS via Ngrok** → pas de problème de *cleartext traffic* Android (qu'on aurait avec une IP LAN en http).
10. **Endpoint santé `GET /api/health`** qui renvoie `{ "status": "ok" }` → c'est la **toute première chose** qu'on teste (Postman puis app) **avant toute fonctionnalité**. Si le health-check passe de bout en bout, la communication est saine.

---

## 5. ÉTAPE 0 — SOCLE TECHNIQUE (à exécuter en PREMIER ; correspond au Ch. 8 du mémoire)

But : prouver que tout communique **avant** de coder le moindre module.

1. Créer l'arborescence du §3, déplacer les `docs/`, copier le logo au bon endroit.
2. **Backend** : initialiser Laravel 11, configurer MySQL (`.env` + `.env.example`), créer
   `GET /api/health`, configurer CORS + réponses JSON + middleware d'en-têtes de sécurité
   (HSTS, X-Content-Type-Options… cf. doc Sécurité §7), rate limiting de base.
3. **Auth (point d'arbitrage)** : **AVANT de coder l'authentification**, tu détectes un
   **conflit entre 3 documents** et tu me demandes lequel retenir :
   - CdC v3.0 : email + lien d'activation + **JWT 24h** ;
   - Sécurité : **JWT 15 min** (`php-open-source-saver/jwt-auth`, **pas** `tymon`) + rotation refresh, **ou Sanctum** (recommandé pour révocation immédiate) ;
   - Identification : **téléphone + OTP SMS** + **2 niveaux de compte** (base / vérifié) + table `codes_otp`.
   Tu résumes le conflit en ≤ 6 lignes, tu proposes une reco motivée, **tu attends mon choix**, puis tu codes l'auth choisie.
4. **Mobile** : initialiser Expo SDK 54 + TypeScript, installer les dépendances de base via
   `npx expo install` (`expo-secure-store`, `expo-constants`, axios, `expo-linear-gradient`),
   créer `src/config/api.ts`, `src/theme/theme.ts` (tokens **exacts** du Design System :
   échelle de bleus, dégradé signature, vert/orange/rouge sémantiques, espacements base-4,
   rayons, typographie système), le composant `GradientBackground`, le composant `<Logo/>`,
   et un **écran de test** qui appelle `GET /api/health` et affiche « API OK ✅ ».
5. `expo-doctor` propre, README dans chaque projet, `git init` + `.gitignore` (exclure `.env`, `/vendor`, `/node_modules`).
6. **Livrer** : guide de test Postman+Ngrok du health-check, puis guide Expo Go (afficher « API OK »). **STOP → j'écris « socle validé ».**

---

## 6. ORDRE DES MODULES (plan de mémoire, Ch. 9) — ne pas dévier

1. **Module 1 — Triage et orientation médicale** (F1.1 → F1.8) ← *on commence ici*
2. **Module 2 — Carnet de santé familial + QR Code dynamique** (F2.1 → F2.9)
3. **Module 3 — Géolocalisation des structures** (F3.1 → F3.10) *(introduit Google Maps + Firebase ; prévenir si dev build requis)*
4. **Module 4 — Portail administratif hospitalier** (web Blade, 3 niveaux)
5. **Module 5 — Santé publique & urgences** (SOS, carte vitale, alertes épidémiques, suivi grossesse/chroniques, comparateur de prix)

Chaque module = **étape A (backend) → validation → étape B (frontend) → validation.**

---

## 7. MODULE 1 — DÉTAIL (le premier à exécuter, après socle validé)

> Lire d'abord `docs/Securite`, `docs/DesignSystem`, `docs/CahierDesCharges` (§5.1) et confirmer les sections appliquées.

### Étape 1A — Backend (le cerveau)
- Migrations + modèles : `symptomes` (nom_fr, catégorie, `poids_severite`, `specialite_hint`, `questions_complementaires_json`, `actif`) et `triages` (cf. CdC §8.2).
- **Seed de symptômes ivoiriens** réalistes (paludisme, fièvre typhoïde, etc.) avec poids et questions complémentaires en JSON.
- `App\Services\TriageService` : algorithme **arbre de décision**, score 0–100, seuils **léger 0–30 / modéré 31–65 / urgent 66–100**, impact antécédents plafonné à 20, et **règle « drapeau rouge »** : tout symptôme/réponse critique force immédiatement le niveau **URGENT**.
- **Reco ROUGE = SAMU 185** (numéro vert Côte d'Ivoire) — **PAS le « 15 »** du CdC (numéro français). Correction obligatoire.
- Déduction automatique de la **spécialité** (CdC §5.1.3).
- `TriageRequest` (validation : `membre_id`, `symptomes` array 1–20, `reponses`…), `TriageController`, routes versionnées `/api/v1/...` : `GET /symptomes`, `POST /triage/analyser`, `GET /triage/{id}/fiche` (F1.8), `GET /triage/historique`.
- **Livrer** : collection Postman + guide pas-à-pas via Ngrok (cas léger/modéré/urgent + cas drapeau rouge). **STOP → « backend 1 validé ».**

### Étape 1B — Frontend (Expo Go)
- Écrans selon le Design System (cartes blanches sur dégradé, badges icône+couleur+texte, cibles ≥ 48 dp, libellés d'accessibilité) :
  sélection des symptômes (chips + recherche autocomplétée) → questionnaire progressif (échelle 1–10) → **résultat** (score 48 sp, badge vert/orange/rouge, reco + spécialité) → **fiche partageable F1.8** (partage WhatsApp + copier) → historique.
- Toutes les couleurs **depuis `theme.ts`** (aucune couleur en dur).
- **Livrer** : guide de test Expo Go (sur mon téléphone, via Ngrok). **STOP → « module 1 validé ».**

---

## 8. EXIGENCES DE QUALITÉ (toutes étapes)

- Code **commenté en français**, clair, défendable devant un jury L3.
- Sécurité : Eloquent (requêtes paramétrées), Form Requests, `$fillable` explicite, AES-256
  (casts `encrypted`) sur données médicales, clé hors base, journal d'audit immuable au Module 2.
- Accessibilité & minimalisme : redondance icône+couleur+texte, gros texte, une action principale par écran, fluide sur 3G / téléphone d'entrée de gamme.
- `.env.example` documenté (jamais de secret réel), README par projet, commits Git par étape.
- Si une fonctionnalité dépasse Expo Go (Google Maps natif, push FCM réel, certificate pinning) → **tu me préviens** et tu proposes l'option (development build / EAS) sans bloquer le reste.

---

## 9. FORMAT ATTENDU À CHAQUE ÉTAPE

À chaque étape, tu produis dans cet ordre :
1. **Fichiers lus** + sections applicables (et conflits éventuels signalés).
2. **Packages** à installer + **commandes exactes** (`npx expo install …` / `composer require …`) + justification + note de compatibilité SDK 54.
3. **Le code**, organisé proprement dans la bonne arborescence.
4. **Le guide de test** reproductible (Postman+Ngrok pour le backend ; Expo Go pour le mobile), avec les résultats attendus.
5. **STOP** : tu me demandes de tester et de valider, et tu **attends ma réponse écrite** avant de continuer.

---

## 10. PREMIÈRE ACTION

Ne code **aucune fonctionnalité de module** maintenant. Commence par **l'Étape 0 (Socle technique, §5)** :
crée l'arborescence, installe les bases (versions compatibles vérifiées), neutralise les
causes d'erreurs de communication (§4), place le logo (§3), et **prouve la connectivité avec
le health-check** (Postman+Ngrok puis Expo Go). Pour l'authentification, **pose-moi d'abord la
question d'arbitrage du §5.3.** Puis arrête-toi pour validation.
