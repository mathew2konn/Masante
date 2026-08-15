<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Urgence\ServiceNumerosUrgence;
use Illuminate\Http\JsonResponse;

/**
 * P6.8e — Les numéros d'urgence nationaux (CDC_09 §8).
 *
 * ═══ PUBLIC, ET ICI CE N'EST PAS UNE COMMODITÉ ═══
 *
 * `/villes`, `/structures`, `/specialites` sont publics par confort d'écran. Celui-ci l'est par
 * nécessité : son consommateur est atteignable **depuis l'écran de connexion**, pour un secouriste
 * qui ramasse le téléphone d'un inconscient (FN2). Exiger un jeton reviendrait à demander ses
 * identifiants à quelqu'un qui n'a pas de compte, devant un blessé.
 *
 * ═══ IL REFUSE HONNÊTEMENT, ET C'EST LE CLIENT QUI REPLIE ═══
 *
 * Sans version en vigueur, cette route répond **503**, exactement comme le calendrier vaccinal de
 * P6.8b : le serveur ne sert jamais la table de travail en se faisant passer pour le référentiel.
 *
 * Ce qui change par rapport aux neuf référentiels précédents, c'est **où vit la résilience** : chez
 * le client, qui garde le dernier contenu reçu dans `SecureStore` (lequel survit à la déconnexion,
 * à la différence du cache chiffré P2) et retombe en dernier recours sur la valeur livrée avec
 * l'application. *L'honnêteté est due à l'exploitant, la disponibilité au secouriste — et les deux
 * tiennent ensemble parce qu'elles ne vivent pas au même endroit.*
 *
 * FRONTIÈRE : ce contrôleur ne décide de rien. Il ne choisit pas quel numéro appeler pour quel
 * symptôme, ne recommande rien, n'ordonne rien — l'ordre lui-même est une donnée du référentiel.
 */
class NumeroUrgenceController extends Controller
{
    public function __construct(private readonly ServiceNumerosUrgence $numeros) {}

    /**
     * GET /api/v1/numeros-urgence — les numéros en vigueur.
     *
     * `source` et `source_detail` sont EXPOSÉS. Ce n'est pas un détail d'implémentation : le jeu
     * livré porte `declaration_projet`, et une application qui affiche un numéro d'urgence doit
     * pouvoir dire d'où il vient si on le lui demande. Ce que l'écran en fait est sa décision —
     * l'écran SOS, lui, n'affiche rien de tout cela (décision C1).
     */
    public function index(): JsonResponse
    {
        // Déclenche le refus bruyant AVANT de composer une réponse vide, qui aurait ressemblé à
        // « ce pays n'a aucun numéro d'urgence » (motif `VaccinController::contenu()`).
        if (! $this->numeros->estEnVigueur()) {
            abort(503, 'Le référentiel des numéros d\'urgence n\'a aucune version en vigueur '
                .'(CDC_09 §10). Les applications composent la valeur qu\'elles ont en mémoire.');
        }

        return response()->json([
            'numeros' => array_map(static fn (array $n): array => [
                'code'          => $n['code'],
                'numero'        => $n['numero'],
                'libelle'       => $n['libelle'],
                'description'   => $n['description'] ?? null,
                'ordre'         => $n['ordre'] ?? 100,
                'source'        => $n['source'] ?? null,
                'source_detail' => $n['source_detail'] ?? null,
            ], $this->numeros->actifs()),
            // §10 « toute décision conserve la version du référentiel utilisée ».
            'version' => $this->numeros->version(),
        ]);
    }
}
