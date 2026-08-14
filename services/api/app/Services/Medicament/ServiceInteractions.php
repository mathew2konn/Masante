<?php

namespace App\Services\Medicament;

use App\Models\InteractionMedicamenteuse;
use App\Models\Medicament;
use App\Support\Medicaments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Écriture et lecture des interactions déclarées (CDC_09 §6.2).
 *
 * ═══ CE QUE CE SERVICE FAIT, ET CE QU'IL NE FERA JAMAIS ═══
 *
 * Il enregistre ce qu'une autorité déclare, et il rapporte ce qui est déclaré. Il ne calcule aucun
 * risque, ne propose aucune alternative, n'adapte aucune dose et ne refuse aucune prescription :
 * tout cela appartient au `interaction-service` de CDC_05 §2, et une machine qui refuserait une
 * ordonnance prendrait une décision médicale (CDC_00 §4).
 *
 * ═══ L'ORDRE DU COUPLE EST LA GARANTIE CENTRALE ═══
 *
 * Le couple est toujours écrit avec `medicament_a_id < medicament_b_id`. Sans cela, « A avec B » et
 * « B avec A » seraient deux lignes que le moteur accepterait toutes les deux : le référentiel
 * porterait **deux affirmations** sur le même fait clinique, et rien ne garantirait qu'elles
 * s'accordent sur le niveau. L'ordre rend l'unicité déclarative — c'est la base qui refuse, pas une
 * vérification applicative qu'on pourrait oublier d'appeler.
 */
final class ServiceInteractions
{
    /**
     * Déclare une interaction entre deux médicaments.
     *
     * @throws ValidationException si les deux médicaments sont le même produit, ou si l'interaction
     *                             est déjà déclarée (dans un sens ou dans l'autre).
     */
    public function declarer(
        Medicament $a,
        Medicament $b,
        string $niveau,
        string $description,
        ?string $conduiteATenir = null,
        ?string $source = null,
    ): InteractionMedicamenteuse {
        if ($a->getKey() === $b->getKey()) {
            // Un produit n'interagit pas avec lui-même. Le laisser passer produirait une ligne que
            // toute lecture d'interactions renverrait sur elle-même, et qu'aucun niveau ne peut
            // qualifier honnêtement.
            throw ValidationException::withMessages([
                'medicament_b_id' => 'Un médicament ne peut pas être déclaré en interaction avec lui-même.',
            ]);
        }

        if (! in_array($niveau, Medicaments::niveauxInteraction(), true)) {
            throw ValidationException::withMessages([
                'niveau' => 'Niveau d\'interaction inconnu.',
            ]);
        }

        [$premier, $second] = $this->ordonner($a, $b);

        $interaction = new InteractionMedicamenteuse([
            'niveau'           => $niveau,
            'description'      => $description,
            'conduite_a_tenir' => $conduiteATenir,
            'source'           => $source,
        ]);

        $interaction->medicament_a_id = $premier->getKey();
        $interaction->medicament_b_id = $second->getKey();

        try {
            $interaction->save();
        } catch (UniqueConstraintViolationException) {
            // C'est le MOTEUR qui refuse, pas un contrôle applicatif : le couple ordonné rend
            // l'unicité déclarative, donc infranchissable même par un chemin qu'on n'a pas prévu.
            throw ValidationException::withMessages([
                'medicament_b_id' => 'Une interaction est déjà déclarée entre ces deux médicaments.',
            ]);
        }

        return $interaction;
    }

    /**
     * Les interactions déclarées entre les médicaments d'une liste — la lecture qu'expose §6.2.
     *
     * On ne renvoie que les couples dont **les deux** membres sont dans la liste : une interaction
     * avec un produit que le patient ne prend pas n'a rien à faire dans la réponse, et l'y mettre
     * ferait passer une information générale pour une alerte le concernant.
     *
     * @param  array<int, int>  $medicamentIds
     * @return Collection<int, InteractionMedicamenteuse>
     */
    public function entre(array $medicamentIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $medicamentIds)));

        if (count($ids) < 2) {
            return collect();
        }

        return InteractionMedicamenteuse::query()
            ->with(['medicamentA:id,code,nom_generique,nom_commercial', 'medicamentB:id,code,nom_generique,nom_commercial'])
            ->whereIn('medicament_a_id', $ids)
            ->whereIn('medicament_b_id', $ids)
            ->get()
            ->sortBy(fn (InteractionMedicamenteuse $i) => array_search($i->niveau, Medicaments::ORDRE_GRAVITE, true))
            ->values();
    }

    /**
     * Ordonne le couple par identifiant croissant.
     *
     * @return array{0: Medicament, 1: Medicament}
     */
    private function ordonner(Medicament $a, Medicament $b): array
    {
        return $a->getKey() < $b->getKey() ? [$a, $b] : [$b, $a];
    }
}
