<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tâches planifiées
|--------------------------------------------------------------------------
|
| P6.8b — Échéances du calendrier vaccinal national (CDC_09 §8).
|
| PREMIÈRE tâche planifiée de ce projet côté Laravel : jusqu'ici, seuls les microservices Java et
| Python en portaient (`@Scheduled`). Le déclenchement au JOUR EXACT rend la commande idempotente
| par construction, donc un passage quotidien suffit et un passage manqué ne double rien — il ne
| rattrape simplement pas le jour perdu, ce qui est dit comme limite.
|
| DIT SANS ENJOLIVER : cette déclaration ne s'exécute que si un `schedule:run` est branché sur un
| cron (`* * * * * php artisan schedule:run`). Aucun n'existe dans l'environnement de développement
| de ce projet, où le serveur est lancé à la main. La tâche est donc « prête à activer » au sens
| d'ADR-014 — conçue, déclarée, exécutable à la demande, et pas encore planifiée en exploitation.
| La commande reste appelable directement, ce qui est le chemin utilisé au G2.
*/
Schedule::command('masante:vaccins:echeances')
    ->dailyAt('07:00')
    // Sans cette garde, deux serveurs applicatifs notifieraient deux fois la même famille.
    ->withoutOverlapping()
    ->runInBackground();
