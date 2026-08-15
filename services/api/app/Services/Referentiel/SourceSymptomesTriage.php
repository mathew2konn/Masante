<?php

namespace App\Services\Referentiel;

use App\Models\Symptome;

/**
 * Référentiel national des symptômes de triage (table `symptomes`, Module 1).
 *
 * POURQUOI CELUI-CI : `poids_severite`, `drapeau_rouge` et les questions complémentaires ne sont
 * pas de la donnée d'annuaire, ce sont les RÈGLES qui décident du niveau d'urgence d'un citoyen.
 * Aujourd'hui, corriger un poids de sévérité rend inexplicable tout triage antérieur — le G0 a
 * confirmé que `triages` ne stocke aucune version de protocole. Versionner ce référentiel est le
 * préalable à l'estampille des décisions.
 *
 * La table n'est PAS modifiée et `TriageService` continue de la lire directement : le triage est
 * un module prouvé, « corrections chirurgicales uniquement ». Le socle en fige des instantanés.
 *
 * L'INSTANTANÉ RETIENT LES SYMPTÔMES INACTIFS. `actif` est une donnée du référentiel, pas un
 * filtre du socle : un triage passé a pu s'appuyer sur un symptôme désactivé depuis. L'exclure de
 * l'instantané rendrait ce triage irrejouable — ce que le versionnage est censé empêcher.
 */
final class SourceSymptomesTriage implements SourceReferentiel
{
    public const CODE = 'symptomes_triage';

    /** Le score de triage se lit sur 100 : au-delà, la valeur est aberrante (§10). */
    private const POIDS_MAX = 100;

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Symptômes et règles de triage';
    }

    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        return Symptome::query()
            // `id` en second critère : `nom_fr` n'est pas UNIQUE en base (le contrôle qualité le
            // signale s'il se répète), l'ordre doit rester total quand même.
            ->orderBy('nom_fr')
            ->orderBy('id')
            ->get()
            ->map(fn (Symptome $s): array => [
                // `id` fait partie de l'instantané : c'est par lui qu'un triage archivé désigne
                // les symptômes qu'il a retenus. Un instantané sans identifiant serait lisible,
                // mais pas rattachable à une décision passée.
                'id'                             => (int) $s->id,
                'nom_fr'                         => $s->nom_fr,
                'categorie'                      => $s->categorie,
                'poids_severite'                 => (int) $s->poids_severite,
                'specialite_hint'                => $s->specialite_hint,
                'drapeau_rouge'                  => (bool) $s->drapeau_rouge,
                'questions_complementaires_json' => $s->questions_complementaires_json,
                // ═══ P6.8c — CES LIBELLÉS NE SONT PAS CEUX DU RÉFÉRENTIEL DES MALADIES ═══
                //
                // Ils sont libres, et ils mêlent des maladies (« Paludisme »), des syndromes
                // (« Détresse respiratoire ») et un état physiologique (« Grossesse »). Le G0 de
                // P6.8c a par ailleurs vérifié qu'ILS N'ONT AUCUN LECTEUR : `TriageController`
                // sélectionne des colonnes explicites et ne les renvoie pas, et rien dans le mobile
                // ne les affiche. Leur seule sortie du serveur est CET INSTANTANÉ — c'est-à-dire
                // l'endroit qui leur donne le plus d'autorité.
                //
                // POURQUOI ILS NE SONT PAS RATTACHÉS ICI, et le porteur est nommé : le triage est
                // refondu en **P10**, et y toucher maintenant modifierait deux fois un module validé
                // G5 (même raisonnement que `specialite_hint` en P6.8a). S'y ajoute qu'aucun
                // consommateur ne les lit : les rattacher aujourd'hui serait le socle à vide refusé
                // en P6.3-D3. *Nommer un manque ne le comble pas, mais un manque nommé ne s'oublie
                // pas.*
                'maladies_probables_json'        => $s->maladies_probables_json,
                'actif'                          => (bool) $s->actif,
            ])
            ->all();
    }

    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le référentiel est vide : le triage n\'aurait plus aucun symptôme à proposer.'];
        }

        // Un référentiel de triage dont tous les symptômes sont désactivés est syntaxiquement
        // valide et cliniquement inutilisable. C'est le genre de publication qu'il faut arrêter.
        if (! collect($contenu)->contains(fn (array $l): bool => $l['actif'] === true)) {
            $erreurs[] = 'Aucun symptôme actif : le triage serait publié hors d\'état de fonctionner.';
        }

        $nomsVus = [];

        foreach ($contenu as $ligne) {
            $nom = trim((string) $ligne['nom_fr']);

            if ($nom === '') {
                $erreurs[] = "Symptôme n°{$ligne['id']} : libellé absent.";
                continue;
            }

            // Doublons (§10). La comparaison ignore la casse : « Fièvre » et « fièvre » sont le
            // même symptôme pour un citoyen qui choisit dans une liste.
            $cle = mb_strtolower($nom);
            if (isset($nomsVus[$cle])) {
                $erreurs[] = "Doublon : le symptôme « {$nom} » apparaît plusieurs fois "
                    ."(n°{$nomsVus[$cle]} et n°{$ligne['id']}).";
            }
            $nomsVus[$cle] = $ligne['id'];

            // Valeurs aberrantes (§10).
            if ($ligne['poids_severite'] < 0 || $ligne['poids_severite'] > self::POIDS_MAX) {
                $erreurs[] = "« {$nom} » : poids de sévérité aberrant ({$ligne['poids_severite']}, "
                    .'attendu entre 0 et '.self::POIDS_MAX.').';
            }

            // Cohérence clinique : un drapeau rouge force le niveau URGENT. Un drapeau rouge à
            // poids nul est contradictoire — l'un dit « critique », l'autre « sans gravité ».
            if ($ligne['drapeau_rouge'] === true && $ligne['poids_severite'] === 0) {
                $erreurs[] = "« {$nom} » : drapeau rouge posé mais poids de sévérité nul — "
                    .'la règle se contredit elle-même.';
            }

            // Format des questions complémentaires : le triage les parcourt comme une liste.
            $questions = $ligne['questions_complementaires_json'];
            if ($questions !== null && ! array_is_list($questions)) {
                $erreurs[] = "« {$nom} » : les questions complémentaires ne forment pas une liste.";
            }
        }

        return $erreurs;
    }
}
