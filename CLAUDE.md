# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

**MaSante** (nom de code interne : IVOIRSANTÉ) — plateforme mobile de santé pour la Côte d'Ivoire,
projet de mémoire L3. Données médicales sensibles → conformité **loi n°2013-450** / **ARTCI**, et
standards **OWASP** (Top 10 / ASVS / MASVS).

Monorepo de **deux projets strictement séparés** qui ne communiquent **que** via l'API REST en JSON :

- `ivoirsante-api/` — **backend Laravel 13** (PHP 8.3, MySQL 8.4). Aucune logique mobile.
- `ivoirsante-mobile/` — **frontend Expo SDK 54** (React Native + TypeScript). Aucune logique métier serveur.
- `docs/` — documents de référence **faisant autorité** (lecture seule, voir « Workflow »).
- `PROMPT_CLAUDE_CODE_IVOIRSANTE.md` — cahier de mission complet (rôle, règles, ordre des modules).

## Spécificités d'environnement (pièges à connaître)

- **PHP : utiliser explicitement `C:\wamp64\bin\php\php8.3.28\php.exe`** — PAS le `php` par défaut (8.5,
  ciblé hors support Laravel + conflit Xdebug). Toujours préfixer par `XDEBUG_MODE=off`.
- **Composer** : `XDEBUG_MODE=off /c/wamp64/bin/php/php8.3.28/php.exe /c/composer/composer.phar <cmd>`.
- **MySQL** : WAMP MySQL **8.4.7**, base `ivoirsante`. Le binaire est `/c/wamp64/bin/mysql/mysql8.4.7/bin/mysql.exe`
  (pas dans le PATH). Le serveur a **MyISAM par défaut** → `config/database.php` force `'engine' => 'InnoDB'`
  (indispensable pour les FK / transactions). Le mot de passe root est dans `ivoirsante-api/.env` (non versionné).
- **Lire les docs de référence** (pas de visionneuse native) :
  - `.docx` → Python : `zipfile` sur `word/document.xml` puis strip des balises.
  - `.pdf` → `pdftotext -layout fichier.pdf sortie.txt` (poppler, dispo dans le shell).
- **Mobile** : installer les dépendances **uniquement** via `npx expo install <pkg>` (jamais `npm install` brut),
  pour garantir la compatibilité **SDK 54**. Vérifier avec `npx expo-doctor` + `npx expo install --check`.
- **Tesseract OCR** (Module 5.8, « scan de reçu » FN7) : binaire installé via
  `winget install --id UB-Mannheim.TesseractOCR`. Les fichiers de langue vivent **dans le projet**
  (`ivoirsante-api/storage/app/tessdata/`, fra + eng — **git-ignorés**, à retélécharger sur un clone neuf depuis
  `tessdata_fast`), pour ne pas dépendre d'un dossier système en prod. Auto-hébergé **par exigence légale** :
  un reçu de pharmacie est une donnée de santé (loi 2013-450), il ne part pas chez un OCR en ligne. Absent →
  l'API renvoie 503 et le mobile bascule sur la saisie manuelle (dégradation prévue).

## Commandes courantes

### Backend (`ivoirsante-api/`)
```bash
PHP=C:\wamp64\bin\php\php8.3.28\php.exe   # toujours ce binaire, XDEBUG_MODE=off
$PHP artisan serve --host=0.0.0.0 --port=8000   # --host 0.0.0.0 requis pour Ngrok
$PHP artisan migrate            # ou migrate:fresh --seed pour repartir propre
$PHP artisan db:seed --class=SymptomeSeeder
$PHP artisan route:list --path=api
$PHP artisan config:clear       # après tout changement de .env
$PHP artisan test               # PHPUnit ; test unique : $PHP artisan test --filter=NomDuTest
XDEBUG_MODE=off $PHP /c/composer/composer.phar audit   # doit rester à 0 avis
```

### Mobile (`ivoirsante-mobile/`)
```bash
npx expo start -c               # -c vide le cache (obligatoire après changement d'URL/config)
npx expo install <pkg>          # ajouter une dépendance (jamais npm install)
npx expo-doctor                 # doit rester 18/18
npx tsc --noEmit                # vérif TypeScript
```

### Tunnel de dev (test sur téléphone physique)
`localhost` n'est pas joignable depuis Expo Go. On expose via Ngrok : `ngrok http 8000`, puis on
reporte l'URL HTTPS **aux deux endroits** : `ivoirsante-api/.env` (`APP_URL`, `FRONTEND_URL`) et
`ivoirsante-mobile/.env` (`EXPO_PUBLIC_API_URL`). Test rapide : ouvrir `<ngrok>/triage-demo` ou `<ngrok>/api/health`.

## Architecture & conventions

### Communication API ↔ mobile (durcie volontairement)
- API **stateless** dans `routes/api.php` (aucune session web → pas de 419/CSRF). Exceptions rendues en
  **JSON** pour `api/*` (`bootstrap/app.php`). `trustProxies('*')` pour le HTTPS terminé par Ngrok.
- En-têtes de sécurité dans `app/Http/Middleware/SecurityHeaders.php` (§7.1 doc Sécurité ; HSTS prod uniquement).
- Rate limiting défini dans `app/Providers/AppServiceProvider.php` : limiteur `api` (100/min) et `login` (5/min).
- CORS (`config/cors.php`) : origine = `FRONTEND_URL` (URL Ngrok) sinon `*` en dev.
- Routes métier **versionnées** sous `/api/v1/...`.

### Triage (Module 1) — le cœur métier
- `app/Services/TriageService.php` : arbre de décision, score **0-100**, seuils **léger 0-30 / modéré 31-65 /
  urgent 66-100**, impact antécédents **plafonné à 20**, **règle drapeau rouge** (symptôme/réponse critique →
  niveau URGENT forcé), déduction de spécialité (§5.1.3). Urgence = **SAMU 185** (numéro vert CI — jamais le « 15 »).
- **Les règles métier vivent en base** (table `symptomes` : `poids_severite`, `drapeau_rouge`,
  `questions_complementaires_json`) → modifiables sans redéployer (F1.3). Voir `SymptomeSeeder`.
- Tables `triages.membre_id` / `structure_visitee_id` sont **nullable sans FK** : les contraintes seront
  ajoutées par les Modules 2 (carnet/membres) et 3 (structures), pas encore existants.

### Mobile — sources uniques de vérité
- `src/theme/theme.ts` : tous les tokens du Design System (couleurs, espacements base-4, typo). **Aucune
  couleur en dur** dans un écran.
- `src/config/api.ts` : **client axios unique** ; baseURL lue depuis `app.config.ts` → `extra.apiUrl`
  (← `EXPO_PUBLIC_API_URL`). En-têtes par défaut JSON + `ngrok-skip-browser-warning`. Intercepteur ajoutant
  le token Bearer depuis `expo-secure-store` (tokens jamais en clair).
- Configuration via `app.config.ts` (pas `app.json`). Logo = source unique `assets/images/logo.png`.

### Authentification (décidée, pas encore implémentée)
**Téléphone + OTP SMS** (2 niveaux : base / vérifié) **+ Laravel Sanctum** (token Bearer, révocation immédiate).
Sanctum est installé ; les endpoints OTP restent à coder. OTP **simulé en dev** (pas de passerelle SMS).

## Workflow imposé (règles de travail — IMPORTANT)

Le `PROMPT_CLAUDE_CODE_IVOIRSANTE.md` impose une méthode stricte, à respecter :

1. **Lire et résumer les docs de référence AVANT chaque module** : `docs/Securite_IVOIRSANTE.docx`,
   `docs/DesignSystem_IVOIRSANTE.docx`, `docs/CahierDesCharges...pdf`, `docs/Identification_IVOIRSANTE.docx`.
   Indiquer explicitement quelles sections s'appliquent.
2. **Barrières de validation** : pour chaque module, construire le **backend (étape A)** → fournir un guide
   de test (Postman + Ngrok) → **attendre « backend N validé »**. Puis le **frontend (étape B)** → guide de
   test Expo Go → **attendre « module N validé »**. Ne jamais enchaîner sans validation écrite.
3. **Ne jamais anticiper le module suivant.** Corrections chirurgicales (isoler la partie fautive).
4. **Signaler les conflits entre documents** au lieu de trancher seul.
5. Commits Git par étape (un commit par étape A / B validée).

Ordre des modules : **1)** Triage *(fait : backend 1A)* → **2)** Carnet de santé familial + QR → **3)** Géolocalisation
(introduit Google Maps + Firebase) → **4)** Portail admin web (Blade) → **5)** Santé publique & urgences.
