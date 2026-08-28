<?php

namespace App\Services\Triage;

use App\Http\Controllers\Api\V1\TriageController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * P10c-2-i (F7/F8) — Client HTTP Laravel → `triage-service` (CDC_05, CDC_03 §10.1).
 *
 * ═══ UN SEUL RÉSULTAT, TROIS MOTIFS DE DÉGRADATION — JAMAIS DE SCORE INVENTÉ ═══
 *
 * {@see scorer()} ne lève jamais pour une raison de service : gaté OFF, disjoncteur ouvert,
 * injoignable et refus honnête du service produisent tous le même {@see ResultatTriageIa} en mode
 * `degrade`, avec un motif distinct. C'est {@see TriageController} qui
 * absorbe et rend le triage complet (F6, A6 — la dégradation gracieuse, pas le refus bruyant, parce
 * que le résultat du protocole est complet sans l'IA).
 *
 * ═══ CE QUI COMPTE COMME UN ÉCHEC POUR LE DISJONCTEUR, ET CE QUI N'EN EST PAS UN ═══
 *
 * Un `503 modele_indisponible` est une RÉPONSE du service, pas une panne : le service a été
 * atteint, a répondu dans les temps, et a dit honnêtement « pas de modèle ». Le compter comme un
 * échec ouvrirait le circuit à chaque appel tant que P10c-3 n'a livré aucun modèle — exactement le
 * régime nominal de cet incrément (F5/F6) — pour protéger un service qui ne dysfonctionne pas.
 *
 * Seuls comptent : l'INJOIGNABILITÉ (timeout, connexion refusée) et une réponse VRAIMENT
 * inattendue (ni 503 honnête, ni futur 200 de P10c-3). Le G1 le distingue explicitement dans ses
 * preuves prévues : « ON + service debout → 503, triage rendu complet » est un cas, « ON + service
 * à terre → disjoncteur ouvert » en est un AUTRE.
 */
class ClientTriageIa
{
    public function __construct(private readonly DisjoncteurTriageIa $disjoncteur) {}

    /**
     * @param  array<string, mixed>  $requete  Charge MINIMISÉE (F9) — aucune identité.
     */
    public function scorer(array $requete): ResultatTriageIa
    {
        if (! config('masante.triage_ia.enabled')) {
            // Gaté OFF (F7) : aucun appel réseau ne part. C'est le réglage par défaut.
            return ResultatTriageIa::degrade('desactive', 0);
        }

        if ($this->disjoncteur->estOuvert()) {
            // F8 : circuit ouvert → AUCUN appel réseau, la dégradation est immédiate.
            return ResultatTriageIa::degrade('disjoncteur_ouvert', 0);
        }

        $debut = microtime(true);

        try {
            $reponse = Http::connectTimeout((float) config('masante.triage_ia.timeout_connexion_s'))
                ->timeout((float) config('masante.triage_ia.timeout_lecture_s'))
                ->post(rtrim((string) config('masante.triage_ia.base_url'), '/').'/api/v1/triage/score', $requete);
        } catch (ConnectionException $e) {
            $this->disjoncteur->enregistrerEchec();

            return ResultatTriageIa::degrade('injoignable', $this->latenceMs($debut));
        }

        $latence = $this->latenceMs($debut);

        // Le refus HONNÊTE du service (F6) : atteint, répondu, pas de modèle. Ce n'est PAS un
        // échec du disjoncteur — voir l'en-tête.
        if ($reponse->status() === 503) {
            $this->disjoncteur->enregistrerSucces();
            $motif = (string) ($reponse->json('motif') ?? 'modele_indisponible');

            return ResultatTriageIa::degrade($motif, $latence);
        }

        // Inatteignable tant qu'aucun modèle n'existe (P10c-3) : un 200 réel n'a rien à
        // désérialiser aujourd'hui. Laissé en l'état pour que le point d'accroche soit visible.
        if ($reponse->successful()) {
            $this->disjoncteur->enregistrerSucces();

            return ResultatTriageIa::degrade('reponse_inattendue_200', $latence);
        }

        // Toute autre réponse (500, 422 signant une erreur de contrat…) est un signal réel que
        // quelque chose ne va pas — comptée pour le disjoncteur, contrairement au 503 honnête.
        $this->disjoncteur->enregistrerEchec();

        return ResultatTriageIa::degrade('reponse_inattendue_'.$reponse->status(), $latence);
    }

    private function latenceMs(float $debut): int
    {
        return (int) round((microtime(true) - $debut) * 1000);
    }
}
