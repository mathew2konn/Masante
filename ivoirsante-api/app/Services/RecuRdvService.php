<?php

namespace App\Services;

use App\Models\Paiement;
use App\Models\RecuRdv;
use App\Models\RendezVous;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Flux paiement + reçu de RDV (Analyse_Delta_RDV N1/N2/N3).
 *
 * ⚠️ PAIEMENT SIMULÉ (pas de passerelle Mobile Money réelle — cf. FT5 / limite CNAM). On modélise
 * l'encaissement : statut `paye` d'emblée, `transaction_ref` factice. Le scan/validation à
 * l'accueil et le rôle Caisse (N7) relèvent du Module 4.
 *
 * Le QR de check-in (N3) est un token signé HMAC AUTONOME, à secret CLOISONNÉ (distinct du QR
 * carnet `QrTokenService` et du code CMU `CarteCmuService`) : il ne porte AUCUNE donnée médicale
 * et n'ouvre JAMAIS le dossier — il ne fait que référencer le RDV pour le check-in à l'accueil.
 */
class RecuRdvService
{
    /** Durée de vie du code de check-in régénéré à chaque affichage, en minutes. */
    private const CODE_TTL_MINUTES = 15;

    /**
     * Encaisse (simulé) un RDV et émet son reçu. Idempotence : un seul reçu par RDV (unique en base).
     *
     * @throws ValidationException  si le RDV n'a pas de tarif exploitable ou est déjà réglé.
     */
    public function payer(RendezVous $rdv, string $mode): RecuRdv
    {
        $rdv->loadMissing(['medecin', 'structure', 'recu']);

        if ($rdv->recu !== null) {
            throw ValidationException::withMessages(['paiement' => 'Ce rendez-vous a déjà un reçu.']);
        }

        $montant = $this->montantPour($rdv);
        if ($montant === null) {
            throw ValidationException::withMessages([
                'montant' => 'Aucun tarif de consultation n\'est défini pour ce rendez-vous.',
            ]);
        }

        return DB::transaction(function () use ($rdv, $mode, $montant) {
            $paiement = Paiement::create([
                'rendez_vous_id'  => $rdv->id,
                'montant'         => $montant,
                'mode'            => $mode,
                'statut'          => 'paye',                                // simulé
                'transaction_ref' => 'SIM-'.strtoupper(Str::random(12)),   // factice
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

    /** Montant = tarif du médecin choisi (F3.5) sinon tarif plancher de la structure ; null si aucun. */
    private function montantPour(RendezVous $rdv): ?int
    {
        $tarif = $rdv->medecin?->tarif_consultation ?? $rdv->structure?->tarif_min_cfa;

        return $tarif !== null ? (int) $tarif : null;
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
