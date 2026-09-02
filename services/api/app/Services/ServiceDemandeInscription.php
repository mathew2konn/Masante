<?php

namespace App\Services;

use App\Models\DemandeInscriptionEtablissement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * P11.1 — Le cycle d'une demande d'inscription (CDC_11 §3, méthode 2).
 *
 * Dépose → la plateforme vérifie → approuve (l'établissement naît) ou rejette (avec son motif).
 *
 * ═══ CE QUE CE SERVICE NE FAIT PAS, ET C'EST VOULU ═══
 *
 * Il **ne crée pas** l'établissement lui-même : il appelle `OnboardingEtablissementService`, le
 * même chemin qu'emprunte la méthode 1. Réécrire ici la création aurait produit deux façons de
 * faire naître un établissement, qui auraient divergé à la première évolution du schéma — et
 * auraient divergé du côté qu'on regarde le moins, celui qu'un administrateur n'emprunte que
 * quelques fois par mois.
 *
 * Il **ne vérifie pas** la légitimité de l'établissement. Confronter un numéro d'autorisation à
 * l'autorité de tutelle est un acte humain ; prétendre l'automatiser donnerait à une machine
 * l'apparence d'une habilitation qu'elle n'a pas. Le service enregistre **qui** a décidé et
 * **quand**, ce qui est le fait qu'un litige discutera.
 */
class ServiceDemandeInscription
{
    public function __construct(
        private readonly OnboardingEtablissementService $onboarding,
    ) {}

    /**
     * Dépôt public d'une candidature.
     *
     * ANTI-ABUS SANS DÉPENDANCE : une seule demande en attente par adresse du demandeur. Ce
     * n'est pas un captcha — en ajouter un serait une dépendance externe (§2.6) et un service
     * tiers sur un formulaire public. Ça n'empêche pas quelqu'un de déterminé d'utiliser dix
     * adresses ; ça empêche le cas réel, qui est le double envoi par impatience, et ça garde la
     * file lisible pour ceux qui la traitent. Le limiteur de requêtes de la route fait le reste.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function deposer(array $donnees): DemandeInscriptionEtablissement
    {
        $email = $donnees['demandeur_email'];

        $existante = DemandeInscriptionEtablissement::query()
            ->where('demandeur_email', $email)
            ->where('statut', DemandeInscriptionEtablissement::EN_ATTENTE)
            ->first();

        if ($existante !== null) {
            // On rend la référence de la demande en cours plutôt qu'un refus sec : le demandeur
            // qui envoie deux fois a le plus souvent perdu sa référence, et la lui redonner
            // répond à son vrai besoin. Aucune donnée de la demande n'est exposée au-delà.
            throw ValidationException::withMessages([
                'demandeur_email' => [
                    'Une demande est déjà en cours pour cette adresse (référence '
                    .$existante->reference.'). Attendez sa décision avant d’en déposer une autre.',
                ],
            ]);
        }

        $demande = new DemandeInscriptionEtablissement($donnees);
        $demande->reference = DemandeInscriptionEtablissement::genererReference();
        $demande->statut = DemandeInscriptionEtablissement::EN_ATTENTE;
        $demande->save();

        return $demande;
    }

    /**
     * Approuve une candidature : l'établissement naît, son gestionnaire aussi, le lien est rendu.
     *
     * Sous **verrou pessimiste** avec garde d'état : deux agents qui ouvrent la même demande et
     * cliquent en même temps créeraient sinon deux établissements pour une seule candidature.
     * Motif de `ServiceGouvernanceProtocole::publier()` et du décaissement de P5.5b-2.
     *
     * @param  array<string, mixed>  $etablissement  Données validées, complétées par l'agent.
     * @return string L'URL d'activation à transmettre au gestionnaire.
     */
    public function approuver(
        DemandeInscriptionEtablissement $demande,
        array $etablissement,
        array $gestionnaire,
        User $decideur,
    ): string {
        return DB::transaction(function () use ($demande, $etablissement, $gestionnaire, $decideur) {
            $verrouillee = DemandeInscriptionEtablissement::query()
                ->whereKey($demande->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->exigerEnAttente($verrouillee);

            // Le compte gestionnaire ne peut pas naître si l'adresse sert déjà : on le dit ici,
            // avant de créer l'établissement, plutôt que de laisser remonter une violation
            // d'unicité qui aurait déjà écrit la structure et l'aurait laissée sans gestionnaire.
            if (User::query()->where('email', $gestionnaire['email'])->exists()) {
                throw ValidationException::withMessages([
                    'gestionnaire_email' => ['Un compte existe déjà avec cette adresse.'],
                ]);
            }

            $resultat = $this->onboarding->creer($etablissement, $gestionnaire);

            $verrouillee->forceFill([
                'statut' => DemandeInscriptionEtablissement::APPROUVEE,
                'structure_id' => $resultat->structure->id,
                'decide_par' => $decideur->id,
                'decide_par_nom' => $decideur->nomLisible(),
                'decide_le' => now(),
            ])->save();

            return $resultat->lienActivation;
        });
    }

    /** Rejette une candidature. Le motif est obligatoire — le moteur le refuse aussi. */
    public function rejeter(
        DemandeInscriptionEtablissement $demande,
        string $motif,
        User $decideur,
    ): DemandeInscriptionEtablissement {
        $motif = trim($motif);

        if ($motif === '') {
            throw ValidationException::withMessages([
                'motif_rejet' => ['Un rejet doit dire pourquoi : le demandeur le lira.'],
            ]);
        }

        return DB::transaction(function () use ($demande, $motif, $decideur) {
            $verrouillee = DemandeInscriptionEtablissement::query()
                ->whereKey($demande->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->exigerEnAttente($verrouillee);

            $verrouillee->forceFill([
                'statut' => DemandeInscriptionEtablissement::REJETEE,
                'motif_rejet' => $motif,
                'decide_par' => $decideur->id,
                'decide_par_nom' => $decideur->nomLisible(),
                'decide_le' => now(),
            ])->save();

            return $verrouillee;
        });
    }

    /**
     * Une décision déjà prise ne se reprend pas.
     *
     * 409 et non 403 : l'agent A LE DROIT de décider, c'est CETTE demande qui n'est plus à
     * décider. Le précédent est celui du quatre-yeux de P7-C, où confondre les deux aurait fait
     * croire à un défaut d'habilitation.
     */
    private function exigerEnAttente(DemandeInscriptionEtablissement $demande): void
    {
        if (! $demande->estEnAttente()) {
            throw new RuntimeException(
                'Cette demande a déjà été traitée ('.$demande->statut.').',
            );
        }
    }
}
