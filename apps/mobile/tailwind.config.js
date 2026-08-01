// NativeWind (mobile) — consomme le preset PARTAGÉ (@masante/shared) : mêmes tokens que le web.
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./app/**/*.{js,jsx,ts,tsx}', './src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset'), require('@masante/shared/tailwind-preset')],
  theme: { extend: {} },
  plugins: [],
};
