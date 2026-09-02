<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * B1-c — charge `routes/channels.php` (D9). N'appelle PAS `Broadcast::routes()` : la route
 * d'autorisation est posée à la main dans `routes/api.php`, sous `auth:sanctum` (guard mobile),
 * là où `Broadcast::routes()` viserait par défaut le guard `web` — précédent exact du piège
 * rencontré sur `rdv.validate` en P4.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        require base_path('routes/channels.php');
    }
}
