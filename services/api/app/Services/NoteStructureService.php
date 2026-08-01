<?php

namespace App\Services;

use App\Models\StructureSanitaire;

/**
 * Recalcul de la note dénormalisée d'une structure (CdC §8.3 : `note_moyenne`, `nb_avis`).
 *
 * Ces deux colonnes résument les avis VISIBLES ; elles doivent donc être rafraîchies aussi bien
 * au dépôt d'un avis (Module 3, F3.9) qu'à sa modération (Module 4.6). Sans cela, masquer un avis
 * depuis le portail laisserait la fiche publique afficher une moyenne qui ne correspond plus
 * aux avis affichés.
 */
class NoteStructureService
{
    /** Recalcule `nb_avis` et `note_moyenne` à partir des seuls avis visibles. */
    public function recalculer(StructureSanitaire $structure): void
    {
        $visibles = $structure->avis()->where('visible', true);

        $nb = $visibles->count();
        $moyenne = $nb > 0 ? round((float) $visibles->avg('note'), 2) : null;

        $structure->update(['nb_avis' => $nb, 'note_moyenne' => $moyenne]);
    }
}
