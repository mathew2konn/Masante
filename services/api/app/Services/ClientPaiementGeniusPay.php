<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client Laravel → paiement-service (Java), canal GeniusPay (B4, montage A, ADR-056).
 *
 * Laravel devient ÉMETTEUR pour la première fois (S4/S5/S10) : chaque appel est signé par
 * {@see SigneurPrincipalSortant} avec le rôle {@code SYSTEME}, miroir exact de ce que
 * {@code ServicePrincipal} (Java) exige côté vérification.
 *
 * <b>Aucune règle métier ici</b> (CDC_02 §0.1) : ce client transporte et signe, il ne décide de
 * rien — l'exonération de commission, le palier, la disponibilité réelle d'un établissement
 * viennent tous du service appelé, jamais recalculés ou devinés ici.
 */
class ClientPaiementGeniusPay
{
    private const SUB = 'laravel-masante';

    private const ROLE = 'SYSTEME';

    /** Durée de mise en cache de `estConfigure()` — un cache, pas une copie (S7). */
    private const CACHE_MINUTES = 5;

    public function __construct(private readonly SigneurPrincipalSortant $signeur) {}

    /**
     * Ouvre (ou réutilise) un checkout pour une facture déjà créée côté paiement-service (P5.2a).
     *
     * @param  array{factureId:string, montant:int, devise?:string, etablissementRef:string, patientRef?:string, correlationId?:string, objet?:string}  $demande
     * @return array<string, mixed> la vue rendue par `GeniusPayController::initier()`
     *
     * @throws RuntimeException si le service est injoignable ou refuse — aucun checkout inventé
     */
    public function initierCheckout(array $demande, string $cleIdempotence): array
    {
        return $this->appeler(
            'POST',
            '/api/v1/interne/geniuspay/paiements',
            $demande,
            ['Idempotency-Key' => $cleIdempotence],
        );
    }

    /**
     * Consulte une transaction par sa référence interne (`MS-…`).
     *
     * @return array<string, mixed>
     */
    public function consulter(string $referenceInterne): array
    {
        return $this->appeler('GET', '/api/v1/interne/geniuspay/paiements/'.$referenceInterne);
    }

    /**
     * L'établissement peut-il encaisser en ligne AUJOURD'HUI ? (S7).
     *
     * Interroge le microservice — qui seul connaît la liste des marchands, elle N'EST PAS recopiée
     * ici — et met la réponse en cache quelques minutes : ça se périme seul, ce n'est jamais la
     * source. Un service injoignable répond « non configuré » sans mettre le résultat en cache :
     * une panne transitoire ne doit pas suspendre un établissement réel plus longtemps que la
     * panne elle-même, et « rien n'est proposé qui ne puisse aboutir » reste le sens sûr.
     */
    public function estConfigure(string $etablissementRef): bool
    {
        $cle = 'geniuspay:marchand-configure:'.$etablissementRef;
        $enCache = Cache::get($cle);
        if ($enCache !== null) {
            return (bool) $enCache;
        }

        try {
            $reponse = $this->appeler('GET', '/api/v1/interne/geniuspay/marchands/'.$etablissementRef);
        } catch (RuntimeException) {
            return false;
        }

        $configure = (bool) ($reponse['configure'] ?? false);
        Cache::put($cle, $configure, now()->addMinutes(self::CACHE_MINUTES));

        return $configure;
    }

    /**
     * @param  array<string, mixed>  $corps
     * @param  array<string, string>  $entetesSupplementaires
     * @return array<string, mixed>
     */
    private function appeler(string $methode, string $chemin, array $corps = [], array $entetesSupplementaires = []): array
    {
        $entetes = array_merge(
            $this->signeur->signer($methode, $chemin, self::SUB, [self::ROLE]),
            $entetesSupplementaires,
        );

        $base = rtrim((string) config('masante.paiement_service.base_url'), '/');
        $requete = Http::withHeaders($entetes)
            ->connectTimeout((float) config('masante.paiement_service.timeout_connexion_s'))
            ->timeout((float) config('masante.paiement_service.timeout_lecture_s'));

        try {
            $reponse = $methode === 'GET' ? $requete->get($base.$chemin) : $requete->post($base.$chemin, $corps);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Paiement-service injoignable ({$chemin}).", previous: $e);
        }

        if (! $reponse->successful()) {
            throw new RuntimeException(
                "Paiement-service a répondu {$reponse->status()} sur {$chemin} : ".$reponse->body()
            );
        }

        return (array) $reponse->json();
    }
}
