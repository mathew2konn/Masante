<?php

namespace App\Services\Professionnel;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Numéro national de professionnel de santé (CDC_09 §5.2) — `PRO` + 6 chiffres.
 *
 * FORMAT LITTÉRAL, SANS CLÉ DE CONTRÔLE, pour la raison exacte de l'identifiant d'établissement
 * (P6.4a) : le §3.2 impose explicitement un checksum pour le NIS et **ne le fait pas ailleurs**.
 * La clé du NIS protège d'une faute de frappe commise par un citoyen qui saisit son numéro de
 * tête ; un professionnel, lui, est toujours choisi dans une liste ou rattaché à son compte.
 * Ajouter une clé ici serait de la symétrie décorative — et rendrait le format plus long à lire
 * pour une garantie que personne ne réclame.
 *
 * LE PAYS QUALIFIE, IL NE S'ÉCRIT PAS DEDANS. Deux pays peuvent tous deux avoir un `PRO000001` :
 * l'unicité porte sur le couple `(pays_code, numero_professionnel)`. C'est l'inverse du NIS, et
 * la différence tient à ce que désigne chaque identifiant : un patient traverse les frontières
 * avec son dossier, un ordre professionnel s'arrête à la sienne.
 */
final class GenerateurNumeroProfessionnel
{
    public const PREFIXE = 'PRO';

    /** 6 chiffres : la borne du format, pas un choix. */
    private const MAX = 999_999;

    /**
     * Le prochain numéro disponible pour un pays. À appeler dans une transaction.
     *
     * L'ORDRE DE VERROU est celui corrigé en P6.1 après un deadlock RÉEL (MySQL 1213), retrouvé
     * identique en P6.4a : le motif « insertOrIgnore puis SELECT … FOR UPDATE » pose un verrou
     * PARTAGÉ pendant le contrôle de doublon, puis réclame un verrou EXCLUSIF — deux transactions
     * qui montent ainsi en verrou se bloquent mutuellement. On prend donc le verrou exclusif DÈS
     * LE PREMIER ACCÈS, par un UPDATE atomique, et on n'insère que si aucune ligne n'a bougé.
     */
    public function suivant(string $paysCode = 'CI'): string
    {
        $paysCode = strtoupper($paysCode);

        $affectees = DB::table('professionnel_compteurs')
            ->where('pays_code', $paysCode)
            ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);

        if ($affectees === 0) {
            try {
                DB::table('professionnel_compteurs')->insert([
                    'pays_code'  => $paysCode,
                    'dernier'    => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Course perdue : une transaction concurrente vient de créer la ligne du pays.
                // On reprend le chemin normal — incrément atomique sous verrou exclusif.
                DB::table('professionnel_compteurs')
                    ->where('pays_code', $paysCode)
                    ->update(['dernier' => DB::raw('dernier + 1'), 'updated_at' => now()]);
            }
        }

        // La ligne est déjà verrouillée en exclusif par cette transaction : `lockForUpdate` ne peut
        // pas bloquer, et la valeur lue ne bougera plus jusqu'au commit.
        $compteur = (int) DB::table('professionnel_compteurs')
            ->where('pays_code', $paysCode)
            ->lockForUpdate()
            ->value('dernier');

        if ($compteur > self::MAX) {
            throw new RuntimeException(
                "Séquence de numéros professionnels épuisée pour {$paysCode} "
                .'('.number_format(self::MAX, 0, ',', ' ').' professionnels).'
            );
        }

        return self::composer($compteur);
    }

    /** `PRO` + le compteur cadré à 6 chiffres. */
    public static function composer(int $compteur): string
    {
        return self::PREFIXE.str_pad((string) $compteur, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Une chaîne a-t-elle la forme d'un numéro professionnel ?
     *
     * Contrôle de FORME uniquement. Sans clé de contrôle il n'y a rien de plus à vérifier, et
     * prétendre le contraire donnerait une fausse assurance : un `PRO999999` bien formé peut
     * n'avoir jamais été attribué. Ne consulte pas la base — comme la vérification du NIS, pour
     * ne pas offrir un oracle d'énumération.
     */
    public static function formeValide(string $numero): bool
    {
        return preg_match('/^'.self::PREFIXE.'\d{6}$/', $numero) === 1;
    }
}
