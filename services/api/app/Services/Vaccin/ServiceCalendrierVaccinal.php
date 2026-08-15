<?php

namespace App\Services\Vaccin;

use App\Models\MembreFamille;
use App\Models\Vaccination;
use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\ReferentielException;
use App\Services\Referentiel\SourceVaccins;
use App\Support\ReglesCalendrierVaccinal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * P6.8b — « Qu'est-ce qui est dû, pour cette personne-là, aujourd'hui ? » (CDC_09 §8).
 *
 * ═══ IL LIT LA VERSION PUBLIÉE, PAS LA TABLE — ET C'EST UNE DÉCISION ═══
 *
 * Le plan G1 annonçait comme limite que « les consommateurs lisent les tables en direct » (limites
 * L1/L2 d'ADR-025). Ce consommateur-ci est **neuf** : aucun module validé G5 ne serait touché en
 * faisant autrement. Le faire lire la table aurait donc livré, dès le premier jour, exactement le
 * défaut qu'un incrément entier a dû refermer pour `seuils_mesure` — un référentiel gouverné dont
 * les seuls lecteurs ignorent la gouvernance. *Corriger un âge dû par un `UPDATE` direct n'a
 * désormais aucun effet avant publication : c'est le but (§1.2.4).*
 *
 * **ÉCHEC BRUYANT, JAMAIS DE REPLI SUR LA TABLE** (décision L1) : un repli laisserait un oubli de
 * publication passer inaperçu, tout fonctionnerait, et personne ne saurait que la garantie est
 * inactive. 503 et non 404 — le membre existe, c'est la mise en vigueur qui manque.
 *
 * **UNE SEULE VERSION PAR REQUÊTE** (mémoïsation, motif L2) : les échéances d'un même enfant sont
 * jugées par le même calendrier, même si une publication survient au milieu du traitement.
 *
 * ═══ CE QU'IL NE FAIT PAS ═══
 *
 * Il n'écrit rien : ni dans `vaccinations`, ni dans `rappels`. Et il ne conclut jamais rien de
 * médical — il dit ce que le calendrier prévoit, pas ce qu'il faut faire d'un patient
 * ({@see ReglesCalendrierVaccinal}).
 */
final class ServiceCalendrierVaccinal
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

    /** Une version du calendrier est-elle en vigueur ? Ne lève rien. */
    public function estEnVigueur(): bool
    {
        return $this->lireSiPubliee() !== null;
    }

    /**
     * Le vaccin en vigueur portant ce code national, tel que publié, ou `null`.
     *
     * NE LÈVE PAS de 503, à la différence de {@see pour()} : ces deux accesseurs servent au chemin
     * d'ÉCRITURE, où l'absence de calendrier en vigueur doit devenir un message attribué au champ
     * que l'utilisateur a rempli, et non une panne de service au milieu d'un enregistrement.
     *
     * @return array<string, mixed>|null
     */
    public function vaccinPublie(string $code): ?array
    {
        foreach ($this->lireSiPubliee()['contenu'] ?? [] as $vaccin) {
            if (($vaccin['code'] ?? null) === $code) {
                return $vaccin;
            }
        }

        return null;
    }

    /**
     * L'échéance publiée d'une dose, ou `null`.
     *
     * @return array<string, mixed>|null
     */
    public function echeancePubliee(string $code, int $dose): ?array
    {
        foreach ($this->vaccinPublie($code)['echeances'] ?? [] as $echeance) {
            if ((int) ($echeance['numero_dose'] ?? 0) === $dose) {
                return $echeance;
            }
        }

        return null;
    }

    /**
     * Le calendrier vaccinal d'un membre, à une date.
     *
     * `$aujourdhui` est un paramètre et non `now()` : c'est ce qui rend le module vérifiable sans
     * toucher à l'horloge du système (motif {@see ReglesCalendrierVaccinal}).
     *
     * @return array<string, mixed>
     */
    public function pour(MembreFamille $membre, ?CarbonInterface $aujourdhui = null): array
    {
        $aujourdhui = CarbonImmutable::parse($aujourdhui ?? CarbonImmutable::now());
        $publie     = $this->charger();
        $age        = ReglesCalendrierVaccinal::ageEnJours($membre->date_naissance, $aujourdhui);

        // ═══ L'ÂGE INCONNU SE DIT, IL NE SE SUPPOSE PAS ═══
        //
        // Sans date de naissance, aucune échéance n'est calculable. Supposer un âge produirait un
        // calendrier entier de fausses échéances pour quelqu'un dont on ne sait rien — et un parent
        // verrait « en retard » sur des doses qui ne le concernent peut-être pas. Motif des trois
        // silences de P7-D2 : on annonce ce qui manque plutôt que de combler.
        //
        // DIT SANS ENJOLIVER : `membres_famille.date_naissance` est NOT NULL depuis le Module 2, si
        // bien que cette branche n'est PAS atteignable par ce chemin aujourd'hui. Elle n'est pas
        // décorative pour autant — `ageEnJours()` accepte `null` par signature, et un jour où un
        // dossier viendrait d'un import ou d'un autre porteur, la réponse doit être « je ne sais
        // pas » et non un retard imaginaire. Un vecteur dédié dit exactement cela.
        if ($age === null) {
            return [
                'version'          => $publie['version'],
                'age_jours'        => null,
                'incertitude'      => 'La date de naissance de ce membre n\'est pas renseignée : '
                    .'le calendrier vaccinal ne peut pas être établi.',
                'echeances'        => [],
                'resume'           => $this->resume([]),
                'demonstration'    => 0,
                'avertissement'    => $this->avertissementDemonstration(0),
            ];
        }

        $faites    = $this->dosesFaites($membre);
        $naissance = CarbonImmutable::parse($membre->date_naissance)->startOfDay();
        $echeances = [];
        $demo      = 0;

        foreach ($publie['contenu'] as $vaccin) {
            if (! ($vaccin['actif'] ?? false)) {
                continue;
            }

            foreach ($vaccin['echeances'] ?? [] as $e) {
                $dose  = (int) ($e['numero_dose'] ?? 1);
                $cle   = $vaccin['code'].'#'.$dose;
                $faite = isset($faites[$cle]);

                if (($e['source'] ?? null) === 'demonstration') {
                    $demo++;
                }

                $echeances[] = [
                    'vaccin_code'        => $vaccin['code'],
                    'vaccin_libelle'     => $vaccin['libelle'],
                    'abreviation'        => $vaccin['abreviation'] ?? null,
                    'maladies_evitees'   => $vaccin['maladies_evitees'] ?? null,
                    'statut_marche'      => $vaccin['statut_marche'] ?? null,
                    'numero_dose'        => $dose,
                    'nb_doses'           => (int) ($vaccin['nb_doses'] ?? 1),
                    'libelle_echeance'   => $e['libelle_echeance'] ?? null,
                    'obligatoire'        => (bool) ($e['obligatoire'] ?? false),
                    'age_jours_du'       => (int) ($e['age_jours_du'] ?? 0),
                    'date_prevue'        => $naissance->addDays((int) ($e['age_jours_du'] ?? 0))->toDateString(),
                    'statut'             => ReglesCalendrierVaccinal::statutEcheance(
                        $faite,
                        $age,
                        (int) ($e['age_jours_du'] ?? 0),
                        (int) ($e['tolerance_jours'] ?? 0),
                        isset($e['age_jours_limite']) ? (int) $e['age_jours_limite'] : null,
                    ),
                    // L'identifiant de la ligne de carnet qui l'honore : l'écran peut y renvoyer,
                    // et on ne recopie pas le contenu de la ligne ici (deux copies = deux vérités).
                    'vaccination_id'     => $faites[$cle] ?? null,
                    'de_demonstration'   => ($e['source'] ?? null) === 'demonstration',
                ];
            }
        }

        // Tri par date d'exigibilité : c'est l'ordre dans lequel un parent lit son carnet, et le
        // seul qui rende « ce qui vient ensuite » immédiatement visible.
        usort($echeances, static fn (array $a, array $b) => [$a['age_jours_du'], $a['vaccin_libelle'], $a['numero_dose']]
            <=> [$b['age_jours_du'], $b['vaccin_libelle'], $b['numero_dose']]);

        return [
            'version'       => $publie['version'],
            'age_jours'     => $age,
            'echeances'     => $echeances,
            'resume'        => $this->resume($echeances),
            'demonstration' => $demo,
            'avertissement' => $this->avertissementDemonstration($demo),
        ];
    }

    /**
     * Les doses déjà administrées de ce membre, indexées « code#dose ».
     *
     * Rapprochement par le CODE NATIONAL FIGÉ sur la ligne, jamais par le nom du vaccin : « BCG »,
     * « bcg » et « B.C.G. » sont trois chaînes différentes, et c'est précisément l'ambiguïté que le
     * référentiel supprime. Une ligne non rattachée ne peut donc pas honorer une échéance — c'est
     * assumé et annoncé : le rattachement est ce qui fait la différence entre un texte et un fait
     * vérifiable.
     *
     * @return array<string, int>
     */
    private function dosesFaites(MembreFamille $membre): array
    {
        return Vaccination::query()
            ->where('membre_id', $membre->id)
            ->whereNotNull('vaccin_code')
            ->whereNotNull('numero_dose')
            ->whereNotNull('date_administration')
            ->get(['id', 'vaccin_code', 'numero_dose'])
            ->mapWithKeys(fn (Vaccination $v): array => [
                $v->vaccin_code.'#'.$v->numero_dose => $v->id,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $echeances
     * @return array<string, int>
     */
    private function resume(array $echeances): array
    {
        $resume = [
            ReglesCalendrierVaccinal::FAIT       => 0,
            ReglesCalendrierVaccinal::A_FAIRE    => 0,
            ReglesCalendrierVaccinal::EN_RETARD  => 0,
            ReglesCalendrierVaccinal::A_VENIR    => 0,
            ReglesCalendrierVaccinal::HORS_DELAI => 0,
        ];

        foreach ($echeances as $e) {
            $resume[$e['statut']]++;
        }

        return $resume;
    }

    /**
     * Le témoin visible du remplacement (motif P6.7a).
     *
     * Je n'ai vu ni l'arrêté du PEV ivoirien, ni le calendrier officiel du Ministère. Tant que ce
     * calendrier n'est pas chargé, l'écran doit le dire — une donnée de démonstration qui ne se
     * signale pas finit par être prise pour une donnée de référence.
     */
    private function avertissementDemonstration(int $nombre): ?string
    {
        if ($nombre === 0) {
            return null;
        }

        return sprintf(
            '%d échéance%s de ce calendrier %s issue%s d\'un jeu de DÉMONSTRATION et n\'%s pas été '
            .'validée%s par une autorité sanitaire. Ce calendrier ne remplace pas l\'avis d\'un '
            .'professionnel de santé.',
            $nombre,
            $nombre > 1 ? 's' : '',
            $nombre > 1 ? 'sont' : 'est',
            $nombre > 1 ? 's' : '',
            $nombre > 1 ? 'ont' : 'a',
            $nombre > 1 ? 's' : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function charger(): array
    {
        $publie = $this->lireSiPubliee();

        if ($publie === null) {
            abort(503, 'Le calendrier vaccinal national n\'a aucune version en vigueur : aucune '
                .'échéance ne peut être établie tant qu\'une version n\'a pas été publiée '
                .'(CDC_09 §10).');
        }

        return $publie;
    }

    /**
     * L'instantané en vigueur, ou `null` s'il n'y en a aucun.
     *
     * MÉMOÏSÉ, et `false` distingue « pas encore cherché » de « cherché, rien trouvé » : sans cette
     * distinction, une absence de publication ferait rejouer la lecture à chaque appel dans la même
     * requête, et deux appels pourraient tomber de part et d'autre d'une publication.
     *
     * @return array<string, mixed>|null
     */
    private function lireSiPubliee(): ?array
    {
        if ($this->publie !== null) {
            return $this->publie === false ? null : $this->publie;
        }

        try {
            return $this->publie = $this->diffusion->lire(SourceVaccins::CODE);
        } catch (ReferentielException) {
            $this->publie = false;

            return null;
        }
    }
}
