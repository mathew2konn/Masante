# MaSante / IVOIRSANTÉ — Mobile (Expo / React Native)

Application mobile de **MaSante**, séparée du backend : aucune logique métier serveur ici ;
toute communication passe par l'API REST en JSON (`ivoirsante-api`).

- **Expo SDK 54** · **TypeScript** · testé sur **Expo Go (SDK 54)** via **Ngrok**
- React 19.1.0 · React Native 0.81.5
- `expo-doctor` : **18/18 ✅** · `expo install --check` : versions compatibles SDK 54

> ⚠️ Dépendances installées **uniquement** via `npx expo install` (jamais `npm install` brut),
> pour garantir la compatibilité SDK 54 (§2).

---

## Structure

```
src/
  config/api.ts        ← client axios UNIQUE + URL de base (1 seule source) + health-check
  theme/theme.ts       ← tokens du Design System (couleurs, espacements, typo) — source unique
  components/
    GradientBackground.tsx  ← fond dégradé signature
    Logo.tsx                ← logo depuis assets/images/logo.png (source unique)
assets/images/         ← logo.png (source) + icon / adaptive-icon / splash-icon générés
app.config.ts          ← nom « MaSante », icônes, et extra.apiUrl (URL de l'API)
App.tsx                ← écran de test du socle : affiche « API OK ✅ »
```

## 1. Configurer l'URL de l'API (UNE seule place — §4.6)

L'URL de base de l'API vit dans **`app.config.ts` → `extra.apiUrl`**. Quand l'URL Ngrok
change, deux options :

- **Recommandé** : créer un fichier `.env` à la racine du projet mobile :
  ```
  EXPO_PUBLIC_API_URL=https://xxxx.ngrok-free.app
  ```
- Ou éditer directement la constante `API_URL` dans `app.config.ts`.

## 2. Lancer l'app

```bash
cd ivoirsante-mobile
npx expo start -c        # -c vide le cache (à faire après un changement d'URL/config)
```

Scannez le QR code avec **Expo Go (SDK 54)** sur votre téléphone (même fonctionnement
en 4G ou Wi-Fi, car l'API est jointe via l'URL HTTPS Ngrok).

## 3. Résultat attendu (socle)

L'écran affiche le logo, « MaSante », puis le badge **« API OK ✅ »** (vert) avec
`service: MaSante · base: ok`. Sinon, badge rouge « API INJOIGNABLE » + message d'erreur.
