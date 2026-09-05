<?php

namespace App\Services\Analyse;

use App\Models\DemandeAnalyse;
use App\Models\DemandeAnalyseLigne;
use App\Support\StatutDemandeAnalyse;
use Illuminate\Support\Facades\DB;

/**
 * B5-a — projette `analyses_json` en lignes interrogeables (`demande_analyse_lignes`).
 *
 * Patron `App\Services\Medicament\ProjecteurLignesOrdonnance` (B3-a), transposé : `analyses_json`
 * est le contrat d'écriture des TROIS chemins (patient, délégué, soignant), déjà résolu au
 * catalogue et figé par {@see ServiceLienAnalyse} au moment de la validation. Demander en plus des
 * lignes à l'appelant créerait deux endroits où dire la même chose (refus P6.6a).
 *
 * ═══ CE QUI EST REFUSÉ ═══
 *
 * Une demande déjà `servie` ou `annulee` n'est plus reprojetée : régénérer ses lignes romprait ce à
 * quoi un prélèvement (B5-b) se rattache. Comme en B3-a, la sauvegarde de la demande reste
 * légitime (le médecin peut corriger les renseignements cliniques) — c'est la REPROJECTION des
 * lignes qui n'a pas lieu.
 *
 * ═══ CORRECTION TROUVÉE EN CONSTRUISANT B5-b, PAS AU G0 ═══
 *
 * `demandes_analyses.statut` ne passe à `servie` qu'à la PUBLICATION d'un résultat (B5-c) — pas à
 * l'enregistrement d'un prélèvement (B5-b). Un `statut` encore `emise` n'empêchait donc PAS de
 * reprojeter les lignes d'une demande dont un laboratoire avait DÉJÀ prélevé l'échantillon sur la
 * base de l'ancienne liste d'examens : un médecin éditant sa demande après coup aurait
 * silencieusement désynchronisé ce que le tube contient de ce que le carnet affiche. La garde est
 * donc doublée par une vérification RELATIONNELLE — `ProjecteurLignesOrdonnance` (B3-a) le faisait
 * déjà ainsi (`delivrances()->exists()`), et B5-a s'en était écarté sans qu'aucun `prelevements` ne
 * pût encore exister pour le prouver.
 */
final class ProjecteurLignesDemande
{
    /**
     * (Re)construit les lignes d'une demande à partir de son `analyses_json`.
     *
     * @return int le nombre de lignes projetées, ou -1 si la projection a été refusée
     */
    public function projeter(DemandeAnalyse $demande): int
    {
        // `statut` est casté en enum : comparer à `EMISE->value` (une chaîne) aurait toujours été
        // vrai, quel que soit l'état réel — défaut réel trouvé par les tests (aucune ligne jamais
        // projetée). L'enum doit se comparer à l'enum.
        if ($demande->statut !== StatutDemandeAnalyse::EMISE) {
            return -1;
        }

        if ($demande->prelevements()->exists()) {
            return -1;
        }

        $analyses = $demande->analyses_json;

        if (! is_array($analyses) || $analyses === []) {
            return 0;
        }

        return DB::transaction(function () use ($demande, $analyses): int {
            $demande->lignes()->delete();

            $rang = 0;

            foreach ($analyses as $analyse) {
                if (! is_array($analyse)) {
                    continue;
                }

                $libelle = trim((string) ($analyse['libelle'] ?? ''));

                if ($libelle === '') {
                    continue;
                }

                $ligne = new DemandeAnalyseLigne;
                $ligne->demande_id = $demande->id;
                $ligne->libelle = $libelle;
                $ligne->rang = ++$rang;

                // Repris tels quels de la charge déjà résolue par ServiceLienAnalyse : la
                // re-résoudre ici ferait un second endroit qui interroge le catalogue, et deux
                // réponses possibles pour une même ligne si le catalogue a changé entre-temps
                // (même garde-fou que ProjecteurLignesOrdonnance).
                $ligne->analyse_id = $this->entierOuNull($analyse['analyse_id'] ?? null);
                $ligne->code_national = $this->texteOuNull($analyse['code_national'] ?? null);
                $ligne->unite = $this->texteOuNull($analyse['unite_catalogue'] ?? null);
                $ligne->conditions_prelevement = $this->texteOuNull($analyse['conditions_prelevement'] ?? null);

                $ligne->save();
            }

            return $rang;
        });
    }

    private function texteOuNull(mixed $valeur): ?string
    {
        if (! is_scalar($valeur)) {
            return null;
        }

        $texte = trim((string) $valeur);

        return $texte === '' ? null : $texte;
    }

    private function entierOuNull(mixed $valeur): ?int
    {
        // `is_numeric` et non un cast direct : une entrée mal formée ne doit pas devenir 0 et se
        // faire passer pour un rattachement au catalogue (leçon P10b-2).
        return is_numeric($valeur) ? (int) $valeur : null;
    }
}
