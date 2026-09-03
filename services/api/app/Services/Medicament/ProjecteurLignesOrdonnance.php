<?php

namespace App\Services\Medicament;

use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use Illuminate\Support\Facades\DB;

/**
 * B3-a — projette `medicaments_json` en lignes interrogeables (`ordonnance_lignes`).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE PROJECTION, ET NON UNE SECONDE SAISIE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `medicaments_json` est le contrat d'écriture des TROIS chemins (patient, délégué, soignant) et il
 * est validé, résolu au référentiel et figé par `ServiceLienMedicament` (P6.6b). Demander en plus
 * des lignes à l'appelant créerait **deux endroits où dire la même chose**, donc deux vérités sur
 * une prescription — ce que ce projet refuse depuis P6.6a.
 *
 * Les lignes en sont donc DÉRIVÉES, par un seul crochet, ce qui donne la garantie que P6.6b
 * exigeait : *une garantie qui ne vaudrait que sur l'un des chemins n'en serait pas une*. Le `PUT`
 * est couvert au même titre que la création.
 *
 * ═══ CE QUI EST REFUSÉ, ET C'EST LE POINT DE SÉCURITÉ ═══
 *
 * Une ordonnance DÉJÀ SERVIE n'est plus reprojetée. Régénérer ses lignes changerait ce à quoi une
 * délivrance se rattache — au mieux on perdrait la trace de ce qui a été servi, au pire on
 * l'attacherait à un autre médicament. *Une prescription servie ne se réécrit pas ;* si elle est
 * fausse, elle se corrige par une nouvelle ordonnance, comme une facture se corrige par un avoir
 * (P5.2b).
 *
 * ═══ CE QUI PASSE EN CLAIR, ET CE QUI RESTE CHIFFRÉ ═══
 *
 * Décision de B3-a (D3), que B2-c avait renvoyée : l'identité du produit est en clair — sans quoi
 * ni la délivrance, ni la vérification d'interactions, ni le §7.6 ne sont possibles — et ce qui
 * décrit le traitement de la personne reste chiffré.
 */
final class ProjecteurLignesOrdonnance
{
    /**
     * (Re)construit les lignes d'une ordonnance à partir de son `medicaments_json`.
     *
     * @return int le nombre de lignes projetées, ou -1 si la projection a été refusée
     */
    public function projeter(Ordonnance $ordonnance): int
    {
        // Une prescription servie ne se réécrit pas. On ne lève pas : la sauvegarde de l'ordonnance
        // reste légitime (le patient peut ajouter une photo du papier), c'est la REPROJECTION qui
        // n'a pas lieu — même esprit qu'en P7-D0, où un échec de signature ne défait pas l'écriture.
        if ($ordonnance->delivrances()->exists()) {
            return -1;
        }

        $medicaments = $ordonnance->medicaments_json;

        if (! is_array($medicaments) || $medicaments === []) {
            return 0;
        }

        return DB::transaction(function () use ($ordonnance, $medicaments): int {
            $ordonnance->lignes()->delete();

            $rang = 0;

            foreach ($medicaments as $medicament) {
                if (! is_array($medicament)) {
                    continue;
                }

                $nom = trim((string) ($medicament['nom'] ?? ''));

                if ($nom === '') {
                    continue;
                }

                $ligne = new OrdonnanceLigne;
                $ligne->ordonnance_id = $ordonnance->id;
                $ligne->nom = $nom;
                $ligne->rang = ++$rang;

                // Repris tels quels de la charge déjà résolue par `ServiceLienMedicament` : les
                // re-résoudre ici ferait un second endroit qui interroge le référentiel, et deux
                // réponses possibles pour une même ligne si le référentiel a changé entre-temps.
                $ligne->medicament_id = $this->entierOuNull($medicament['medicament_id'] ?? null);
                $ligne->code_national = $this->texteOuNull($medicament['code_national'] ?? null);
                $ligne->dci = $this->texteOuNull($medicament['dci'] ?? null);
                $ligne->dosage = $this->texteOuNull($medicament['dosage_referentiel'] ?? null);

                $ligne->posologie = $this->texteOuNull($medicament['posologie'] ?? null);
                $ligne->duree = $this->texteOuNull($medicament['duree'] ?? null);
                $ligne->instructions = $this->texteOuNull($medicament['instructions'] ?? null);
                $ligne->quantite_prescrite = $this->entierOuNull($medicament['quantite'] ?? null);

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
        // `is_numeric` et non un cast direct : `(int) 'abc'` vaut 0, et une quantité de zéro
        // prescrite n'est pas la même chose qu'une quantité absente (leçon P10b-2).
        return is_numeric($valeur) ? (int) $valeur : null;
    }
}
