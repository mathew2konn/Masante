<?php

namespace App\Services\Etablissement;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Identifiant national d'établissement (CDC_09 §4.3) — `ETS` + 6 chiffres.
 *
 * FORMAT LITTÉRAL, SANS CLÉ DE CONTRÔLE (décision G1 D2). Le §3.2 impose explicitement un
 * checksum pour le NIS et **ne le fait pas ici** ; l'exemple imposé `ETS000152` n'en porte
 * aucune. Ajouter une clé rendrait l'exemple du corpus invalide — et le risque qu'elle traite
 * (la faute de frappe d'un citoyen saisissant son identifiant de tête) n'existe pas pour un
 * établissement, qui est toujours choisi dans une liste.
 *
 * LE PAYS QUALIFIE, IL NE S'ÉCRIT PAS DANS L'IDENTIFIANT. Deux pays peuvent tous deux avoir un
 * `ETS000152` : l'identifiant est national, pas mondial, et l'unicité porte sur le couple
 * `(pays_code, identifiant_national)`. C'est ce qui permet de respecter l'exemple imposé sans
 * renoncer au principe multi-pays du §1.2.5 — là où le NIS, lui, devait discriminer les pays
 * DANS la valeur parce qu'un patient traverse les frontières.
 */
final class GenerateurIdentifiantEtablissement
{
    public const PREFIXE = 'ETS';

    /** 6 chiffres : la borne du format, pas un choix. */
    private const MAX = 999_999;

    /**
     * Le prochain identifiant disponible pour un pays. À appeler dans une transaction.
     *
     * L'ORDRE DE VERROU est celui corrigé en P6.1 après un deadlock réel (MySQL 1213) : le motif
     * « insertOrIgnore puis SELECT … FOR UPDATE » pose un verrou PARTAGÉ lors du contrôle de
     * doublon, puis réclame un verrou EXCLUSIF — deux transactions qui montent ainsi en verrou se
     * bloquent mutuellement. On prend donc le verrou exclusif DÈS LE PREMIER ACCÈS, par un UPDATE
     * atomique, et on n'insère que si aucune ligne n'a été touchée.
     */
    public function suivant(string $paysCode = 'CI'): string
    {
        $paysCode = strtoupper($paysCode);

        $affectees = DB::table('etablissement_compteurs')
            ->where('pays_code', $paysCode)
            ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);

        if ($affectees === 0) {
            try {
                DB::table('etablissement_compteurs')->insert([
                    'pays_code'  => $paysCode,
                    'dernier'    => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Course perdue : une transaction concurrente vient de créer la ligne du pays.
                // On reprend le chemin normal — incrément atomique sous verrou exclusif.
                DB::table('etablissement_compteurs')
                    ->where('pays_code', $paysCode)
                    ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);
            }
        }

        // La ligne est déjà verrouillée en exclusif par cette transaction : `lockForUpdate` ne
        // peut pas bloquer, et la valeur lue ne bougera plus jusqu'au commit.
        $compteur = (int) DB::table('etablissement_compteurs')
            ->where('pays_code', $paysCode)
            ->lockForUpdate()
            ->value('dernier');

        if ($compteur > self::MAX) {
            throw new RuntimeException(
                "Séquence d'identifiants d'établissement épuisée pour {$paysCode} "
                .'('.number_format(self::MAX, 0, ',', ' ').' établissements).'
            );
        }

        return self::composer($compteur);
    }

    /** `ETS` + le compteur cadré à 6 chiffres — la forme du §4.3. */
    public static function composer(int $compteur): string
    {
        return self::PREFIXE.str_pad((string) $compteur, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Une chaîne a-t-elle la forme d'un identifiant d'établissement ?
     *
     * Contrôle de FORME uniquement — sans clé de contrôle, il n'y a rien de plus à vérifier, et
     * prétendre le contraire donnerait une fausse assurance. Ne consulte pas la base.
     */
    public static function formeValide(string $identifiant): bool
    {
        return preg_match('/^'.self::PREFIXE.'\d{6}$/', $identifiant) === 1;
    }
}
