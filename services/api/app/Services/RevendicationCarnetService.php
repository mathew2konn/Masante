<?php

namespace App\Services;

use App\Models\CarnetTransfert;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Revendication d'un carnet (incrément B) — le remplacement de la fusion de dossiers.
 *
 * LE PROBLÈME QU'ELLE RÉSOUT : jusqu'ici, la personne à qui l'on partageait SON PROPRE carnet
 * voyait quand même l'écran « Créez votre dossier de santé » (P6.1) et en créait un second, avec
 * un second NIS. Le partage rendait le doublon visible ; il ne l'empêchait pas.
 *
 * SUR QUOI ELLE S'APPUIE — c'était la question du propriétaire, et la réponse n'est pas un score :
 * DEUX ACTES HUMAINS INDÉPENDANTS.
 *   1. le responsable partage ce carnet à ce numéro EN AFFIRMANT qu'il est celui de la personne
 *      invitée (`delegations.est_le_dossier_du_delegue`) — il connaît sa famille ;
 *   2. la personne s'authentifie sur ce numéro et le reconnaît comme sien.
 * Deux homonymes stricts ne revendiqueront jamais le même carnet : une seule des deux l'a reçu.
 *
 * POURQUOI C'EST NON DESTRUCTIF PAR CONSTRUCTION : la ligne `membres_famille` garde son `id`. Le
 * NIS reste le sien, `nis_journal` n'a rien à corriger, et les dix-neuf tables qui référencent le
 * dossier suivent sans un seul UPDATE. Rien n'est fusionné, donc rien n'est à défaire.
 */
class RevendicationCarnetService
{
    /**
     * Les carnets que ce compte peut reconnaître comme siens.
     *
     * Trois conditions cumulatives, chacune indispensable :
     *  - une délégation ACTIVE portant l'assertion du responsable ;
     *  - le carnet n'est le dossier titulaire de PERSONNE (sinon on prendrait au responsable son
     *    propre dossier de santé) ;
     *  - le compte n'a pas encore de dossier titulaire — c'est-à-dire qu'on est exactement dans
     *    l'instant qui précède la création du doublon.
     *
     * @return Collection<int, Delegation>
     */
    public function revendicables(User $utilisateur): Collection
    {
        if ($this->aDejaUnDossierTitulaire($utilisateur)) {
            return new Collection;
        }

        return Delegation::query()
            ->where('delegue_user_id', $utilisateur->id)
            ->where('est_le_dossier_du_delegue', true)
            ->active()
            ->whereHas('membre', fn ($q) => $q->where('est_titulaire', false))
            ->with(['membre', 'titulaire:id,nom,prenom'])
            ->get();
    }

    /**
     * Transfère la propriété du carnet au compte qui le revendique.
     *
     * @throws \RuntimeException si les conditions ne sont pas réunies (message destiné à l'API)
     */
    public function revendiquer(User $utilisateur, MembreFamille $membre): MembreFamille
    {
        return DB::transaction(function () use ($utilisateur, $membre) {
            // Verrou pessimiste : deux revendications simultanées du même carnet ne doivent pas
            // produire deux transferts. La seconde verra `est_titulaire` déjà posé et échouera.
            $membre = MembreFamille::whereKey($membre->getKey())->lockForUpdate()->firstOrFail();

            $delegation = Delegation::query()
                ->where('delegue_user_id', $utilisateur->id)
                ->where('membre_id', $membre->id)
                ->where('est_le_dossier_du_delegue', true)
                ->active()
                ->first();

            if ($delegation === null) {
                throw new \RuntimeException('Ce carnet ne vous a pas été reconnu comme le vôtre.');
            }

            if ($membre->est_titulaire) {
                throw new \RuntimeException('Ce carnet est déjà le dossier personnel de son propriétaire.');
            }

            if ($this->aDejaUnDossierTitulaire($utilisateur)) {
                throw new \RuntimeException('Vous avez déjà un dossier de santé personnel.');
            }

            $ancienProprietaire = $membre->user_id;

            // UNE SEULE sauvegarde. Deux écritures successives passeraient par un état
            // intermédiaire (`est_titulaire = 1` avec l'ancien `user_id`, ou l'inverse) que le
            // CHECK `ck_membres_titulaire_coherent` refuse. Le hook du modèle recalcule
            // `titulaire_du_compte` à partir des deux valeurs finales.
            $membre->user_id       = $utilisateur->id;
            $membre->est_titulaire = true;
            $membre->save();

            // La délégation a joué son rôle : elle est consommée. La laisser active laisserait la
            // personne « déléguée sur son propre dossier », ce qui n'a plus de sens.
            $delegation->update(['revoquee_at' => now()]);

            // L'ancien propriétaire garde l'accès — il ne perd pas d'un coup la vue sur un carnet
            // qu'il suivait. Mais il la garde désormais PAR DÉLÉGATION, révocable par la personne
            // concernée : c'est le renversement de propriété que l'incrément B apporte.
            $this->delegationInverse($membre, $utilisateur, $ancienProprietaire);

            CarnetTransfert::create([
                'membre_id'       => $membre->id,
                'ancien_user_id'  => $ancienProprietaire,
                'nouveau_user_id' => $utilisateur->id,
                'delegation_id'   => $delegation->id,
                'motif'           => CarnetTransfert::MOTIF_REVENDICATION,
            ]);

            return $membre->fresh();
        }, 3);
    }

    /**
     * Rend au précédent propriétaire un accès en lecture, immédiatement actif.
     *
     * POURQUOI ACCEPTÉE D'OFFICE : il avait déjà accès à ce carnet — il en était propriétaire. Une
     * invitation en attente le priverait de la vue sur un dossier qu'il suivait, sans qu'il ait
     * rien demandé. L'écran de revendication l'annonce clairement, et le nouveau propriétaire peut
     * la retirer à tout moment.
     */
    private function delegationInverse(MembreFamille $membre, User $nouveau, ?int $ancienUserId): void
    {
        if ($ancienUserId === null || $ancienUserId === $nouveau->id) {
            return;
        }

        Delegation::updateOrCreate(
            ['delegue_user_id' => $ancienUserId, 'membre_id' => $membre->id],
            [
                'titulaire_user_id'        => $nouveau->id,
                'droits'                   => Delegation::DROIT_LECTURE,
                'est_le_dossier_du_delegue' => false,
                'invitee_at'               => now(),
                'acceptee_at'              => now(),
                'revoquee_at'              => null,
            ],
        );
    }

    private function aDejaUnDossierTitulaire(User $utilisateur): bool
    {
        return MembreFamille::where('user_id', $utilisateur->id)
            ->where('est_titulaire', true)
            ->exists();
    }
}
