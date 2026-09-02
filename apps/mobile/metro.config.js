// Metro config monorepo (pnpm) — Metro ne remonte pas l'arbre de dossiers par défaut :
// on lui indique explicitement ce qu'il doit surveiller hors du projet.
// NativeWind enveloppe cette config (withNativeWind) — voir la fin du fichier.
const { getDefaultConfig } = require('expo/metro-config');
const { withNativeWind } = require('nativewind/metro');
const path = require('path');

const projectRoot = __dirname;
const workspaceRoot = path.resolve(projectRoot, '../..');

const config = getDefaultConfig(projectRoot);

// 1. Surveiller CE DONT METRO A BESOIN, et rien de plus.
//
// Cette ligne surveillait `workspaceRoot` en entier, ce qui suffisait tant que la racine ne
// contenait que du code. Depuis l'arrivée du corpus de données nationales (`data/`, plusieurs
// mégaoctets de CSV, de JSON et de CQL), surveiller toute la racine ferait scanner à chaque
// `expo start` des fichiers qu'aucune ligne de l'application n'importe : ce corpus alimente la
// base côté serveur et les tests, jamais le bundle mobile.
//
// On désigne donc les deux seuls emplacements utiles. Le workspace ne contient qu'un paquet
// partagé (`packages/shared`), et le code mobile n'importe que `@masante/shared` — vérifié.
//
// Régler ce point par le `.gitignore` n'aurait rien changé : celui-ci ne gouverne que ce que Git
// suit, il n'a aucun effet sur ce que Metro surveille.
config.watchFolders = [
  ...(config.watchFolders ?? []),
  path.resolve(workspaceRoot, 'packages', 'shared'),
  path.resolve(workspaceRoot, 'node_modules'),
];

// 2. Résolution des node_modules : projet puis racine (hoisted).
config.resolver.nodeModulesPaths = [
  path.resolve(projectRoot, 'node_modules'),
  path.resolve(workspaceRoot, 'node_modules'),
];

module.exports = withNativeWind(config, { input: './global.css' });
