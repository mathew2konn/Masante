<?php

namespace App\Support;

/**
 * Mode de règlement d'une commande (B3-d, F6, réécrit après B4) — miroir PHP de
 * `ModeReglementCommande`.
 *
 * `SUR_PLACE` : la plateforme ne touche à rien, littéralement aucun appel réseau (§9.6 vérifié
 * par construction). `EN_LIGNE` : checkout GeniusPay RÉEL (canal B4-b, transposé) — la commission
 * suit automatiquement le mécanisme déjà générique de `PaiementNotificationController` (B4-a),
 * sans nouvel appel à `CommissionService` depuis le domaine commande.
 */
enum ModeReglementCommande: string
{
    case SUR_PLACE = 'sur_place';
    case EN_LIGNE = 'en_ligne';
}
