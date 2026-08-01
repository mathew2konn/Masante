// Metro config monorepo (pnpm) — Metro ne remonte pas l'arbre de dossiers par défaut :
// on lui indique explicitement la racine du workspace et les node_modules à surveiller.
// NativeWind enveloppe cette config (withNativeWind) — voir la fin du fichier.
const { getDefaultConfig } = require('expo/metro-config');
const { withNativeWind } = require('nativewind/metro');
const path = require('path');

const projectRoot = __dirname;
const workspaceRoot = path.resolve(projectRoot, '../..');

const config = getDefaultConfig(projectRoot);

// 1. Surveiller tout le monorepo (pour @masante/shared) SANS perdre les défauts Expo.
config.watchFolders = [...(config.watchFolders ?? []), workspaceRoot];
// 2. Résolution des node_modules : projet puis racine (hoisted).
config.resolver.nodeModulesPaths = [
  path.resolve(projectRoot, 'node_modules'),
  path.resolve(workspaceRoot, 'node_modules'),
];

module.exports = withNativeWind(config, { input: './global.css' });
