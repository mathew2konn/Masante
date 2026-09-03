<?php

namespace App\Services\Medicament;

use App\Models\Delivrance;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\StockOfficine;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\PrixMedicamentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B3-b — l'inventaire d'une officine (CDC_11 §7.3 et §7.5).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * L'INVENTAIRE ALIMENTE LE RELEVÉ PUBLIC, IL NE LE DOUBLE PAS
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `prix_pharmacie` reste la seule valeur que lit le comparateur (module G5, contrat inchangé).
 * Quand l'officine tient son stock, chaque mouvement qui fait basculer la disponibilité — et chaque
 * changement de prix — est **répercuté** dans le relevé, par le service qui l'écrit déjà
 * (`PrixMedicamentService`, jamais réécrit ici).
 *
 * Sans cela, le comparateur et la fiche officine pourraient se contredire, et *le patient ne
 * saurait pas laquelle croire* — motif P6.7b, où le délai du laboratoire prime mais où les deux
 * sont portés.
 *
 * ═══ LE STOCK EST UNE SOMME, JAMAIS UNE COLONNE ═══
 *
 * Aucune méthode n'écrit un stock : on écrit des MOUVEMENTS, et le stock en est la somme. Une
 * erreur se corrige par un `ajustement`, qui la laisse visible. C'est la partie double du wallet
 * (P5.3a), et c'est ce qui rend l'historique vérifiable.
 */
final class ServiceStockOfficine
{
    public const PERMISSION = 'medicament.manage';

    public function __construct(private readonly PrixMedicamentService $prix) {}

    /**
     * L'article de cette officine pour ce produit, créé s'il n'existe pas encore.
     *
     * @throws ValidationException
     */
    public function article(StructureSanitaire $officine, Medicament $medicament): StockOfficine
    {
        $this->assertOfficine($officine);

        return StockOfficine::firstOrCreate([
            'structure_id' => $officine->id,
            'medicament_id' => $medicament->id,
        ]);
    }

    /**
     * Enregistre un mouvement.
     *
     * LE SIGNE EST DÉDUIT DU TYPE, jamais demandé à l'appelant : une « entrée de -5 » n'a pas de
     * sens, et laisser l'appelant choisir le signe ferait dépendre l'intégrité du stock de la
     * discipline de chaque site d'appel. Le moteur refuse de toute façon un signe contraire au
     * type — les deux gardes ne se rattrapent pas, elles se confirment.
     *
     * @throws ValidationException
     */
    public function mouvement(
        User $agent,
        StockOfficine $article,
        string $type,
        int $quantite,
        array $details = [],
    ): MouvementStock {
        $this->assertHabilite($agent);

        if (! in_array($type, ['entree', 'sortie', 'peremption', 'ajustement'], true)) {
            $this->refus('Type de mouvement inconnu.');
        }

        if ($quantite === 0) {
            $this->refus('Un mouvement de stock porte une quantité non nulle.');
        }

        $signee = match ($type) {
            'entree' => abs($quantite),
            'sortie', 'peremption' => -abs($quantite),
            // L'ajustement est le seul qui va dans les deux sens : c'est sa raison d'être.
            default => $quantite,
        };

        // On ne descend pas sous zéro : un stock négatif serait une affirmation fausse sur ce qui
        // est en rayon. L'officine qui constate un écart le corrige par un `ajustement`, pas en
        // servant ce qu'elle n'a pas.
        if ($signee < 0 && $article->stockCourant() + $signee < 0) {
            $this->refus(sprintf(
                'Le stock de « %s » est de %d : impossible d\'en sortir %d.',
                $article->medicament?->nom_generique ?? 'ce produit',
                $article->stockCourant(),
                abs($signee),
            ));
        }

        return DB::transaction(function () use ($agent, $article, $type, $signee, $details): MouvementStock {
            $mouvement = new MouvementStock;
            $mouvement->stock_id = $article->id;
            $mouvement->type = $type;
            $mouvement->quantite = $signee;
            $mouvement->lot = $details['lot'] ?? null;
            $mouvement->date_peremption = $details['date_peremption'] ?? null;
            $mouvement->motif = $details['motif'] ?? null;
            $mouvement->delivrance_id = $details['delivrance_id'] ?? null;
            // Identifiant + nom FIGÉ : le fait doit continuer de nommer son auteur (ADR-042 D1).
            $mouvement->agent_user_id = $agent->id;
            $mouvement->agent_nom = $agent->nomLisible();
            $mouvement->save();

            $this->repercuterAuReleve($article->fresh(), $agent);

            return $mouvement;
        });
    }

    /**
     * Fixe le prix de vente de l'officine, et le répercute au relevé public.
     *
     * @throws ValidationException
     */
    public function fixerPrix(User $agent, StockOfficine $article, ?int $prix, ?int $seuil = null): StockOfficine
    {
        $this->assertHabilite($agent);

        $article->prix_cfa = $prix;
        $article->seuil_alerte = $seuil;
        $article->save();

        $this->repercuterAuReleve($article->fresh(), $agent);

        return $article;
    }

    /**
     * B3-a → B3-b : une délivrance sort du stock.
     *
     * SI L'OFFICINE TIENT SON INVENTAIRE. Sinon, la délivrance passe sans rien décrémenter — et
     * c'est délibéré : refuser de servir parce qu'une pharmacie ne tient pas son stock dans notre
     * application priverait un patient de son traitement pour une raison qui ne le concerne pas
     * (même esprit qu'en P7-D0, où un échec de signature ne défait pas l'écriture).
     *
     * @return int le nombre de lignes de stock effectivement décrémentées
     */
    public function sortirPourDelivrance(User $agent, Delivrance $delivrance): int
    {
        $sorties = 0;

        foreach ($delivrance->lignes as $ligne) {
            $medicamentId = $ligne->ligne?->medicament_id;

            if ($medicamentId === null) {
                // Ligne non rattachée au référentiel : on ne devine pas de quel article il s'agit.
                continue;
            }

            $article = StockOfficine::where('structure_id', $delivrance->structure_id)
                ->where('medicament_id', $medicamentId)
                ->first();

            if ($article === null || $article->stockCourant() < $ligne->quantite) {
                // L'officine ne tient pas cet article, ou son inventaire est déjà à découvert :
                // on ne fabrique pas un stock négatif pour faire coller les chiffres.
                continue;
            }

            $this->mouvement($agent, $article, 'sortie', $ligne->quantite, [
                'delivrance_id' => $delivrance->id,
                'motif' => 'Délivrance d\'ordonnance',
            ]);

            $sorties++;
        }

        return $sorties;
    }

    /** Les articles sous leur seuil d'alerte (§7.3). Ceux sans seuil n'y figurent pas. */
    public function alertes(StructureSanitaire $officine): Collection
    {
        return StockOfficine::with('medicament:id,nom_generique,nom_commercial')
            ->where('structure_id', $officine->id)
            ->whereNotNull('seuil_alerte')
            ->get()
            ->filter(fn (StockOfficine $a): bool => $a->sousLeSeuil() === true)
            ->values();
    }

    /** Les lots périmés ou qui le seront dans `$jours` jours (§7.3). */
    public function peremptions(StructureSanitaire $officine, int $jours = 90): Collection
    {
        return MouvementStock::query()
            ->whereIn('stock_id', StockOfficine::where('structure_id', $officine->id)->select('id'))
            ->where('type', 'entree')
            ->whereNotNull('date_peremption')
            ->whereDate('date_peremption', '<=', now()->addDays($jours))
            ->orderBy('date_peremption')
            ->get();
    }

    /**
     * Répercute l'état de l'article dans le relevé public.
     *
     * PAR LE SERVICE QUI L'ÉCRIT DÉJÀ, jamais par un accès direct : `PrixMedicamentService` porte
     * les bornes de plausibilité et la garde « est-ce une pharmacie », et les réécrire ici
     * produirait deux façons d'enregistrer un prix — divergeant du côté qu'aucun humain n'ouvre.
     */
    private function repercuterAuReleve(StockOfficine $article, User $agent): void
    {
        $medicament = $article->medicament;
        $officine = $article->structure;

        if ($medicament === null || $officine === null) {
            return;
        }

        if (! $article->estDisponible()) {
            $this->prix->signalerRupture($medicament, $officine, 'pharmacie_portail', $agent);

            return;
        }

        // Un prix non fixé ne se devine pas : l'article est en rayon, le relevé le dit, mais aucun
        // montant n'est inventé. `signalerRupture` ne convient pas — il dirait « pas en stock ».
        if ($article->prix_cfa === null) {
            return;
        }

        $this->prix->releverPrix($medicament, $officine, (int) $article->prix_cfa, 'pharmacie_portail', $agent);
    }

    private function assertOfficine(StructureSanitaire $officine): void
    {
        if (! $officine->estPharmacie()) {
            $this->refus('Un stock d\'officine ne se tient que dans une pharmacie.');
        }
    }

    private function assertHabilite(User $agent): void
    {
        // Vérifiée ICI et pas seulement par le middleware : les routes du portail sont sur le guard
        // `web`, et un `permission:` au mauvais guard laisse passer (piège de P4).
        if (! $agent->can(self::PERMISSION)) {
            $this->refus('Vous n\'êtes pas habilité à tenir ce stock.');
        }
    }

    /** @return never */
    private function refus(string $message, string $champ = 'stock'): void
    {
        throw ValidationException::withMessages([$champ => $message]);
    }
}
