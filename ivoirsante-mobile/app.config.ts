import { ExpoConfig, ConfigContext } from 'expo/config';

/**
 * Configuration Expo de MaSante (IVOIRSANTÉ) — SDK 54.
 *
 * Remplace app.json pour pouvoir injecter dynamiquement l'URL de l'API (extra.apiUrl).
 * Icône / splash / adaptive-icon proviennent tous du logo (source unique : assets/images/).
 */

// ⚠️ SOURCE UNIQUE DE L'URL API (§4.6) — à mettre à jour quand l'URL Ngrok change.
// Peut être surchargée sans toucher au code via la variable d'env EXPO_PUBLIC_API_URL.
const API_URL = process.env.EXPO_PUBLIC_API_URL ?? 'https://VOTRE-SOUS-DOMAINE.ngrok-free.app';

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: 'MaSante',
  slug: 'ivoirsante-mobile',
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
  ],
  // Routes typées (autocomplétion + vérif des href par TypeScript).
  experiments: {
    typedRoutes: true,
  },
  // Valeurs lues à l'exécution côté app via expo-constants (voir src/config/api.ts).
  extra: {
    apiUrl: API_URL,
  },
});
