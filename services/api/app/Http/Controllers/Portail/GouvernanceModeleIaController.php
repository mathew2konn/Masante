<?php

namespace App\Http\Controllers\Portail;

use App\Models\AlerteDerive;
use App\Models\ExportJeuEntrainement;
use App\Models\VersionModeleIa;
use App\Services\Triage\ServiceComparaisonModeleIa;
use App\Services\Triage\ServiceDeriveModeleIa;
use App\Services\Triage\ServiceExportJeuEntrainement;
use App\Services\Triage\ServiceGouvernanceModeleIa;
use App\Support\RegistreRetourTriage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * P10c-3-i (F19) — Écran de gouvernance des modèles IA (CDC_05 §7.2/§8/§9).
 *
 * Même précédent que {@see ValidationApprentissageController} : l'habilitation qui fait AUTORITÉ
 * est celle des services, vérifiée à l'intérieur (piège P4 sur `rdv.validate`) ; le middleware de
 * ces routes n'évite qu'un écran inutile à qui n'est pas habilité. Sans investissement de design
 * (K1, P6.4d).
 */
class GouvernanceModeleIaController
{
    public function __construct(
        private readonly ServiceExportJeuEntrainement $export,
        private readonly ServiceGouvernanceModeleIa $gouvernance,
        // Lot B : la confrontation après coup, et la surveillance de dérive.
        private readonly ServiceComparaisonModeleIa $comparaison,
        private readonly ServiceDeriveModeleIa $derive,
    ) {}

    public function index(): View
    {
        return view('portail.modeles-ia.index', [
            'exports' => ExportJeuEntrainement::query()->latest('id')->limit(20)->get(),
            'versions' => VersionModeleIa::query()
                ->with('metriques')
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function exporter(Request $request): RedirectResponse
    {
        try {
            $export = $this->export->exporter(
                $request->user(),
                (string) config('referentiels.pays_defaut', 'CI'),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['gouvernance' => $e->getMessage()]);
        }

        return redirect()->route('portail.modeles-ia.index')->with(
            'statut',
            "Export #{$export->id} produit : {$export->nb_lignes} ligne(s)."
        );
    }

    public function entrainer(ExportJeuEntrainement $export, Request $request): RedirectResponse
    {
        try {
            $version = $this->gouvernance->entrainer($request->user(), $export);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['gouvernance' => $e->getMessage()]);
        }

        return redirect()->route('portail.modeles-ia.index')->with(
            'statut',
            "Version {$version->numero_version} entraînée : candidat prêt pour revue."
        );
    }

    public function valider(VersionModeleIa $version, Request $request): RedirectResponse
    {
        try {
            $this->gouvernance->valider($request->user(), $version);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['gouvernance' => $e->getMessage()]);
        }

        return redirect()->route('portail.modeles-ia.index')->with('statut', 'Version validée.');
    }

    /**
     * P10c-3-ii (F24) — Mettre en service, ou revenir en arrière (§8, rollback).
     *
     * Le même bouton sert aux deux : activer une version archivée EST le rollback. En faire deux
     * actions distinctes suggérerait qu'un retour arrière est une opération d'exception, alors que
     * le §8 le demande explicitement comme une capacité normale.
     */
    public function activer(VersionModeleIa $version, Request $request): RedirectResponse
    {
        try {
            $this->gouvernance->activer($request->user(), $version);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['gouvernance' => $e->getMessage()]);
        }

        return redirect()->route('portail.modeles-ia.index')->with(
            'statut',
            "Version {$version->numero_version} en service. La précédente est archivée : un seul "
            .'modèle répond à la fois.'
        );
    }

    /**
     * P10c-3-ii lot B (F29) — La comparaison prédiction ⇄ verdict, sur la surface administrateur.
     *
     * ═══ POURQUOI ICI, ET NULLE PART AILLEURS ═══
     *
     * Le modèle prédit en observation : personne ne le voit dans le parcours de soin. La montrer au
     * soignant AVANT son verdict fermerait la boucle — son jugement devient l'étiquette
     * d'entraînement du modèle suivant, et **le défaut serait invisible dans les métriques, elles
     * s'amélioreraient**. Cet écran est donc le seul endroit où la prédiction se lit, et il est
     * réservé aux contrôleurs plateforme (`ia_triage.valider`), jamais à l'établissement dont les
     * triages sont examinés (ADR-017 §7).
     */
    public function comparaison(VersionModeleIa $version): View
    {
        return view('portail.modeles-ia.comparaison', [
            'comparaison' => $this->comparaison->pour($version),
            'classes' => ServiceComparaisonModeleIa::CLASSES,
            'libelles' => RegistreRetourTriage::RETOURS,
            'derives' => AlerteDerive::query()
                ->where('version_id', $version->id)
                ->orderByDesc('date_rapport')
                ->orderBy('nature')
                ->limit(50)
                ->get(),
        ]);
    }

    /**
     * Le rapport de dérive à la demande — le même calcul que la tâche quotidienne (F37/F38).
     *
     * Il ne désactive rien : l'alerte prévient, un humain décide (F39).
     */
    public function analyserDerive(Request $request): RedirectResponse
    {
        $rapport = $this->derive->analyser((string) config('referentiels.pays_defaut', 'CI'));

        return redirect()->route('portail.modeles-ia.index')->with('statut', match ($rapport['statut']) {
            'aucun_modele_actif' => 'Aucun modèle en service : il n\'y a rien à surveiller.',
            'echantillon_insuffisant' => sprintf(
                'Échantillon insuffisant (%d de référence, %d observées) : aucun indice n\'est '
                .'calculé plutôt qu\'un chiffre qui ne voudrait rien dire.',
                $rapport['nb_reference'], $rapport['nb_observees']),
            default => $rapport['alertes'] === 0
                ? 'Analyse faite : aucune dérive au-delà des seuils.'
                : $rapport['alertes'].' dérive(s) constatée(s). Le modèle reste EN SERVICE — la '
                    .'décision vous appartient.',
        });
    }
}
