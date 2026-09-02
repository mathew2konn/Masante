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

/*
|--------------------------------------------------------------------------
| Facturation partenaire — Lot 1 : bascule au Palier 0 (docs/REGLES_RECOUVREMENT_PARTENAIRE.md)
|--------------------------------------------------------------------------
|
| DEUXIÈME tâche planifiée de ce projet côté Laravel. Même statut que la précédente : « prête à
| activer » (ADR-014) — aucun `schedule:run` n'est branché sur un cron dans cet environnement de
| développement, la commande reste appelable directement (`masante:recouvrement:verifier-echeances`).
|
| Heure choisie avant celle du calendrier vaccinal, sans dépendance entre les deux : une bascule au
| Palier 0 est une décision commerciale, pas une raison de retarder une notification de santé.
*/
Schedule::command('masante:recouvrement:verifier-echeances')
    ->dailyAt('06:00')
    // Même raison que ci-dessus : deux serveurs applicatifs ne doivent pas basculer deux fois la
    // même structure ou lui imputer un double passage le même jour.
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Facturation patient — Lot 9 : relance des factures en retard (R18)
|--------------------------------------------------------------------------
|
| TROISIÈME tâche planifiée de ce projet côté Laravel. Même statut « prête à activer » (ADR-014)
| que les deux précédentes. Après le recouvrement partenaire (06:00) : une relance patient n'a pas
| de raison de précéder la bascule commerciale du jour.
|
| `relance_envoyee_le` (posé par le service, pas ici) est ce qui rend un passage manqué inoffensif
| et un second passage le même jour sans effet — la garantie « une seule fois » (R18) ne dépend pas
| de cette planification, elle tiendrait même sans `withoutOverlapping()`.
*/
Schedule::command('masante:facturation:relancer-patients')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Surveillance de derive du modele IA — P10c-3-ii lot B (CDC_05 §8)
|--------------------------------------------------------------------------
|
| QUATRIEME tache planifiee de ce projet cote Laravel, et meme statut « prete a activer »
| (ADR-014) : aucun `schedule:run` n'est branche sur un cron dans cet environnement, la commande
| reste appelable directement (`masante:triage:modele:derive`) — c'est le chemin du G2.
|
| Apres les trois autres, deliberement : une derive constatee n'appelle aucune action urgente, et
| **rien ne se desactive automatiquement** (F39). Elle previent un controleur plateforme, qui
| decidera avec le rollback a sa disposition. Retarder une notification de sante pour rendre plus
| tot un indice statistique serait le mauvais ordre.
|
| `withoutOverlapping()` pour la meme raison que les precedentes ; l'idempotence du rapport, elle,
| ne depend pas de cette garde — la cle (version, jour, nature, indicateur) la tient deja.
*/
Schedule::command('masante:triage:modele:derive')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();
