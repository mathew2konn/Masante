<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Active $this->authorize() + Policies (anti-IDOR, §4.3 Sécurité) dans tous les contrôleurs.
    use AuthorizesRequests;
}
