<?php

namespace App\Console\Commands;

use App\Models\SpecialiteMedicale;
use App\Models\Symptome;
use App\Models\SymptomeSpecialite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P10a — Transpose `symptomes.specialite_hint` en orientations rangées (CDC_05 §5).
 *
 * ═══ CE N'EST PAS UNE DEVINETTE, C'EST UNE ADOPTION — motif P6.8a ═══
 *
 * Chaque part d'un libellé, une fois NORMALISÉE (minuscules, accents retirés, parenthèse retirée),
 * tombe **exactement** sur un code du vocabulaire national :
 *
 *     « Cardiologie / Urgences »          → cardiologie, urgences
 *     « ORL (Oto-Rhino-Laryngologie) »    → orl
 *     « Gynécologie / Maternité »         → gynecologie, puis RIEN pour « Maternité »
 *
 * **Aucun rapprochement par ressemblance.** Si la normalisation ne tombe pas sur un code existant,
 * la part est **signalée, jamais devinée** — précédent P6.8a (le rattachement des praticiens passe
 * par le lien structurel, pas par le libellé) et P6.4a (*une liste inventée qui a l'air juste ne se
 * fait jamais corriger*). `Maternité` n'est pas au vocabulaire des 13 termes : elle ressortira dans
 * le rapport, et c'est un humain qui décidera.
 *
 * ═══ L'ORDRE ÉCRIT DEVIENT LE RANG, ET RIEN D'AUTRE ═══
 *
 * « Cardiologie / Urgences » donne `cardiologie` au rang 1 et `urgences` au rang 2, **parce que
 * c'est l'ordre dans lequel c'est écrit**. Je ne réordonne pas : décider que les urgences priment
 * sur la cardiologie pour ce symptôme serait un **arbitrage clinique**, et cette commande n'a pas
 * qualité pour le rendre. Le rang est désormais une donnée gouvernée — deux agents habilités
 * pourront le corriger en connaissance de cause, ce que personne ne pouvait faire tant qu'il vivait
 * dans un `str_contains`.
 *
 * ═══ `sexe_requis` TRANSPOSE UNE RÈGLE EXISTANTE, IL N'EN INVENTE PAS ═══
 *
 * `TriageService` écartait déjà la gynécologie pour un patient non féminin
 * (`str_contains($h, 'gyn') && $sexe !== 'F'`). La commande porte ce fait sur la liaison. Elle ne
 * crée aucune restriction nouvelle : ce qui n'était pas restreint hier ne l'est pas aujourd'hui.
 *
 * ═══ IDEMPOTENTE, ET LE DRY-RUN NE SOUS-ESTIME PAS ═══
 *
 * Le G2 de P6.8a a trouvé un aperçu qui annonçait « 0 praticien » avant d'en rattacher 28 — *un
 * aperçu qui sous-estime ce qu'il va faire n'aide pas à décider*. Ici le comptage est fait sur les
 * mêmes données dans les deux modes.
 *
 *   XDEBUG_MODE=off php artisan masante:orientation:backfill --dry-run
 */
class BackfillOrientationTriage extends Command
{
    protected $signature = 'masante:orientation:backfill
                            {--dry-run : Montre ce qui serait écrit, sans rien écrire}';

    protected $description = "Transpose les libellés d'orientation des symptômes en liaisons rangées";

    /**
     * Les parts qui exigent un sexe — transposition de la règle qui existait dans le service.
     *
     * @var array<string, string>
     */
    private const SEXE_REQUIS = [
        'gynecologie' => 'F',

        // ═══ `maternite` EST ICI POUR NE RIEN CHANGER, PAS POUR AJOUTER UNE RÈGLE ═══
        //
        // Le service testait `str_contains($h, 'gyn')` sur la chaîne ENTIÈRE, donc
        // « Gynécologie / Maternité » était écartée **en entier** pour un patient non féminin — les
        // deux parts, pas seulement la première. Porter la restriction sur `maternite` reproduit
        // exactement ce comportement.
        //
        // Ne PAS l'y mettre serait le vrai changement : un patient masculin se verrait désormais
        // orienter vers une maternité, ce qui n'arrivait pas hier. La commande transpose, elle
        // n'arbitre pas.
        'maternite' => 'F',
    ];

    public function handle(): int
    {
        $simulation = (bool) $this->option('dry-run');

        $codes = SpecialiteMedicale::query()
            ->where('pays_code', config('referentiels.pays_defaut', 'CI'))
            ->pluck('id', 'code');

        if ($codes->isEmpty()) {
            $this->error('Le vocabulaire des spécialités est vide : lancez d\'abord '
                .'`db:seed --class=SpecialiteMedicaleSeeder`.');

            return self::FAILURE;
        }

        $symptomes = Symptome::query()->whereNotNull('specialite_hint')->orderBy('id')->get();

        $aCreer      = [];
        $introuvable = [];

        foreach ($symptomes as $symptome) {
            $rang = 0;

            foreach ($this->decouper($symptome->specialite_hint) as $part) {
                $code = $this->normaliser($part);
                $rang++;

                if (! $codes->has($code)) {
                    // SIGNALÉ, JAMAIS DEVINÉ.
                    $introuvable[$part] = ($introuvable[$part] ?? 0) + 1;

                    continue;
                }

                $aCreer[] = [
                    'symptome_id'   => $symptome->id,
                    'specialite_id' => $codes->get($code),
                    'code'          => $code,
                    'rang'          => $rang,
                    'sexe_requis'   => self::SEXE_REQUIS[$code] ?? null,
                    'symptome'      => $symptome->nom_fr,
                ];
            }
        }

        // Comptage fait sur les MÊMES données dans les deux modes (leçon du G2 de P6.8a).
        $existantes = SymptomeSpecialite::query()
            ->get(['symptome_id', 'specialite_id'])
            ->map(fn ($l) => $l->symptome_id.'-'.$l->specialite_id)
            ->flip();

        $nouvelles = array_values(array_filter(
            $aCreer,
            fn (array $l): bool => ! $existantes->has($l['symptome_id'].'-'.$l['specialite_id']),
        ));

        $this->info(($simulation ? '[SIMULATION] ' : '')
            .count($symptomes).' symptôme(s) annoté(s), '
            .count($aCreer).' orientation(s) résolue(s), '
            .count($nouvelles).' à créer.');

        foreach ($nouvelles as $l) {
            $this->line(sprintf(
                '  %-28s → %-20s rang %d%s',
                mb_substr($l['symptome'], 0, 28),
                $l['code'],
                $l['rang'],
                $l['sexe_requis'] !== null ? ' (sexe '.$l['sexe_requis'].')' : '',
            ));
        }

        if ($introuvable !== []) {
            $this->newLine();
            $this->warn('Parts SANS code au vocabulaire national — signalées, jamais devinées :');

            foreach ($introuvable as $part => $n) {
                $this->line("  « {$part} » ({$n} symptôme(s)) — ajoutez le terme au vocabulaire, "
                    .'ou corrigez le libellé. Tant que ce n\'est pas fait, ce symptôme n\'oriente '
                    .'pas vers cette notion.');
            }
        }

        if ($simulation || $nouvelles === []) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($nouvelles): void {
            foreach ($nouvelles as $l) {
                SymptomeSpecialite::create([
                    'symptome_id'   => $l['symptome_id'],
                    'specialite_id' => $l['specialite_id'],
                    'rang'          => $l['rang'],
                    'sexe_requis'   => $l['sexe_requis'],
                ]);
            }
        });

        $this->info(count($nouvelles).' orientation(s) créée(s).');

        return self::SUCCESS;
    }

    /**
     * Découpe « Cardiologie / Urgences » en ses parts, dans l'ordre écrit.
     *
     * @return array<int, string>
     */
    private function decouper(string $libelle): array
    {
        return array_values(array_filter(array_map('trim', explode('/', $libelle))));
    }

    /**
     * Table de translittération EXPLICITE.
     *
     * ═══ POURQUOI PAS `iconv('ASCII//TRANSLIT')` — DÉFAUT TROUVÉ EN LANÇANT LA COMMANDE ═══
     *
     * Le premier essai l'utilisait, et le dry-run sur les vraies données a signalé « Gynécologie »
     * comme INTROUVABLE alors que le code `gynecologie` existe. Vérification faite :
     * `iconv` rend `é` par **`'e`** sur cette plateforme, d'où `gyn_ecologie`.
     *
     * Et le pire n'est pas l'erreur, c'est qu'elle est **dépendante du locale** : ailleurs `iconv`
     * aurait rendu `e`, et la commande aurait marché. Une normalisation dont le résultat change avec
     * la machine se comporte différemment en production et en test — exactement la divergence
     * relevée en P6.8c avec la collation MySQL. Une table explicite est déterministe partout.
     *
     * @var array<string, string>
     */
    private const TRANSLITTERATION = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ];

    /**
     * « ORL (Oto-Rhino-Laryngologie) » → `orl` ; « Gynécologie » → `gynecologie`.
     *
     * Normalisation STRICTE, pas approchée : parenthèse retirée, accents translittérés par la table
     * ci-dessus, minuscules, espaces en soulignés. Le résultat doit tomber **exactement** sur un
     * code existant, sinon la part est signalée. Aucune distance d'édition, aucun « à peu près » —
     * c'est ce qui distingue une adoption d'une devinette.
     */
    private function normaliser(string $part): string
    {
        // La parenthèse porte un développement du sigle, jamais une seconde spécialité.
        $sans = trim(preg_replace('/\s*\([^)]*\)/u', '', $part) ?? $part);

        $minuscules = strtr(mb_strtolower($sans), self::TRANSLITTERATION);

        return trim(preg_replace('/[^a-z0-9]+/', '_', $minuscules) ?? $minuscules, '_');
    }
}
