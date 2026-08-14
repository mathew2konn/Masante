<?php

namespace App\Services\Analyse;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Code national d'une analyse (CDC_09 §7.3) — `ANA` + 6 chiffres.
 *
 * FORMAT LITTÉRAL, SANS CLÉ DE CONTRÔLE — quatrième application du même raisonnement après `ETS`
 * (P6.4a), `PRO` (P6.5a) et `MED` (P6.6a). §3.2 impose explicitement un checksum **au NIS** et ne le
 * fait nulle part ailleurs : la clé du NIS protège d'une faute de frappe commise par un citoyen qui
 * saisit son numéro de tête ; une analyse est toujours choisie dans une liste.
 *
 * LE PAYS QUALIFIE, IL NE S'ÉCRIT PAS DEDANS : unicité sur `(pays_code, code)`.
 *
 * ═══ CE CODE N'EST PAS LOINC, ET NE PRÉTEND PAS L'ÊTRE ═══
 *
 * CDC_09 §9.1 recommande LOINC pour les analyses. `analyses.loinc` existe pour l'accueillir et
 * reste **vide** : le jeu LOINC n'est pas en notre possession. Le code national est une clé locale,
 * pas un standard international, et les confondre ferait croire à une interopérabilité qui n'existe
 * pas.
 */
final class GenerateurCodeAnalyse
{
    public const PREFIXE = 'ANA';

    private const MAX = 999_999;

    /**
     * Le prochain code disponible pour un pays. À appeler dans une transaction.
     *
     * ORDRE DE VERROU repris de P6.1, où le motif « insertOrIgnore puis SELECT … FOR UPDATE » a
     * produit un deadlock réel sur MySQL (1213) : on prend le verrou exclusif dès le premier accès,
     * et on n'insère que si aucune ligne n'a bougé.
     */
    public function suivant(string $paysCode = 'CI'): string
    {
        $paysCode = strtoupper($paysCode);

        $affectees = DB::table('analyse_compteurs')
            ->where('pays_code', $paysCode)
            ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);

        if ($affectees === 0) {
            try {
                DB::table('analyse_compteurs')->insert([
                    'pays_code'  => $paysCode,
                    'dernier'    => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                DB::table('analyse_compteurs')
                    ->where('pays_code', $paysCode)
                    ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);
            }
        }

        $compteur = (int) DB::table('analyse_compteurs')
            ->where('pays_code', $paysCode)
            ->lockForUpdate()
            ->value('dernier');

        if ($compteur > self::MAX) {
            throw new RuntimeException(
                "Séquence de codes d'analyse épuisée pour {$paysCode} "
                .'('.number_format(self::MAX, 0, ',', ' ').' entrées).'
            );
        }

        return self::composer($compteur);
    }

    public static function composer(int $compteur): string
    {
        return self::PREFIXE.str_pad((string) $compteur, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Contrôle de FORME uniquement. Sans clé de contrôle il n'y a rien de plus à vérifier, et
     * prétendre le contraire donnerait une fausse assurance. Ne consulte pas la base — pas d'oracle
     * d'énumération (précédent NIS).
     */
    public static function formeValide(string $code): bool
    {
        return preg_match('/^'.self::PREFIXE.'\d{6}$/', $code) === 1;
    }
}
