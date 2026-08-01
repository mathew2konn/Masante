import type { Metadata } from 'next';
import { i18n } from '@masante/shared';
import { Card } from '@/components/ui/Card';
import { LogoutButton } from '@/components/LogoutButton';

export const metadata: Metadata = {
  title: 'Espace réservé aux professionnels — MaSanté',
  robots: { index: false, follow: false },
};

/**
 * Page affichée à un compte NON professionnel (patient) qui atteint le portail web.
 * ADR-011 : le portail est réservé aux pros ; les patients utilisent l'application mobile.
 */
export default function ReserveProsPage() {
  const t = i18n.fr;
  return (
    <main className="flex min-h-screen items-center justify-center bg-background p-6">
      <Card className="max-w-md text-center">
        <h1 className="mb-2 text-2xl font-bold text-blue-900">Espace réservé aux professionnels</h1>
        <p className="mb-6 text-ink-700">
          Ce portail est destiné aux professionnels de santé. En tant que patient, retrouvez tous vos
          services dans l’application mobile {t.app.nom}.
        </p>
        <LogoutButton />
      </Card>
    </main>
  );
}
