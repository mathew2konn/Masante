<?php

namespace App\Services\Medicament;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Code national du médicament (CDC_09 §6.2) — `MED` + 6 chiffres.
 *
 * FORMAT LITTÉRAL, SANS CLÉ DE CONTRÔLE — troisième application du même raisonnement, après `ETS`
 * (P6.4a) et `PRO` (P6.5a). Le §3.2 impose explicitement un checksum **au NIS** et ne le fait nulle
 * part ailleurs. Ici, une raison de plus, et elle est décisive : l'exemple imposé au §6.3 est
 * `MED000458`. Aucune convention de clé de contrôle ne le laisserait valide — en ajouter une
 * rendrait le corpus faux, ce que ADR-021 avait déjà dû corriger dans l'autre sens en recalculant
 * l'exemple de NIS d'ADR-001.
 *
 * LE PAYS QUALIFIE, IL NE S'ÉCRIT PAS DEDANS : l'unicité porte sur `(pays_code, code)`, donc CI et
 * SN peuvent tous deux avoir un `MED000458`. C'est l'inverse du NIS, et pour la même raison qu'en
 * P6.5a : un patient traverse les frontières avec son dossier, une autorisation de mise sur le
 * marché s'arrête à la sienne.
 */
final class GenerateurCodeMedicament
{
    public const PREFIXE = 'MED';

    /** 6 chiffres : la borne du format, pas un choix. */
    private const MAX = 999_999;

    /**
     * Le prochain code disponible pour un pays. À appeler dans une transaction.
     *
     * ORDRE DE VERROU repris de P6.1, où le motif « insertOrIgnore puis SELECT … FOR UPDATE » a
     * produit un deadlock RÉEL sur MySQL (1213) : verrou partagé pendant le contrôle de doublon,
     * puis verrou exclusif — deux transactions qui montent ainsi se bloquent mutuellement. On prend
     * donc le verrou exclusif dès le premier accès, et on n'insère que si aucune ligne n'a bougé.
     */
    public function suivant(string $paysCode = 'CI'): string
    {
        $paysCode = strtoupper($paysCode);

        $affectees = DB::table('medicament_compteurs')
            ->where('pays_code', $paysCode)
            ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);

        if ($affectees === 0) {
            try {
                DB::table('medicament_compteurs')->insert([
                    'pays_code'  => $paysCode,
                    'dernier'    => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Course perdue : une transaction concurrente vient de créer la ligne du pays.
                DB::table('medicament_compteurs')
                    ->where('pays_code', $paysCode)
                    ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);
            }
        }

        $compteur = (int) DB::table('medicament_compteurs')
            ->where('pays_code', $paysCode)
            ->lockForUpdate()
            ->value('dernier');

        if ($compteur > self::MAX) {
            throw new RuntimeException(
                "Séquence de codes médicament épuisée pour {$paysCode} "
                .'('.number_format(self::MAX, 0, ',', ' ').' produits).'
            );
        }

        return self::composer($compteur);
    }

    /** `MED` + le compteur cadré à 6 chiffres. */
    public static function composer(int $compteur): string
    {
        return self::PREFIXE.str_pad((string) $compteur, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Une chaîne a-t-elle la FORME d'un code médicament ?
     *
     * Forme uniquement : sans clé de contrôle il n'y a rien de plus à vérifier, et prétendre le
     * contraire donnerait une fausse assurance — un `MED999999` bien formé peut n'avoir jamais été
     * attribué. Ne consulte pas la base (pas d'oracle d'énumération, précédent NIS).
     */
    public static function formeValide(string $code): bool
    {
        return preg_match('/^'.self::PREFIXE.'\d{6}$/', $code) === 1;
    }
}
