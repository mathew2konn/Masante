<?php

namespace App\Services\Triage;

use Illuminate\Support\Facades\Cache;

/**
 * P10c-2-i (F8) — Disjoncteur vers `triage-service` (CDC_05, CDC_03 §10.1).
 *
 * ═══ UN ÉTAT PARTAGÉ, PAS UNE VARIABLE DE PROCESSUS ═══
 *
 * Un compteur d'échecs en mémoire PHP ne survit pas à la requête : chaque requête rouvrirait le
 * circuit et retomberait dans le timeout. L'état vit donc dans le cache (store `database`, F5 de
 * P6.3) — partagé entre toutes les requêtes du même processus applicatif.
 *
 * ═══ TROIS ÉTATS, DEUX CLÉS SEULEMENT ═══
 *
 * - **Fermé** : `CLE_OUVERT_JUSQUA` absente. Les appels partent normalement, les échecs
 *   s'accumulent dans `CLE_ECHECS` jusqu'au seuil configuré.
 * - **Ouvert** : `CLE_OUVERT_JUSQUA` porte un horodatage dans le futur. {@see estOuvert()} répond
 *   `true` : AUCUN appel réseau ne part, la dégradation est immédiate.
 * - **Demi-ouvert** : l'horodatage de `CLE_OUVERT_JUSQUA` est passé, mais la CLÉ elle-même est
 *   encore là (son TTL physique dépasse volontairement la durée d'ouverture logique — voir plus
 *   bas). C'est le PROCHAIN appel qui sert d'essai.
 *
 * ═══ POURQUOI LE TTL PHYSIQUE DE `CLE_OUVERT_JUSQUA` EST PLUS LONG QUE LA DURÉE D'OUVERTURE ═══
 *
 * Défaut trouvé PAR UN TEST, pas par relecture : un TTL physique égal à la durée d'ouverture fait
 * EXPIRER la clé au moment même où l'essai de la demi-ouverture doit avoir lieu. Un échec de cet
 * essai retombait alors sur le chemin « fermé, on accumule depuis zéro » — un seul échec ne
 * rouvrait rien, contrairement à ce que {@see enregistrerEchec()} promettait depuis toujours. Le
 * TTL physique est donc porté à DEUX FOIS la durée d'ouverture : la clé reste lisible assez
 * longtemps après l'expiration logique pour que le prochain échec la retrouve et rouvre le circuit
 * immédiatement, sans réaccumuler le seuil.
 *
 * ═══ LA VALEUR STOCKÉE EST UN ENTIER, JAMAIS UN OBJET `Carbon` ═══
 *
 * Défaut trouvé PAR LE G2 LIVE, invisible aux tests (une seule requête PHP y écrit ET y relit,
 * dans le même processus — l'autoloader ne peut pas diverger de lui-même). En conditions réelles,
 * `php artisan serve` traite chaque requête HTTP dans un processus PHP séparé : un `Carbon`
 * sérialisé par l'un et désérialisé par un autre en devient un `__PHP_Incomplete_Class`, et la
 * comparaison plante en `TypeError` — un disjoncteur qui casse le triage qu'il est censé protéger.
 * Un horodatage Unix (entier) traverse cette frontière sans y être sensible.
 */
class DisjoncteurTriageIa
{
    private const CLE_OUVERT_JUSQUA = 'triage_ia:disjoncteur:ouvert_jusqua';

    private const CLE_ECHECS = 'triage_ia:disjoncteur:echecs';

    public function estOuvert(): bool
    {
        $ouvertJusqua = Cache::get(self::CLE_OUVERT_JUSQUA);

        return $ouvertJusqua !== null && now()->timestamp < $ouvertJusqua;
    }

    /**
     * Un appel a échoué (timeout, connexion refusée, réponse inattendue). Deux chemins :
     *
     * - la clé `CLE_OUVERT_JUSQUA` existe déjà (même à horodatage passé = demi-ouvert) → l'essai a
     *   échoué, le circuit se ROUVRE immédiatement, sans réaccumuler le seuil ;
     * - sinon → chemin normal, le compteur s'incrémente et n'ouvre qu'au seuil.
     */
    public function enregistrerEchec(): void
    {
        $duree = (int) config('masante.triage_ia.disjoncteur_duree_ouverture_s');
        $seuil = (int) config('masante.triage_ia.disjoncteur_seuil_echecs');
        $ttlPhysique = now()->addSeconds($duree * 2);

        if (Cache::has(self::CLE_OUVERT_JUSQUA)) {
            Cache::put(self::CLE_OUVERT_JUSQUA, now()->addSeconds($duree)->timestamp, $ttlPhysique);
            Cache::put(self::CLE_ECHECS, $seuil, $ttlPhysique);

            return;
        }

        $echecs = (int) Cache::get(self::CLE_ECHECS, 0) + 1;
        Cache::put(self::CLE_ECHECS, $echecs, $ttlPhysique);

        if ($echecs >= $seuil) {
            Cache::put(self::CLE_OUVERT_JUSQUA, now()->addSeconds($duree)->timestamp, $ttlPhysique);
        }
    }

    /** Un appel a réussi : referme le circuit et efface le compteur — l'essai a validé le service. */
    public function enregistrerSucces(): void
    {
        Cache::forget(self::CLE_OUVERT_JUSQUA);
        Cache::forget(self::CLE_ECHECS);
    }
}
