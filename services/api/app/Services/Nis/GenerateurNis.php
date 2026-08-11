<?php

namespace App\Services\Nis;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Génération d'un NIS depuis la séquence nationale (CDC_09 §3.3).
 *
 * CONCURRENCE — le point critique de cet incrément. Le compteur est lu et incrémenté sous
 * verrou pessimiste (`SELECT … FOR UPDATE`) : deux attributions simultanées se sérialisent,
 * la seconde attend la première et lit une valeur déjà incrémentée. Sans ce verrou, deux
 * dossiers pourraient recevoir le même NIS — défaut inacceptable pour un identifiant
 * « unique, permanent, non réutilisable » (CDC_09 §3.2).
 *
 * PRÉREQUIS D'APPEL : cette méthode DOIT être invoquée à l'intérieur d'une transaction
 * (le verrou n'a de sens que jusqu'au commit). `AttributeurNis` s'en charge ; un appel
 * direct hors transaction lève une exception plutôt que de produire un NIS non garanti.
 */
final class GenerateurNis
{
    public function __construct(private readonly CalculateurNis $calculateur) {}

    /**
     * Réserve le numéro suivant de la séquence et compose le NIS correspondant.
     *
     * @param  string  $paysCode  Code pays ISO à 2 lettres (défaut « CI »).
     * @return array{nis: string, compteur: int, annee: int}
     *
     * @throws \RuntimeException si appelée hors transaction.
     */
    public function suivant(string $paysCode = 'CI'): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'GenerateurNis::suivant() doit être appelée dans une transaction : '
                .'hors transaction, le verrou du compteur ne garantit plus l\'unicité.'
            );
        }

        $annee = (int) date('y');

        // ORDRE DE VERROU — corrigé après un deadlock réel constaté en G2 (MySQL 1213).
        //
        // La séquence « insertOrIgnore puis SELECT … FOR UPDATE » (motif hérité du service
        // paiement, valide sur PostgreSQL) DEADLOCKE sur MySQL : `INSERT IGNORE` pose un verrou
        // PARTAGÉ sur la ligne déjà présente lors du contrôle de doublon, puis le `FOR UPDATE`
        // réclame un verrou EXCLUSIF. Deux transactions détenant chacune le S et demandant
        // chacune le X se bloquent mutuellement (montée en verrou croisée).
        //
        // Parade : prendre le verrou exclusif DÈS LE PREMIER ACCÈS, via un UPDATE atomique.
        // Aucun verrou partagé préalable, donc aucune montée en verrou possible.
        $affectees = DB::table('nis_compteurs')
            ->where('pays_code', $paysCode)
            ->where('annee', $annee)
            ->update([
                'dernier'    => DB::raw('dernier + 1'),
                'updated_at' => now(),
            ]);

        // Première attribution de l'année : la ligne de séquence n'existe pas encore.
        if ($affectees === 0) {
            try {
                DB::table('nis_compteurs')->insert([
                    'pays_code'  => $paysCode,
                    'annee'      => $annee,
                    'dernier'    => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Course perdue : une transaction concurrente vient de créer la ligne.
                // On reprend le chemin normal — incrément atomique sous verrou exclusif.
                DB::table('nis_compteurs')
                    ->where('pays_code', $paysCode)
                    ->where('annee', $annee)
                    ->update([
                        'dernier'    => DB::raw('dernier + 1'),
                        'updated_at' => now(),
                    ]);
            }
        }

        // Relecture de NOTRE valeur : la ligne est déjà verrouillée en exclusif par cette
        // transaction (UPDATE ou INSERT ci-dessus), donc `lockForUpdate` ne peut pas bloquer
        // et la valeur lue ne peut plus bouger jusqu'au commit.
        $compteur = (int) DB::table('nis_compteurs')
            ->where('pays_code', $paysCode)
            ->where('annee', $annee)
            ->lockForUpdate()
            ->value('dernier');

        if ($compteur > 99_999_999) {
            throw new \RuntimeException(
                "Séquence NIS épuisée pour {$paysCode}/{$annee} (99 999 999 dossiers)."
            );
        }

        return [
            'nis'      => $this->calculateur->composer($this->prefixe($paysCode), $annee, $compteur),
            'compteur' => $compteur,
            'annee'    => $annee,
        ];
    }

    /**
     * Préfixe alphabétique du pays : code ISO + « S » pour Santé (CI → CIS, SN → SNS).
     * Multi-pays sans modification de code (CDC_09 §1.2 principe 5).
     */
    public function prefixe(string $paysCode): string
    {
        return strtoupper($paysCode).'S';
    }
}
