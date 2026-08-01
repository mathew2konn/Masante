# Guide de test G4 — P1 Identité (RBAC + MFA)

Périmètre : rôles RBAC (front A mobile), MFA TOTP (backend + front B mobile + front C web),
sphères en fond global (mobile). Objectif G4 : **test réel** sur appareils/navigateurs, réseau
bridé et hors-ligne. Coche chaque scénario ; note tout écart.

> Convention : **« libellé »** = texte exact affiché. Les écrans en **lecture seule** sont signalés.

---

## 0. Pré-requis communs (backend)

Dans `services/api` (PHP = `C:\wamp64\bin\php\php8.3.28\php.exe`, préfixer `XDEBUG_MODE=off`) :

```bash
# 1. Base MySQL WAMP « ivoirsante » migrée + jeux de données (rôles + user de test)
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate --seed

# 2. API joignable sur le réseau local (pour Ngrok mobile + web)
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan serve --host=0.0.0.0 --port=8000
```

### Comptes de test
| Compte | Téléphone (local) | Mot de passe | Rôle | Note |
|--------|-------------------|--------------|------|------|
| Patient | `0700000000` | `password` | `patient` | Créé et **pré-vérifié** par le seeder |
| Pro | `0700000000` | `password` | `patient` + `medecin` | À enrichir (ci-dessous) |

Pour tester le **portail web pro**, ajoute un rôle professionnel au compte de test :

```bash
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan tinker
>>> \App\Models\User::where('telephone','+2250700000000')->first()->assignRole('medecin');
>>> exit
```

> Le téléphone se saisit en **local** (`0700000000`) ; le backend attend `+2250700000000` — la
> conversion est faite par l'app. Le compte pré-vérifié permet la connexion par mot de passe.

### Interrupteur MFA (`MFA_ENFORCE`)
Par défaut **désactivé** : la MFA est *proposée* (jamais exigée à la connexion). Les tests
d'enrôlement (mobile A.2 / web B.4) fonctionnent sans y toucher. Pour tester le **défi MFA à la
connexion** (web B.5), édite `services/api/.env` :

```
MFA_ENFORCE=true
```
puis `artisan config:clear`. **Remets `false`** après le test B.5.

---

## A. Mobile — Expo Go SDK 54 + Ngrok

### A.0 Lancement
1. Tunnel Ngrok vers l'API : `ngrok http 8000` → copie l'URL `https://xxxx.ngrok-free.app`.
2. Renseigne-la dans `apps/mobile/app.config.ts` (`extra.apiUrl`) **ou** `EXPO_PUBLIC_API_URL`.
3. `pnpm --filter @masante/mobile start` (ou `npx expo start --tunnel`) → scanne le QR avec Expo Go.
4. Connecte-toi : écran **« Connexion »**, téléphone `0700000000`, mot de passe `password`.

### A.1 Front A — le rôle est affiché (lecture seule)
1. Barre d'onglets du bas → onglet **« Carnet »**.
2. En haut, la carte de compte affiche : prénom + nom, puis le numéro, puis **une ligne bleue en gras avec le rôle : « Patient »**, puis le badge de statut (« ● Compte de base » ou « ✓ Compte vérifié »).

✅ **Attendu** : la ligne rôle affiche **« Patient »** (fournie par le backend, jamais déduite). Si le compte a plusieurs rôles, ils sont séparés par « · » (ex. « Patient · Médecin »).
🔒 Écran **lecture seule** : aucun bouton sur cette ligne.

### A.2 Front B — activer la double authentification (MFA)
> ⚠️ Ne pas confondre **« Sécurité »** (verrou PIN de l'app, existant) et **« Double authentification »** (MFA du compte, nouveau).

1. Onglet **« Carnet »** → descends jusqu'à la liste de réglages → bouton **« Double authentification »**.
2. Écran **« Double authentification »**, sous-titre **« Un second code à la connexion (2FA) »**.
   - Carte d'état : **« Inactive »**.
   - Bouton **« Activer la double authentification »**.
3. Appuie sur **« Activer la double authentification »**.
   - L'écran passe en **« Configurer »** : **un QR code**, puis **« Ou saisissez cette clé manuellement : »** + une clé en lettres, puis une carte **« Comment faire »**, puis le champ **« Code de vérification »** (clavier numérique) et le bouton **« Activer »** (désactivé tant qu'il n'y a pas 6 chiffres).
4. Ouvre **Google Authenticator** (ou Authy) sur le téléphone → ajoute un compte → **scanne le QR**.
5. Saisis le code à 6 chiffres affiché par l'app d'authentification → **« Activer »**.

✅ **Attendu** : alerte **« Double authentification activée »**. De retour sur l'écran, l'état passe à **« Activée »** et le bouton devient **« Désactiver »**.
❌ **Code faux** : saisis `000000` → message d'erreur sous le champ, l'état reste inactif.

### A.3 Front B — désactiver
1. Écran **« Double authentification »** (état **« Activée »**) → bouton **« Désactiver »**.
2. Alerte de confirmation **« Désactiver la double authentification »** → **« Désactiver »**.

✅ **Attendu** : l'état repasse à **« Inactive »**.

### A.4 Sphères en fond de toute l'application
1. Parcours plusieurs écrans : **Accueil**, **Triage**, **Carnet**, **Carte**, puis un détail (ex. un membre).

✅ **Attendu** : les **sphères bleues rebondissantes** sont visibles en fond derrière **chaque** écran (elles l'étaient déjà au démarrage/splash). Le contenu reste **lisible** (posé sur des cartes blanches), les sphères **ne captent aucun appui** (les boutons répondent normalement).
👀 **Point de vigilance perf** : vérifie la **fluidité** en naviguant vite entre onglets et en scrollant une longue liste (Carnet avec plusieurs membres, Médicaments). Signale tout ralentissement/chauffe.

### A.5 Robustesse — hors-ligne
1. Écran **« Double authentification »** → active le **mode avion**.
2. Appuie sur **« Activer la double authentification »**.

✅ **Attendu** : message d'erreur clair (pas de crash, pas de gel). Retour au réseau → l'action refonctionne.

---

## B. Web — Chrome + Firefox (desktop **et** mobile)

### B.0 Lancement
1. Variable d'environnement API : `API_URL=http://localhost:8000` (défaut si non défini).
2. `pnpm --filter @masante/web dev` → ouvre `http://localhost:3000`.

### B.1 Garde d'accès (non connecté)
1. Navigue vers `http://localhost:3000/` **sans être connecté**.

✅ **Attendu** : redirection immédiate vers **`/login`** (garde du middleware).

### B.2 Connexion pro → portail
1. Sur **`/login`** : carte **« Portail professionnel »**, texte **« Connectez-vous avec votre numéro et votre mot de passe. »**.
2. **« Numéro de téléphone »** = `0700000000` ; **« Mot de passe »** = `password` → **« Se connecter »**.

✅ **Attendu** (MFA_ENFORCE=false) : arrivée sur le portail **`/`**.
   - En-tête : prénom + nom (**« User Test »**), en dessous les rôles (**« Patient · Médecin »**), et un bouton **« Se déconnecter »**.
   - Corps : **« Bienvenue »**, **« Votre espace professionnel MaSanté. »**, puis une carte **« Sécurisez votre compte »** avec le lien **« Configurer la double authentification »** (car la MFA n'est pas encore configurée).
❌ **Identifiants erronés** : mot de passe faux → **« Identifiants invalides. »** (rôle `alert`).

### B.3 Un patient sur le web est refusé (ADR-011)
1. Retire temporairement le rôle pro : `tinker` → `...->removeRole('medecin');`
2. Reconnecte-toi sur `/login`.

✅ **Attendu** : redirection vers **`/reserve-pros`** — carte **« Espace réservé aux professionnels »** invitant à utiliser l'app mobile, + **« Se déconnecter »**.
3. Remets le rôle : `...->assignRole('medecin');`

### B.4 Enrôlement MFA (portail)
1. Connecté au portail → clique **« Configurer la double authentification »** (ou va sur `/securite/mfa`).
2. Écran **« Double authentification »**, état **« Inactive »** → **« Activer la double authentification »**.
3. Bloc **« Configurer »** : **QR code** + **« Ou saisissez cette clé manuellement : »** + la clé.
4. Scanne le QR avec l'app d'authentification → saisis le code dans **« Code de vérification »** → **« Activer »**.

✅ **Attendu** : l'état passe à **« ✓ Activée »**, bouton **« Désactiver »** disponible. Sur le portail `/`, la carte devient **« ✓ Double authentification active »**.
❌ **Code faux** : **« Code incorrect. Réessayez. »**.

### B.5 Défi MFA à la connexion (MFA_ENFORCE=true)
> Pré-requis : B.4 réalisé (facteur confirmé) **et** `MFA_ENFORCE=true` + `config:clear`.
1. **« Se déconnecter »** → reviens sur `/login`.
2. Saisis téléphone + mot de passe → **« Se connecter »**.

✅ **Attendu** : le formulaire bascule sur le second facteur — texte **« Saisissez le code de votre application d'authentification. »**, champ **« Code de vérification »**, bouton **« Vérifier »**. **Aucun accès au portail** avant vérification.
3. Saisis le code de l'app d'authentification → **« Vérifier »** → arrivée sur le portail.
❌ **Code faux/expiré** : **« Code incorrect ou expiré. »**.
4. **Remets `MFA_ENFORCE=false`** + `config:clear`.

### B.6 Déconnexion
1. Depuis le portail → **« Se déconnecter »**.

✅ **Attendu** : retour à **`/login`** ; revenir sur `/` redirige de nouveau vers `/login` (cookie de session effacé).

### B.7 Réseau bridé + hors-ligne (DevTools → Network)
1. **« Slow 3G »** : refais B.2. ✅ Le bouton montre **« Veuillez patienter… »**, la connexion aboutit sans blocage.
2. **« Offline »** : sur `/login`, tente de te connecter. ✅ **« Connexion impossible. Vérifiez votre réseau. »** (pas de page blanche).

### B.8 Accessibilité (a11y AA)
1. **Navigation clavier** : sur `/login`, parcours au **Tab** — chaque champ et bouton reçoit un **focus visible** (contour). Soumission à **Entrée**.
2. **Libellés** : chaque champ a une étiquette associée (« Numéro de téléphone », « Mot de passe », « Code de vérification »). Les messages d'erreur sont annoncés (`role="alert"` / `aria-describedby`).
3. Refais les points clés sous **Firefox** et en **vue mobile** (largeur ~375 px) : mise en page lisible, pas de débordement horizontal.

---

## C. Checklist de clôture (avant G5)

- [ ] A.1 Rôle « Patient » affiché dans le Carnet (lecture seule)
- [ ] A.2 Enrôlement MFA mobile (QR scanné, code confirmé → « Activée »)
- [ ] A.3 Désactivation MFA mobile
- [ ] A.4 Sphères en fond de tous les écrans, contenu lisible, gestes OK, **fluidité correcte**
- [ ] A.5 Hors-ligne géré proprement (mobile)
- [ ] B.1 Redirection `/ → /login` sans session
- [ ] B.2 Connexion pro → portail (identité + rôles affichés)
- [ ] B.3 Patient → `/reserve-pros`
- [ ] B.4 Enrôlement MFA web (QR)
- [ ] B.5 Défi MFA à la connexion (avec `MFA_ENFORCE=true`)
- [ ] B.6 Déconnexion
- [ ] B.7 Réseau bridé + hors-ligne
- [ ] B.8 Clavier / focus / labels (Chrome + Firefox, desktop + mobile)
- [ ] `MFA_ENFORCE` **remis à `false`** après B.5

> Tout coché sans écart → écrire **« Module P1 validé »** (G5) et consigner en mémoire.
