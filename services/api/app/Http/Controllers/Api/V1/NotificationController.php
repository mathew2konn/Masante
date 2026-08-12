<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications en application (incrément D1).
 *
 * ANTI-IDOR : toutes les requêtes partent de `$request->user()->notifications()`. Il n'existe
 * aucun chemin permettant de nommer un destinataire — un identifiant de notification appartenant à
 * autrui est simplement introuvable (404), jamais refusé (403) : un 403 confirmerait son existence.
 *
 * FRONTIÈRE : ce contrôleur ne compose rien. Le titre, le corps et la cible sont décidés par
 * {@see \App\Services\ServiceNotification} au moment de l'événement, et figés en base.
 */
class NotificationController extends Controller
{
    /** Une page suffit largement à un usage familial ; au-delà, la liste n'est plus lue. */
    private const PAR_PAGE = 50;

    /** GET /api/v1/notifications */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        $notifications = $utilisateur->notifications()
            ->latest()
            ->limit(self::PAR_PAGE)
            ->get()
            ->map(fn ($n) => [
                'id'      => $n->id,
                'type'    => $n->type,
                'lue'     => $n->read_at !== null,
                'creee_a' => $n->created_at,
                // `data` est déjà décodé par le modèle natif : titre, corps et identifiants de
                // navigation, sans aucun contenu médical (règle posée au G1).
                'donnees' => $n->data,
            ]);

        return response()->json([
            'notifications' => $notifications,
            'non_lues'      => $utilisateur->unreadNotifications()->count(),
        ]);
    }

    /** GET /api/v1/notifications/non-lues — le strict nécessaire à la pastille. */
    public function nonLues(Request $request): JsonResponse
    {
        return response()->json(['non_lues' => $request->user()->unreadNotifications()->count()]);
    }

    /**
     * POST /api/v1/notifications/{notification}/lu
     *
     * Idempotent : marquer deux fois n'écrase pas la date de première lecture. « Quand l'a-t-il
     * vue » doit rester la PREMIÈRE fois, pas la dernière — c'est ce qui donne sa valeur à la
     * question « le responsable était-il au courant ? ».
     */
    public function marquerLu(Request $request, string $notification): JsonResponse
    {
        $ligne = $request->user()->notifications()->findOrFail($notification);

        if ($ligne->read_at === null) {
            $ligne->markAsRead();
        }

        return response()->json([
            'id'       => $ligne->id,
            'lue'      => true,
            'non_lues' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** POST /api/v1/notifications/tout-lu */
    public function toutMarquerLu(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['non_lues' => 0]);
    }
}
