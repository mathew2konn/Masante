<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Avis;
use App\Models\RendezVous;
use App\Models\StructureSanitaire;
use App\Services\NoteStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Module 3 / étape 3A.2 — Avis patients sur les structures (F3.9).
 *
 * Lecture PUBLIQUE des avis visibles ; création RÉSERVÉE aux comptes (auth:sanctum).
 *  - `consultation_verifiee` : vrai si l'auteur a un RDV confirmé/honoré dans la structure
 *    (badge « vérifié », inspiré Doctolib).
 *  - Modération basique : un commentaire contenant un mot interdit est masqué (visible=false)
 *    et marqué `signale` ; la modération fine relève du portail admin (Module 4).
 *  - Un avis par utilisateur et par structure : on met à jour l'avis existant le cas échéant.
 */
class AvisController extends Controller
{
    /** Mots interdits (modération automatique minimale). */
    private const MOTS_INTERDITS = ['insulte', 'arnaque', 'connard', 'idiot', 'voleur'];

    public function __construct(private readonly NoteStructureService $notes)
    {
    }

    /** Avis visibles d'une structure (public), les plus récents d'abord. */
    public function index(StructureSanitaire $structure): JsonResponse
    {
        $avis = $structure->avis()
            ->where('visible', true)
            ->with('user:id,prenom')
            ->latest()
            ->get();

        return response()->json(['avis' => $avis]);
    }

    /** Dépose (ou met à jour) l'avis de l'utilisateur authentifié sur une structure. */
    public function store(Request $request, StructureSanitaire $structure): JsonResponse
    {
        $data = $request->validate([
            'note'        => ['required', 'integer', 'between:1,5'],
            'commentaire' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        // Badge « consultation vérifiée » : un RDV confirmé/honoré de l'un de SES membres ici.
        $verifiee = RendezVous::where('structure_id', $structure->id)
            ->whereIn('statut', ['confirme', 'honore'])
            ->whereHas('membre', fn ($m) => $m->where('user_id', $user->id))
            ->exists();

        $masque = $this->contientMotInterdit($data['commentaire'] ?? '');

        $avis = Avis::updateOrCreate(
            ['structure_id' => $structure->id, 'user_id' => $user->id],
            [
                'note'                  => $data['note'],
                'commentaire'           => $data['commentaire'] ?? null,
                'consultation_verifiee' => $verifiee,
                'signale'               => $masque,
                'visible'               => ! $masque,
            ],
        );

        // Dénormalisation partagée avec la modération du portail (4.6) : une seule source de vérité.
        $this->notes->recalculer($structure);

        return response()->json(['avis' => $avis->load('user:id,prenom')], 201);
    }

    /** Détecte un mot interdit (modération automatique minimale). */
    private function contientMotInterdit(string $texte): bool
    {
        $texte = Str::lower($texte);
        foreach (self::MOTS_INTERDITS as $mot) {
            if (Str::contains($texte, $mot)) {
                return true;
            }
        }

        return false;
    }
}
