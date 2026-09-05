<?php

namespace App\Services\Analyse;

use App\Models\Analyse;
use Illuminate\Validation\ValidationException;

/**
 * P6.7a — Le lien entre une ligne de résultat et le catalogue national (CDC_09 §7.3).
 *
 * ═══ LE DÉFAUT QUE CE SERVICE REFERME ═══
 *
 * `resultats_json` était validé en `nullable|array`, **sans aucune structure** : le mobile y envoyait
 * des couples `{parametre, valeur}` en texte libre. Ni unité, ni référence, ni milieu prélevé. Deux
 * laboratoires pouvaient rendre la même analyse sous deux noms et deux unités, et rien ne les
 * rapprochait — l'inverse exact de ce que §7.3 demande : « les résultats sont interprétés de manière
 * cohérente, quel que soit le laboratoire ».
 *
 * Troisième instance de la même famille de défauts, après `ordonnances.medecin_nom` (P6.5) et
 * `medicaments_json.*.nom` (P6.6).
 *
 * ═══ MÊME FORME QU'EN P6.6b, ET C'EST VOULU ═══
 *
 * Lien FACULTATIF (un patient qui recopie un compte rendu papier n'a pas de liste sous les yeux, et
 * le catalogue est incomplet) ; mais quand il est fourni, le code national, le libellé et **l'unité**
 * sont relus au catalogue et **figés**. Un compte rendu doit continuer de dire ce qu'il disait, même
 * si le catalogue est corrigé ensuite.
 *
 * L'UNITÉ EST FIGÉE AVEC LE RESTE, et c'est le point le plus important ici : un résultat dont
 * l'unité changerait après coup deviendrait faux d'un facteur 10 ou 100 sans que rien ne le signale.
 *
 * ═══ B5-a — RÉUTILISÉ TEL QUEL POUR LES DEMANDES D'EXAMEN, PAS RÉÉCRIT ═══
 *
 * `demande_analyse_lignes` (B5-a) porte le même lien facultatif au même catalogue, pour la même
 * raison. Le réécrire aurait créé un second endroit qui interroge le catalogue (refus P6.6a) ;
 * seul le nom du champ signalé dans l'erreur diffère selon l'appelant, d'où `$champ`.
 */
final class ServiceLienAnalyse
{
    /**
     * Résout les lignes d'un tableau validé (`resultats_json` ou `analyses_json`).
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @param  string  $champ  le nom du champ signalé dans le message d'erreur — celui que
     *                         l'appelant a réellement validé, pour que le refus s'attribue au bon
     *                         endroit du formulaire.
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException si un `analyse_id` ne désigne aucune entrée du catalogue
     */
    public function resoudre(array $lignes, string $champ = 'resultats_json'): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $l): ?int => isset($l['analyse_id']) ? (int) $l['analyse_id'] : null,
            $lignes,
        )));

        $analyses = $ids === [] ? collect() : Analyse::whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_map(function (array $ligne) use ($analyses, $champ): array {
            // Les clés dérivées ne viennent jamais du client : on les efface avant de les reposer,
            // sinon une ligne pourrait porter un code national et une unité que rien n'a vérifiés.
            unset($ligne['code_national'], $ligne['libelle_catalogue'], $ligne['unite_catalogue']);

            if (! isset($ligne['analyse_id'])) {
                return $ligne;
            }

            $id = (int) $ligne['analyse_id'];
            $analyse = $analyses->get($id);

            if ($analyse === null) {
                throw ValidationException::withMessages([
                    $champ => "L'analyse n°{$id} n'existe pas au catalogue national.",
                ]);
            }

            $ligne['analyse_id']        = $id;
            $ligne['code_national']     = $analyse->code;
            $ligne['libelle_catalogue'] = $analyse->libelle;
            $ligne['unite_catalogue']   = $analyse->unite;

            return $ligne;
        }, $lignes));
    }
}
