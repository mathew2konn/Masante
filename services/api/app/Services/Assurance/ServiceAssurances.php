<?php

namespace App\Services\Assurance;

use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\ReferentielException;
use App\Services\Referentiel\SourceAssurances;
use App\Support\TypesOrganismeAssurance;

/**
 * P6.8d — Ce que le référentiel des organismes d'assurance dit, dans sa version en vigueur
 * (CDC_09 §8/§10).
 *
 * ═══ IL LIT LA VERSION PUBLIÉE, PAS LA TABLE ═══
 *
 * Motif de `ServiceCalendrierVaccinal` (P6.8b) et de `ServiceMaladies` (P6.8c), pour la même raison :
 * ce consommateur est NEUF, aucun module validé G5 ne serait touché en faisant autrement. Le faire
 * lire la table livrerait dès le premier jour exactement le défaut qu'un incrément entier a dû
 * refermer pour `seuils_mesure` — un référentiel gouverné dont les seuls lecteurs ignorent la
 * gouvernance (ADR-025 §5/§6).
 *
 * **ÉCHEC BRUYANT, JAMAIS DE REPLI SUR LA TABLE** : un repli laisserait un oubli de publication
 * passer inaperçu — tout fonctionnerait, et personne ne saurait la garantie inactive. 503, parce que
 * ce n'est pas la ressource qui manque mais sa mise en vigueur.
 *
 * ═══ SAUF SUR LE CHEMIN D'ÉCRITURE ═══
 *
 * {@see organismePublie()} et {@see listePubliee()} ne lèvent rien : l'absence de version en vigueur
 * doit devenir un message attribué au champ que l'assuré a rempli, ou une liste vide qui s'explique
 * — jamais une panne au milieu de la déclaration de sa mutuelle.
 *
 * ═══ UNE SEULE VERSION PAR REQUÊTE ═══
 *
 * Mémoïsation (motif L2) : deux lectures dans la même requête ne peuvent pas tomber de part et
 * d'autre d'une publication.
 */
final class ServiceAssurances
{
    /** @var array<string, mixed>|false|null Instantané en vigueur ; `false` = cherché, aucun. */
    private array|false|null $publie = null;

    public function __construct(private readonly DiffusionReferentiel $diffusion) {}

    /** Le numéro de la version en vigueur — cité dans chaque réponse (§10). */
    public function version(): int
    {
        return (int) $this->charger()['version'];
    }

    /** Une version est-elle en vigueur ? Ne lève rien. */
    public function estEnVigueur(): bool
    {
        return $this->lireSiPubliee() !== null;
    }

    /**
     * L'organisme en vigueur portant ce code national dans ce pays, tel que publié, ou `null`.
     * NE LÈVE RIEN.
     *
     * @return array<string, mixed>|null
     */
    public function organismePublie(string $code, ?string $pays = null): ?array
    {
        $pays = strtoupper($pays ?? (string) config('referentiels.pays_defaut', 'CI'));

        foreach ($this->lireSiPubliee()['contenu'] ?? [] as $organisme) {
            if (($organisme['code'] ?? null) === $code
                && strtoupper((string) ($organisme['pays_code'] ?? '')) === $pays) {
                return $organisme;
            }
        }

        return null;
    }

    /**
     * La liste ACTIVE de la version en vigueur, prête pour une liste de choix ou une réponse d'API.
     * NE LÈVE RIEN — un tableau vide veut dire « aucune version publiée », et l'écran le dit.
     *
     * ORDRE : le régime national d'abord, puis par nom. Un assuré ivoirien sur deux cherche la CMU ;
     * l'enfouir dans un ordre alphabétique lui ferait parcourir une liste pour trouver l'évidence.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listePubliee(?string $pays = null): array
    {
        $pays  = strtoupper($pays ?? (string) config('referentiels.pays_defaut', 'CI'));
        $liste = [];

        foreach ($this->lireSiPubliee()['contenu'] ?? [] as $organisme) {
            if (! ($organisme['actif'] ?? false)) {
                continue;
            }

            if (strtoupper((string) ($organisme['pays_code'] ?? '')) !== $pays) {
                continue;
            }

            $liste[] = [
                'code'             => $organisme['code'],
                'nom'              => $organisme['nom'],
                'sigle'            => $organisme['sigle'] ?? null,
                'type'             => $organisme['type'],
                'type_libelle'     => TypesOrganismeAssurance::libelle((string) $organisme['type']),
                'agrement_statut'  => $organisme['agrement_statut'] ?? null,
                'agrement_fin'     => $organisme['agrement_fin'] ?? null,
                // Vide, et c'est dit (motif `loinc` / `code_cim10`).
                'numero_agrement'  => $organisme['numero_agrement'] ?? null,
                'de_demonstration' => ($organisme['source'] ?? null) === 'demonstration',
            ];
        }

        usort($liste, static fn (array $a, array $b) => [$a['type'] !== 'cnam', $a['nom']]
            <=> [$b['type'] !== 'cnam', $b['nom']]);

        return $liste;
    }

    /**
     * Recherche par nom ou par sigle, sans accent ni casse.
     *
     * Ce n'est PAS un rapprochement approximatif : on compare des chaînes normalisées, on ne mesure
     * aucune distance. Retrouver un organisme que l'assuré est en train de nommer n'est pas deviner
     * chez qui il est assuré — et c'est bien lui qui choisit dans la liste.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rechercher(string $terme, ?string $pays = null, int $limite = 50): array
    {
        $terme = self::normaliser($terme);
        $liste = $this->listePubliee($pays);

        if ($terme === '') {
            return array_slice($liste, 0, $limite);
        }

        $trouves = array_filter($liste, static function (array $o) use ($terme): bool {
            return str_contains(self::normaliser((string) $o['nom']), $terme)
                || str_contains(self::normaliser((string) ($o['sigle'] ?? '')), $terme);
        });

        return array_slice(array_values($trouves), 0, $limite);
    }

    /**
     * Le témoin visible du remplacement (motif P6.7a / P6.8b / P6.8c).
     *
     * Aucun numéro d'agrément n'a été chargé dans ce projet, et aucun assureur privé réel n'est
     * nommé. Tant que ce n'est pas fait, l'écran doit le dire — *une donnée de démonstration qui ne
     * se signale pas finit par être prise pour une donnée de référence*.
     */
    public function avertissementDemonstration(?string $pays = null): ?string
    {
        $liste       = $this->listePubliee($pays);
        $demo        = count(array_filter($liste, static fn (array $o): bool => $o['de_demonstration']));
        $sansAgrement = count(array_filter(
            $liste,
            static fn (array $o): bool => ($o['numero_agrement'] ?? null) === null,
        ));

        if ($demo === 0 && $sansAgrement === 0) {
            return null;
        }

        return sprintf(
            '%d organisme%s de ce référentiel %s issu%s d\'un jeu de DÉMONSTRATION et n\'%s été '
            .'agréé%s par aucune autorité dans ce projet ; %d n\'%s aucun numéro d\'agrément '
            .'enregistré. Cette liste ne prouve pas qu\'un organisme est agréé.',
            $demo,
            $demo > 1 ? 's' : '',
            $demo > 1 ? 'sont' : 'est',
            $demo > 1 ? 's' : '',
            $demo > 1 ? 'ont' : 'a',
            $demo > 1 ? 's' : '',
            $sansAgrement,
            $sansAgrement > 1 ? 'ont' : 'a',
        );
    }

    /** Minuscules sans accent : « Générale » et « generale » désignent la même chose. */
    public static function normaliser(string $valeur): string
    {
        $sansAccent = strtr(
            mb_strtolower(trim($valeur)),
            [
                'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                'î' => 'i', 'ï' => 'i', 'í' => 'i',
                'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
                'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
                'ç' => 'c', 'ñ' => 'n',
            ],
        );

        return preg_replace('/\s+/', ' ', $sansAccent) ?? $sansAccent;
    }

    /**
     * @return array<string, mixed>
     */
    private function charger(): array
    {
        $publie = $this->lireSiPubliee();

        if ($publie === null) {
            abort(503, 'Le référentiel national des organismes d\'assurance n\'a aucune version en '
                .'vigueur : aucun organisme ne peut être servi tant qu\'une version n\'a pas été '
                .'publiée (CDC_09 §10).');
        }

        return $publie;
    }

    /**
     * L'instantané en vigueur, ou `null` s'il n'y en a aucun.
     *
     * MÉMOÏSÉ, et `false` distingue « pas encore cherché » de « cherché, rien trouvé » : sans cette
     * distinction, une absence de publication ferait rejouer la lecture à chaque appel.
     *
     * @return array<string, mixed>|null
     */
    private function lireSiPubliee(): ?array
    {
        if ($this->publie !== null) {
            return $this->publie === false ? null : $this->publie;
        }

        try {
            return $this->publie = $this->diffusion->lire(SourceAssurances::CODE);
        } catch (ReferentielException) {
            $this->publie = false;

            return null;
        }
    }
}
