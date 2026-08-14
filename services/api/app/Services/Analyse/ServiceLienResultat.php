<?php

namespace App\Services\Analyse;

use App\Models\Medecin;
use App\Models\StructureSanitaire;
use Illuminate\Validation\ValidationException;

/**
 * P6.7b — Les liens d'un résultat vers le prescripteur et le laboratoire (CDC_09 §7.2).
 *
 * ═══ CE QUE CE SERVICE CORRIGE ═══
 *
 * P6.7a réécrivait `medecin_prescripteur` avec le nom du soignant qui consignait le résultat, en le
 * présentant comme le miroir de `ordonnances.medecin_nom`. **C'était faux** : pour une ordonnance,
 * celui qui écrit EST le prescripteur ; pour un résultat, celui qui consigne est souvent quelqu'un
 * d'autre. Le serveur inscrivait alors le nom du mauvais médecin — et une affirmation fausse portée
 * par le système est plus difficile à contester qu'une saisie humaine non vérifiée.
 *
 * ═══ CE QU'IL FAIT À LA PLACE ═══
 *
 * Le prescripteur et le laboratoire d'un résultat sont des déclarations sur des **TIERS**. On ne les
 * devine pas : on les fait **vérifier** quand elles sont faites. Le texte libre reste — un patient
 * qui recopie un compte rendu papier n'a pas de liste sous les yeux — mais s'il fournit un
 * identifiant, le serveur relit le référentiel et **fige** le nom.
 *
 * Même forme que le lien médicament (P6.6b) et le lien analyse (P6.7a), pour la même raison : ce
 * que le serveur peut vérifier n'a pas à être cru, et ce qu'il a vérifié doit rester stable.
 *
 * ═══ POURQUOI ON NE DÉDUIT PAS LE LABORATOIRE DE L'ÉTABLISSEMENT DU SOIGNANT ═══
 *
 * Un résultat vient très souvent d'un laboratoire externe. Le déduire de l'établissement de celui
 * qui consigne serait la même erreur que celle de P6.7a sur le prescripteur, transposée.
 */
final class ServiceLienResultat
{
    /**
     * Résout les liens d'un résultat d'analyse.
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     *
     * @throws ValidationException si un identifiant ne désigne rien, ou désigne autre chose qu'un
     *                             laboratoire
     */
    public function resoudre(array $valide): array
    {
        // Les valeurs figées ne viennent jamais du client : on les efface avant de les reposer.
        unset($valide['medecin_prescripteur_nom'], $valide['laboratoire_nom'], $valide['laboratoire_code']);

        $valide = $this->resoudreLePrescripteur($valide);

        return $this->resoudreLeLaboratoire($valide);
    }

    /**
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     */
    private function resoudreLePrescripteur(array $valide): array
    {
        if (! isset($valide['medecin_prescripteur_id'])) {
            return $valide;
        }

        $id = (int) $valide['medecin_prescripteur_id'];
        $fiche = Medecin::find($id);

        if ($fiche === null) {
            throw ValidationException::withMessages([
                'medecin_prescripteur_id' => "Le professionnel n°{$id} n'existe pas au référentiel national.",
            ]);
        }

        $valide['medecin_prescripteur_id']  = $id;
        $valide['medecin_prescripteur_nom'] = $fiche->nom_complet;

        // Le texte libre est ALIGNÉ sur le référentiel quand le lien existe : garder deux noms
        // différents dans la même ligne laisserait le lecteur choisir lequel croire.
        $valide['medecin_prescripteur'] = $fiche->nom_complet;

        return $valide;
    }

    /**
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     */
    private function resoudreLeLaboratoire(array $valide): array
    {
        if (! isset($valide['laboratoire_id'])) {
            return $valide;
        }

        $id = (int) $valide['laboratoire_id'];
        $structure = StructureSanitaire::find($id);

        if ($structure === null) {
            throw ValidationException::withMessages([
                'laboratoire_id' => "L'établissement n°{$id} n'existe pas au référentiel national.",
            ]);
        }

        // UN RÉSULTAT NE VIENT PAS D'UNE PHARMACIE. Laisser passer n'importe quel établissement
        // ferait du champ « laboratoire » un champ « établissement », et le référentiel des
        // laboratoires ne voudrait plus rien dire.
        if ($structure->type !== 'laboratoire') {
            throw ValidationException::withMessages([
                'laboratoire_id' => "« {$structure->nom} » n'est pas un laboratoire au référentiel national.",
            ]);
        }

        $valide['laboratoire_id']   = $id;
        $valide['laboratoire_nom']  = $structure->nom;
        $valide['laboratoire_code'] = $structure->identifiant_national;
        $valide['laboratoire']      = $structure->nom;

        return $valide;
    }
}
