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

        // Le refus HONNÊTE du service (F6/F23) : atteint, répondu. Deux motifs possibles —
        // `modele_indisponible` (aucune version active, régime nominal) et
        // `modele_absent_du_service` (le registre en désigne une que l'instance n'a pas). Aucun des
        // deux n'est un échec du disjoncteur : le service fonctionne, il refuse (F31).
        if ($reponse->status() === 503) {
            $this->disjoncteur->enregistrerSucces();
            $motif = (string) ($reponse->json('motif') ?? 'modele_indisponible');

            return ResultatTriageIa::degrade($motif, $latence);
        }

        if ($reponse->successful()) {
            $this->disjoncteur->enregistrerSucces();

            return $this->observationDepuis($reponse->json(), $latence);
        }

        // ═══ UN REFUS DE CONTRAT N'EST PAS UNE PANNE DU SERVICE (P10c-3-ii) ═══
        //
        // Un 422 nommé (`bande_age_inconnue`, `volume_insuffisant`) dit que le service a compris la
        // requête et l'a jugée invalide : il fonctionne, c'est l'APPELANT qui a tort — typiquement
        // parce que les bornes de tranches ont divergé entre la config Laravel et le service (F26).
        //
        // Le G2 live a montré les deux défauts que cela évite : Laravel écrasait le motif précis en
        // `reponse_inattendue_422`, et comptait l'appel comme un échec. **Une divergence de
        // configuration se serait donc déguisée en service en panne**, puis aurait ouvert le
        // disjoncteur — exactement ce que le motif distinct de F23 existe pour empêcher.
        if ($reponse->status() === 422 && is_string($motif = $reponse->json('motif'))) {
            $this->disjoncteur->enregistrerSucces();

            return ResultatTriageIa::degrade($motif, $latence);
        }

        // Toute AUTRE réponse (500, 4xx sans motif…) est un signal réel que quelque chose ne va
        // pas — comptée pour le disjoncteur, contrairement au 503 honnête.
        $this->disjoncteur->enregistrerEchec();

        return ResultatTriageIa::degrade('reponse_inattendue_'.$reponse->status(), $latence);
    }

    /**
     * Désérialise une prédiction réelle (P10c-3-ii, F22/F27).
     *
     * ═══ UNE RÉPONSE INCOMPLÈTE EST DÉGRADÉE, JAMAIS RECOMPLÉTÉE ═══
     *
     * Rule-005 exige explication, confiance et limites. Si l'une manque, on ne fabrique pas la
     * valeur absente et on n'enregistre pas une observation amputée : on dégrade avec un motif qui
     * NOMME le défaut. Une explication inventée côté Laravel serait pire que pas d'explication —
     * elle aurait l'air d'en être une.
     *
     * @param  array<string, mixed>|null  $corps
     */
    private function observationDepuis(?array $corps, int $latence): ResultatTriageIa
    {
        $facteurs = $corps['facteurs'] ?? null;
        $probabilites = $corps['probabilites'] ?? null;
        $classe = $corps['classe_predite'] ?? null;

        if (! is_array($corps) || ! is_array($facteurs) || $facteurs === []
            || ! is_array($probabilites) || ! is_string($classe)
            || empty($corps['limites']) || empty($corps['confiance']) || empty($corps['modele_version'])) {
            return ResultatTriageIa::degrade('reponse_incomplete', $latence);
        }

        return ResultatTriageIa::observation(
            modeleVersion: (string) $corps['modele_version'],
            // ═══ LA PROBABILITÉ RETENUE EST CELLE DE LA CLASSE RENDUE ═══
            //
            // C'est la confiance du modèle dans SA réponse. Celle de `sous_triage` se lit dans
            // l'explication : la stocker AUSSI dans cette colonne ferait deux chemins pour le même
            // fait, et l'écran de gouvernance pourrait afficher l'un en croyant lire l'autre.
            probabilite: (float) ($probabilites[$classe] ?? 0.0),
            facteurs: $facteurs,
            explication: ['classe_predite' => $classe, 'probabilites' => $probabilites],
            confiance: (string) $corps['confiance'],
            limites: (string) $corps['limites'],
            latenceMs: $latence,
        );
    }

    private function latenceMs(float $debut): int
    {
        return (int) round((microtime(true) - $debut) * 1000);
    }
}
