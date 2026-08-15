<?php

namespace App\Services\Maladie;

use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\ReferentielException;
use App\Services\Referentiel\SourceMaladies;

/**
 * P6.8c — Ce que le référentiel des maladies dit, dans sa version en vigueur (CDC_09 §8/§10).
 *
 * ═══ IL LIT LA VERSION PUBLIÉE, PAS LA TABLE ═══
 *
 * Motif de `ServiceCalendrierVaccinal` (P6.8b), et pour la même raison : ce consommateur est NEUF,
 * aucun module validé G5 ne serait touché en faisant autrement. Le faire lire la table livrerait dès
 * le premier jour exactement le défaut qu'un incrément entier a dû refermer pour `seuils_mesure` —
 * un référentiel gouverné dont les seuls lecteurs ignorent la gouvernance (ADR-025 §5/§6).
 *
 * **ÉCHEC BRUYANT, JAMAIS DE REPLI SUR LA TABLE** : un repli laisserait un oubli de publication
 * passer inaperçu — tout fonctionnerait, et personne ne saurait la garantie inactive. 503, parce que
 * ce n'est pas la ressource qui manque mais sa mise en vigueur.
 *
 * ═══ SAUF SUR LE CHEMIN D'ÉCRITURE ═══
 *
 * {@see maladiePubliee()} et {@see listePubliee()} ne lèvent rien : l'absence de version en vigueur
 * doit devenir un message attribué au champ que l'utilisateur a rempli, ou un `<select>` vide qui
 * s'explique — jamais une panne de service au milieu d'un enregistrement d'alerte sanitaire.
 *
 * ═══ UNE SEULE VERSION PAR REQUÊTE ═══
 *
 * Mémoïsation (motif L2) : deux lectures dans la même requête ne peuvent pas tomber de part et
 * d'autre d'une publication.
 */
final class ServiceMaladies
{
    /** @var array<string, mixed>|false|null Instantané en vigueur ; `false` = cherché, aucun. */
    private array|false|null $publie = null;

    public function __construct(private readonly DiffusionReferentiel $diffusion) {}

    /** Le numéro de la version en vigueur — cité dans chaque réponse (§10). */
    public function version(): int
    {
        return (int) $this->charger()['version'];
    }

    /**
     * Le contenu de la version en vigueur — lève 503 s'il n'y en a aucune.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contenuPublie(): array
    {
        return $this->charger()['contenu'];
    }

    /** Une version est-elle en vigueur ? Ne lève rien. */
    public function estEnVigueur(): bool
    {
        return $this->lireSiPubliee() !== null;
    }

    /**
     * La maladie en vigueur portant ce code national, telle que publiée, ou `null`. NE LÈVE RIEN.
     *
     * @return array<string, mixed>|null
     */
    public function maladiePubliee(string $code): ?array
    {
        foreach ($this->lireSiPubliee()['contenu'] ?? [] as $maladie) {
            if (($maladie['code'] ?? null) === $code) {
                return $maladie;
            }
        }

        return null;
    }

    /**
     * La liste ACTIVE de la version en vigueur, prête pour un `<select>` ou une réponse d'API.
     * NE LÈVE RIEN — un tableau vide veut dire « aucune version publiée », et l'écran le dit.
     *
     * ORDRE : ce que le pays surveille en premier. Un agent qui publie une alerte cherche d'abord
     * parmi les maladies à surveillance prioritaire — les enfouir dans un ordre alphabétique de
     * plusieurs centaines d'entrées ferait perdre du temps au moment où il en manque.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listePubliee(?string $pays = null): array
    {
        $pays  = strtoupper($pays ?? config('referentiels.pays_defaut', 'CI'));
        $liste = [];

        foreach ($this->lireSiPubliee()['contenu'] ?? [] as $maladie) {
            if (! ($maladie['actif'] ?? false)) {
                continue;
            }

            $surveillance = $this->surveillancePour($maladie, $pays);

            $liste[] = [
                'code'                     => $maladie['code'],
                'libelle'                  => $maladie['libelle'],
                'code_cim10'               => $maladie['code_cim10'] ?? null,
                'code_cim11'               => $maladie['code_cim11'] ?? null,
                'description'              => $maladie['description'] ?? null,
                'de_demonstration'         => ($maladie['source'] ?? null) === 'demonstration',
                'surveillance_prioritaire' => (bool) ($surveillance['surveillance_prioritaire'] ?? false),
                'declaration_obligatoire'  => (bool) ($surveillance['declaration_obligatoire'] ?? false),
                'libelles'                 => $maladie['libelles'] ?? [],
            ];
        }

        usort($liste, static fn (array $a, array $b) => [! $a['surveillance_prioritaire'], $a['libelle']]
            <=> [! $b['surveillance_prioritaire'], $b['libelle']]);

        return $liste;
    }

    /**
     * Recherche par libellé officiel OU par libellé alternatif — c'est le service que rend le
     * multilingue (§8) : « palu » retrouve « Paludisme », et un libellé en langue nationale aussi.
     *
     * SANS ACCENT NI CASSE, et ce n'est PAS un rapprochement approximatif : on compare des chaînes
     * normalisées, on ne mesure aucune distance. *Deviner une maladie à partir d'un texte serait un
     * diagnostic posé par une machine* (CDC_00 §4) ; retrouver une entrée que l'utilisateur est en
     * train de nommer ne l'est pas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rechercher(string $terme, ?string $pays = null, int $limite = 20): array
    {
        $terme = self::normaliser($terme);

        if ($terme === '') {
            return array_slice($this->listePubliee($pays), 0, $limite);
        }

        $trouvees = [];

        foreach ($this->listePubliee($pays) as $maladie) {
            $candidats = [$maladie['libelle']];

            foreach ($maladie['libelles'] as $alternatif) {
                $candidats[] = $alternatif['libelle'] ?? '';
            }

            foreach ($candidats as $candidat) {
                if (str_contains(self::normaliser((string) $candidat), $terme)) {
                    $trouvees[] = $maladie;

                    break;
                }
            }
        }

        return array_slice($trouvees, 0, $limite);
    }

    /**
     * Le statut de surveillance d'une maladie publiée dans un pays, ou `null`.
     *
     * @param  array<string, mixed>  $maladie
     * @return array<string, mixed>|null
     */
    public function surveillancePour(array $maladie, ?string $pays = null): ?array
    {
        $pays = strtoupper($pays ?? config('referentiels.pays_defaut', 'CI'));

        foreach ($maladie['surveillance'] ?? [] as $s) {
            if (strtoupper((string) ($s['pays_code'] ?? '')) === $pays) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Le témoin visible du remplacement (motif P6.7a / P6.8b).
     *
     * Aucun code CIM n'a été chargé dans ce projet : CIM-10 et CIM-11 sont des publications de
     * l'OMS. Tant que ce n'est pas fait, l'écran doit le dire — *une donnée de démonstration qui ne
     * se signale pas finit par être prise pour une donnée de référence*.
     */
    public function avertissementDemonstration(?string $pays = null): ?string
    {
        $liste = $this->listePubliee($pays);
        $demo  = count(array_filter($liste, static fn (array $m): bool => $m['de_demonstration']));
        $sansCim = count(array_filter(
            $liste,
            static fn (array $m): bool => ($m['code_cim10'] ?? null) === null && ($m['code_cim11'] ?? null) === null,
        ));

        if ($demo === 0 && $sansCim === 0) {
            return null;
        }

        return sprintf(
            '%d entrée%s de ce référentiel %s issue%s d\'un jeu de DÉMONSTRATION et n\'%s pas été '
            .'validée%s par une autorité sanitaire ; %d n\'%s aucun code CIM. Ce référentiel ne '
            .'remplace pas l\'avis d\'un professionnel de santé.',
            $demo,
            $demo > 1 ? 's' : '',
            $demo > 1 ? 'sont' : 'est',
            $demo > 1 ? 's' : '',
            $demo > 1 ? 'ont' : 'a',
            $demo > 1 ? 's' : '',
            $sansCim,
            $sansCim > 1 ? 'ont' : 'a',
        );
    }

    /** Minuscules sans accent : « Fièvre typhoïde » et « fievre typhoide » désignent la même chose. */
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
            abort(503, 'Le référentiel national des maladies n\'a aucune version en vigueur : '
                .'aucune maladie ne peut être servie tant qu\'une version n\'a pas été publiée '
                .'(CDC_09 §10).');
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
            return $this->publie = $this->diffusion->lire(SourceMaladies::CODE);
        } catch (ReferentielException) {
            $this->publie = false;

            return null;
        }
    }
}
