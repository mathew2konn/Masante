<?php

namespace Tests\Concerns;

use App\Models\ReferentielMesure;
use App\Models\User;
use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use App\Services\Referentiel\SourceSeuilsMesure;
use Database\Seeders\PortailRolesSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Met en vigueur les seuils nationaux de mesure — le préalable de tout vecteur qui écrit une mesure
 * depuis la bascule L1 (ADR-025 §5).
 *
 * IL FAUT DEUX COMPTES, ET CE N'EST PAS UNE LOURDEUR DE TEST. Le quatre-yeux du §10 ne se contourne
 * pas : l'auteur d'une proposition ne peut pas la publier. Un helper qui aurait triché avec un seul
 * compte aurait prouvé le contraire de ce que la bascule garantit — et aurait masqué une régression
 * le jour où le contrôle sauterait.
 */
trait PublieLesSeuilsDeMesure
{
    /**
     * Enregistre, propose et publie le contenu ACTUEL de `referentiels_mesure`.
     *
     * @return int le numéro de la version mise en vigueur
     */
    protected function publierLesSeuils(string $motif = 'Mise en vigueur nationale.'): int
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $gouvernance = app(ServiceGouvernanceReferentiel::class);
        $gouvernance->enregistrer(SourceSeuilsMesure::CODE);

        $auteur = $this->agentReferentiel(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agentReferentiel(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);

        $gouvernance->proposer(SourceSeuilsMesure::CODE, 'CI', $auteur, $motif);

        $numero = $gouvernance->publier(SourceSeuilsMesure::CODE, 'CI', $decideur, $motif)->numero;

        $this->simulerNouvelleRequete();

        return $numero;
    }

    /**
     * Corrige un seuil EN TABLE puis publie la version qui l'entérine — le chemin légitime d'une
     * correction clinique depuis L1.
     */
    protected function corrigerUnSeuilEtPublier(string $type, array $valeurs, string $motif): int
    {
        ReferentielMesure::where('type_mesure', $type)->update($valeurs);

        $gouvernance = app(ServiceGouvernanceReferentiel::class);

        $auteur = $this->agentReferentiel(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agentReferentiel(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);

        $gouvernance->proposer(SourceSeuilsMesure::CODE, 'CI', $auteur, $motif);

        $numero = $gouvernance->publier(SourceSeuilsMesure::CODE, 'CI', $decideur, $motif)->numero;

        $this->simulerNouvelleRequete();

        return $numero;
    }

    /**
     * Rétablit la frontière de requête que le harnais de test efface.
     *
     * `Illuminate\Routing\Route` MÉMOÏSE son contrôleur pour toute la durée de l'application. En
     * production ce n'est jamais visible — chaque requête HTTP reboote l'application, donc chaque
     * requête obtient un `MesureSanteService` neuf, qui lit la version en vigueur au moment où elle
     * arrive. Dans un test, l'application vit d'un bout à l'autre : sans ce flush, une requête
     * postérieure à une publication continuerait de lire la version chargée par la PREMIÈRE requête.
     *
     * C'est exactement ce que fait Laravel Octane entre deux requêtes. On restaure la frontière
     * réelle plutôt que de retirer la mémoïsation — car pinner UNE version pour toute la durée
     * d'une requête est voulu : les deux lignes d'une tension doivent être jugées par les mêmes
     * seuils, même si une publication survient entre les deux écritures.
     */
    protected function simulerNouvelleRequete(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
    }

    protected function agentReferentiel(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
