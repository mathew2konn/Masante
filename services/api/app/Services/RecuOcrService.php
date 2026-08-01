<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Module 5 / 5.8 — Lecture du reçu de pharmacie (CdC FN7 : « patients rapportent prix après achat
 * via scan de reçu »).
 *
 * TROIS PRINCIPES, dans l'ordre d'importance :
 *
 *  1. L'OCR TOURNE CHEZ NOUS. Un reçu de pharmacie dit quels médicaments une personne identifiable
 *     a achetés : c'est une donnée de santé (loi n°2013-450). L'envoyer à Google Vision ou à un
 *     service en ligne reviendrait à exporter des données de santé ivoiriennes chez un tiers
 *     étranger. On utilise donc Tesseract, auto-hébergé — cohérent avec le choix d'OpenStreetMap
 *     contre Google Maps au Module 3.
 *
 *  2. L'IMAGE EST DÉTRUITE IMMÉDIATEMENT. Elle n'est jamais écrite dans `storage/app`, jamais
 *     conservée, jamais rattachée au dossier : elle vit le temps d'un appel à Tesseract, dans le
 *     dossier temporaire, et disparaît. Ce qu'on garde, c'est un nombre — pas une photo.
 *
 *  3. L'OCR NE DÉCIDE RIEN. Il PROPOSE des montants ; le patient choisit et confirme. Un chiffre
 *     mal reconnu qui entrerait silencieusement dans le comparateur empoisonnerait la médiane de
 *     tous les autres. La reconnaissance de caractères sur un ticket froissé est faillible par
 *     nature : on la traite comme une aide à la saisie, pas comme une source.
 */
class RecuOcrService
{
    /** Montants renvoyés au maximum : une aide au choix, pas un dépouillement de ticket. */
    private const MAX_MONTANTS = 8;

    /** Le binaire Tesseract est-il utilisable sur cette machine ? (sinon : saisie manuelle) */
    public function estDisponible(): bool
    {
        return is_file((string) config('masante.ocr.binaire'));
    }

    /**
     * Extrait les montants candidats d'une photo de reçu, du plus probable au moins probable.
     *
     * @return array{montants: array<int, int>, texte: string}
     */
    public function lire(UploadedFile $image): array
    {
        $texte = $this->texteDe($image);

        return [
            'montants' => $this->montantsDe($texte),
            // Le texte brut est renvoyé pour que le patient VOIE ce qui a été lu et comprenne
            // pourquoi tel montant est proposé. Une boîte noire qui propose « 2500 » sans montrer
            // d'où ça sort n'inspire aucune confiance — et empêche de corriger.
            'texte'    => $texte,
        ];
    }

    /** Fait tourner Tesseract sur le fichier temporaire de l'upload, puis efface toute trace. */
    private function texteDe(UploadedFile $image): string
    {
        // On travaille sur une COPIE dans le dossier temporaire : le fichier d'origine de PHP est
        // de toute façon supprimé en fin de requête, et on ne touche jamais au stockage applicatif.
        $chemin = tempnam(sys_get_temp_dir(), 'recu_').'.'.$image->getClientOriginalExtension();
        copy($image->getRealPath(), $chemin);

        try {
            $process = new Process([
                (string) config('masante.ocr.binaire'),
                $chemin,
                'stdout',
                '--tessdata-dir', (string) config('masante.ocr.tessdata'),
                '-l', (string) config('masante.ocr.langue'),
            ]);
            $process->setTimeout((float) config('masante.ocr.timeout_s'));
            $process->mustRun();

            return $process->getOutput();
        } catch (ProcessFailedException $e) {
            Log::warning('OCR du reçu impossible', ['erreur' => $e->getMessage()]);

            return '';
        } finally {
            // Quoi qu'il arrive — succès, échec, exception — la photo du reçu disparaît.
            @unlink($chemin);
        }
    }

    /**
     * Repère les montants d'un texte de ticket.
     *
     * Heuristique volontairement simple et EXPLICABLE (on doit pouvoir la défendre) : tout nombre
     * de 2 à 7 chiffres, les séparateurs de milliers tolérés (« 2 500 », « 2.500 »). Les plus
     * GRANDS d'abord : sur un ticket, le prix d'une boîte est plus grand que la quantité, la TVA ou
     * la date — et c'est le patient qui tranche de toute façon.
     *
     * On écarte ce qui ressemble à une année (2020-2030) : sur un reçu, « 2026 » est une date, pas
     * un prix.
     *
     * @return array<int, int>
     */
    private function montantsDe(string $texte): array
    {
        preg_match_all('/\b\d{1,3}(?:[ .\x{00A0}]\d{3})+\b|\b\d{2,7}\b/u', $texte, $trouves);

        return collect($trouves[0])
            ->map(fn (string $brut) => (int) preg_replace('/\D/', '', $brut))
            ->filter(fn (int $montant) => $montant >= (int) config('masante.prix.plancher_cfa'))
            ->reject(fn (int $montant) => $montant >= 2020 && $montant <= 2030)   // une année, pas un prix
            ->unique()
            ->sortDesc()
            ->take(self::MAX_MONTANTS)
            ->values()
            ->all();
    }
}
