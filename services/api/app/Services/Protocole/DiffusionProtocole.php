<?php

namespace App\Services\Protocole;

use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use Illuminate\Support\Facades\Cache;

/**
 * P10b-1 — Diffusion en lecture des protocoles publiés (CDC_08 §11, §6.2).
 *
 * ═══ L'INVALIDATION DE CACHE N'EXISTE PAS ICI, ET C'EST VOULU ═══
 *
 * Le §6.2 demande que la publication d'une version déclenche « l'invalidation des caches ». La clé
 * porte le numéro de la version : `protocole:CI:TRIAGE-NIVEAU:v3`. Publier la v4 fait lire
 * `…:v4`, absente du cache : le nouveau contenu est servi immédiatement, **sans qu'aucune ligne de
 * code n'ait à supprimer quoi que ce soit**. L'entrée `v3` expire seule et reste correcte tant
 * qu'elle vit — c'est l'instantané immuable de la version 3.
 *
 * Motif D4 de P6.3, et l'argument vaut ici aussi : *un cache qu'on n'a pas à invalider est un cache
 * qu'on ne peut pas oublier d'invalider*. Il rend en prime le mécanisme indépendant du store —
 * passer à Redis (§11) est un changement de configuration, zéro ligne de code.
 *
 * ═══ SEULES LES VERSIONS `actif` SONT DIFFUSÉES — C'EST LE §1.6, EN COMPORTEMENT ═══
 *
 * « Aucun protocole utilisable sans validation ». Ce service ne sait pas lire un brouillon : les
 * protocoles thérapeutiques seedés en brouillon (décision G1 N3) ne sont donc appliqués nulle part,
 * **par construction et non par intention**. Retirer la garde ne rendrait pas le moteur permissif,
 * il le rendrait incapable de trouver quoi que ce soit.
 *
 * ═══ PAS DE REPLI SUR LES TABLES DE TRAVAIL ═══
 *
 * Si aucune version n'est active, ce service le DIT. Il ne lit jamais `protocole_regles` en
 * direct : un repli laisserait un oubli de publication passer inaperçu, et la garantie serait
 * inactive sans que personne ne le sache (décision de L1+L2, reprise en P10a).
 */
final class DiffusionProtocole
{
    /** Une heure. Le TTL n'a pas d'effet sur la fraîcheur : la clé porte déjà la version. */
    private const TTL = 3600;

    /**
     * Le contenu en vigueur d'un protocole.
     *
     * @return array{code: string, pays_code: string, titre: string, version: string, numero: int,
     *               publie_le: ?string, empreinte: string, contenu: array<string, mixed>}
     *
     * @throws ProtocoleException si le protocole est inconnu ou n'a aucune version active.
     */
    public function lire(string $code, ?string $paysCode = null): array
    {
        $paysCode ??= config('referentiels.pays_defaut', 'CI');

        $protocole = Protocole::query()
            ->where('pays_code', $paysCode)
            ->where('code', $code)
            ->first();

        if ($protocole === null) {
            throw new ProtocoleException(
                "Aucun protocole « {$code} » au registre du pays « {$paysCode} ».",
                404,
            );
        }

        $numero = ProtocoleVersion::query()
            ->where('protocole_id', $protocole->id)
            ->where('etat', ProtocoleVersion::ACTIF)
            ->value('numero');

        if ($numero === null) {
            throw new ProtocoleException(
                "Le protocole « {$code} » n'a aucune version en vigueur : il a peut-être été rédigé, "
                .'mais aucune version n\'a franchi les quatre validations du §7. Rien ne peut être '
                .'appliqué tant qu\'une version n\'a pas été publiée (CDC_08 §1.6).',
                404,
            );
        }

        $version = Cache::remember(
            $this->cle($code, $paysCode, (int) $numero),
            self::TTL,
            function () use ($protocole, $numero): array {
                $v = $protocole->versions()->where('numero', $numero)->firstOrFail();

                return [
                    'libelle'   => $v->libelle,
                    'numero'    => (int) $v->numero,
                    'publie_le' => $v->publie_le?->toIso8601String(),
                    'empreinte' => $v->empreinte,
                    'contenu'   => $v->contenu_json,
                ];
            },
        );

        return [
            'code'      => $protocole->code,
            'pays_code' => $protocole->pays_code,
            'titre'     => $protocole->titre,
            'version'   => $version['libelle'],
            'numero'    => $version['numero'],
            'publie_le' => $version['publie_le'],
            'empreinte' => $version['empreinte'],
            'contenu'   => $version['contenu'],
        ];
    }

    /**
     * L'instantané d'une version PRÉCISE, active ou archivée — le chemin qui rend une décision
     * passée explicable (§6.1 « chaque décision conserve la version exacte du protocole utilisée »,
     * exigence médico-légale).
     *
     * Un brouillon n'est jamais servi ici : il n'a jamais été en vigueur, donc aucune décision n'a
     * pu s'appuyer dessus.
     */
    public function lireVersion(string $code, int $numero, ?string $paysCode = null): array
    {
        $paysCode ??= config('referentiels.pays_defaut', 'CI');

        $protocole = Protocole::query()
            ->where('pays_code', $paysCode)
            ->where('code', $code)
            ->first();

        if ($protocole === null) {
            throw new ProtocoleException("Aucun protocole « {$code} » au registre.", 404);
        }

        $version = $protocole->versions()
            ->where('numero', $numero)
            ->whereIn('etat', [ProtocoleVersion::ACTIF, ProtocoleVersion::ARCHIVE])
            ->first();

        if ($version === null) {
            throw new ProtocoleException(
                "La version n°{$numero} de « {$code} » n'existe pas ou n'a jamais été en vigueur.",
                404,
            );
        }

        return [
            'code'      => $protocole->code,
            'pays_code' => $protocole->pays_code,
            'titre'     => $protocole->titre,
            'version'   => $version->libelle,
            'numero'    => (int) $version->numero,
            'etat'      => $version->etat,
            'publie_le' => $version->publie_le?->toIso8601String(),
            'empreinte' => $version->empreinte,
            'contenu'   => $version->contenu_json,
        ];
    }

    /**
     * Une version est-elle en vigueur ?
     *
     * NE REPLIE PAS ET NE LÈVE PAS — c'est la vérité brute, destinée à ce qu'un écran
     * d'exploitation puisse dire honnêtement que le protocole est hors service (motif
     * `ServiceNumerosUrgence::estEnVigueur()` en P6.8e et `ServiceSymptomesTriage` en P10a).
     */
    public function estEnVigueur(string $code, ?string $paysCode = null): bool
    {
        $paysCode ??= config('referentiels.pays_defaut', 'CI');

        return ProtocoleVersion::query()
            ->where('etat', ProtocoleVersion::ACTIF)
            ->whereHas('protocole', fn ($q) => $q
                ->where('pays_code', $paysCode)
                ->where('code', $code))
            ->exists();
    }

    private function cle(string $code, string $paysCode, int $numero): string
    {
        return "protocole:{$paysCode}:{$code}:v{$numero}";
    }
}
