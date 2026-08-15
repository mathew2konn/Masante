<?php

namespace App\Services\Maladie;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Code national d'une maladie (CDC_09 §8) — `MAL` + 6 chiffres.
 *
 * ═══ LITTÉRAL, SANS CLÉ DE CONTRÔLE ═══
 *
 * Sixième application du raisonnement de `ETS` (P6.4a), `PRO` (P6.5a), `MED` (P6.6a), `ANA` (P6.7a)
 * et `VAC` (P6.8b) : §3.2 impose explicitement un checksum **au NIS** et ne le fait nulle part
 * ailleurs. La clé du NIS protège d'une faute de frappe commise par un citoyen qui saisit son numéro
 * de tête ; une maladie est toujours choisie dans une liste.
 *
 * ═══ ET CE CODE N'EST PAS UN CODE CIM ═══
 *
 * Le critère posé en P6.8b — « instance → numéro ; terme de nomenclature → code littéral » —
 * plaiderait pour `fievre_typhoide`. Il ne s'applique pas ici, pour une raison propre à ce
 * référentiel : **la CIM occupera la place du code littéral** le jour où elle sera chargée.
 * Fabriquer `fievre_typhoide` créerait un pseudo-code qui RESSEMBLE à un code de nomenclature et
 * devrait cohabiter avec `A01.0` — deux codes littéraux concurrents pour la même chose.
 *
 * ═══ UNE SEULE SÉQUENCE, GLOBALE ═══
 *
 * Tous les générateurs précédents sont indexés par pays parce que leurs objets le sont. Celui-ci ne
 * peut pas l'être sans contredire la décision E2 : une maladie n'appartient à aucun pays, et
 * `maladies.code` est unique GLOBALEMENT.
 */
final class GenerateurCodeMaladie
{
    public const PREFIXE = 'MAL';

    /** Clé de la ligne unique du compteur — voir l'en-tête : la séquence est globale. */
    private const CLE = 'global';

    private const MAX = 999_999;

    /**
     * Le prochain code disponible. À appeler dans une transaction.
     *
     * ORDRE DE VERROU repris de P6.1, où le motif « insertOrIgnore puis SELECT … FOR UPDATE » a
     * produit un deadlock réel sur MySQL (erreur 1213) : on prend le verrou exclusif dès le premier
     * accès, et on n'insère que si aucune ligne n'a bougé.
     */
    public function suivant(): string
    {
        $affectees = DB::table('maladie_compteurs')
            ->where('cle', self::CLE)
            ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);

        if ($affectees === 0) {
            try {
                DB::table('maladie_compteurs')->insert([
                    'cle'        => self::CLE,
                    'dernier'    => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                DB::table('maladie_compteurs')
                    ->where('cle', self::CLE)
                    ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);
            }
        }

        $compteur = (int) DB::table('maladie_compteurs')
            ->where('cle', self::CLE)
            ->lockForUpdate()
            ->value('dernier');

        if ($compteur > self::MAX) {
            throw new RuntimeException(
                'Séquence de codes de maladie épuisée ('
                .number_format(self::MAX, 0, ',', ' ').' entrées).'
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
