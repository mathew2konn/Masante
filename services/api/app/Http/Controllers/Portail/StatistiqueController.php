<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\AccesDossier;
use App\Models\Avis;
use App\Models\RendezVous;
use App\Models\Signalement;
use App\Models\StructureSanitaire;
use App\Models\TokenQr;
use App\Models\Triage;
use App\Models\User;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.8 — Statistiques (CdC §5.4.2 ; §5.4.1 étape « consulte les statistiques de
 * rendez-vous et d'avis »).
 *
 * Deux écrans cloisonnés par permission :
 *  - `stats.global`        → l'admin voit la plateforme entière ;
 *  - `stats.etablissement` → le gestionnaire ne voit QUE son établissement.
 *
 * MINIMISATION (loi n°2013-450). Ces écrans n'affichent que des AGRÉGATS d'activité. Les triages
 * portent des données de santé : on en compte la répartition par niveau de gravité, on n'en liste
 * jamais un seul, et aucun indicateur n'est rattachable à un patient. Un établissement dont on
 * afficherait « 3 triages urgents » avec les noms serait une fuite ; « 22 triages urgents ce
 * mois-ci » n'en est pas une.
 */
class StatistiqueController extends Controller
{
    /** Statuts de RDV, dans l'ordre du cycle de vie (miroir de l'enum). */
    private const STATUTS_RDV = ['en_attente', 'confirme', 'refuse', 'annule', 'honore'];

    /** Niveaux de triage (Module 1). */
    private const NIVEAUX_TRIAGE = ['leger', 'modere', 'urgent'];

    /** Mois en français : `config('app.locale')` vaut `en`, donc pas de `translatedFormat()`. */
    private const MOIS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /** Vue globale de la plateforme (admin). */
    public function global(): View
    {
        $debutMois = now()->startOfMonth();

        return view('portail.statistiques.global', [
            'etablissementsActifs' => StructureSanitaire::where('actif', true)->count(),
            'etablissementsTotal'  => StructureSanitaire::count(),
            'comptesParRole'       => $this->comptesParRole(),
            'rdvParStatut'         => $this->comptesParColonne(RendezVous::query(), 'statut', self::STATUTS_RDV),
            'rdvTotal'             => RendezVous::count(),
            'triagesParNiveau'     => $this->comptesParColonne(Triage::query(), 'niveau', self::NIVEAUX_TRIAGE),
            'triagesTotal'         => Triage::count(),
            'avisVisibles'         => Avis::where('visible', true)->count(),
            'noteMoyenne'          => $this->noteMoyenne(Avis::where('visible', true)),
            'signalementsEnAttente' => Signalement::where('statut', 'en_attente')->count(),
            'avisAModerer'         => Avis::where('signale', true)->count(),
            'scansDuMois'          => TokenQr::whereNotNull('used_at')->where('used_at', '>=', $debutMois)->count(),
            'moisCourant'          => self::MOIS[$debutMois->month].' '.$debutMois->year,
            // Revue a posteriori des bris de glace (Note_Continuite §5.3, garde-fou n°6) : un taux
            // anormal par établissement révèle un abus. On compte les OUVERTURES (`duree_minutes`
            // nulle) : la ligne de clôture référence le même accès, elle ferait doublon.
            'brisDeGlaceDuMois'    => AccesDossier::where('type_acces', 'bris_de_glace')
                ->whereNull('duree_minutes')
                ->where('created_at', '>=', $debutMois)
                ->count(),
            'brisDeGlaceTotal'     => AccesDossier::where('type_acces', 'bris_de_glace')
                ->whereNull('duree_minutes')
                ->count(),
        ]);
    }

    /** Vue de MON établissement (gestionnaire). 403 si le compte n'est rattaché à aucun. */
    public function etablissement(): View
    {
        $structureId = auth()->user()->structure_id;
        abort_if($structureId === null, Response::HTTP_FORBIDDEN, 'Compte non rattaché à un établissement.');

        $structure = StructureSanitaire::with('services')->findOrFail($structureId);
        $rdv = RendezVous::where('structure_id', $structureId);
        $avis = Avis::where('structure_id', $structureId)->where('visible', true);

        $parStatut = $this->comptesParColonne(RendezVous::where('structure_id', $structureId), 'statut', self::STATUTS_RDV);
        $traites = $parStatut['confirme'] + $parStatut['refuse'];

        return view('portail.statistiques.etablissement', [
            'structure'     => $structure,
            'rdvParStatut'  => $parStatut,
            'rdvTotal'      => (clone $rdv)->count(),
            // Part des demandes tranchées qui ont abouti — 0 si aucune n'a encore été traitée.
            'tauxConfirmation' => $traites > 0 ? (int) round($parStatut['confirme'] / $traites * 100) : 0,
            'enAttente'     => $parStatut['en_attente'],
            'avisVisibles'  => (clone $avis)->count(),
            'noteMoyenne'   => $this->noteMoyenne(clone $avis),
            'servicesActifs' => $structure->services->where('actif', true)->count(),
            'servicesTotal' => $structure->services->count(),
            'signalementsPublies' => Signalement::where('structure_id', $structureId)
                ->where('visible_publiquement', true)->count(),
        ]);
    }

    /**
     * Compte les lignes par valeur d'une colonne, en garantissant une clé par valeur attendue
     * (zéro compris) : les vues et les graphiques reçoivent toujours la même forme.
     *
     * @param  array<string>  $valeurs
     * @return array<string, int>
     */
    private function comptesParColonne($requete, string $colonne, array $valeurs): array
    {
        $comptes = $requete->groupBy($colonne)->selectRaw("{$colonne}, count(*) as total")->pluck('total', $colonne);

        return collect($valeurs)->mapWithKeys(fn ($v) => [$v => (int) ($comptes[$v] ?? 0)])->all();
    }

    /** @return array<string, int> */
    private function comptesParRole(): array
    {
        return collect(array_keys(CompteController::ROLES))
            ->mapWithKeys(fn ($role) => [$role => User::role($role)->count()])
            ->all();
    }

    private function noteMoyenne($requete): ?float
    {
        $moyenne = $requete->avg('note');

        return $moyenne !== null ? round((float) $moyenne, 2) : null;
    }
}
