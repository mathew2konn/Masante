import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import Link from 'next/link';
import { i18n } from '@masante/shared';
import { getMe, estProfessionnel, mesZones } from '@/lib/session';
import { LogoutButton } from '@/components/LogoutButton';
import { Navigation } from '@/components/Navigation';

/** Portail professionnel — pages privées, non indexables (CDC_02). */
export const metadata: Metadata = {
  robots: { index: false, follow: false },
};

/**
 * Garde d'accès SERVEUR (CDC_02) : la session (cookie httpOnly) et le rôle font autorité côté
 * backend. Sans session → login. Compte non professionnel → page réservée (ADR-011 : le portail
 * web est pour les pros ; les patients utilisent l'app mobile).
 *
 * P11.0 — CE LAYOUT NE GARDE QUE L'ENTRÉE. Chaque page de zone appelle en plus `exigerZone()`,
 * qui vérifie la permission qui l'ouvre. Les deux gardes ne se remplacent pas : celle-ci dit
 * « vous êtes un professionnel », l'autre dit « cette zone est à vous ». Sans la seconde, tout
 * compte professionnel atteindrait toutes les applications — ce qui était le cas jusqu'ici.
 *
 * La navigation est dérivée du MÊME registre que la garde de zone : un lien ne peut donc pas
 * mener à une page qui refusera.
 */
export default async function PortailLayout({ children }: { children: React.ReactNode }) {
  const user = await getMe();
  if (!user) redirect('/login');
  if (!estProfessionnel(user)) redirect('/reserve-pros');

  const t = i18n.fr;
  const roles = (user.roles ?? []).map((r) => t.roles[r]).join(' · ');
  const zones = await mesZones();

  return (
    <div className="min-h-screen bg-background">
      <header className="flex items-center justify-between border-b border-line bg-surface px-6 py-4">
        <Link
          href="/"
          className="rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          <p className="font-semibold text-blue-900">
            {user.prenom} {user.nom}
          </p>
          <p className="text-sm text-ink-500">{roles}</p>
        </Link>
        <LogoutButton />
      </header>

      <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6 md:flex-row">
        {zones.length > 0 ? (
          <aside className="md:w-60 md:shrink-0">
            <Navigation zones={zones} />
          </aside>
        ) : null}
        <main className="min-w-0 flex-1">{children}</main>
      </div>
    </div>
  );
}
