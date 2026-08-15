<?php

namespace App\Services\Referentiel;

use App\Models\Vaccin;

/**
 * Référentiel national des vaccins et calendrier vaccinal (CDC_09 §8), sous gouvernance §10.
 *
 * ═══ LA PROJECTION PREND LA LIGNE ENTIÈRE — question reposée, pas recopiée ═══
 *
 * P6.4a excluait la note d'avis et les horaires parce qu'ils sont RECALCULÉS à chaque avis déposé.
 * La question est reposée ici, comme en P6.6a et P6.8a, et la réponse est la même : **rien n'écrit
 * automatiquement dans `vaccins` ni dans `calendrier_vaccinal`**. Les vaccinations réelles vivent
 * dans `vaccinations`, une autre table, celle du carnet — inscrire une dose au carnet d'un enfant ne
 * touche donc pas le référentiel, et un vecteur en miroir le prouve.
 *
 * ═══ UN SEUL INSTANTANÉ POUR LES DEUX TABLES ═══
 *
 * Les publier séparément laisserait une échéance désigner un vaccin absent de la version en
 * vigueur, donc une référence irrésoluble — motif exact des interactions de P6.6a et des strates de
 * P6.7a. Les échéances sont portées **par code national**, jamais par identifiant technique : un
 * instantané doit rester lisible quand les identifiants d'une base ne sont plus ceux d'une autre.
 *
 * ═══ CE QUE LA GOUVERNANCE PROTÈGE ICI ═══
 *
 * Un calendrier vaccinal est une décision de santé publique. Avancer une échéance de six à quatre
 * semaines, rendre une dose obligatoire, ouvrir une fenêtre de rattrapage : ce sont des actes
 * d'autorité, pas des corrections de saisie. Le quatre-yeux du §10 s'applique pour la même raison
 * qu'aux seuils de mesure et aux valeurs de référence biologiques.
 */
final class SourceVaccins implements SourceReferentiel
{
    /** Les provenances admises pour une échéance — miroir de l'ENUM `calendrier_vaccinal.source`. */
    private const SOURCES = ['demonstration', 'autorite_nationale', 'oms', 'societe_savante', 'publication'];

    public const CODE = 'vaccins';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Vaccins disponibles et calendrier vaccinal national';
    }

    /**
     * §10 « chaque référentiel a un responsable désigné ». Le ministère — pour la raison qui a fait
     * naître la permission `vaccin.referentiel` : un centre de vaccination qui pourrait modifier le
     * calendrier serait juge et partie sur ce qu'il administre.
     */
    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        return Vaccin::query()
            ->with('echeances')
            // Ordre stable et TOTAL : `code` est unique par pays, `pays_code` le complète pour que
            // deux pays ne s'entrelacent pas au gré des identifiants techniques (précédent P6.4a).
            ->orderBy('pays_code')
            ->orderBy('code')
            ->get()
            ->map(fn (Vaccin $v): array => [
                'code'                => $v->code,
                'pays_code'           => $v->pays_code,
                'libelle'             => $v->libelle,
                'abreviation'         => $v->abreviation,
                'maladies_evitees'    => $v->maladies_evitees,
                'voie_administration' => $v->voie_administration,
                'nb_doses'            => $v->nb_doses,
                'statut_marche'       => $v->statut_marche,
                'description'         => $v->description,
                'actif'               => (bool) $v->actif,
                'echeances'           => $v->echeances
                    ->sortBy('numero_dose')
                    ->map(fn ($e): array => [
                        'numero_dose'      => $e->numero_dose,
                        'age_jours_du'     => $e->age_jours_du,
                        'tolerance_jours'  => $e->tolerance_jours,
                        'age_jours_limite' => $e->age_jours_limite,
                        'obligatoire'      => (bool) $e->obligatoire,
                        'libelle_echeance' => $e->libelle_echeance,
                        'source'           => $e->source,
                        'source_detail'    => $e->source_detail,
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * Contrôles qualité §10 — bloquants à la publication.
     *
     * Ce qu'ils garantissent : **un calendrier publié ne peut pas contenir une échéance sans
     * provenance, une dose manquante au milieu d'un schéma, ni une fenêtre de rattrapage qui se
     * ferme avant de s'ouvrir.**
     */
    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le référentiel est vide : aucun vaccin à publier.'];
        }

        $codesVus = [];
        $actifs   = 0;

        foreach ($contenu as $ligne) {
            $code      = (string) ($ligne['code'] ?? '');
            $pays      = $ligne['pays_code'] ?? '??';
            $etiquette = $code !== '' ? $code : '« '.($ligne['libelle'] ?? 'sans libellé').' »';

            if ($code === '') {
                $erreurs[] = "{$etiquette} : code national absent — la commande "
                    .'`masante:vaccins:backfill` en attribue un.';
            } else {
                // LE PAYS FAIT PARTIE DE LA CLÉ. Sans lui, ce contrôle serait plus strict que
                // l'index `uq_vaccin_code_pays` et le référentiel deviendrait impubliable dès le
                // second pays — c'est exactement le défaut que le G2 de P6.5a a attrapé.
                $cle = $pays.'-'.$code;

                if (isset($codesVus[$cle])) {
                    $erreurs[] = "Doublon : le code « {$code} » apparaît plusieurs fois pour {$pays}.";
                }
                $codesVus[$cle] = true;
            }

            if (trim((string) ($ligne['libelle'] ?? '')) === '') {
                $erreurs[] = "{$etiquette} : libellé absent — c'est lui que le parent lit.";
            }

            if ($ligne['actif'] ?? false) {
                $actifs++;
            }

            $erreurs = array_merge($erreurs, $this->controlerLeCalendrier($ligne, $etiquette));
        }

        if ($actifs === 0) {
            $erreurs[] = 'Aucun vaccin actif : le référentiel publié ne permettrait de rattacher '
                .'aucune ligne de carnet.';
        }

        return $erreurs;
    }

    /**
     * Contrôles du schéma vaccinal d'un vaccin.
     *
     * @param  array<string, mixed>  $ligne
     * @return array<int, string>
     */
    private function controlerLeCalendrier(array $ligne, string $etiquette): array
    {
        $erreurs   = [];
        $echeances = is_array($ligne['echeances'] ?? null) ? $ligne['echeances'] : [];
        $nbDoses   = (int) ($ligne['nb_doses'] ?? 0);

        // ── Le schéma annoncé et le calendrier saisi doivent coïncider ───────────────────────────
        //
        // C'est la raison d'être de la colonne `nb_doses`, redondante par construction. Sans elle,
        // on ne pourrait pas distinguer « ce vaccin a trois doses » de « on n'en a saisi que trois
        // sur quatre » — donc pas dire qu'un calendrier est INCOMPLET. Un parent verrait alors un
        // schéma tronqué présenté comme complet.
        if (count($echeances) !== $nbDoses) {
            $erreurs[] = sprintf(
                '%s : le schéma annonce %d dose%s mais le calendrier en porte %d.',
                $etiquette,
                $nbDoses,
                $nbDoses > 1 ? 's' : '',
                count($echeances),
            );
        }

        $dosesVues = [];

        foreach ($echeances as $e) {
            $dose = (int) ($e['numero_dose'] ?? 0);

            if (isset($dosesVues[$dose])) {
                $erreurs[] = "{$etiquette} : la dose n°{$dose} est décrite plusieurs fois.";
            }
            $dosesVues[$dose] = true;

            // ── La provenance, garde centrale du module ─────────────────────────────────────────
            //
            // Motif repris de P6.7a : une échéance vaccinale sans provenance est une rumeur. La
            // colonne est NOT NULL en base ; ce contrôle attrape ce que la base ne peut pas voir —
            // une valeur hors nomenclature arrivée par un import.
            if (! in_array($e['source'] ?? null, self::SOURCES, true)) {
                $erreurs[] = sprintf(
                    '%s dose n°%d : provenance absente ou inconnue — une échéance vaccinale sans '
                    .'source ne peut pas être publiée.',
                    $etiquette,
                    $dose,
                );
            }

            if (trim((string) ($e['libelle_echeance'] ?? '')) === '') {
                $erreurs[] = "{$etiquette} dose n°{$dose} : libellé d'échéance absent "
                    ."(« À la naissance », « 6 semaines »…).";
            }

            // Redondant avec le trigger, et délibérément : le moteur refuse l'écriture, ce contrôle
            // NOMME le problème à la publication. Le premier protège la base d'un import direct,
            // le second explique à un humain ce qu'il doit corriger.
            $limite = $e['age_jours_limite'] ?? null;

            if ($limite !== null && (int) $limite < (int) ($e['age_jours_du'] ?? 0)) {
                $erreurs[] = sprintf(
                    '%s dose n°%d : la fenêtre de rattrapage se ferme (%d j) avant de s\'ouvrir (%d j).',
                    $etiquette,
                    $dose,
                    (int) $limite,
                    (int) ($e['age_jours_du'] ?? 0),
                );
            }
        }

        // ── Les doses vont de 1 à N, sans trou ──────────────────────────────────────────────────
        //
        // Un calendrier portant les doses 1 et 3 mais pas la 2 ferait dire à l'écran qu'un enfant
        // est à jour alors qu'une dose du schéma n'a jamais été proposée.
        for ($d = 1; $d <= $nbDoses; $d++) {
            if (! isset($dosesVues[$d])) {
                $erreurs[] = "{$etiquette} : la dose n°{$d} du schéma n'a aucune échéance.";
            }
        }

        return $erreurs;
    }
}
