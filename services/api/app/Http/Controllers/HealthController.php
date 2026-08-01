<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint de santé — §4.10 du prompt / couche diagnostic.
 *
 * C'est la TOUTE PREMIÈRE chose testée (Postman puis app mobile) avant toute fonctionnalité.
 * S'il répond « ok » de bout en bout (téléphone -> Ngrok -> Laravel), la chaîne de
 * communication est saine. Il vérifie aussi que la base MySQL répond.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Vérifie que la base de données répond (sans exposer de détail sensible).
        $baseOk = true;
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            $baseOk = false;
        }

        return response()->json([
            'status'      => 'ok',
            'service'     => config('app.name'),
            'environment' => config('app.env'),
            'database'    => $baseOk ? 'ok' : 'ko',
            'time'        => now()->toIso8601String(),
        ]);
    }
}
