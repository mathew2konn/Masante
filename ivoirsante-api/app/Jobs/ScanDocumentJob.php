<?php

namespace App\Jobs;

use App\Models\DocumentMedical;
use App\Services\DocumentStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * F2.10 — Analyse antivirus d'un document importé.
 *
 * Le document est créé au statut `en_attente` (verrou de téléchargement). Ce job décide de son sort :
 *  - `antivirus.enabled = true`  (prod) → scan ClamAV du contenu DÉCHIFFRÉ ; `sain` / `infecte`.
 *  - `antivirus.enabled = false` (dev)  → stub « simulé » (comme l'OTP dev) → `sain`.
 *
 * Posture fail-closed : si l'antivirus est activé mais indisponible (binaire absent, erreur), on NE marque
 * PAS `sain` — le document reste `en_attente` (donc non téléchargeable) et l'incident est journalisé.
 *
 * On transporte l'ID (pas le modèle) : sûr à la sérialisation et robuste au soft-delete.
 */
class ScanDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $documentId) {}

    public function handle(DocumentStorageService $stockage): void
    {
        $document = DocumentMedical::find($this->documentId);

        if ($document === null) {
            return; // document supprimé entre-temps : rien à analyser.
        }

        if (! config('masante.antivirus.enabled')) {
            $document->update(['statut_antivirus' => 'sain']); // stub dev
            return;
        }

        $statut = $this->scannerClamAv($stockage, $document);

        if ($statut !== null) {
            $document->update(['statut_antivirus' => $statut]);
        }
        // $statut === null → antivirus indisponible : on laisse `en_attente` (fail-closed).
    }

    /**
     * Scanne le contenu déchiffré via clamdscan (flux sur stdin). Renvoie 'sain' / 'infecte',
     * ou null si le scan n'a pas pu aboutir (à traiter en fail-closed par l'appelant).
     */
    private function scannerClamAv(DocumentStorageService $stockage, DocumentMedical $document): ?string
    {
        try {
            $contenu = $stockage->retrieveDecrypted($document);

            $descripteurs = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open('clamdscan --no-summary --stream -', $descripteurs, $tuyaux);

            if (! is_resource($process)) {
                Log::warning('ScanDocumentJob : clamdscan indisponible.', ['document_id' => $document->id]);
                return null;
            }

            fwrite($tuyaux[0], $contenu);
            fclose($tuyaux[0]);
            fclose($tuyaux[1]);
            fclose($tuyaux[2]);

            $code = proc_close($process);

            // clamdscan : 0 = propre, 1 = virus trouvé, 2 = erreur.
            return match ($code) {
                0 => 'sain',
                1 => 'infecte',
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('ScanDocumentJob : échec du scan antivirus.', [
                'document_id' => $document->id,
                'exception'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
