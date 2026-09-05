<?php

namespace App\Services\Analyse;

use App\Models\DemandeAnalyse;
use App\Models\Prelevement;
use Illuminate\Support\Str;

/**
 * B5-b — L'identifiant du prélèvement : l'ÉTIQUETTE, distincte du jeton (L5).
 *
 * Le jeton de {@see DemandeAnalyse} est un SECRET D'ACCÈS (64 caractères, `$hidden`,
 * comparé en temps constant). Celui-ci est une ÉTIQUETTE : imprimée, collée sur un tube qui
 * circule physiquement, elle n'ouvre rien. *Mettre un secret d'accès sur une étiquette qui
 * circule reviendrait à distribuer la clé du dossier avec l'échantillon.*
 *
 * OPAQUE ET NON SÉQUENTIEL (patron `DemandeInscriptionEtablissement::genererReference()`,
 * P11.1) : un compteur laisserait deviner le volume d'analyses d'un laboratoire et énumérer les
 * prélèvements de la veille.
 */
final class GenerateurIdentifiantPrelevement
{
    public function generer(): string
    {
        do {
            $identifiant = 'PRE-'.Str::upper(Str::random(10));
        } while (Prelevement::where('identifiant', $identifiant)->exists());

        return $identifiant;
    }
}
