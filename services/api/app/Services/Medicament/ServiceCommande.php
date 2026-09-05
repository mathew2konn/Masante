<?php

namespace App\Services\Medicament;

use App\Models\Commande;
use App\Models\Medicament;
use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\StructureSanitaire;
use App\Models\User;
use App\Services\ClientPaiementGeniusPay;
use App\Services\PrixMedicamentService;
use App\Services\ResolveurEtablissementRef;
use App\Support\ModeReglementCommande;
use App\Support\ModeRetraitCommande;
use App\Support\StatutCommande;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * B3-d — le patient passe, règle et annule une commande (CDC_11 §9.5).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * F6, RÉÉCRIT APRÈS B4 (VALIDÉ G5) : LE RÈGLEMENT EN LIGNE EMPRUNTE LE CANAL RÉEL
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * `disponibiliteEnLigne()`/`ouvrirPaiementEnLigne()`/`confirmerReglementEnLigne()` sont la
 * transposition TERME À TERME de `RecuRdvService` (B4-b) : checkout GeniusPay RÉEL, vraie Facture
 * Java créée puis réutilisée, règlement qui ne devient vrai qu'à la NOTIFICATION (jamais un retour
 * d'application). `commandes` porte SON PROPRE règlement (`mode_reglement`/`regle_le`/
 * `reference_reglement`/`commande_geniuspay_id`) et son PROPRE préfixe de corrélation
 * (`commande:`) — JAMAIS `factures_patient`, qui est une table de facturation DE SOINS (CMU,
 * reste à charge) dont une commande de médicaments n'est pas un acte.
 *
 * `CommissionService` N'EST NI APPELÉ NI MODIFIÉ ICI : la commission suit automatiquement le
 * mécanisme déjà générique de `PaiementNotificationController::calculerCommissionSiApplicable()`
 * (B4-a), qui se déclenche sur tout succès `canal=geniuspay` avec un établissement résoluble —
 * quel que soit ce qui est payé. `mode_reglement` reste `sur_place` (défaut) tant que
 * `ouvrirPaiementEnLigne()` n'a jamais été appelée avec succès : c'est l'ACTION du patient qui le
 * révèle, jamais une déclaration préalable — patron exact de `RecuRdvService::payer()` vs
 * `ouvrirPaiementEnLigne()`, où le mode est découvert par le chemin emprunté.
 *
 * ═══ LE VECTEUR CENTRAL : `ordonnance_requise` CESSE D'ÊTRE DÉCORATIVE (F3) ═══
 * Une ligne portant un produit à `ordonnance_requise = true` exige une ordonnance DÉSIGNÉE ; sinon
 * la commande est refusée EN NOMMANT LE PRODUIT — jamais un refus opaque. La garde vit ICI, au
 * serveur : un mobile qui grise un bouton n'empêche rien, un client modifié pourrait sinon
 * commander un antibiotique sans ordonnance (frontière CDC_01 §0.1).
 */
final class ServiceCommande
{
    /** Préfixe du `correlationId` envoyé à Java (F6) — DISTINCT de `facture-patient:` (B4-b) :
     *  une commande n'est pas un acte de soins, elle porte son propre règlement. */
    private const PREFIXE_CORRELATION = 'commande:';

    public function __construct(
        private readonly PrixMedicamentService $prix,
        private readonly ClientPaiementGeniusPay $paiementGeniusPay,
        private readonly ResolveurEtablissementRef $resolveurEtablissementRef,
    ) {}

    /**
     * Passe une commande.
     *
     * @param  array<int, array{medicament_id:int, quantite:int}>  $lignes
     *
     * @throws ValidationException
     */
    public function passer(
        User $auteur,
        MembreFamille $membre,
        StructureSanitaire $officine,
        array $lignes,
        string $modeRetrait,
        ?string $adresseLivraison,
        ?Ordonnance $ordonnance,
        ?string $commentaire,
    ): Commande {
        if (! $officine->estPharmacie()) {
            $this->refus('Une commande de médicaments ne se passe que dans une pharmacie.');
        }

        if ($lignes === []) {
            $this->refus('Une commande porte au moins un médicament.');
        }

        $modeRetraitEnum = ModeRetraitCommande::tryFrom($modeRetrait);
        if ($modeRetraitEnum === null) {
            $this->refus('Mode de retrait inconnu.');
        }

        // F7 — on enregistre le CHOIX, on ne construit pas un service de livraison : le serveur
        // refuse une commande en livraison chez une officine qui ne la fait pas.
        if ($modeRetraitEnum === ModeRetraitCommande::LIVRAISON) {
            if (! (bool) $officine->livraison_disponible) {
                $this->refus('Cette officine ne propose pas la livraison.');
            }
            if ($adresseLivraison === null || trim($adresseLivraison) === '') {
                $this->refus('Une adresse de livraison est requise.');
            }
        }

        // F11 — l'ordonnance d'autrui est refusée : elle doit appartenir au membre de CETTE
        // commande, jamais à un autre carnet.
        if ($ordonnance !== null && (int) $ordonnance->membre_id !== (int) $membre->id) {
            $this->refus('Cette ordonnance n\'appartient pas à ce carnet.');
        }

        $lignesResolues = $this->resoudreLignes($lignes, $officine, $ordonnance);

        // F3 — LA DÉCISION CENTRALE : un produit sous ordonnance exige une ordonnance DÉSIGNÉE ET
        // QUI LE PRESCRIT — désigner une ordonnance qui ne porte pas ce produit n'est pas
        // « avoir une ordonnance pour ce produit ». Sans ce second contrôle, une délivrance créée
        // à la remise (F9) porterait sur un produit absent de l'ordonnance qu'elle cite. Le refus
        // NOMME le produit dans les deux cas.
        foreach ($lignesResolues as $ligne) {
            if (! $ligne['ordonnance_requise']) {
                continue;
            }
            if ($ordonnance === null) {
                $this->refus(sprintf(
                    '« %s » nécessite une ordonnance : désignez-en une pour commander ce produit.',
                    $ligne['nom'],
                ));
            }
            if ($ligne['ordonnance_ligne_id'] === null) {
                $this->refus(sprintf(
                    '« %s » n\'est pas prescrit par l\'ordonnance désignée.',
                    $ligne['nom'],
                ));
            }
        }

        return DB::transaction(function () use (
            $auteur, $membre, $officine, $lignesResolues, $modeRetraitEnum, $adresseLivraison,
            $ordonnance, $commentaire,
        ): Commande {
            $commande = Commande::create([
                'membre_id' => $membre->id,
                'user_id' => $auteur->id,
                'structure_id' => $officine->id,
                'ordonnance_id' => $ordonnance?->id,
                'mode_retrait' => $modeRetraitEnum->value,
                'adresse_livraison' => $adresseLivraison,
                'commentaire' => $commentaire,
            ]);

            $montantTotal = 0;
            $montantConnu = true;

            foreach ($lignesResolues as $ligne) {
                $commande->lignes()->create([
                    'medicament_id' => $ligne['medicament_id'],
                    'medicament_code' => $ligne['medicament_code'],
                    'nom' => $ligne['nom'],
                    'dci' => $ligne['dci'],
                    'dosage' => $ligne['dosage'],
                    'ordonnance_requise' => $ligne['ordonnance_requise'],
                    'ordonnance_ligne_id' => $ligne['ordonnance_ligne_id'],
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire_indicatif_cfa' => $ligne['prix_unitaire_cfa'],
                ]);

                if ($ligne['prix_unitaire_cfa'] === null) {
                    $montantConnu = false;
                } else {
                    $montantTotal += $ligne['prix_unitaire_cfa'] * $ligne['quantite'];
                }
            }

            // On ne fabrique pas un montant : si UN SEUL prix est inconnu, le total reste null
            // plutôt que d'afficher une somme partielle qui aurait l'air complète.
            if ($montantConnu) {
                $commande->update(['montant_indicatif_cfa' => $montantTotal]);
            }

            return $commande->fresh('lignes');
        });
    }

    /** Annule une commande — tant que rien n'est remis (F5). */
    public function annuler(Commande $commande): Commande
    {
        if ($commande->statut === StatutCommande::REMISE) {
            $this->refus('Une commande déjà remise ne peut plus être annulée.');
        }
        if ($commande->statut === StatutCommande::ANNULEE) {
            return $commande; // idempotent
        }

        $commande->update(['statut' => StatutCommande::ANNULEE->value, 'annulee_le' => now()]);

        return $commande->fresh();
    }

    /**
     * B3-d (F6) — cette officine peut-elle encaisser cette commande en ligne AUJOURD'HUI ?
     * Zéro appel réseau si l'établissement n'a pas d'identifiant national (patron B4-b S1).
     */
    public function disponibiliteEnLigne(Commande $commande): bool
    {
        $commande->loadMissing('structure');
        $ref = $commande->structure !== null ? $this->resolveurEtablissementRef->formater($commande->structure) : null;

        return $ref !== null && $this->paiementGeniusPay->estConfigure($ref);
    }

    /**
     * Ouvre (ou RÉUTILISE) un checkout GeniusPay réel pour cette commande.
     *
     * N'est possible qu'une fois la commande ACCEPTÉE (on ne paie pas avant de savoir que la
     * pharmacie peut servir) — jamais avant, jamais après un règlement déjà acté (S6 : seule la
     * notification fait foi).
     *
     * @return array{checkout_url: ?string, reference: string}
     *
     * @throws ValidationException
     */
    public function ouvrirPaiementEnLigne(Commande $commande): array
    {
        $commande->loadMissing('structure', 'membre');

        if ($commande->estReglee()) {
            $this->refus('Cette commande a déjà été réglée.');
        }
        if ($commande->statut !== StatutCommande::ACCEPTEE) {
            $this->refus('Cette commande doit être acceptée par l\'officine avant d\'être réglée en ligne.');
        }
        if ($commande->montant_indicatif_cfa === null) {
            $this->refus('Aucun montant connu pour cette commande : le paiement en ligne n\'est pas disponible.');
        }

        $etablissementRef = $commande->structure !== null
            ? $this->resolveurEtablissementRef->formater($commande->structure) : null;
        if ($etablissementRef === null) {
            $this->refus('Cette officine n\'a pas d\'identifiant national : le paiement en ligne n\'est pas disponible.');
        }
        if (! $this->paiementGeniusPay->estConfigure($etablissementRef)) {
            $this->refus('Le paiement en ligne n\'est pas configuré pour cette officine.');
        }

        $patientRef = 'membre:'.$commande->membre_id;

        try {
            // Une vraie Facture Java, réutilisée si déjà créée — même raison exacte qu'en B4-b :
            // ServiceWebhookGeniusPay::appliquer() exige une Facture réelle pour solder un
            // paiement, dans la MÊME transaction que la transition vers SUCCESS.
            if ($commande->commande_geniuspay_id === null) {
                $factureJava = $this->paiementGeniusPay->creerFacture(
                    $etablissementRef, $patientRef, (int) $commande->montant_indicatif_cfa,
                    'Commande #'.$commande->reference,
                );
                $commande->update([
                    'commande_geniuspay_id' => $factureJava['id'],
                    'mode_reglement' => ModeReglementCommande::EN_LIGNE->value,
                ]);
            }

            $resultat = $this->paiementGeniusPay->initierCheckout([
                'factureId' => $commande->commande_geniuspay_id,
                'montant' => (int) $commande->montant_indicatif_cfa,
                'devise' => 'XOF',
                'etablissementRef' => $etablissementRef,
                'patientRef' => $patientRef,
                'correlationId' => $this->correlationIdPour($commande),
                // ORDONNANCE si un objet réel du domaine médicament existe, AUTRE sinon (vente
                // libre) — deux valeurs déjà présentes dans l'enum Java ObjetPaiement, zéro
                // ligne Java touchée (ce champ ne fait que tracer, jamais une décision).
                'objet' => $commande->ordonnance_id !== null ? 'ORDONNANCE' : 'AUTRE',
            ], (string) Str::uuid());
        } catch (RuntimeException $e) {
            // Relayé tel quel — jamais un message réinventé côté Laravel (patron B4-b).
            $this->refus($e->getMessage());
        }

        return [
            'checkout_url' => $resultat['checkoutUrl'] ?? null,
            'reference' => $commande->reference,
        ];
    }

    /**
     * SEUL point où un règlement en ligne devient vrai (S6) — appelé depuis
     * `PaiementNotificationController::reglerCommandeSiApplicable()` sur un succès GeniusPay dont
     * le `correlationId` désigne cette commande.
     *
     * Idempotent sous verrou : une notification rejouée ne modifie rien. Silencieux (jamais
     * d'exception) sur une commande introuvable ou déjà réglée — un webhook n'a jamais le droit
     * de faire échouer autre chose que lui-même.
     */
    public function confirmerReglementEnLigne(int $commandeId, string $paiementIdExterne, string $dateTransaction): void
    {
        DB::transaction(function () use ($commandeId, $paiementIdExterne, $dateTransaction): void {
            $commande = Commande::where('id', $commandeId)->lockForUpdate()->first();
            if ($commande === null || $commande->estReglee()) {
                return;
            }

            $commande->update([
                'regle_le' => Carbon::parse($dateTransaction),
                'reference_reglement' => $paiementIdExterne,
            ]);
        });
    }

    /** Parse un `correlationId` reçu en notification. `null` si le préfixe ne correspond pas à
     *  ce chemin (B4-b, ou un autre émetteur) — jamais une devinette. */
    public function commandeIdDepuisCorrelation(?string $correlationId): ?int
    {
        if ($correlationId === null || ! str_starts_with($correlationId, self::PREFIXE_CORRELATION)) {
            return null;
        }
        $id = substr($correlationId, strlen(self::PREFIXE_CORRELATION));

        return ctype_digit($id) ? (int) $id : null;
    }

    private function correlationIdPour(Commande $commande): string
    {
        return self::PREFIXE_CORRELATION.$commande->id;
    }

    /**
     * Résout chaque ligne demandée contre le référentiel RÉEL et l'officine — jamais ce que le
     * client déclare : identité de produit, prix indicatif (relevé public de CETTE officine,
     * jamais un second calcul), et le lien à l'ordonnance désignée quand il existe.
     *
     * @param  array<int, array{medicament_id:int, quantite:int}>  $lignes
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function resoudreLignes(array $lignes, StructureSanitaire $officine, ?Ordonnance $ordonnance): array
    {
        $ordonnanceLignesParMedicament = $ordonnance !== null
            ? $ordonnance->lignes()->get()->keyBy('medicament_id')
            : collect();

        $resolues = [];

        foreach ($lignes as $entree) {
            $quantite = (int) ($entree['quantite'] ?? 0);
            if ($quantite < 1) {
                $this->refus('Chaque ligne de commande porte une quantité d\'au moins 1.');
            }

            $medicament = Medicament::find($entree['medicament_id'] ?? null);
            if ($medicament === null) {
                $this->refus('Un des médicaments demandés est introuvable.');
            }

            $offre = $this->offrePourCetteOfficine($medicament, $officine);

            $ordonnanceLigne = $ordonnanceLignesParMedicament->get($medicament->id);

            $resolues[] = [
                'medicament_id' => $medicament->id,
                'medicament_code' => $medicament->code ?? null,
                'nom' => $medicament->libelle,
                'dci' => $medicament->nom_generique,
                'dosage' => $medicament->dosage,
                'ordonnance_requise' => (bool) $medicament->ordonnance_requise,
                'ordonnance_ligne_id' => $ordonnanceLigne?->id,
                'quantite' => $quantite,
                'prix_unitaire_cfa' => $offre['prix_cfa'] ?? null,
            ];
        }

        return $resolues;
    }

    /**
     * Le prix indicatif de CE médicament chez CETTE officine, tel qu'affiché au comparateur —
     * jamais un second calcul, jamais une valeur déclarée par le client.
     */
    private function offrePourCetteOfficine(Medicament $medicament, StructureSanitaire $officine): array
    {
        $offre = $this->prix->comparer($medicament)
            ->first(fn (array $o) => ($o['structure']->id ?? null) === $officine->id);

        return $offre ?? [];
    }

    /** @return never */
    private function refus(string $message, string $champ = 'commande'): void
    {
        throw ValidationException::withMessages([$champ => $message]);
    }
}
