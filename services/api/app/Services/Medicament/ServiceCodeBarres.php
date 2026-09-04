<?php

namespace App\Services\Medicament;

use App\Models\Medicament;
use Illuminate\Validation\ValidationException;

/**
 * B3-c — Résout une saisie de code-barres → produit (CDC_11 §7.6).
 *
 * SÉPARÉ DE `ReglesCodeBarres` : *les règles jugent, le service rassemble l'état* (leçon P6.5b) —
 * celle-ci ne touche que des chaînes, celui-ci touche la base.
 *
 * ═══ CE QUE LE SCAN PROUVE, ET CE QU'IL NE PROUVE PAS (E5) ═══
 *
 * Un falsificateur RECOPIE un code-barres. `identifier()` répond « connu du référentiel » ou
 * `null` — JAMAIS « authentique ». Un code inconnu SIGNALE sans bloquer : refuser priverait le
 * patient de son traitement pour une raison que lui seul ne peut pas résoudre (même raisonnement
 * que B3-b sur un article non tenu en stock).
 *
 * ═══ LA LECTURE N'EST PAS GOUVERNÉE, LA DONNÉE L'EST (E9) ═══
 *
 * `identifier()` lit la TABLE `medicaments`, jamais l'instantané publié du référentiel. Trois
 * raisons : (1) une colonne neuve n'est dans AUCUNE version déjà publiée — lire l'instantané
 * rendrait la fonctionnalité morte à la livraison ; (2) tout le domaine pharmacie lit déjà la
 * table ; (3) un refus bruyant (503) devant un comptoir bloquerait une dispensation, ce qu'E5
 * refuse déjà pour un code inconnu. Asymétrie ASSUMÉE et NOMMÉE, comme `poids_severite` en
 * P10b-3-ii — porteur : l'élévation de la gouvernance du socle P6.3.
 */
final class ServiceCodeBarres
{
    /**
     * Le produit désigné par ce code-barres, ou `null` — jamais une exception.
     *
     * `null` recouvre trois cas qu'on ne distingue PAS au scan : saisie vide, code mal formé, code
     * bien formé mais absent du référentiel. Les distinguer inviterait à sonder la base pour
     * apprendre quels préfixes sont attribués — l'écran affiche « inconnu du référentiel » dans
     * les trois cas, et la délivrance passe quand même.
     */
    public function identifier(?string $saisie): ?Medicament
    {
        $saisie = trim((string) $saisie);

        if ($saisie === '') {
            return null;
        }

        $code = ReglesCodeBarres::normaliser($saisie);

        if (! ReglesCodeBarres::estGtin($code)) {
            return null;
        }

        return Medicament::where('code_barres', $code)->first();
    }

    /**
     * La saisie d'un AGENT HABILITÉ au référentiel — refus NOMMÉ à l'entrée, contrairement au scan.
     *
     * Un agent qui gouverne le référentiel doit savoir POURQUOI sa saisie est refusée ; un
     * pharmacien au comptoir qui scanne un produit inconnu n'a besoin que de savoir qu'il l'est
     * (`identifier()`).
     *
     * L'unicité `(pays_code, code_barres)` n'est PAS revérifiée ici : elle est déclarative
     * (`uq_medicament_code_barres`), et la dupliquer produirait une seconde vérité qui pourrait
     * diverger — précédent P6.6a/P6.8a, où un doublon surfait en `QueryException` (1062) plutôt
     * que d'être anticipé par le service.
     *
     * @throws ValidationException
     */
    public function assertSaisieValide(string $saisie): string
    {
        $code = ReglesCodeBarres::normaliser($saisie);

        if (! ReglesCodeBarres::estGtin($code)) {
            throw ValidationException::withMessages([
                'code_barres' => 'Ce code-barres n\'a pas la forme d\'un GTIN valide '
                    .'(8, 12, 13 ou 14 chiffres, clé de contrôle cohérente).',
            ]);
        }

        return $code;
    }
}
