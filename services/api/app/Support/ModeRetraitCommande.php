<?php

namespace App\Support;

/** Retrait à l'officine, ou livraison à l'adresse déclarée (B3-d, F7). Miroir PHP de `ModeRetraitCommande`. */
enum ModeRetraitCommande: string
{
    case RETRAIT = 'retrait';
    case LIVRAISON = 'livraison';
}
