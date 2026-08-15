<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Referentiel\ServiceGouvernanceReferentiel;
use Database\Seeders\PortailRolesSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Met en vigueur un référentiel gouverné — le préalable de tout vecteur qui lit une version publiée.
 *
 * EXTRAIT de `PublieLesSeuilsDeMesure` (L1+L2) quand P6.8b a eu besoin du même mécanisme pour le
 * calendrier vaccinal. Rien n'a changé de son comportement : seule la partie générique a été
 * remontée ici, la partie propre aux seuils est restée où elle était.
 *
 * IL FAUT DEUX COMPTES, ET CE N'EST PAS UNE LOURDEUR DE TEST. Le quatre-yeux du §10 ne se contourne
 * pas : l'auteur d'une proposition ne peut pas la publier. Un helper qui aurait triché avec un seul
 * compte aurait prouvé le contraire de ce que la gouvernance garantit — et aurait masqué une
 * régression le jour où le contrôle sauterait.
 */
trait GouverneUnReferentiel
{
    /**
     * Enregistre, propose et publie le contenu ACTUEL d'un référentiel.
     *
     * @return int le numéro de la version mise en vigueur
     */
    protected function publierReferentiel(string $code, string $motif = 'Mise en vigueur nationale.'): int
    {
        $this->seed(PortailRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $gouvernance = app(ServiceGouvernanceReferentiel::class);
        $gouvernance->enregistrer($code);

        $auteur   = $this->agentReferentiel(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agentReferentiel(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);

        $gouvernance->proposer($code, 'CI', $auteur, $motif);

        $numero = $gouvernance->publier($code, 'CI', $decideur, $motif)->numero;

        $this->simulerNouvelleRequete();

        return $numero;
    }

    /**
     * Propose et publie une NOUVELLE version du contenu actuel — le chemin légitime d'une
     * correction, une fois la table modifiée.
     */
    protected function republierReferentiel(string $code, string $motif): int
    {
        $gouvernance = app(ServiceGouvernanceReferentiel::class);

        $auteur   = $this->agentReferentiel(ServiceGouvernanceReferentiel::PERMISSION_PROPOSER);
        $decideur = $this->agentReferentiel(ServiceGouvernanceReferentiel::PERMISSION_PUBLIER);

        $gouvernance->proposer($code, 'CI', $auteur, $motif);

        $numero = $gouvernance->publier($code, 'CI', $decideur, $motif)->numero;

        $this->simulerNouvelleRequete();

        return $numero;
    }

    /**
     * Rétablit la frontière de requête que le harnais de test efface.
     *
     * `Illuminate\Routing\Route` MÉMOÏSE son contrôleur pour toute la durée de l'application. En
     * production ce n'est jamais visible — chaque requête HTTP reboote l'application, donc chaque
     * requête obtient un service neuf, qui lit la version en vigueur au moment où elle arrive. Dans
     * un test, l'application vit d'un bout à l'autre : sans ce flush, une requête postérieure à une
     * publication continuerait de lire la version chargée par la PREMIÈRE requête.
     *
     * C'est exactement ce que fait Laravel Octane entre deux requêtes. On restaure la frontière
     * réelle plutôt que de retirer la mémoïsation — car pinner UNE version pour toute la durée
     * d'une requête est voulu.
     *
     * CE PIÈGE A DÉJÀ FAIT PASSER UN VECTEUR QUI NE PROUVAIT RIEN : au premier essai de L1, le
     * vecteur central « un `UPDATE` direct reste sans effet » survivait à la neutralisation de la
     * bascule, parce que les deux saisies partageaient le même service mémoïsé. Il prouvait la
     * mémoïsation, pas la bascule.
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
