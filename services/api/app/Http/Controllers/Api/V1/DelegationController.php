<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDelegationRequest;
use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Phase B / B3 — Délégation d'accès (voie 3, Note_Continuite chap. 4), élargie par le carnet
 * familial partagé (incrément A, plan G1 du 2026-08-11).
 *
 * Le titulaire invite un délégué (par téléphone) sur l'un de SES membres ; le délégué accepte
 * depuis son app ; le titulaire (ou le délégué) peut révoquer/refuser, avec effet immédiat.
 *
 * Les délégations créées ici portent désormais `lecture` : le délégué voit le carnet, il ne peut
 * ni le modifier ni le supprimer (MembreFamillePolicy laisse `update`/`delete` au propriétaire).
 * Les délégations ANTÉRIEURES conservent `qr_generation` et n'ouvrent donc aucun dossier — la
 * migration n'a rien élargi rétroactivement.
 */
class DelegationController extends Controller
{
    /** Liste des délégations accordées (comme titulaire) et reçues (comme délégué), actives/en attente. */
    public function index(Request $request): JsonResponse
    {
        $uid = $request->user()->id;

        $accordees = Delegation::where('titulaire_user_id', $uid)
            ->whereNull('revoquee_at')
            ->with(['membre:id,prenom,nom', 'delegue:id,prenom,nom,telephone'])
            ->latest()
            ->get();

        $recues = Delegation::where('delegue_user_id', $uid)
            ->whereNull('revoquee_at')
            ->with(['membre:id,prenom,nom', 'titulaire:id,prenom,nom,telephone'])
            ->latest()
            ->get();

        return response()->json(['accordees' => $accordees, 'recues' => $recues]);
    }

    /** Invite un délégué (par téléphone) sur un membre du titulaire. */
    public function store(StoreDelegationRequest $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre); // le membre appartient au titulaire (anti-IDOR).
        $titulaire = $request->user();

        $this->exigerTitulaireHabilite($titulaire);
        $delegue = $this->resoudreDelegue($request->validated()['telephone'], $titulaire);

        $existante = Delegation::where('delegue_user_id', $delegue->id)
            ->where('membre_id', $membre->id)
            ->first();

        if ($existante && $existante->revoquee_at === null) {
            abort(422, $existante->acceptee_at === null
                ? 'Une invitation est déjà en attente pour ce proche.'
                : 'Ce proche est déjà délégué pour ce membre.');
        }

        $delegation = $this->inviter($titulaire, $delegue, $membre);

        return response()->json([
            'delegation' => $delegation->load(['membre:id,prenom,nom', 'delegue:id,prenom,nom,telephone']),
        ], 201);
    }

    /**
     * POST /api/v1/delegations/en-masse — partage plusieurs carnets d'un coup.
     *
     * POURQUOI : dans le scénario du carnet familial, un responsable qui accueille un nouveau
     * membre lui partage TOUS les carnets de la famille. Le faire un par un serait quinze allers
     * et retours sur un réseau 3G — l'ergonomie n'est pas un confort ici, elle décide si la
     * fonctionnalité est utilisée ou non.
     *
     * `membre_ids` absent = tous les carnets du compte. Les carnets déjà partagés avec ce proche
     * sont IGNORÉS, pas rejetés : un partage en masse doit être rejouable sans que l'appelant ait
     * à savoir ce qui existe déjà.
     */
    public function storeEnMasse(StoreDelegationRequest $request): JsonResponse
    {
        $titulaire = $request->user();
        $this->exigerTitulaireHabilite($titulaire);

        $valide = $request->validate([
            'membre_ids'   => ['sometimes', 'array'],
            'membre_ids.*' => ['integer'],
        ]) + $request->validated();

        $delegue = $this->resoudreDelegue($valide['telephone'], $titulaire);

        // Requête SCOPÉE au compte : un id appartenant à autrui est simplement absent du résultat,
        // jamais une erreur qui révélerait son existence (anti-IDOR, §4.3).
        $membres = $titulaire->membresFamille()
            ->when(
                isset($valide['membre_ids']),
                fn ($q) => $q->whereIn('id', $valide['membre_ids'])
            )
            ->get();

        $creees  = [];
        $ignores = [];

        foreach ($membres as $membre) {
            $existante = Delegation::where('delegue_user_id', $delegue->id)
                ->where('membre_id', $membre->id)
                ->first();

            if ($existante && $existante->revoquee_at === null) {
                $ignores[] = $membre->id;

                continue;
            }

            $creees[] = $this->inviter($titulaire, $delegue, $membre)->id;
        }

        return response()->json([
            'invitations_creees' => count($creees),
            'deja_partages'      => count($ignores),
            'delegation_ids'     => $creees,
        ], 201);
    }

    /** Le délégué accepte l'invitation (depuis son app). */
    public function accepter(Request $request, Delegation $delegation): JsonResponse
    {
        abort_if($delegation->delegue_user_id !== $request->user()->id, 403, 'Action non autorisée.');
        abort_if($delegation->revoquee_at !== null, 422, 'Cette délégation a été révoquée.');

        if ($delegation->acceptee_at === null) {
            $delegation->update(['acceptee_at' => now()]);
        }

        return response()->json([
            'delegation' => $delegation->load(['membre:id,prenom,nom', 'titulaire:id,prenom,nom,telephone']),
        ]);
    }

    /** Révoque (titulaire) ou refuse (délégué) la délégation — effet immédiat. */
    public function destroy(Request $request, Delegation $delegation): JsonResponse
    {
        $uid = $request->user()->id;
        abort_unless(
            $delegation->titulaire_user_id === $uid || $delegation->delegue_user_id === $uid,
            403,
            'Action non autorisée.',
        );

        if ($delegation->revoquee_at === null) {
            $delegation->update(['revoquee_at' => now()]);
        }

        return response()->json(['message' => 'Délégation révoquée.']);
    }

    /** Gate « titulaire vérifié » (chap. 4.2) — dormant tant que le flag est faux (dev). */
    private function exigerTitulaireHabilite(User $titulaire): void
    {
        if (config('masante.delegation.exiger_titulaire_verifie') && ! $titulaire->compteEstVerifie()) {
            abort(403, 'Un compte vérifié (CMU/CNI) est requis pour déléguer l\'accès.');
        }
    }

    /** Le compte destinataire, ou 422 explicite. On ne délègue qu'à un compte réel et vérifié. */
    private function resoudreDelegue(string $telephone, User $titulaire): User
    {
        $delegue = User::where('telephone', $telephone)->first();

        abort_if($delegue === null, 422, 'Aucun compte MaSanté associé à ce numéro.');
        abort_if(! $delegue->telephoneEstVerifie(), 422, 'Le compte associé à ce numéro n\'est pas encore vérifié.');
        abort_if($delegue->id === $titulaire->id, 422, 'Vous ne pouvez pas vous déléguer un de vos propres membres.');

        return $delegue;
    }

    /**
     * Crée (ou réarme) l'invitation. Réutilise une éventuelle ligne révoquée — la contrainte
     * UNIQUE(délégué, membre) interdit d'en empiler une seconde.
     *
     * Le droit accordé est `lecture` : le délégué verra le carnet. L'écriture arrive à
     * l'incrément C, avec son circuit de brouillon — elle n'est pas accordée ici.
     */
    private function inviter(User $titulaire, User $delegue, MembreFamille $membre): Delegation
    {
        $delegation = Delegation::updateOrCreate(
            ['delegue_user_id' => $delegue->id, 'membre_id' => $membre->id],
            [
                'titulaire_user_id' => $titulaire->id,
                'droits'            => Delegation::DROIT_LECTURE,
                'invitee_at'        => now(),
                'acceptee_at'       => null,
                'revoquee_at'       => null,
            ],
        );

        Log::info('Invitation de délégation envoyée', [
            'titulaire_id' => $titulaire->id,
            'delegue_id'   => $delegue->id,
            'membre_id'    => $membre->id,
            'droits'       => Delegation::DROIT_LECTURE,
        ]); // notification en application : incrément D.

        return $delegation;
    }
}
