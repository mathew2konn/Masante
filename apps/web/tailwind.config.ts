import type { Config } from 'tailwindcss';
// Preset PARTAGÉ (@masante/shared) : mêmes tokens que le mobile (source unique).
import preset from '@masante/shared/tailwind-preset';

const config: Config = {
  presets: [preset],
  content: ['./src/**/*.{ts,tsx}'],
  theme: { extend: {} },
  plugins: [],
};

export default config;
