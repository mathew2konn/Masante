<?php

namespace Tests\Concerns;

use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use App\Models\User;
use App\Services\Protocole\ServiceGouvernanceProtocole;
use App\Services\Triage\ServiceNiveauTriage;
use App\Services\Triage\ServiceQuestionnaire;
use Database\Seeders\PortailRolesSeeder;
use Database\Seeders\ProtocoleSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * P10b-1 — Met en vigueur le protocole qui décide du niveau de triage.
 *
 * ═══ POURQUOI CE TRAIT EXISTE, ET CE QU'IL DIT DU MODULE ═══
 *
 * Depuis P10b-1, `POST /triage/analyser` répond **503** tant qu'aucun protocole n'est publié : le
 * niveau de priorité ne vient plus du code, il vient d'un texte validé par quatre relecteurs (§7).
 * Vingt et un vecteurs antérieurs — orientation (P10a), antécédents (Module 1), numéros d'urgence
 * (P6.8e) — se sont donc mis à échouer d'un coup.
 *
 * **C'est la preuve que le refus bruyant fonctionne, pas une régression.** Ils ont été complétés,
 * jamais affaiblis : aucun n'a été rendu tolérant au 503, aucune assertion n'a été retirée. Le
 * précédent est L1+L2, où la bascule de `seuils_mesure` avait produit exactement le même effet sur
 * la suite des mesures.
 *
 * ═══ DEUX COMPTES AU MINIMUM, ET CE N'EST PAS UNE LOURDEUR ═══
 *
 * Le quatre-yeux du §10 ne se contourne pas. Un helper qui publierait avec un seul compte aurait
 * prouvé le contraire de ce que la gouvernance garantit — et aurait masqué la régression le jour
 * où le contrôle sauterait. Même raisonnement, mot pour mot, que {@see GouverneUnReferentiel}.
 *
 * ═══ LES QUATRE VALIDATIONS SONT RÉELLEMENT POSÉES ═══
 *
 * Le helper ne prend pas de raccourci par la base : il appelle le service, donc il traverse
 * l'habilitation, l'anti-substitution et les contrôles du §7.4. Si l'une de ces gardes se mettait
 * à refuser à tort, **toute la suite de triage tomberait** — ce qui est le bon niveau d'alarme
 * pour une garde qui protège une règle clinique.
 */
trait PublieLeProtocoleDeTriage
{
    /**
     * Met en vigueur les DEUX protocoles sans lesquels un triage ne peut pas aboutir.
     *
     * @return int le numéro de la version du protocole de NIVEAU (contrat historique du helper)
     */
    protected function publierProtocoleDeTriage(): int
    {
        $this->seed(PortailRolesSeeder::class);
        $this->seed(ProtocoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $numero = $this->publierProtocole(ServiceNiveauTriage::CODE);

        // ═══ P10b-3-i — LE DÉPLOIEMENT A UNE TROISIÈME ÉTAPE ═══
        //
        // `POST /triage/analyser` répond désormais 503 tant que le QUESTIONNAIRE n'est pas publié,
        // en plus du référentiel des symptômes (P10a) et du protocole de niveau (P10b-1). Trente
        // vecteurs antérieurs se sont mis à échouer d'un coup lors de la bascule.
        //
        // **C'est la preuve que le refus bruyant fonctionne, pas une régression** — et c'est
        // exactement ce qui s'était produit en P10b-1 et, avant lui, en L1+L2. Les vecteurs sont
        // complétés ici, en un seul endroit ; aucun n'a été rendu tolérant au 503, aucune
        // assertion n'a été retirée.
        //
        // Le refus vaut même quand le patient ne répond à aucune question. Sans lui, un oubli de
        // publication ferait trier des patients **sans jamais les interroger**, avec un score
        // systématiquement plus bas et rien pour le signaler : la panne muette que ce projet
        // ferme depuis P6.3.
        $this->publierProtocole(ServiceQuestionnaire::CODE);

        // `ServiceNiveauTriage` est lié en `scoped` : il pinne une version pour la durée d'une
        // requête. Sans cet oubli, une requête postérieure à la publication continuerait de lire
        // l'état d'avant — le piège attrapé en L1+L2 et documenté dans `GouverneUnReferentiel`.
        if (method_exists($this, 'simulerNouvelleRequete')) {
            $this->simulerNouvelleRequete();
        } else {
            $this->app->forgetScopedInstances();
        }

        return $numero;
    }

    /**
     * Fait franchir à un protocole les quatre validations du §7 puis le publie, à deux comptes.
     *
     * Le helper ne prend aucun raccourci par la base : il appelle le service, donc il traverse
     * l'habilitation, l'anti-substitution et les contrôles du §7.4.
     *
     * @return int le numéro de la version mise en vigueur
     */
    protected function publierProtocole(string $code): int
    {
        $gouvernance = app(ServiceGouvernanceProtocole::class);

        $version = Protocole::query()
            ->where('code', $code)
            ->firstOrFail()
            ->versions()
            ->where('etat', ProtocoleVersion::BROUILLON)
            ->firstOrFail();

        $relecteur = $this->agentProtocole(...array_values(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION));

        foreach (array_keys(ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION) as $type) {
            $gouvernance->valider($version, $relecteur, $type, 'favorable', 'Relecteur '.$type);
        }

        return (int) $gouvernance->publier(
            $version->refresh(),
            $this->agentProtocole(ServiceGouvernanceProtocole::PERMISSION_PUBLIER),
        )->numero;
    }

    protected function agentProtocole(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
