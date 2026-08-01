module.exports = function (api) {
  api.cache(true);
  return {
    // jsxImportSource: nativewind → active className sur les composants RN.
    // babel-preset-expo (SDK 54) inclut Expo Router + injecte auto le plugin reanimated/worklets.
    presets: [
      ['babel-preset-expo', { jsxImportSource: 'nativewind' }],
      'nativewind/babel',
    ],
  };
};
