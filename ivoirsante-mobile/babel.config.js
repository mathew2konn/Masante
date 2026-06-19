module.exports = function (api) {
  api.cache(true);
  return {
    // babel-preset-expo inclut le support d'Expo Router (SDK 54).
    presets: ['babel-preset-expo'],
  };
};
