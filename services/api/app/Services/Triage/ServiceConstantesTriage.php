<?php

namespace App\Services\Triage;

use App\Models\MembreFamille;
use App\Models\MesureSante;
use App\Models\ReferentielMesure;
use App\Models\TriageConstante;
use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\ReferentielException;
use App\Services\Referentiel\SourceSeuilsMesure;
use App\Support\RegistreFaitsProtocole;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * P10c-1 — Les constantes cliniques du §5.2, confrontées au référentiel PUBLIÉ (CDC_05 §5.1/§5.2 ;
 * CDC_09 §10 ; ADR-043).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CE QUE CE SERVICE GOUVERNE, ET CE QU'IL NE GOUVERNE PAS — LE POINT DE CONCEPTION
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Il lit `seuils_mesure` pour l'**unité**, les **décimales** et les **bornes de plausibilité**.
 * C'est une question de **qualité de donnée** : *300 °C n'est pas un patient, c'est une faute de
 * frappe*. Aucune décision clinique là-dedans.
 *
 * Il ne lit **jamais** `critique_bas` / `critique_haut` et n'appelle **jamais**
 * {@see ReferentielMesure::statutPour()}. Ces seuils sont gouvernés par les **deux signatures
 * administratives** du §10 ; faire dépendre le niveau d'urgence d'un citoyen de cette valeur la
 * soumettrait à deux signatures là où le **§7 en exige quatre**. C'est l'asymétrie que P10b-3-i a
 * refermée pour l'impact des réponses, et on ne la rouvre pas un cran plus bas.
 *
 * Le seuil qui change un triage est une **règle de protocole** comparant la valeur brute
 * (`SI constante.temperature >= 39.5 ET age < 5 …`), relue et signée par quatre validateurs. C'est
 * le contre-exemple du §1.2 retourné à l'endroit.
 *
 * ═══ DEUX SÉMANTIQUES D'ÉCHEC, DÉLIBÉRÉMENT ═══
 *
 * {@see seuils()} refuse bruyamment (503) : c'est le chemin d'exécution, et un repli laisserait un
 * oubli de publication invisible (décision de L1+L2).
 * {@see typesDisponibles()} ne lève pas : c'est la vérité brute, lue par le contrôle qualité au
 * moment où un humain publie un protocole — le faire échouer en 503 transformerait « ce type n'est
 * pas publié » en panne de serveur. Motif `ServiceNumerosUrgence::estEnVigueur()` (P6.8e), qui ne
 * replie ni ne journalise.
 */
final class ServiceConstantesTriage
{
    /** La précision de `triage_constantes.valeur`, alignée sur `mesures_sante.valeur`. */
    private const DECIMALES_STOCKEES = 2;

    /** Les seuils de la version en vigueur, mémoïsés pour la requête (liaison `scoped`). */
    private ?Collection $seuils = null;

    private ?int $version = null;

    /** Vrai dès qu'une tentative de lecture a eu lieu — `null` mémoïsé compris. */
    private bool $charge = false;

    public function __construct(private readonly DiffusionReferentiel $diffusion) {}

    /**
     * Les seuils publiés, ou refus bruyant.
     *
     * @return Collection<int, ReferentielMesure>
     */
    public function seuils(): Collection
    {
        $this->charger();

        if ($this->seuils === null) {
            // 503 et non 404 : les seuils existent en table, c'est leur MISE EN VIGUEUR qui manque.
            // Même distinction qu'en P10a (symptômes), P10b-1 (niveau) et P10b-3-i (questionnaire).
            abort(503, 'Les seuils nationaux de mesure n\'ont aucune version en vigueur : une '
                .'constante clinique ne peut pas être acceptée tant qu\'une version n\'a pas été '
                .'publiée (CDC_09 §10).');
        }

        return $this->seuils;
    }

    /** La version qui gouverne cette requête — estampillée sur chaque constante écrite (§10). */
    public function version(): int
    {
        $this->seuils();

        return (int) $this->version;
    }

    /**
     * Les types collectables de la version en vigueur — TABLEAU VIDE si rien n'est publié.
     *
     * Ne lève pas : voir l'en-tête. C'est ce que lit le contrôle qualité d'un protocole.
     *
     * @return array<int, string>
     */
    public function typesDisponibles(): array
    {
        $this->charger();

        return $this->seuils === null
            ? []
            : $this->seuils->pluck('type_mesure')->all();
    }

    /**
     * Le référentiel d'un type, ou `null` s'il n'est pas de la version en vigueur.
     */
    public function seuil(string $type): ?ReferentielMesure
    {
        return $this->seuils()->firstWhere('type_mesure', $type);
    }

    /**
     * Ce que l'écran de triage peut proposer de saisir, et ce que le carnet en dit.
     *
     * ═══ TROIS ÉTATS, ET UN SEUL EST UNE AFFIRMATION SUR LE PRÉSENT ═══
     *
     * `proposition` — une mesure du carnet DANS la fenêtre de fraîcheur : le champ est pré-rempli,
     * **avec sa date**, et le patient corrige s'il veut.
     * `contexte` — une mesure HORS fenêtre : montrée (« dernière température connue : 38,2 °C, il y
     * a 3 jours »), **jamais pré-remplie**, et elle n'entre dans aucune règle.
     * ni l'un ni l'autre — rien à dire.
     *
     * C'est la leçon des trois sources de P6.4b (*« vous êtes à X »* = mesure, *« ville choisie »* =
     * déclaration, *« dernière position connue »* = souvenir) : on dit **laquelle des trois** on
     * tient, on ne les confond jamais. Une température prise il y a trois mois n'est pas une
     * température, et la faire entrer dans une règle clinique la présenterait comme le présent.
     *
     * `fraicheur_max_minutes` à NULL veut dire **jamais pré-rempli** : une donnée absente
     * n'autorise pas silencieusement la réutilisation d'une mesure ancienne (motif P6.5a — *un
     * professionnel sans autorisation n'est pas « probablement autorisé »*).
     *
     * @return array<int, array<string, mixed>>
     */
    public function proposables(?MembreFamille $membre): array
    {
        $dernieres = $membre === null ? collect() : $this->dernieresMesures($membre);

        return $this->seuils()->map(function (ReferentielMesure $seuil) use ($dernieres): array {
            $ligne = [
                'type_mesure' => $seuil->type_mesure,
                'libelle' => $seuil->libelle,
                'unite' => $seuil->unite,
                'decimales' => (int) $seuil->decimales,
                'valeur_min' => (float) $seuil->valeur_min,
                'valeur_max' => (float) $seuil->valeur_max,
                'proposition' => null,
                'contexte' => null,
            ];

            /** @var MesureSante|null $mesure */
            $mesure = $dernieres->get($seuil->type_mesure);

            if ($mesure === null) {
                return $ligne;
            }

            $vue = [
                'valeur' => (float) $mesure->valeur,
                'date_mesure' => $mesure->date_mesure?->toIso8601String(),
                'mesure_id' => (int) $mesure->id,
            ];

            $cle = $this->estFraiche($seuil, $mesure) ? 'proposition' : 'contexte';
            $ligne[$cle] = $vue;

            return $ligne;
        })->values()->all();
    }

    /**
     * Confronte les constantes brutes du client à la version PUBLIÉE, et refuse ce qui n'y entre pas.
     *
     * ═══ ON REFUSE, ON N'ÉCRÊTE JAMAIS ═══
     *
     * Ramener 45 à 42 accepterait une saisie fausse **en la corrigeant sans le dire** : le patient
     * croirait avoir saisi 45 et son dossier porterait 42. C'est mot pour mot la décision R7 de
     * P10b-3-i sur les bornes d'échelle du questionnaire.
     *
     * ═══ L'ORIGINE EST DÉCIDÉE PAR LE SERVEUR, JAMAIS DÉCLARÉE PAR LE CLIENT ═══
     *
     * Le client n'envoie qu'un type et une valeur. C'est le serveur qui reconnaît une valeur reprise
     * du carnet, en la comparant à ce qu'il avait lui-même proposé. Laisser le client déclarer
     * « celle-ci vient du carnet » rejouerait la faute refermée quatre fois : `source` d'une
     * contribution (P7-C), `obligatoire` d'une vaccination (P6.8b), `provenance` d'une couverture
     * (P6.8d), `medecin_nom` d'une ordonnance (P6.5a).
     *
     * @param  array<int, array<string, mixed>>  $brutes  [{type_mesure, valeur}, …]
     * @param  MembreFamille|null  $membre  Pour reconnaître une valeur reprise du carnet.
     * @return array<string, array<string, mixed>> Indexées par type.
     *
     * @throws ValidationException
     */
    public function normaliser(array $brutes, ?MembreFamille $membre = null): array
    {
        if ($brutes === []) {
            return [];
        }

        $proposees = $this->propositionsIndexees($membre);
        $normalisees = [];
        $erreurs = [];

        foreach ($brutes as $index => $brute) {
            $type = (string) ($brute['type_mesure'] ?? '');
            $valeur = $brute['valeur'] ?? null;

            // Une constante vide n'est pas une erreur : tout est facultatif au triage depuis le
            // Module 1, et cet incrément ne change pas ce contrat.
            if ($valeur === null || $valeur === '') {
                continue;
            }

            $seuil = $this->seuils()->firstWhere('type_mesure', $type);

            if ($seuil === null) {
                $erreurs["constantes.{$index}.type_mesure"][] = "La constante « {$type} » ne fait "
                    .'pas partie de la version en vigueur du référentiel des seuils de mesure. '
                    .'Constantes de cette version : '
                    .implode(', ', $this->typesDisponibles()).'.';

                continue;
            }

            if (isset($normalisees[$type])) {
                $erreurs["constantes.{$index}.type_mesure"][] = "« {$seuil->libelle} » est fournie "
                    .'deux fois : le triage ne saurait pas laquelle des deux valeurs retenir.';

                continue;
            }

            $refus = $this->refusDeValeur($seuil, $valeur);

            if ($refus !== null) {
                $erreurs["constantes.{$index}.valeur"][] = $refus;

                continue;
            }

            $normalisees[$type] = $this->composer($seuil, (float) $valeur, $proposees[$type] ?? null);
        }

        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        return $normalisees;
    }

    /**
     * Les faits remis au moteur de protocoles, sous le nom que les conditions leur donnent.
     *
     * ═══ SEULE LA VALEUR BRUTE SORT D'ICI ═══
     *
     * Aucun `constante.temperature_statut`. Voir l'en-tête : classer une valeur en `critique` est
     * une décision gouvernée par deux signatures, et un protocole qui s'en servirait déciderait de
     * l'urgence sans être passé par les quatre validations du §7.
     *
     * @param  array<string, array<string, mixed>>  $normalisees
     * @return array<string, float>
     */
    public function faits(array $normalisees): array
    {
        $faits = [];

        foreach ($normalisees as $type => $ligne) {
            $faits[RegistreFaitsProtocole::PREFIXE_CONSTANTE.$type] = (float) $ligne['valeur'];
        }

        return $faits;
    }

    /**
     * Les lignes à écrire pour un triage.
     *
     * @param  array<string, array<string, mixed>>  $normalisees
     * @return array<int, array<string, mixed>>
     */
    public function lignes(array $normalisees): array
    {
        $lignes = [];

        foreach ($normalisees as $type => $ligne) {
            $lignes[] = [
                'type_mesure' => $type,
                'valeur' => $ligne['valeur'],
                // L'unité est FIGÉE ici. La résoudre à la lecture ferait qu'une correction du
                // référentiel changerait rétroactivement le sens d'une valeur enregistrée.
                'unite' => $ligne['unite'],
                'origine' => $ligne['origine'],
                'mesure_id' => $ligne['mesure_id'],
                'referentiel_version' => $this->version(),
            ];
        }

        return $lignes;
    }

    /**
     * La valeur est-elle recevable ? Renvoie le motif de refus, ou `null`.
     */
    private function refusDeValeur(ReferentielMesure $seuil, mixed $valeur): ?string
    {
        if (! is_numeric($valeur)) {
            return "« {$seuil->libelle} » attend un nombre.";
        }

        $nombre = (float) $valeur;
        $min = (float) $seuil->valeur_min;
        $max = (float) $seuil->valeur_max;

        if ($nombre < $min || $nombre > $max) {
            return "« {$seuil->libelle} » : la valeur doit être comprise entre {$min} et {$max} "
                ."{$seuil->unite} (reçu {$nombre}). La valeur n'est pas ramenée dans la plage : "
                .'votre dossier porterait alors une mesure que vous n\'avez pas relevée.';
        }

        // ═══ UNE PRÉCISION QUE PERSONNE NE SAIT PORTER SERAIT ARRONDIE EN SILENCE ═══
        //
        // Deux bornes de nature différente se rencontrent ici, et **la plus stricte l'emporte** :
        //
        //   - `decimales` est une donnée du référentiel PUBLIÉ. C'est une règle gouvernée, relue et
        //     signée : la refuser d'appliquer reviendrait à publier une contrainte décorative — le
        //     défaut exact que cet incrément referme pour `valeur_min`/`valeur_max` (constat X4 de
        //     P10b-3-i : « le référentiel publiait des bornes et le serveur ne les regardait pas »).
        //   - `decimal(8,2)` est ce que la COLONNE sait porter. MySQL tronque au-delà avec un
        //     simple avertissement : le patient saisirait 39,555 et son dossier porterait 39,56
        //     sans que rien ne le dise. Défense en profondeur : si un référentiel publiait une
        //     précision que le stockage ne peut pas tenir, c'est le stockage qui a le dernier mot,
        //     et le message nomme alors la borne réellement appliquée plutôt que la promesse.
        //
        // Dans les deux cas on REFUSE plutôt que d'altérer (E5) : *le patient croirait avoir saisi
        // 39,555 et son dossier porterait 39,56*.
        $autorisees = min((int) $seuil->decimales, self::DECIMALES_STOCKEES);

        if (round($nombre, $autorisees) !== $nombre) {
            $mot = $autorisees === 1 ? 'décimale' : 'décimales';

            return "« {$seuil->libelle} » : au plus {$autorisees} {$mot} "
                ."(reçu {$nombre}). Une décimale de plus serait arrondie sans que vous le sachiez.";
        }

        return null;
    }

    /**
     * Compose la ligne normalisée, en décidant de l'ORIGINE côté serveur.
     *
     * Une valeur strictement égale à celle que le serveur a proposée depuis le carnet est réputée
     * **reprise** : c'est ce que signifie « le champ était pré-rempli et le patient l'a validé ».
     *
     * Un patient qui saisirait de lui-même exactement la valeur proposée serait compté comme
     * l'ayant reprise. La nuance est sans conséquence : la valeur EST celle du carnet, et elle lui
     * a été montrée avec sa date.
     *
     * @param  array<string, mixed>|null  $proposee
     * @return array<string, mixed>
     */
    private function composer(ReferentielMesure $seuil, float $valeur, ?array $proposee): array
    {
        $reprise = $proposee !== null && abs(((float) $proposee['valeur']) - $valeur) < PHP_FLOAT_EPSILON;

        return [
            'valeur' => $valeur,
            'unite' => (string) $seuil->unite,
            'libelle' => (string) $seuil->libelle,
            'origine' => $reprise ? TriageConstante::ORIGINE_CARNET : TriageConstante::ORIGINE_SAISIE,
            'mesure_id' => $reprise ? (int) $proposee['mesure_id'] : null,
        ];
    }

    /**
     * Les propositions du carnet, indexées par type — celles-là seules peuvent valoir « reprise ».
     *
     * @return array<string, array<string, mixed>>
     */
    private function propositionsIndexees(?MembreFamille $membre): array
    {
        if ($membre === null) {
            return [];
        }

        $indexees = [];

        foreach ($this->proposables($membre) as $ligne) {
            if ($ligne['proposition'] !== null) {
                $indexees[$ligne['type_mesure']] = $ligne['proposition'];
            }
        }

        return $indexees;
    }

    /**
     * La dernière mesure du carnet, par type.
     *
     * @return Collection<string, MesureSante>
     */
    private function dernieresMesures(MembreFamille $membre): Collection
    {
        return $membre->mesuresSante()
            ->whereIn('type_mesure', $this->typesDisponibles())
            ->orderByDesc('date_mesure')
            ->get()
            ->keyBy('type_mesure');
    }

    /**
     * La mesure est-elle dans la fenêtre de fraîcheur PUBLIÉE pour son type ?
     *
     * ═══ CE QUE LA CAMPAGNE DE MUTATION A APPRIS SUR CETTE MÉTHODE ═══
     *
     * La garde `$fenetre === null` a d'abord **survécu** à sa mutation, et le vecteur qui la visait
     * passait pour une autre raison : sans elle, `(int) null` vaut 0, la fenêtre devient « zéro
     * minute », et une mesure passée est de toute façon écartée. Le vecteur prouvait l'arithmétique,
     * pas l'intention — septième instance de cette famille dans le projet.
     *
     * Le cas qui les distingue est une mesure **datée dans le futur** : horloge d'appareil en
     * avance, ou date saisie à la main. Avec une fenêtre nulle, l'arithmétique la déclarerait
     * fraîche ; la garde, elle, dit **jamais**. C'est ce cas que le vecteur exerce désormais.
     *
     * *Faire reposer « ne jamais proposer » sur le fait que `(int) null` vaut 0 serait un accident,
     * pas une décision.*
     */
    private function estFraiche(ReferentielMesure $seuil, MesureSante $mesure): bool
    {
        $fenetre = $seuil->fraicheur_max_minutes;

        // NULL = jamais pré-rempli, quoi que dise le calcul ci-dessous. Le sens sûr : une donnée
        // absente n'autorise pas silencieusement la réutilisation d'une mesure (motif P6.5a).
        if ($fenetre === null || $mesure->date_mesure === null) {
            return false;
        }

        return $mesure->date_mesure->greaterThanOrEqualTo(now()->subMinutes((int) $fenetre));
    }

    /**
     * Lit la version en vigueur une seule fois par requête. `null` = aucune n'est publiée.
     */
    private function charger(): void
    {
        if ($this->charge) {
            return;
        }

        $this->charge = true;

        try {
            $diffuse = $this->diffusion->lire(SourceSeuilsMesure::CODE);
        } catch (ReferentielException) {
            return;
        }

        $this->version = (int) $diffuse['version'];

        // Hydratés sans exister en base — motif L1 (`MesureSanteService::charger()`). Ces instances
        // n'ont pas d'`id` et ne doivent jamais être sauvegardées.
        $this->seuils = collect($diffuse['contenu'])
            ->map(fn (array $ligne): ReferentielMesure => new ReferentielMesure($ligne))
            ->sortBy('ordre')
            ->values();
    }
}
