/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // @masante/shared est du TS non transpilé : Next doit le compiler.
  transpilePackages: ['@masante/shared'],
};

export default nextConfig;
