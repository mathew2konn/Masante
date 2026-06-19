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
