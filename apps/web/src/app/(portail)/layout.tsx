import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { i18n } from '@masante/shared';
import { getMe, estProfessionnel } from '@/lib/session';
import { LogoutButton } from '@/components/LogoutButton';

/** Portail professionnel — pages privées, non indexables (CDC_02). */
export const metadata: Metadata = {
  robots: { index: false, follow: false },
};

/**
 * Garde d'accès SERVEUR (CDC_02) : la session (cookie httpOnly) et le rôle font autorité côté
 * backend. Sans session → login. Compte non professionnel → page réservée (ADR-011 : le portail
 * web est pour les pros ; les patients utilisent l'app mobile).
 */
export default async function PortailLayout({ children }: { children: React.ReactNode }) {
  const user = await getMe();
  if (!user) redirect('/login');
  if (!estProfessionnel(user)) redirect('/reserve-pros');

  const t = i18n.fr;
  const roles = (user.roles ?? []).map((r) => t.roles[r]).join(' · ');

  return (
    <div className="min-h-screen bg-background">
      <header className="flex items-center justify-between border-b border-line bg-surface px-6 py-4">
        <div>
          <p className="font-semibold text-blue-900">
            {user.prenom} {user.nom}
          </p>
          <p className="text-sm text-ink-500">{roles}</p>
        </div>
        <LogoutButton />
      </header>
      <main className="mx-auto max-w-3xl p-6">{children}</main>
    </div>
  );
}
