<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Page de démonstration/test du Module 1 (Triage), servie par le backend lui-même.
// Même origine que l'API => aucun problème CORS, testable directement au navigateur.
// Outil de DEV uniquement (utile pour la soutenance / vérification visuelle).
Route::get('/triage-demo', function () {
    return view('triage-demo');
});

// Page de démonstration/test du Module 2A.1 (Authentification téléphone + OTP).
// Même origine que l'API => aucun CORS, testable directement au navigateur (localhost ou Ngrok).
// Outil de DEV uniquement : le code OTP renvoyé par l'API n'est exposé qu'en environnement local.
Route::get('/auth-demo', function () {
    return view('auth-demo');
});

// Page de démonstration/test du Module 2 (Carnet de santé 2A.2 + QR dynamique 2A.3).
// Même origine que l'API => aucun CORS. Le QR est rendu côté navigateur (le token ne sort
// jamais de la page). Outil de DEV uniquement (vérification visuelle / soutenance).
Route::get('/carnet-demo', function () {
    return view('carnet-demo');
});
