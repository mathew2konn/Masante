<?php

namespace App\Services\Referentiel;

use App\Models\Referentiel;
use App\Models\ReferentielVersion;
use Illuminate\Support\Facades\Cache;

/**
 * Diffusion en lecture des référentiels publiés (CDC_09 §10 « exposés en lecture à tous les
 * services via API + cache, invalidation par événement lors d'une nouvelle version »).
 *
 * L'INVALIDATION N'EXISTE PAS ICI — ET C'EST VOULU. La clé de cache porte le numéro de la version
 * publiée : `referentiel:CI:seuils_mesure:v7`. Publier la version 8 fait lire `…:v8`, qui n'est pas
 * en cache : le nouveau contenu est servi immédiatement, sans qu'aucune ligne de code n'ait à
 * supprimer quoi que ce soit. L'entrée `v7` expire seule à son TTL, et reste correcte tant qu'elle
 * vit — c'est l'instantané immuable de la version 7.
 *
 * Ce choix n'est pas un contournement : c'est ce qui rend le mécanisme indépendant du store.
 * `Cache::tags()` aurait été l'autre voie, mais le driver `database` — celui de cette
 * installation — ne les supporte pas. Ici, passer à Redis est un changement de configuration
 * (`CACHE_STORE=redis`), zéro ligne de code : « prêt à activer », littéralement.
 *
 * CE QUI N'EST PAS MIS EN CACHE : la ligne de registre qui porte le numéro de version. C'est une
 * lecture indexée sur une table de quelques lignes, et la mettre en cache imposerait précisément
 * l'invalidation explicite qu'on vient d'éviter. Un cache qu'on n'a pas à invalider est un cache
 * qu'on ne peut pas oublier d'invalider.
 */
final class DiffusionReferentiel
{
    /**
     * Le contenu en vigueur d'un référentiel, avec sa version.
     *
     * @return array{code: string, pays_code: string, libelle: string, version: int,
     *               publiee_le: ?string, nb_entrees: int, empreinte: string,
     *               contenu: array<int, array<string, mixed>>}
     */
    public function lire(string $code, ?string $paysCode = null): array
    {
        $paysCode ??= config('referentiels.pays_defaut');
        $referentiel = $this->registre($code, $paysCode);

        if (! $referentiel->estPublie()) {
            throw new ReferentielException(
                "Le référentiel « {$code} » n'a aucune version publiée : il n'y a rien à diffuser.",
                404,
            );
        }

        $numero = $referentiel->version_publiee_numero;

        $version = Cache::remember(
            $this->cle($code, $paysCode, $numero),
            config('referentiels.cache_ttl'),
            function () use ($referentiel, $numero): array {
                $v = $referentiel->versions()->where('numero', $numero)->firstOrFail();

                return [
                    'nb_entrees' => $v->nb_entrees,
                    'empreinte'  => $v->empreinte,
                    'contenu'    => $v->contenu_json,
                ];
            },
        );

        return [
            'code'       => $referentiel->code,
            'pays_code'  => $referentiel->pays_code,
            'libelle'    => $referentiel->libelle,
            'version'    => $numero,
            'publiee_le' => $referentiel->publiee_le?->toIso8601String(),
            'nb_entrees' => $version['nb_entrees'],
            'empreinte'  => $version['empreinte'],
            'contenu'    => $version['contenu'],
        ];
    }

    /**
     * L'instantané d'une version PRÉCISE, publiée ou archivée — le chemin qui rend une décision
     * passée rejouable (§10 « toute décision conserve la version du référentiel utilisée »).
     *
     * Une proposition n'est pas servie ici : elle n'a jamais été en vigueur, donc aucune décision
     * n'a pu s'appuyer dessus.
     */
    public function lireVersion(string $code, int $numero, ?string $paysCode = null): array
    {
        $paysCode ??= config('referentiels.pays_defaut');
        $referentiel = $this->registre($code, $paysCode);

        $version = $referentiel->versions()
            ->where('numero', $numero)
            ->whereIn('statut', [ReferentielVersion::PUBLIEE, ReferentielVersion::ARCHIVEE])
            ->first();

        if ($version === null) {
            throw new ReferentielException(
                "La version n°{$numero} de « {$code} » n'existe pas ou n'a jamais été en vigueur.",
                404,
            );
        }

        return [
            'code'       => $referentiel->code,
            'pays_code'  => $referentiel->pays_code,
            'libelle'    => $referentiel->libelle,
            'version'    => $version->numero,
            'statut'     => $version->statut,
            'publiee_le' => $version->decide_le?->toIso8601String(),
            'nb_entrees' => $version->nb_entrees,
            'empreinte'  => $version->empreinte,
            'contenu'    => $version->contenu_json,
        ];
    }

    /**
     * L'ESTAMPILLE à apposer sur une décision clinique ou financière (§10).
     *
     * Ce que P6.3 livre : le moyen de dire « cette décision s'appuie sur seuils_mesure v7 ».
     * Ce que P6.3 ne fait PAS : l'apposer sur les décisions existantes. Brancher `triages` ou
     * `ordonnances` reviendrait à modifier des modules validés G5, et estamper rétroactivement les
     * décisions passées serait pire — elles n'ont EU aucune version, et leur en inventer une serait
     * un mensonge d'archive. Une décision antérieure au socle le dira, plutôt que de prétendre.
     *
     * @return array{referentiel: string, pays_code: string, version: int}|null
     */
    public function estampille(string $code, ?string $paysCode = null): ?array
    {
        $paysCode ??= config('referentiels.pays_defaut');
        $referentiel = Referentiel::query()
            ->where('code', $code)->where('pays_code', $paysCode)->first();

        if ($referentiel === null || ! $referentiel->estPublie()) {
            return null;
        }

        return [
            'referentiel' => $referentiel->code,
            'pays_code'   => $referentiel->pays_code,
            'version'     => $referentiel->version_publiee_numero,
        ];
    }

    private function registre(string $code, string $paysCode): Referentiel
    {
        $referentiel = Referentiel::query()
            ->where('code', $code)->where('pays_code', $paysCode)->first();

        if ($referentiel === null) {
            throw new ReferentielException(
                "Le référentiel « {$code} » n'est pas enregistré pour le pays « {$paysCode} ».",
                404,
            );
        }

        return $referentiel;
    }

    private function cle(string $code, string $paysCode, int $numero): string
    {
        return "referentiel:{$paysCode}:{$code}:v{$numero}";
    }
}
