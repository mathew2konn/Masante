<?php

use App\Support\AutorisationCanalPresenceRdv;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| B1-c — Autorisation des canaux de diffusion (D9, CDC_11 §9)
|--------------------------------------------------------------------------
|
| PREMIER FICHIER DE CE PROJET (Reverb, jamais installé avant B1-c). Un seul canal existe :
| `rdv.{id}.presence`, PRIVÉ. Le jugement (« qui a le droit ? ») vit dans
| {@see \App\Support\AutorisationCanalPresenceRdv}, pas ici — un test qui passerait par ce fichier
| directement ne prouverait rien, les seuls pilotes de diffusion utilisables en test (`null`/`log`)
| n'implémentant pas la vérification d'authentification d'un canal privé.
*/
Broadcast::channel('rdv.{rdvId}.presence', AutorisationCanalPresenceRdv::verifier(...));
