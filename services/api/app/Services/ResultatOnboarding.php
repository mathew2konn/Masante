<?php

namespace App\Services;

use App\Models\StructureSanitaire;
use App\Models\User;

/**
 * P11.1 — Ce que produit la création d'un établissement : la structure, son gestionnaire, et le
 * lien d'activation à lui transmettre.
 *
 * Un objet plutôt qu'une chaîne : la méthode 2 a besoin de savoir QUEL établissement est né pour
 * le rattacher à la demande approuvée. La première écriture de ce service rendait le seul lien,
 * et l'appelant retrouvait la structure **en relisant le compte par son adresse e-mail** — un
 * détour qui aurait rattaché la demande au mauvais établissement le jour où deux comptes auraient
 * partagé une adresse, et qui redemandait à la base ce que l'appelé tenait déjà.
 */
final readonly class ResultatOnboarding
{
    public function __construct(
        public StructureSanitaire $structure,
        public User $gestionnaire,
        public string $lienActivation,
    ) {}
}
