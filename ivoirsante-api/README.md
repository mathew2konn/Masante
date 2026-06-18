# MaSante / IVOIRSANTÉ — API (backend Laravel)

API REST de la plateforme de santé **MaSante**. Backend séparé du mobile : il ne contient
aucun code React Native ; la communication se fait uniquement en **JSON via `/api`**.

- **Laravel** 13.16.1 — **PHP** 8.3.28 — **MySQL** 8.4 (InnoDB) — **Auth** Laravel Sanctum (token Bearer)
- Sécurité : `composer audit` = **0 avis**, en-têtes de sécurité (§7.1), rate limiting (§9),
  réponses JSON pour `api/*`, CORS configuré pour le tunnel Ngrok (§4).

> ⚠️ PHP : utiliser **PHP 8.3.28** de WAMP (pas 8.5 : Laravel cible 8.2–8.4 ; 8.5 déclenche
> aussi un conflit avec l'extension Xdebug). Binaire : `C:\wamp64\bin\php\php8.3.28\php.exe`.

---

## 1. Prérequis

- WAMP avec **PHP 8.3.28** et **MySQL 8.4** démarrés.
- **Composer**, **ngrok** installés.
- Une base MySQL `ivoirsante` (déjà créée ; sinon : `CREATE DATABASE ivoirsante CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`).

## 2. Installation

```bash
cd ivoirsante-api
cp .env.example .env            # puis renseigner DB_PASSWORD
php artisan key:generate
php artisan migrate
```

## 3. Démarrer le serveur (accessible depuis le téléphone via Ngrok)

```bash
# 1) Laravel écoute sur toutes les interfaces (indispensable pour Ngrok / §4.4)
php artisan serve --host=0.0.0.0 --port=8000

# 2) Dans un autre terminal, ouvrir le tunnel HTTPS
ngrok http 8000
```

Ngrok affiche une URL `https://xxxx.ngrok-free.app`. **Reporter cette URL** dans `.env` :
`APP_URL=` et `FRONTEND_URL=` (puis `php artisan config:clear`).
`localhost`/`127.0.0.1` **ne sont pas joignables** depuis un téléphone physique.

## 4. Tester le health-check

```bash
# En local
curl http://127.0.0.1:8000/api/health
# Via Ngrok (ajouter l'en-tête pour sauter la page d'avertissement Ngrok)
curl -H "ngrok-skip-browser-warning: true" https://xxxx.ngrok-free.app/api/health
```

Réponse attendue :

```json
{"status":"ok","service":"MaSante","environment":"local","database":"ok","time":"..."}
```

## 5. Endpoints du socle

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/health` | Health-check (chaîne mobile → Ngrok → Laravel + base) — `throttle:api` |
| GET | `/api/user` | Renvoie l'utilisateur authentifié (`auth:sanctum`) — exemple, remplacé au Module Auth |

## 6. Choix d'authentification retenu

**Téléphone + OTP SMS (2 niveaux base/vérifié) + Laravel Sanctum** (révocation immédiate).
OTP **simulé en dev** (pas de passerelle SMS branchée). Implémenté au prochain sous-module.
