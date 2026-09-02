import Link from 'next/link';
import { redirect } from 'next/navigation';
import { getMe, getMfaStatus, mesZones } from '@/lib/session';
import { Card } from '@/components/ui/Card';

/**
 * Accueil du portail professionnel. Point d'entrée neutre : il AFFICHE l'identité, l'état de
 * sécurité et les zones que le backend a rendues atteignables, sans aucun métier.
 *
 * P11.0 — LES CARTES SONT DÉRIVÉES DU REGISTRE DE ZONES, plus écrites à la main. Avant, la carte
 * « Rendez-vous » s'affichait pour tout compte professionnel, y compris ceux qui ne portent pas
 * `rdv.validate` : ils cliquaient et lisaient « accès restreint ». Une entrée proposée à qui ne
 * peut pas la suivre n'est pas seulement inutile, elle fait douter de tout le reste de l'écran.
 */
export default async function PortailAccueil() {
  const user = await getMe();
  if (!user) redirect('/login');

  const mfa = await getMfaStatus();

  // MFA obligatoire non configurée → onboarding forcé (le backend est l'autorité de « doit_configurer »).
  if (mfa?.doit_configurer) redirect('/securite/mfa');

  const mfaActive = mfa?.facteur_confirme === true;
  const zones = await mesZones();

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
      ) : null}

      {zones.length === 0 ? (
        /*
         * Un compte professionnel sans aucune zone. Ce n'est pas une erreur de compte, et le lui
         * laisser croire serait la pire des réponses : c'est le cas du rôle `assurance`, dont le
         * portail §8.6 n'existe pas encore dans cette plateforme. On le dit, plutôt que d'afficher
         * un écran vide qui ressemblerait à une panne.
         */
        <Card>
          <h2 className="mb-1 text-lg font-semibold text-blue-900">
            Aucun espace n’est encore ouvert pour votre profil
          </h2>
          <p className="text-sm text-ink-700">
            Votre compte est bien reconnu comme professionnel, mais l’application correspondant à
            votre rôle n’est pas encore disponible sur la plateforme. Ce n’est pas un problème de
            droits : rapprochez-vous de l’administration de MaSanté pour connaître son ouverture.
          </p>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {zones.map((zone) => (
            <Card key={zone.chemin}>
              <h2 className="mb-1 text-lg font-semibold text-blue-900">{zone.libelle}</h2>
              <p className="mb-4 text-sm text-ink-700">{zone.description}</p>
              <Link
                href={`/${zone.chemin}`}
                className="inline-flex rounded-pill bg-primary px-5 py-2.5 text-sm font-semibold text-surface hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                Ouvrir
              </Link>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
