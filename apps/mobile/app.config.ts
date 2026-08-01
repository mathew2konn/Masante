import { ExpoConfig, ConfigContext } from 'expo/config';

/**
 * Configuration Expo de MaSante (IVOIRSANTÉ) — SDK 54.
 *
 * Remplace app.json pour pouvoir injecter dynamiquement l'URL de l'API (extra.apiUrl).
 * Icône / splash / adaptive-icon proviennent tous du logo (source unique : assets/images/).
 */

// ⚠️ SOURCE UNIQUE DE L'URL API (§4.6) — à mettre à jour quand l'URL Ngrok change.
// Peut être surchargée sans toucher au code via la variable d'env EXPO_PUBLIC_API_URL.
const API_URL = process.env.EXPO_PUBLIC_API_URL ?? 'https://bettye-nonchalant-neriah.ngrok-free.dev';

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: 'MaSante',
  slug: 'ivoirsante-mobile',
  owner: 'mathieu-27',
  version: '1.0.0',
  // Schéma de deep-linking requis par Expo Router (navigation par fichiers).
  scheme: 'masante',
  orientation: 'portrait',
  icon: './assets/images/icon.png',
  userInterfaceStyle: 'light',
  newArchEnabled: true,
  splash: {
    image: './assets/images/splash-icon.png',
    resizeMode: 'contain',
    backgroundColor: '#FFFFFF',
  },
  ios: {
    supportsTablet: true,
  },
  android: {
    // Identifiant unique de l'app Android (requis pour builder un APK/AAB via EAS).
    package: 'com.mathew2konn.masante',
    adaptiveIcon: {
      foregroundImage: './assets/images/adaptive-icon.png',
      backgroundColor: '#FFFFFF',
    },
    edgeToEdgeEnabled: true,
    predictiveBackGestureEnabled: false,
  },
  web: {
    favicon: './assets/images/icon.png',
  },
  plugins: [
    'expo-router',
    'expo-secure-store',
    // Stockage local offline (file d'attente d'écriture, cache) — compatible Expo Go.
    'expo-sqlite',
    // Géolocalisation au premier plan uniquement (Module 3 : proximité des structures).
    // Pas de localisation en arrière-plan → reste compatible Expo Go (§3B doc carto OSM).
    [
      'expo-location',
      {
        locationWhenInUsePermission:
          'MaSante utilise votre position pour trouver les structures de santé les plus proches.',
        isAndroidBackgroundLocationEnabled: false,
        isAndroidForegroundServiceEnabled: false,
      },
    ],
    // F2.10 — import de documents : accès appareil photo + galerie (au moment de l'action).
    [
      'expo-image-picker',
      {
        photosPermission:
          'MaSante accède à vos photos pour importer un document médical (résultat, certificat…).',
        cameraPermission:
          "MaSante utilise l'appareil photo pour prendre en photo un document médical à importer.",
      },
    ],
    // Phase B / B2 — verrou applicatif : biométrie (Face ID) pour déverrouiller les sections sensibles.
    [
      'expo-local-authentication',
      {
        faceIDPermission: 'MaSante utilise Face ID pour déverrouiller votre carnet de santé.',
      },
    ],
  ],
  // Routes typées (autocomplétion + vérif des href par TypeScript).
  experiments: {
    typedRoutes: true,
  },
  // Valeurs lues à l'exécution côté app via expo-constants (voir src/config/api.ts).
  extra: {
    apiUrl: API_URL,
    // Identifiant du projet EAS (config dynamique : à renseigner à la main, cf. `eas init`).
    eas: {
      projectId: '6647289e-ee43-4b20-9fd9-34ef98b68b97',
    },
  },
});
