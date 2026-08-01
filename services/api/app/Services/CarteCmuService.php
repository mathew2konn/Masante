<?php

namespace App\Services;

use App\Models\MembreFamille;
use Illuminate\Support\Str;

/**
 * F2.3 — Carte CMU numérique (couche de présentation, doc « Modification F2.3 »).
 *
 * Construit la vue « carte » d'un membre + un CODE DE PRÉSENTATION autonome : une assertion
 * signée HMAC-SHA256 du STATUT CMU déclaré (jamais le numéro, jamais le matricule, jamais un
 * règlement). Le code est vérifiable hors-ligne (tolérant au réseau, §3.2) et n'accorde AUCUN
 * accès au dossier — il est distinct du token QR de partage de dossier (QrTokenService).
 *
 * La vérification par un agent d'accueil (contrôle de signature côté structure) relève du
 * Module 3 (les `agents_garde` n'existent pas encore) : ici on ne fait qu'ÉMETTRE la carte.
 */
class CarteCmuService
{
    /**
     * Vue « carte » d'un membre : titulaire, numéro masqué, statut, validité + code présentable.
     *
     * @return array<string, mixed>
     */
    public function pour(MembreFamille $membre): array
    {
        $disponible = $this->carteDisponible($membre);

        return [
            'titulaire'         => trim($membre->prenom.' '.$membre->nom),
            'cmu_numero_masque' => $membre->cmu_numero_masque,
            'cmu_statut'        => $membre->cmu_statut,
            'cmu_validite'      => optional($membre->cmu_validite)->toDateString(),
            'expiration_proche' => $this->expirationProche($membre),
            'disponible'        => $disponible,
            // Code présentable uniquement au palier requis (sinon null : carte non justificative, §3.3).
            'code_presentation' => $disponible ? $this->genererCode($membre) : null,
            'code_expire_dans'  => $disponible ? $this->ttl() * 60 : null,
        ];
    }

    /** La carte est-elle présentable comme justificatif ? (palier vérifié — sauf stub dev). */
    private function carteDisponible(MembreFamille $membre): bool
    {
        if (! config('masante.cmu.exiger_palier_verifie')) {
            return true; // dev : auth/OTP pas encore là → stub présentable pour tester.
        }

        return $membre->user->compteEstVerifie();
    }

    /** Rappel visuel : validité dans la fenêtre d'alerte (défaut 30 j) et statut actif. */
    private function expirationProche(MembreFamille $membre): bool
    {
        if ($membre->cmu_statut !== 'actif' || $membre->cmu_validite === null) {
            return false;
        }

        $jours = (int) config('masante.cmu.alerte_expiration_jours', 30);

        return $membre->cmu_validite->isBetween(now(), now()->addDays($jours));
    }

    /**
     * Émet le code de présentation : payload compact du statut déclaré, signé HMAC.
     * Format = base64url(json).signature. AUCUN numéro CMU ni matricule.
     */
    private function genererCode(MembreFamille $membre): string
    {
        $payload = [
            'v'   => 1,
            'typ' => 'cmu',
            'ref' => (string) Str::uuid(),                       // corrélation opaque (pas d'id membre).
            'st'  => $membre->cmu_statut,
            'val' => optional($membre->cmu_validite)->toDateString(),
            'exp' => now()->addMinutes($this->ttl())->timestamp,
        ];

        $corps = $this->base64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $corps, $this->secret());

        return $corps.'.'.$signature;
    }

    private function ttl(): int
    {
        return (int) config('masante.cmu.code_ttl_minutes', 10);
    }

    /** Secret de signature à SÉPARATION DE DOMAINE (distinct du secret QR dossier). */
    private function secret(): string
    {
        return hash_hmac('sha256', 'carte-cmu', (string) config('app.key'));
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
