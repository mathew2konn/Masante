<?php

namespace App\Services\Assurance;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Code national d'un organisme d'assurance (CDC_09 §8) — `ASS` + 6 chiffres.
 *
 * ═══ LITTÉRAL, SANS CLÉ DE CONTRÔLE ═══
 *
 * Septième application du raisonnement de `ETS` (P6.4a), `PRO` (P6.5a), `MED` (P6.6a), `ANA` (P6.7a),
 * `VAC` (P6.8b) et `MAL` (P6.8c) : le §3.2 impose explicitement un checksum **au NIS** et ne le fait
 * nulle part ailleurs. La clé du NIS protège d'une faute de frappe commise par un citoyen qui saisit
 * son numéro de tête ; un organisme est toujours choisi dans une liste.
 *
 * ═══ UNE SÉQUENCE PAR PAYS ═══
 *
 * À la différence de `MAL`, qui est global parce qu'une maladie n'appartient à aucun pays (E2), un
 * organisme d'assurance est une **personne morale agréée par un État**. La séquence est donc indexée
 * par pays, comme `ETS`, `PRO`, `MED`, `ANA` et `VAC` — et CI comme SN peuvent porter `ASS000001`.
 */
final class GenerateurCodeOrganisme
{
    public const PREFIXE = 'ASS';

    private const MAX = 999_999;

    /**
     * Le prochain code disponible pour ce pays. À appeler dans une transaction.
     *
     * ORDRE DE VERROU repris de P6.1, où le motif « insertOrIgnore puis SELECT … FOR UPDATE » a
     * produit un deadlock réel sur MySQL (erreur 1213) : on prend le verrou exclusif dès le premier
     * accès, et on n'insère que si aucune ligne n'a bougé.
     */
    public function suivant(string $paysCode = 'CI'): string
    {
        $paysCode = strtoupper($paysCode);

        $affectees = DB::table('organisme_assurance_compteurs')
            ->where('pays_code', $paysCode)
            ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);

        if ($affectees === 0) {
            try {
                DB::table('organisme_assurance_compteurs')->insert([
                    'pays_code'  => $paysCode,
                    'dernier'    => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                DB::table('organisme_assurance_compteurs')
                    ->where('pays_code', $paysCode)
                    ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);
            }
        }

        $compteur = (int) DB::table('organisme_assurance_compteurs')
            ->where('pays_code', $paysCode)
            ->lockForUpdate()
            ->value('dernier');

        if ($compteur > self::MAX) {
            throw new RuntimeException(
                "Séquence de codes d'organisme d'assurance épuisée pour {$paysCode} "
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
