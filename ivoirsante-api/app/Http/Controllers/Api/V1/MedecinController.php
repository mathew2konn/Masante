<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 5 / 5.6 — Recherche dans l'annuaire des médecins (support du choix d'un référent, voie 2).
 *
 * PUBLIC en lecture, comme le reste de l'annuaire (structures, services, médecins réservables du
 * Module 3 / F3.5) : ces données — nom, spécialité, établissement — sont déjà exposées par la fiche
 * d'une structure. On n'ouvre donc aucune donnée nouvelle ; on offre seulement une entrée par NOM,
 * pour qu'un patient qui connaît son médecin n'ait pas à deviner dans quel établissement il exerce.
 *
 * À ne pas confondre avec un annuaire de PATIENTS, que le bris de glace (5.3) refuse précisément :
 * ici on liste des professionnels, pas des malades.
 */
class MedecinController extends Controller
{
    /** Résultats renvoyés au maximum : une aide au choix, pas un export de l'annuaire. */
    private const LIMITE = 30;

    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'q'            => ['nullable', 'string', 'max:120'],
            'specialite'   => ['nullable', 'string', 'max:100'],
            'structure_id' => ['nullable', 'integer', 'exists:structures_sanitaires,id'],
        ]);

        $medecins = Medecin::query()
            ->where('actif', true)
            ->when(
                $filtres['q'] ?? null,
                fn ($requete, $terme) => $this->filtrerParNom($requete, $terme),
            )
            ->when($filtres['specialite'] ?? null, fn ($q, $s) => $q->where('specialite', 'like', "%{$s}%"))
            ->when($filtres['structure_id'] ?? null, fn ($q, $id) => $q->where('structure_id', $id))
            ->with(['structure:id,nom,commune', 'service:id,nom_service'])
            ->orderBy('nom')
            ->limit(self::LIMITE)
            ->get();

        return response()->json(['medecins' => $medecins]);
    }

    /**
     * Recherche par nom, MOT À MOT.
     *
     * Un patient tape « Aya Kouamé », « Dr Kouamé » ou « Kouamé cardiologue » — pas un nom de famille
     * isolé. Comparer la saisie entière à `nom` puis à `prenom` ne trouve alors rien : aucun champ ne
     * contient la chaîne complète. On découpe donc la saisie et on exige que CHAQUE mot se retrouve
     * quelque part (nom, prénom ou spécialité) : les mots affinent, ils ne s'excluent pas.
     *
     * Le titre (« Dr », « Pr ») est ignoré : c'est un mot que le patient écrit naturellement et qui ne
     * distingue personne.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Medecin>  $requete
     * @return \Illuminate\Database\Eloquent\Builder<Medecin>
     */
    private function filtrerParNom($requete, string $terme)
    {
        $mots = collect(preg_split('/\s+/', trim($terme), -1, PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $mot) => in_array(mb_strtolower(rtrim($mot, '.')), ['dr', 'pr'], true))
            ->take(4);   // au-delà, la saisie n'affine plus : elle exclut

        foreach ($mots as $mot) {
            $requete->where(fn ($sous) => $sous->where('nom', 'like', "%{$mot}%")
                ->orWhere('prenom', 'like', "%{$mot}%")
                ->orWhere('specialite', 'like', "%{$mot}%"));
        }

        return $requete;
    }
}
