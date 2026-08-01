<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module 4 / 4.1 — Tableau de bord du portail (contenu adapté au rôle).
 *
 * Le socle 4.1 se limite à l'accueil authentifié ; les fonctions (établissements, services,
 * dispo, RDV, scan) arrivent aux sous-étapes 4.2 → 4.6.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        return view('portail.dashboard', [
            'utilisateur' => $request->user(),
        ]);
    }
}
