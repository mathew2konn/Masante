<?php

namespace App\Services;

use App\Models\AccesDossier;
use App\Models\MembreFamille;
use App\Models\TokenQr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cœur du système QR dynamique (Sécurité §5). Génère des tokens à usage unique donnant un
 * accès temporaire au dossier d'un membre, sans jamais exposer le matricule interne.
 *
 * Garanties :
 *  - Dynamique : un nouveau token (UUID v4) à chaque génération.
 *  - Signé : HMAC-SHA256 lie l'UUID, l'instant et le membre (intégrité).
 *  - Confidentiel en base : on stocke le HASH du token, pas le token lui-même (§5.2).
 *  - Éphémère : expiration 10 minutes.
 *  - Usage unique : consommation sous verrou pessimiste (lockForUpdate) → pas de rejeu (§5.3).
 *
 * La consommation (scan) est ici utilisable côté serveur ; son exposition HTTP côté agent
 * est branchée au Module 3 (les `agents_garde` n'existent pas encore).
 */
class QrTokenService
{
    /** Durée de vie d'un token QR, en minutes (§5.1 Sécurité). */
    private const TTL_MINUTES = 10;

    /**
     * Génère un token pour un membre et renvoie le contenu réel du QR + le délai d'expiration.
     *
     * @return array{qr: string, expires_in: int}
     */
    public function generer(MembreFamille $membre): array
    {
        $uuid      = (string) Str::uuid();
        $timestamp = now()->timestamp;

        // Signature HMAC : lie l'UUID, l'instant et le membre, avec un secret serveur.
        $payload   = $uuid.'|'.$timestamp.'|'.$membre->id;
        $signature = hash_hmac('sha256', $payload, $this->secret());

        // Ce qui est RÉELLEMENT encodé dans le QR : un identifiant opaque (jamais le matricule).
        $tokenPublic = $uuid.'.'.$signature;

        TokenQr::create([
            'membre_id'  => $membre->id,
            'token_hash' => hash('sha256', $tokenPublic), // on stocke le HASH, pas le token.
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'used_at'    => null,
        ]);

        return ['qr' => $tokenPublic, 'expires_in' => self::TTL_MINUTES * 60];
    }

    /**
     * Valide puis consomme un token (usage unique) et journalise l'accès au dossier.
     * Renvoie le membre dont le dossier est ouvert.
     *
     * @param  array{agent_id?: int|null, etablissement?: string|null, ip?: string|null, sections?: array<string>|null}  $contexte
     */
    public function validerEtConsommer(string $tokenPublic, array $contexte = []): MembreFamille
    {
        $hash = hash('sha256', $tokenPublic);

        // Transaction + verrou pessimiste : deux scans simultanés ne peuvent pas
        // consommer le même token (anti double-scan / rejeu, §5.3).
        return DB::transaction(function () use ($hash, $contexte) {
            $token = TokenQr::where('token_hash', $hash)->lockForUpdate()->first();

            abort_if($token === null, 404, 'QR invalide.');
            abort_if($token->estConsomme(), 409, 'QR déjà utilisé.');
            abort_if($token->estExpire(), 410, 'QR expiré.');

            $token->update([
                'used_at'               => now(),
                'used_by_agent_id'      => $contexte['agent_id'] ?? null,
                'used_by_etablissement' => $contexte['etablissement'] ?? null,
            ]);

            // Journal d'audit immuable (§10) : trace l'accès au dossier.
            AccesDossier::create([
                'membre_id'           => $token->membre_id,
                'agent_id'            => $contexte['agent_id'] ?? null,
                'token_qr_id'         => $token->id,
                'type_acces'          => 'qr_scan',
                'sections_consultees' => $contexte['sections'] ?? null,
                'ip_address'          => $contexte['ip'] ?? null,
            ]);

            return $token->membre;
        });
    }

    /** Secret serveur de signature des QR (jamais committé — .env). */
    private function secret(): string
    {
        return (string) config('app.qr_secret');
    }
}
