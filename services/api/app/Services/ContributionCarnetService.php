<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\ResponsableFamille;
use App\Models\User;
use App\Support\RegistreSectionsCarnet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Contributions au carnet d'un proche (incrément C).
 *
 * LE SCÉNARIO, dans les mots du propriétaire : les parents sont en voyage, un enfant de trois ans
 * est malade, la personne restée à la maison l'emmène à l'hôpital. Elle doit pouvoir écrire — un
 * parent peut être absent des semaines. Ce qu'elle écrit part au BROUILLON ; les responsables
 * vérifient (fiche de parcours, appel téléphonique) puis valident.
 *
 * LA SÉPARATION QUI COMPTE — et qui ne doit jamais être effacée :
 *
 *   médecin (QR, référent, bris de glace)  →  écrit DIRECTEMENT au dossier
 *   délégué de la famille                  →  contribution, validée par un responsable
 *
 * Une ordonnance de médecin ne peut pas attendre l'accord d'un parent en voyage. Le brouillon
 * encadre la contribution familiale auto-déclarée, jamais l'acte médical. C'est pourquoi ce
 * service force `source = patient` : il ne peut structurellement pas produire une entrée qui se
 * présenterait comme venant d'un soignant.
 *
 * FRONTIÈRE : tout le jugement est ici. Les contrôleurs traduisent en HTTP, le mobile affiche.
 */
class ContributionCarnetService
{
    /**
     * Dépose une contribution au brouillon.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws \RuntimeException     conditions non réunies (message destiné à l'API)
     * @throws ValidationException   la proposition ne respecte pas les règles de la section
     */
    public function deposer(
        User $auteur,
        MembreFamille $membre,
        string $section,
        array $donnees,
    ): Contribution {
        if (! RegistreSectionsCarnet::existe($section)) {
            throw new \RuntimeException("Cette section n'accepte pas de contribution.");
        }

        // Le PROPRIÉTAIRE n'a rien à proposer : il écrit directement, par les routes du carnet.
        // Le lui faire passer par un brouillon reviendrait à lui demander de se valider lui-même.
        if ($membre->user_id === $auteur->id) {
            throw new \RuntimeException('Vous êtes propriétaire de ce carnet : écrivez-y directement.');
        }

        if (! Delegation::ecriturePour($auteur->id, $membre->id)) {
            throw new \RuntimeException("Vous n'avez pas le droit de contribuer à ce carnet.");
        }

        $controleur = RegistreSectionsCarnet::controleur($section);
        $valide     = Validator::make($donnees, $controleur->reglesDeCreation())->validate();

        // Une contribution est TOUJOURS auto-déclarée. Un délégué ne peut pas faire passer son
        // ajout pour un acte de soignant, quoi qu'il envoie.
        $valide['source']   = 'patient';
        $valide['added_by'] = 'patient';

        return Contribution::create([
            'membre_id'      => $membre->id,
            'auteur_user_id' => $auteur->id,
            'section'        => $section,
            'donnees'        => $valide,
            'statut'         => Contribution::BROUILLON,
        ]);
    }

    /**
     * Valide une contribution : l'entrée est écrite dans la vraie table du carnet.
     *
     * @throws \RuntimeException
     */
    public function valider(User $decideur, Contribution $contribution): Contribution
    {
        return DB::transaction(function () use ($decideur, $contribution) {
            // Verrou : deux responsables qui valident en même temps ne doivent pas produire deux
            // entrées. Le second verra le statut déjà changé.
            $contribution = Contribution::whereKey($contribution->getKey())->lockForUpdate()->firstOrFail();

            $membre = $this->exigerPouvoirDeDecision($decideur, $contribution);
            $this->exigerEnAttente($contribution);

            $controleur = RegistreSectionsCarnet::controleur($contribution->section);
            $entree     = $membre->{$controleur->nomRelation()}()->create($contribution->donnees);

            $contribution->update([
                'statut'             => Contribution::VALIDEE,
                'decide_par_user_id' => $decideur->id,
                'decide_le'          => now(),
                'entree_id'          => $entree->getKey(),
            ]);

            return $contribution->fresh();
        }, 3);
    }

    /** @throws \RuntimeException */
    public function rejeter(User $decideur, Contribution $contribution, ?string $motif = null): Contribution
    {
        return DB::transaction(function () use ($decideur, $contribution, $motif) {
            $contribution = Contribution::whereKey($contribution->getKey())->lockForUpdate()->firstOrFail();

            $this->exigerPouvoirDeDecision($decideur, $contribution);
            $this->exigerEnAttente($contribution);

            // Rien n'est écrit au carnet. La contribution reste consultable : un rejet doit être
            // explicable, à l'auteur comme au second responsable.
            $contribution->update([
                'statut'             => Contribution::REJETEE,
                'decide_par_user_id' => $decideur->id,
                'decide_le'          => now(),
                'motif_rejet'        => $motif,
            ]);

            return $contribution->fresh();
        }, 3);
    }

    /**
     * Les contributions qu'un compte peut voir sur un carnet donné.
     *
     * Trois regards légitimes : le propriétaire, ses responsables désignés, et l'auteur (qui doit
     * pouvoir suivre ce qu'il a proposé). Un délégué en lecture simple voit aussi — le brouillon
     * n'est jamais caché à qui a accès au dossier.
     */
    public function pourLeCarnet(User $utilisateur, MembreFamille $membre): \Illuminate\Support\Collection
    {
        return Contribution::query()
            ->where('membre_id', $membre->id)
            ->with('auteur:id,nom,prenom')
            ->latest()
            ->get();
    }

    /**
     * Les contributions en attente sur TOUS les carnets dont ce compte peut décider.
     *
     * C'est la file du responsable : ce qu'on lui demande d'arbitrer.
     */
    public function enAttentePour(User $decideur): \Illuminate\Support\Collection
    {
        // Les carnets dont il est propriétaire, plus ceux des titulaires qui l'ont désigné.
        $titulaires = ResponsableFamille::query()
            ->where('responsable_user_id', $decideur->id)
            ->actif()
            ->pluck('titulaire_user_id')
            ->push($decideur->id)
            ->unique();

        return Contribution::query()
            ->enAttente()
            ->whereHas('membre', fn ($q) => $q->whereIn('user_id', $titulaires))
            ->with(['auteur:id,nom,prenom', 'membre:id,nom,prenom'])
            ->latest()
            ->get();
    }

    /**
     * Le compte a-t-il le pouvoir de décider sur ce carnet ? Renvoie le membre au passage.
     *
     * @throws \RuntimeException
     */
    private function exigerPouvoirDeDecision(User $decideur, Contribution $contribution): MembreFamille
    {
        $membre = $contribution->membre;

        if ($membre === null) {
            throw new \RuntimeException('Le carnet visé n\'existe plus.');
        }

        if (! in_array($decideur->id, ResponsableFamille::decideursPour($membre->user_id), true)) {
            throw new \RuntimeException('Seul un responsable de ce carnet peut décider.');
        }

        return $membre;
    }

    /** @throws \RuntimeException */
    private function exigerEnAttente(Contribution $contribution): void
    {
        if (! $contribution->estEnAttente()) {
            throw new \RuntimeException('Cette contribution a déjà été traitée.');
        }
    }
}
