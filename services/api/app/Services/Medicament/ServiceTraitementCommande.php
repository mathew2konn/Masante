<?php

namespace App\Services\Medicament;

use App\Models\Commande;
use App\Models\StockOfficine;
use App\Models\User;
use App\Services\ServiceNotification;
use App\Support\StatutCommande;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B3-d — le pharmacien traite une commande (CDC_11 §9.5, littéral : « le pharmacien valide »
 * avant que la vente soit autorisée).
 *
 * DEUX SERVICES ET NON UN (patron du plan) : le patient et le pharmacien ne partagent aucune
 * garde — celui-ci prouve qu'il traite pour SON officine, jamais qu'il commande pour son carnet.
 *
 * ═══ F10 — LA REMISE DONNE SON SENS À LA SÉPARATION DES PERMISSIONS ═══
 * Accepter une commande est un acte de relation client (`commande.traiter`) ; dispenser un acte
 * pharmaceutique (`ordonnance.delivrer`, B3-a). Remettre une commande PORTANT UNE ORDONNANCE exige
 * donc LES DEUX — c'est cette remise-là qui EST une délivrance.
 *
 * ═══ F9 — LA REMISE N'INVENTE PAS UN SECOND CHEMIN DE SORTIE DE STOCK ═══
 * Sous ordonnance : le chemin de B3-a (`ServiceDelivrance::delivrer()`), inchangé — stock et
 * traçabilité nationale (B3-c) en découlent déjà. Vente libre : mouvement de stock direct, motif
 * dédié, AUCUNE trace nationale (limite héritée de B3-c, pas créée ici).
 */
final class ServiceTraitementCommande
{
    public const PERMISSION = 'commande.traiter';

    public function __construct(
        private readonly ServiceDelivrance $delivrance,
        private readonly ServiceStockOfficine $stock,
        private readonly ServiceNotification $notifications,
    ) {}

    public function accepter(User $pharmacien, Commande $commande): Commande
    {
        $this->assertHabilite($pharmacien);
        $this->assertOfficine($pharmacien, $commande);
        $this->assertStatut($commande, StatutCommande::EN_ATTENTE);

        $commande->update([
            'statut' => StatutCommande::ACCEPTEE->value,
            'acceptee_le' => now(),
            'traite_par_user_id' => $pharmacien->id,
        ]);

        $this->notifications->commandeAcceptee($commande->fresh());

        return $commande->fresh();
    }

    public function refuser(User $pharmacien, Commande $commande, string $motif): Commande
    {
        $this->assertHabilite($pharmacien);
        $this->assertOfficine($pharmacien, $commande);
        $this->assertStatut($commande, StatutCommande::EN_ATTENTE);

        if (trim($motif) === '') {
            $this->refus('Un refus de commande doit porter son motif.');
        }

        $commande->update([
            'statut' => StatutCommande::REFUSEE->value,
            'motif_refus' => $motif,
            'traite_par_user_id' => $pharmacien->id,
        ]);

        $this->notifications->commandeRefusee($commande->fresh());

        return $commande->fresh();
    }

    public function preparer(User $pharmacien, Commande $commande): Commande
    {
        $this->assertHabilite($pharmacien);
        $this->assertOfficine($pharmacien, $commande);
        $this->assertStatut($commande, StatutCommande::ACCEPTEE);

        $commande->update(['statut' => StatutCommande::PRETE->value, 'prete_le' => now()]);

        $this->notifications->commandePrete($commande->fresh());

        return $commande->fresh();
    }

    /**
     * Remet la commande au patient — état terminal.
     *
     * @throws ValidationException
     */
    public function remettre(User $pharmacien, Commande $commande): Commande
    {
        $this->assertHabilite($pharmacien);
        $this->assertOfficine($pharmacien, $commande);
        $this->assertStatut($commande, StatutCommande::PRETE);

        $commande->loadMissing('lignes', 'ordonnance');

        $lignesOrdonnance = $commande->lignes->filter(fn ($l) => $l->ordonnance_ligne_id !== null);
        $lignesLibres = $commande->lignes->filter(fn ($l) => $l->ordonnance_ligne_id === null);

        return DB::transaction(function () use ($pharmacien, $commande, $lignesOrdonnance, $lignesLibres): Commande {
            if ($lignesOrdonnance->isNotEmpty() && $commande->ordonnance !== null) {
                // F10 — remettre une commande sous ordonnance EST une délivrance : elle exige en
                // plus la permission qui gouverne cet acte pharmaceutique.
                if (! $pharmacien->can(ServiceDelivrance::PERMISSION)) {
                    $this->refus('Cette commande porte une ordonnance : vous devez aussi être habilité à délivrer.');
                }

                $quantites = $lignesOrdonnance->mapWithKeys(
                    fn ($l) => [$l->ordonnance_ligne_id => $l->quantite],
                )->all();

                // Chemin de B3-a, INCHANGÉ : stock (B3-b) et traçabilité nationale (B3-c) en
                // découlent déjà, sans qu'on les rejoue ici.
                $this->delivrance->delivrer($pharmacien, $commande->ordonnance, $quantites);
            }

            // Vente libre : mouvement de stock direct, motif dédié, AUCUNE trace nationale
            // (limite héritée de B3-c : une vente libre au comptoir n'y entre pas davantage).
            foreach ($lignesLibres as $ligne) {
                $this->sortirVenteLibre($pharmacien, $commande, $ligne);
            }

            $commande->update(['statut' => StatutCommande::REMISE->value, 'remise_le' => now()]);

            return $commande->fresh();
        });
    }

    /**
     * F8 — le stock ne bouge qu'à la remise, et jamais si l'officine ne le tient pas
     * (même esprit qu'en P7-D0 : refuser priverait un patient d'un produit peut-être là).
     */
    private function sortirVenteLibre(User $pharmacien, Commande $commande, $ligne): void
    {
        if ($ligne->medicament_id === null) {
            return;
        }

        $article = StockOfficine::where('structure_id', $commande->structure_id)
            ->where('medicament_id', $ligne->medicament_id)
            ->first();

        if ($article === null || $article->stockCourant() < $ligne->quantite) {
            return;
        }

        $this->stock->mouvement($pharmacien, $article, 'sortie', $ligne->quantite, [
            'motif' => 'Remise d\'une commande',
        ]);
    }

    private function assertStatut(Commande $commande, StatutCommande $attendu): void
    {
        if ($commande->statut !== $attendu) {
            $this->refus(sprintf(
                'Cette commande est « %s » : cette action n\'est pas possible dans cet état.',
                $commande->statut->value,
            ));
        }
    }

    /** Anti-IDOR : le pharmacien ne traite que les commandes de SA propre officine. */
    private function assertOfficine(User $pharmacien, Commande $commande): void
    {
        if ((int) $pharmacien->structure_id !== (int) $commande->structure_id) {
            abort(404);
        }
    }

    private function assertHabilite(User $pharmacien): void
    {
        // Vérifiée ICI et pas seulement par le middleware : les routes du portail sont sur le
        // guard `web`, et un `permission:` au mauvais guard laisse passer (piège de P4).
        if (! $pharmacien->can(self::PERMISSION)) {
            $this->refus('Vous n\'êtes pas habilité à traiter cette commande.');
        }
    }

    /** @return never */
    private function refus(string $message, string $champ = 'commande'): void
    {
        throw ValidationException::withMessages([$champ => $message]);
    }
}
