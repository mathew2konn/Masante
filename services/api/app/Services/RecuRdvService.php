<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\Interne\PaiementNotificationController;
use App\Models\FacturePatient;
use App\Models\Paiement;
use App\Models\RecuRdv;
use App\Models\RendezVous;
use App\Support\MomentPaiement;
use App\Support\StatutFacturePatient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Flux paiement + reçu de RDV (Analyse_Delta_RDV N1/N2/N3), et depuis B4-b son second chemin,
 * réel celui-là (§9 de `plan.md` PLAN 3, ADR-056).
 *
 * ⚠️ `payer()` reste PAIEMENT SIMULÉ (pas de passerelle Mobile Money réelle — cf. FT5 / limite
 * CNAM) : statut `paye` d'emblée, `transaction_ref` factice. Le scan/validation à l'accueil et le
 * rôle Caisse (N7) relèvent du Module 4.
 *
 * `ouvrirPaiementEnLigne()`/`confirmerReglementEnLigne()` (B4-b) sont RÉELS : le règlement passe
 * par GeniusPay (montage A, P5.6b) via {@see ClientPaiementGeniusPay}, et ne devient vrai qu'à la
 * NOTIFICATION reçue par {@see PaiementNotificationController}
 * — jamais un retour d'application, jamais un choix client (S6). Les deux chemins coexistent, ni
 * l'un ni l'autre n'est retiré (S7) : le règlement d'aujourd'hui reste intact pour un
 * établissement non configuré pour l'encaissement en ligne.
 *
 * Le QR de check-in (N3) est un token signé HMAC AUTONOME, à secret CLOISONNÉ (distinct du QR
 * carnet `QrTokenService` et du code CMU `CarteCmuService`) : il ne porte AUCUNE donnée médicale
 * et n'ouvre JAMAIS le dossier — il ne fait que référencer le RDV pour le check-in à l'accueil.
 */
class RecuRdvService
{
    /** Durée de vie du code de check-in régénéré à chaque affichage, en minutes. */
    private const CODE_TTL_MINUTES = 15;

    /** Préfixe du `correlationId` envoyé à Java (B4-b) — GÉNÉRIQUE, pas `rdv:` : une
     *  `FacturePatient` peut naître d'un acte sans rendez-vous (B3-d demain, même mécanisme). */
    private const PREFIXE_CORRELATION = 'facture-patient:';

    public function __construct(
        private readonly ClientPaiementGeniusPay $paiementGeniusPay,
        private readonly ResolveurEtablissementRef $resolveurEtablissementRef,
    ) {}

    /**
     * Encaisse (simulé) un RDV et émet son reçu. Idempotence : un seul reçu par RDV (unique en base).
     *
     * @throws ValidationException  si le RDV n'a pas de tarif exploitable ou est déjà réglé.
     */
    public function payer(RendezVous $rdv, string $mode): RecuRdv
    {
        $rdv->loadMissing(['medecin', 'structure', 'service', 'recu', 'membre']);

        if ($rdv->recu !== null) {
            throw ValidationException::withMessages(['paiement' => 'Ce rendez-vous a déjà un reçu.']);
        }

        $tarif = $this->tarifPour($rdv);
        if ($tarif === null) {
            throw ValidationException::withMessages([
                'montant' => 'Aucun tarif de consultation n\'est défini pour ce rendez-vous.',
            ]);
        }
        [$montant, $tarifSource] = $tarif;

        return DB::transaction(function () use ($rdv, $mode, $montant, $tarifSource) {
            $paiement = Paiement::create([
                'rendez_vous_id'  => $rdv->id,
                'montant'         => $montant,
                'mode'            => $mode,
                'statut'          => 'paye',                                // simulé
                'transaction_ref' => 'SIM-'.strtoupper(Str::random(12)),   // factice
            ]);

            // Lot 5 (reprise du flux RDV, v2 — 2026-08-27) : ÉCRITURE ADDITIONNELLE, jamais un
            // remplacement — le `Paiement` ci-dessus n'est ni modifié ni retiré, c'est toujours
            // lui que lit le reçu (`vue()`, inchangée). `factures_patient` devient EN PLUS la
            // source interrogée par les lots de recouvrement/reporting. Ce point d'entrée EST le
            // règlement (simulé, toujours immédiat et complet) : la facture naît déjà soldée,
            // `statut = PAYEE` d'emblée, jamais `A_REGLER`. Aucun calcul CMU n'existe dans ce
            // flux : simple report du montant.
            $membre = $rdv->membre;
            FacturePatient::create([
                'structure_sanitaire_id'     => $rdv->structure_id,
                'patient_id'                 => $membre->user_id,
                'beneficiaire_id'            => $membre->est_titulaire ? null : $membre->id,
                'rendez_vous_id'             => $rdv->id,
                'reference'                  => $this->genererReferenceFacturePatient(),
                'moment_paiement'            => MomentPaiement::AVANT_ACTE,
                'montant_brut'               => $montant,
                'tarif_source'               => $tarifSource,
                'montant_pris_en_charge_cmu' => 0,
                'montant_reste_a_charge'     => $montant,
                'statut'                     => StatutFacturePatient::PAYEE,
                'paiement_en_ligne_autorise' => true,
                'date_emission'              => now(),
                'date_reglement'             => now(),
            ]);

            return RecuRdv::create([
                'rendez_vous_id' => $rdv->id,
                'paiement_id'    => $paiement->id,
                'reference'      => $this->genererReference(),
                'statut'         => 'paye',
                'expires_at'     => $this->expirationPour($rdv),
            ]);
        });
    }

    /**
     * Ce rendez-vous est-il réglé ? Source de vérité : `factures_patient` (lot 5, 2026-08-27).
     *
     * ═══ CORRECTION B4-b (2026-09-04) ═══ Filtre désormais sur le STATUT, jamais la seule
     * EXISTENCE d'une ligne. Jusqu'à B4-b, la seule façon de faire naître une `FacturePatient`
     * était `payer()`, qui la crée déjà `PAYEE` — l'existence ÉTAIT le règlement, par accident.
     * `ouvrirPaiementEnLigne()` pose désormais une facture `A_REGLER` AVANT tout paiement réel
     * (S5) : sans ce filtre, cette méthode répondrait `true` avant que GeniusPay n'ait rien
     * confirmé, et `RendezVousValidationService::terminer()` (B1-d) clôturerait un rendez-vous
     * jamais payé. Un invariant qui ne tenait que par « une seule façon d'écrire cette ligne »
     * cesse de tenir dès qu'une seconde apparaît — trouvé au G0 de B4-b, pas au G2.
     *
     * // TODO repli historique — supprimable après une date à définir avec Mathieu, une fois
     * qu'aucun rendez-vous actif ne dépend plus de `paiements` (RDV réglés avant ce lot, qui
     * n'ont qu'un couple `Paiement`+`RecuRdv`, sans `facture_patient`).
     */
    public function estRegle(RendezVous $rdv): bool
    {
        if (FacturePatient::where('rendez_vous_id', $rdv->id)
            ->whereIn('statut', [
                StatutFacturePatient::PAYEE->value,
                StatutFacturePatient::PRISE_EN_CHARGE_TOTALE->value,
            ])
            ->exists()) {
            return true;
        }

        // TODO repli historique — supprimable après une date à définir avec Mathieu, une fois
        // qu'aucun rendez-vous actif ne dépend plus de `paiements`.
        return Paiement::where('rendez_vous_id', $rdv->id)->where('statut', 'paye')->exists();
    }

    /** B4-b (D10/S13, portail) — un checkout est-il ouvert et pas encore confirmé ? Lecture seule,
     *  aucune action : le règlement reste un fait que seule la notification établit (S6). */
    public function paiementEnLigneEnAttente(RendezVous $rdv): bool
    {
        return FacturePatient::where('rendez_vous_id', $rdv->id)
            ->where('statut', StatutFacturePatient::A_REGLER->value)
            ->exists();
    }

    /**
     * B4-b (S7) — cet établissement peut-il encaisser ce rendez-vous en ligne AUJOURD'HUI ?
     * Zéro appel réseau si l'établissement n'a pas d'identifiant national (S1) : le refus est
     * alors structurel, pas une question à poser au microservice.
     */
    public function disponibiliteEnLigne(RendezVous $rdv): bool
    {
        $rdv->loadMissing('structure');
        $ref = $rdv->structure !== null ? $this->resolveurEtablissementRef->formater($rdv->structure) : null;

        return $ref !== null && $this->paiementGeniusPay->estConfigure($ref);
    }

    /**
     * B4-b (S5/S12) — ouvre (ou RÉUTILISE) un checkout GeniusPay pour ce rendez-vous.
     *
     * Une seule ligne `FacturePatient`, qui CHANGE d'état (`A_REGLER → PAYEE` à la notification,
     * {@see confirmerReglementEnLigne()}) — jamais une seconde créée. Retaper « Payer en ligne »
     * réutilise la facture `A_REGLER` déjà ouverte pour ce RDV, donc le MÊME `factureId` côté
     * Java, qui réutilise lui-même le checkout encore vivant (`ServiceGeniusPay::executer()`).
     *
     * @return array{checkout_url: ?string, reference: string}
     *
     * @throws ValidationException  déjà réglé, aucun tarif, établissement non configuré, ou refus
     *                               du microservice (plancher, marchand — message RELAYÉ tel quel,
     *                               jamais réinventé : l'autorité sur ces règles reste Java).
     */
    public function ouvrirPaiementEnLigne(RendezVous $rdv): array
    {
        $rdv->loadMissing(['structure', 'service', 'medecin', 'membre', 'recu']);

        if ($rdv->recu !== null) {
            throw ValidationException::withMessages(['paiement' => 'Ce rendez-vous a déjà un reçu.']);
        }

        $tarif = $this->tarifPour($rdv);
        if ($tarif === null) {
            throw ValidationException::withMessages([
                'montant' => 'Aucun tarif de consultation n\'est défini pour ce rendez-vous.',
            ]);
        }
        [$montant, $tarifSource] = $tarif;

        $etablissementRef = $rdv->structure !== null ? $this->resolveurEtablissementRef->formater($rdv->structure) : null;
        if ($etablissementRef === null) {
            throw ValidationException::withMessages([
                'paiement' => 'Cet établissement n\'a pas d\'identifiant national : le paiement en ligne n\'est pas disponible.',
            ]);
        }
        if (! $this->paiementGeniusPay->estConfigure($etablissementRef)) {
            throw ValidationException::withMessages([
                'paiement' => 'Le paiement en ligne n\'est pas configuré pour cet établissement.',
            ]);
        }

        $facture = FacturePatient::where('rendez_vous_id', $rdv->id)
            ->where('statut', StatutFacturePatient::A_REGLER->value)
            ->first();

        $membre = $rdv->membre;
        $patientRef = 'membre:'.$rdv->membre_id;

        if ($facture === null) {
            $facture = FacturePatient::create([
                'structure_sanitaire_id'     => $rdv->structure_id,
                'patient_id'                 => $membre->user_id,
                'beneficiaire_id'            => $membre->est_titulaire ? null : $membre->id,
                'rendez_vous_id'             => $rdv->id,
                'reference'                  => $this->genererReferenceFacturePatient(),
                'moment_paiement'            => MomentPaiement::AVANT_ACTE,
                'montant_brut'               => $montant,
                'tarif_source'               => $tarifSource,
                'montant_pris_en_charge_cmu' => 0,
                'montant_reste_a_charge'     => $montant,
                'statut'                     => StatutFacturePatient::A_REGLER,
                'paiement_en_ligne_autorise' => true,
                'date_emission'              => now(),
            ]);
        }

        try {
            // Une vraie Facture Java, PAS un identifiant opaque (écart trouvé en lisant le code,
            // pas au G1) : le webhook de succès appelle `ServiceFacturation::enregistrerReglement`
            // dans la MÊME transaction que la transition vers SUCCESS — sans facture réelle, cet
            // appel lève et TOUT s'annule, y compris la transition. Réutilisée si déjà créée
            // (retaper « Payer en ligne » ne doit jamais fabriquer une seconde facture Java).
            if ($facture->facture_geniuspay_id === null) {
                $factureJava = $this->paiementGeniusPay->creerFacture(
                    $etablissementRef, $patientRef, $montant, 'Consultation — RDV #'.$rdv->id,
                );
                $facture->update(['facture_geniuspay_id' => $factureJava['id']]);
            }

            $resultat = $this->paiementGeniusPay->initierCheckout([
                'factureId'        => $facture->facture_geniuspay_id,
                'montant'          => $montant,
                'devise'           => 'XOF',
                'etablissementRef' => $etablissementRef,
                'patientRef'       => $patientRef,
                'correlationId'    => $this->correlationIdPour($facture),
                'objet'            => 'RENDEZ_VOUS',
            ], (string) Str::uuid());
        } catch (RuntimeException $e) {
            // Relayé tel quel (plancher R17, marchand révoqué entre le cache et l'appel…) — jamais
            // un message réinventé côté Laravel : l'autorité sur ces refus reste Java (S13/§9.6).
            throw ValidationException::withMessages(['paiement' => $e->getMessage()]);
        }

        return [
            'checkout_url' => $resultat['checkoutUrl'] ?? null,
            'reference'    => $facture->reference,
        ];
    }

    /**
     * B4-b (S6) — SEUL point où un règlement en ligne devient vrai. Appelé depuis
     * {@see PaiementNotificationController} sur un succès
     * GeniusPay dont le `correlationId` désigne une `FacturePatient` (voir
     * {@see facturePatientIdDepuisCorrelation()}).
     *
     * Idempotent sous verrou : une notification rejouée (même ou différent `paiementIdExterne`)
     * ne crée ni un second `Paiement`, ni un second `RecuRdv` — la facture déjà `PAYEE` court-circuite.
     * Silencieux (jamais d'exception) sur une référence introuvable ou déjà close : un webhook
     * n'a jamais le droit de faire échouer autre chose que lui-même.
     */
    public function confirmerReglementEnLigne(int $facturePatientId, string $paiementIdExterne, string $dateTransaction): void
    {
        DB::transaction(function () use ($facturePatientId, $paiementIdExterne, $dateTransaction) {
            $facture = FacturePatient::where('id', $facturePatientId)->lockForUpdate()->first();
            if ($facture === null) {
                Log::warning('B4-b : règlement en ligne pour une FacturePatient introuvable.', [
                    'facturePatientId' => $facturePatientId,
                ]);

                return;
            }
            if ($facture->statut === StatutFacturePatient::PAYEE) {
                return; // idempotent : notification rejouée, rien à refaire.
            }
            if ($facture->rendez_vous_id === null) {
                // Une FacturePatient A_REGLER sans rendez-vous n'est pas de CE chemin (B3-d,
                // futur — même préfixe de corrélation, un second appelant y répondra un jour).
                return;
            }

            $rdv = RendezVous::find($facture->rendez_vous_id);
            if ($rdv === null || $rdv->recu !== null) {
                return;
            }

            $paiement = Paiement::create([
                'rendez_vous_id'  => $rdv->id,
                'montant'         => $facture->montant_brut,
                'mode'            => 'geniuspay',
                'statut'          => 'paye',
                'transaction_ref' => $paiementIdExterne,
            ]);

            $facture->update([
                'statut'         => StatutFacturePatient::PAYEE,
                'date_reglement' => Carbon::parse($dateTransaction),
            ]);

            RecuRdv::create([
                'rendez_vous_id' => $rdv->id,
                'paiement_id'    => $paiement->id,
                'reference'      => $this->genererReference(),
                'statut'         => 'paye',
                'expires_at'     => $this->expirationPour($rdv),
            ]);
        });
    }

    /** Parse un `correlationId` reçu en notification. `null` si le préfixe ne correspond pas à ce
     *  chemin (B4-a, ou un autre émetteur futur) — jamais une devinette. */
    public function facturePatientIdDepuisCorrelation(?string $correlationId): ?int
    {
        if ($correlationId === null || ! str_starts_with($correlationId, self::PREFIXE_CORRELATION)) {
            return null;
        }
        $id = substr($correlationId, strlen(self::PREFIXE_CORRELATION));

        return ctype_digit($id) ? (int) $id : null;
    }

    /** `correlationId` envoyé à Java — générique, réutilisable par un futur émetteur (B3-d). */
    private function correlationIdPour(FacturePatient $facture): string
    {
        return self::PREFIXE_CORRELATION.$facture->id;
    }

    /**
     * Vue « reçu » présentable + code de check-in signé (régénéré à chaque appel).
     *
     * @return array<string, mixed>
     */
    public function vue(RecuRdv $recu): array
    {
        $rdv = $recu->rendezVous()->with([
            'membre:id,nom,prenom',
            'structure:id,nom,commune',
            'service:id,nom_service',
            'medecin:id,titre,nom,prenom',
        ])->first();

        $paiement = $recu->paiement;
        $medecin = $rdv?->medecin;

        return [
            'reference'  => $recu->reference,
            'statut'     => $recu->statut,
            'expires_at' => optional($recu->expires_at)->toIso8601String(),
            'patient'    => $rdv?->membre ? trim($rdv->membre->prenom.' '.$rdv->membre->nom) : null,
            'structure'  => $rdv?->structure ? ['nom' => $rdv->structure->nom, 'commune' => $rdv->structure->commune] : null,
            'service'    => $rdv?->service?->nom_service,
            'medecin'    => $medecin ? trim($medecin->titre.' '.$medecin->prenom.' '.$medecin->nom) : null,
            'date'       => optional($rdv?->date_confirmee ?? $rdv?->date_souhaitee)->toDateString(),
            'montant'    => $paiement?->montant,
            'mode'       => $paiement?->mode,
            'transaction_ref' => $paiement?->transaction_ref,
            // QR de check-in (N3) : signé, autonome, cloisonné — n'ouvre pas le dossier.
            'code'            => $this->genererCode($recu),
            'code_expire_dans' => self::CODE_TTL_MINUTES * 60,
        ];
    }

    /**
     * Vérifie un code de check-in scanné à l'accueil (4.5 / N3) et renvoie le reçu correspondant.
     *
     * Contrôles, dans l'ordre : forme du code, signature HMAC (comparaison en TEMPS CONSTANT,
     * `hash_equals` → pas d'oracle de timing), type de payload, expiration, existence du reçu.
     * Ce code ne donne JAMAIS accès au dossier : il ne référence qu'un rendez-vous.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException  404 code invalide · 410 expiré
     */
    public function verifierCode(string $code): RecuRdv
    {
        $morceaux = explode('.', $code);
        abort_if(count($morceaux) !== 2, 404, 'Code de reçu invalide.');

        [$corps, $signature] = $morceaux;
        $attendue = hash_hmac('sha256', $corps, $this->secret());

        // hash_equals : la comparaison ne fuit pas la position du premier octet divergent.
        abort_if(! hash_equals($attendue, $signature), 404, 'Code de reçu invalide.');

        $payload = json_decode((string) base64_decode(strtr($corps, '-_', '+/'), true), true);

        abort_if(! is_array($payload) || ($payload['typ'] ?? null) !== 'rdv', 404, 'Code de reçu invalide.');
        abort_if(($payload['exp'] ?? 0) < now()->timestamp, 410, 'Code de reçu expiré.');

        $recu = RecuRdv::where('reference', $payload['ref'] ?? '')
            ->where('rendez_vous_id', $payload['rdv'] ?? 0)
            ->first();

        abort_if($recu === null, 404, 'Reçu introuvable.');

        return $recu;
    }

    /** Référence unique FPA-AAAA-XXXXXX (opaque), même forme que `genererReference()` du reçu. */
    private function genererReferenceFacturePatient(): string
    {
        do {
            $ref = 'FPA-'.date('Y').'-'.strtoupper(Str::random(6));
        } while (FacturePatient::where('reference', $ref)->exists());

        return $ref;
    }

    /**
     * B1-a — Le tarif est désormais PORTÉ PAR LE SERVICE (D3 du plan G1), avec repli sur le
     * médecin puis la structure : un refus bruyant casserait tous les établissements dont le
     * service n'a pas encore de tarif configuré. La SOURCE retenue est toujours renvoyée — un
     * montant ne doit jamais mentir sur d'où il vient (précédent `delai_source` P6.7b,
     * `provenance` P6.8d) — et posée sur la facture (`factures_patient.tarif_source`).
     *
     * PUBLIQUE depuis B1-b (D7) : APERÇU du tarif AVANT paiement, affiché sur la fiche RDV
     * (patient comme staff) — même méthode, jamais une seconde façon de calculer le même montant.
     * Elle ne crée rien : c'est `payer()` qui persiste, cette lecture est sans effet de bord.
     *
     * @return array{0: int, 1: string}|null [montant, source] ; null si aucun tarif exploitable.
     */
    public function tarifPour(RendezVous $rdv): ?array
    {
        if ($rdv->service?->tarif_consultation_cfa !== null) {
            return [(int) $rdv->service->tarif_consultation_cfa, 'service'];
        }
        if ($rdv->medecin?->tarif_consultation !== null) {
            return [(int) $rdv->medecin->tarif_consultation, 'medecin'];
        }
        if ($rdv->structure?->tarif_min_cfa !== null) {
            return [(int) $rdv->structure->tarif_min_cfa, 'structure'];
        }

        return null;
    }

    /** Le reçu expire à la fin de la journée du RDV (date confirmée sinon souhaitée). */
    private function expirationPour(RendezVous $rdv): Carbon
    {
        $date = $rdv->date_confirmee ?? $rdv->date_souhaitee;

        return Carbon::parse($date)->endOfDay();
    }

    /** Référence unique MS-RECU-AAAA-XXXXXX (opaque, pas d'id membre). */
    private function genererReference(): string
    {
        do {
            $ref = 'MS-RECU-'.date('Y').'-'.strtoupper(Str::random(6));
        } while (RecuRdv::where('reference', $ref)->exists());

        return $ref;
    }

    /**
     * Code de check-in : payload compact signé HMAC. Format = base64url(json).signature.
     * AUCUNE donnée médicale, aucun matricule — seulement une référence opaque au RDV.
     */
    private function genererCode(RecuRdv $recu): string
    {
        $payload = [
            'v'   => 1,
            'typ' => 'rdv',
            'ref' => $recu->reference,
            'rdv' => $recu->rendez_vous_id,
            'exp' => now()->addMinutes(self::CODE_TTL_MINUTES)->timestamp,
        ];

        $corps = $this->base64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $corps, $this->secret());

        return $corps.'.'.$signature;
    }

    /** Secret de signature à SÉPARATION DE DOMAINE (distinct du QR dossier et du code CMU). */
    private function secret(): string
    {
        return hash_hmac('sha256', 'recu-rdv', (string) config('app.key'));
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
