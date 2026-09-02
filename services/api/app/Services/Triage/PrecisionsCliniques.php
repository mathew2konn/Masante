<?php

namespace App\Services\Triage;

use App\Models\Maladie;
use App\Models\SpecialiteMedicale;
use App\Models\Triage;
use App\Support\RegistreRetourTriage;
use RuntimeException;

/**
 * P10c-3-ii (F32→F34) — Les trois faits que le soignant consigne à côté de son verdict.
 *
 * ═══ CE QUE CETTE CLASSE RÉSOUT, ET CE QU'ELLE REFUSE DE DEVINER ═══
 *
 * Elle relit le diagnostic et la spécialité AU RÉFÉRENTIEL et fige leurs libellés. Elle ne
 * rapproche jamais un texte libre d'une maladie : le serveur ne devine pas un diagnostic, ce serait
 * une affirmation clinique posée par une machine (règle tenue depuis P6.8c, et l'inverse de ce que
 * P6.7a avait tenté avant d'être corrigé en P6.7b).
 *
 * ═══ LE CONTRÔLE DE COHÉRENCE, ET POURQUOI IL REFUSE AU LIEU D'ARBITRER (F33) ═══
 *
 * `decision_finale` dit « adaptée / trop haute / trop basse » ; `niveau_reel` dit quel niveau le
 * soignant aurait retenu. Comparé au niveau du protocole, le second **implique** le premier. Deux
 * façons de dire le même fait peuvent se contredire — c'est la « deux vérités » que ce projet
 * refuse depuis P6.6a.
 *
 * On ne départage pas : **lui seul sait laquelle il pensait.** Écraser l'une par l'autre
 * inscrirait dans une chaîne immuable un verdict que personne n'a formulé. Le refus NOMME la
 * contradiction (motif des quatre validations de P10b-1), pour qu'il corrige la bonne moitié.
 */
final class PrecisionsCliniques
{
    /**
     * Résout les trois faits et refuse toute incohérence.
     *
     * @param  array<string, mixed>  $saisie  `niveau_reel`, `maladie_id`, `specialite_id`.
     * @return array<string, mixed> Les valeurs FIGÉES, prêtes à inscrire.
     */
    public function resoudre(Triage $triage, string $retour, array $saisie): array
    {
        $niveauReel = $this->niveauReel($saisie['niveau_reel'] ?? null);

        $this->exigerCoherence($triage, $retour, $niveauReel);

        return [
            'niveau_reel' => $niveauReel,
        ] + $this->maladie($saisie['maladie_id'] ?? null) + $this->specialite($saisie['specialite_id'] ?? null);
    }

    private function niveauReel(mixed $valeur): ?string
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        // Vocabulaire du §5.3 côté patient — celui que porte déjà `triages.niveau` (P10b-1). On
        // n'en invente pas un second : deux échelles pour la même chose seraient inexploitables.
        $admis = RegistreRetourTriage::niveauxAdmis();

        if (! in_array($valeur, $admis, true)) {
            throw new RuntimeException(
                'Niveau inconnu. Valeurs admises : '.implode(', ', $admis).'.'
            );
        }

        return (string) $valeur;
    }

    /**
     * ═══ LA CONTRADICTION EST REFUSÉE, JAMAIS SILENCIEUSEMENT CORRIGÉE (F33) ═══
     *
     * Un soignant qui dit « orientation adaptée » tout en donnant un niveau différent de celui du
     * protocole se contredit ; celui qui dit « sous-triage » en donnant un niveau plus BAS aussi.
     * Le message nomme les deux moitiés, parce que refuser sans dire ce qui cloche laisserait
     * chercher (motif P6.8a).
     */
    private function exigerCoherence(Triage $triage, string $retour, ?string $niveauReel): void
    {
        if ($niveauReel === null || $triage->niveau === null) {
            return;
        }

        $rangReel = RegistreRetourTriage::rangNiveau($niveauReel);
        $rangProtocole = RegistreRetourTriage::rangNiveau((string) $triage->niveau);

        if ($rangReel === null || $rangProtocole === null) {
            // Un niveau de protocole hors du vocabulaire patient (les 5 niveaux hospitaliers, non
            // livrés) n'est pas comparable : on se tait plutôt que de conclure sur une échelle
            // qu'on ne connaît pas.
            return;
        }

        $attendu = match (true) {
            $rangReel > $rangProtocole => RegistreRetourTriage::SOUS_TRIAGE,
            $rangReel < $rangProtocole => RegistreRetourTriage::SUR_TRIAGE,
            default => RegistreRetourTriage::ADAPTEE,
        };

        if ($attendu !== $retour) {
            throw new RuntimeException(
                'Votre verdict et le niveau que vous indiquez se contredisent : le protocole avait '
                ."retenu « {$triage->niveau} », vous indiquez « {$niveauReel} », ce qui correspond à "
                ."« {$attendu} » et non à « {$retour} ». Corrigez celui des deux qui ne dit pas ce "
                .'que vous pensez — le serveur ne choisit pas à votre place.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function maladie(mixed $id): array
    {
        if ($id === null || $id === '') {
            return ['maladie_id' => null, 'maladie_code' => null, 'maladie_libelle' => null];
        }

        $maladie = Maladie::find($id);

        if ($maladie === null) {
            throw new RuntimeException(
                'Diagnostic inconnu du référentiel national des maladies. Un diagnostic se choisit '
                .'dans la liste : saisi librement, il rendrait insoluble toute statistique et ferait '
                .'d\'une faute de frappe une catégorie.'
            );
        }

        // FIGÉS (P6.6b/P6.7b) : une correction ultérieure du référentiel ne réécrit pas ce qu'un
        // médecin a consigné ce jour-là. C'est un fait HISTORIQUE, pas un état courant.
        return [
            'maladie_id' => $maladie->id,
            'maladie_code' => $maladie->code,
            'maladie_libelle' => $maladie->libelle,
        ];
    }

    /** @return array<string, mixed> */
    private function specialite(mixed $id): array
    {
        if ($id === null || $id === '') {
            return ['specialite_id' => null, 'specialite_code' => null, 'specialite_libelle' => null];
        }

        $specialite = SpecialiteMedicale::find($id);

        if ($specialite === null) {
            throw new RuntimeException(
                'Spécialité inconnue du vocabulaire national (P6.8a). Elle se choisit dans la liste.'
            );
        }

        return [
            'specialite_id' => $specialite->id,
            'specialite_code' => $specialite->code,
            'specialite_libelle' => $specialite->libelle,
        ];
    }
}
