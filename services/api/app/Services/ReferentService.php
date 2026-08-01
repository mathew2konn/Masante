<?php

namespace App\Services;

use App\Models\AccesDossier;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\Referent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Module 5 / 5.6 — Voie 2 « médecin référent » (Sécurité §4.4 ; Note_Continuite §2).
 *
 * La deuxième des quatre voies d'accès au dossier, et la seule qui n'exige RIEN au moment du soin :
 * pas de QR à générer, pas de titulaire à joindre, pas d'urgence à justifier. Le patient a désigné
 * son médecin une fois pour toutes ; celui-ci ouvre le dossier quand il en a besoin.
 *
 * « PERMANENT » NE VEUT PAS DIRE « SESSION ÉTERNELLE ». Ce qui est permanent, c'est le DROIT — pas
 * la fenêtre. Chaque ouverture crée une session de 30 minutes et deux lignes d'audit, exactement
 * comme un scan de QR : le patient voit dans son historique d'accès chaque consultation de son
 * référent, date et durée comprises. Un droit permanent non tracé serait un angle mort.
 *
 * UN SEUL RÉFÉRENT ACTIF PAR MEMBRE — la règle qu'imposait de fait la colonne unique du CdC §8.1
 * (`medecin_referent_id`). Désigner un nouveau médecin révoque le précédent : le patient ne peut pas
 * laisser une porte ouverte derrière lui sans le savoir.
 */
class ReferentService
{
    /** Désignation active du membre, s'il en a une. */
    public function actif(MembreFamille $membre): ?Referent
    {
        return Referent::actif()->where('membre_id', $membre->id)->with('medecin.structure')->first();
    }

    /**
     * Désigne un médecin comme référent du membre. Révoque d'abord la désignation en cours :
     * un seul référent actif à la fois, et la révocation reste inscrite à l'historique.
     */
    public function designer(MembreFamille $membre, Medecin $medecin, User $titulaire): Referent
    {
        $this->revoquerActif($membre, 'remplacement');

        $referent = new Referent;
        $referent->membre_id = $membre->id;
        $referent->medecin_id = $medecin->id;
        $referent->designe_par_user_id = $titulaire->id;
        $referent->designe_at = now();
        $referent->save();

        Log::info('Médecin référent désigné', [
            'membre_id'  => $membre->id,
            'medecin_id' => $medecin->id,
            'par'        => $titulaire->id,
        ]);

        return $referent->load('medecin.structure');
    }

    /** Révocation par le titulaire : effet immédiat, la ligne reste à l'historique. */
    public function revoquer(Referent $referent, string $motif = 'revocation'): void
    {
        if (! $referent->estActif()) {
            return;
        }

        $referent->revoquee_at = now();
        $referent->save();

        Log::info('Médecin référent révoqué', [
            'referent_id' => $referent->id,
            'membre_id'   => $referent->membre_id,
            'motif'       => $motif,
        ]);
    }

    /** Membres dont le compte connecté est le médecin référent (« mes patients suivis » du portail). */
    public function membresSuivisPar(User $user): Collection
    {
        $medecin = $user->medecin;

        if ($medecin === null) {
            return collect();
        }

        return Referent::actif()
            ->where('medecin_id', $medecin->id)
            ->with('membre')
            ->get()
            ->pluck('membre')
            ->filter()
            ->values();
    }

    /** Le compte connecté est-il le référent ACTIF de ce membre ? (revérifié à chaque ouverture) */
    public function estReferentDe(User $user, MembreFamille $membre): bool
    {
        $medecin = $user->medecin;

        if ($medecin === null) {
            return false;
        }

        return Referent::actif()
            ->where('membre_id', $membre->id)
            ->where('medecin_id', $medecin->id)
            ->exists();
    }

    /**
     * Ouvre l'accès référent : écrit la ligne d'audit d'OUVERTURE et notifie le titulaire.
     * Le contrôleur en fait une session de 30 minutes (la ligne de CLÔTURE est écrite par
     * {@see SessionDossierService::fermer()} — journal en ajout seul, §10.2).
     *
     * Aucun `token_qr_id` : c'est précisément ce qui distingue cette voie du scan (CdC §8.4, la
     * colonne est NULL « si médecin référent »).
     */
    public function ouvrir(MembreFamille $membre, User $medecinUser, ?string $ip): AccesDossier
    {
        $acces = AccesDossier::create([
            'membre_id'  => $membre->id,
            'agent_id'   => $medecinUser->id,
            'type_acces' => 'referent',
            'ip_address' => $ip,
        ]);

        $this->notifierTitulaire($membre, $medecinUser, $acces);

        return $acces;
    }

    /**
     * Notification au titulaire du carnet (« votre médecin référent a consulté le dossier de X »).
     * Ni Firebase ni passerelle SMS dans le projet : trace applicative, à brancher au module
     * Notifications — même stub que le scan (4.5) et le bris de glace (5.3).
     */
    private function notifierTitulaire(MembreFamille $membre, User $medecinUser, AccesDossier $acces): void
    {
        Log::info('Dossier consulté par le médecin référent', [
            'acces_id'      => $acces->id,
            'membre_id'     => $membre->id,
            'medecin_user'  => $medecinUser->id,
            'etablissement' => $medecinUser->structure?->nom,
            'notifie'       => $membre->user?->telephone,
        ]);
    }

    /** Révoque la désignation en cours du membre, s'il y en a une. */
    private function revoquerActif(MembreFamille $membre, string $motif): void
    {
        $actif = Referent::actif()->where('membre_id', $membre->id)->first();

        if ($actif !== null) {
            $this->revoquer($actif, $motif);
        }
    }
}
