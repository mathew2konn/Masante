import Link from 'next/link';
import { redirect } from 'next/navigation';
import { getMe, getMfaStatus } from '@/lib/session';
import { Card } from '@/components/ui/Card';

/**
 * Accueil du portail professionnel (P1 — coquille d'identité). Point d'entrée neutre :
 * il AFFICHE l'identité et l'état de sécurité fournis par le backend, sans aucun métier.
 * Les portails par rôle (médecin, pharmacien…) viendront à leurs modules respectifs.
 */
export default async function PortailAccueil() {
  const user = await getMe();
  if (!user) redirect('/login');

  const mfa = await getMfaStatus();

  // MFA obligatoire non configurée → onboarding forcé (le backend est l'autorité de « doit_configurer »).
  if (mfa?.doit_configurer) redirect('/securite/mfa');

  const mfaActive = mfa?.facteur_confirme === true;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-blue-900">Bienvenue</h1>
        <p className="text-ink-700">Votre espace professionnel MaSanté.</p>
      </div>

      {!mfaActive ? (
        <Card>
          <h2 className="mb-1 text-lg font-semibold text-blue-900">Sécurisez votre compte</h2>
          <p className="mb-4 text-sm text-ink-700">
            La double authentification ajoute un second code à la connexion. Elle est fortement
            recommandée pour les comptes professionnels.
          </p>
          <Link
            href="/securite/mfa"
            className="inline-flex rounded-pill bg-primary px-5 py-2.5 text-sm font-semibold text-surface hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            Configurer la double authentification
          </Link>
        </Card>
      ) : (
        <Card>
          <p className="text-sm font-semibold text-blue-900">✓ Double authentification active</p>
          <Link href="/securite/mfa" className="mt-2 inline-flex text-sm text-primary underline">
            Gérer la sécurité
          </Link>
        </Card>
      )}
    </div>
  );
}
