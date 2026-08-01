<?php

namespace App\Services;

use App\Models\MembreFamille;

/**
 * Génère le matricule interne d'un membre — format IVS-AAAA-XX-NNNNN (CdC §8.1).
 *
 *   IVS   préfixe fixe de la plateforme
 *   AAAA  année de création du dossier (4 chiffres)
 *   XX    2 lettres aléatoires (entropie supplémentaire, anti-collision)
 *   NNNNN 5 chiffres aléatoires
 *
 * Le matricule est l'identifiant PERMANENT du dossier ; il ne doit jamais fuiter (§1, §2.1
 * Sécurité) et n'est donc pas exposé par l'API. L'unicité est garantie par la contrainte
 * `unique` en base, vérifiée ici par une boucle de régénération en cas de collision.
 */
class MatriculeService
{
    /** Filet de sécurité : nombre maximal de tentatives avant d'abandonner. */
    private const MAX_TENTATIVES = 10;

    public function generer(): string
    {
        for ($i = 0; $i < self::MAX_TENTATIVES; $i++) {
            $matricule = $this->candidat();

            if (! MembreFamille::where('matricule_ivs', $matricule)->exists()) {
                return $matricule;
            }
        }

        throw new \RuntimeException('Impossible de générer un matricule unique après '.self::MAX_TENTATIVES.' tentatives.');
    }

    /** Construit un candidat IVS-AAAA-XX-NNNNN (non encore vérifié en base). */
    private function candidat(): string
    {
        $annee = now()->format('Y');
        // 2 lettres majuscules réellement aléatoires (A–Z).
        $lettres = chr(random_int(65, 90)).chr(random_int(65, 90));
        $chiffres = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        return "IVS-{$annee}-{$lettres}-{$chiffres}";
    }
}
