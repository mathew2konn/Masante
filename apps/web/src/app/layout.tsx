import type { Metadata } from 'next';
import './globals.css';
import { Providers } from './providers';

export const metadata: Metadata = {
  title: 'MaSanté',
  description: "Plateforme numérique de santé — L'éléphant sanitaire et protecteur",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="fr">
      <body className="bg-background text-ink-900 font-sans antialiased">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
