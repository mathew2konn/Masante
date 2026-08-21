<?php

namespace App\Services\Audit;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ce que doit savoir faire un journal chaîné pour être gouverné par {@see ChaineAudit}.
 *
 * Les quatre journaux du projet écrivent des faits de natures différentes — gouvernance des
 * référentiels, gouvernance des protocoles, signatures PKI, évaluations cliniques. Ce qu'ils
 * partagent n'est pas leur contenu mais leur **mécanique** : un maillon porte l'empreinte du
 * précédent, et la chaîne se vérifie en la recalculant.
 *
 * L'interface expose donc le strict nécessaire à cette mécanique, et rien du métier.
 */
interface JournalChaine
{
    /** Nom de la table, tel qu'il figure dans {@see ChaineAudit::JOURNAUX}. */
    public function nomJournal(): string;

    /** Requête neuve sur le modèle du journal. */
    public function requete(): Builder;

    /**
     * La charge hachée d'une entrée — c'est-à-dire ce que l'empreinte protège.
     *
     * @param  object  $entree  Une ligne du journal.
     * @return array<string, mixed>
     */
    public function charge(object $entree): array;

    /** @return array<string, mixed> */
    public function verifierChaine(): array;
}
