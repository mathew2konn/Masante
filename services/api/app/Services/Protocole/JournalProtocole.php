<?php

namespace App\Services\Protocole;

use App\Models\Protocole;
use App\Models\ProtocoleJournal;
use App\Models\User;
use App\Services\Referentiel\EmpreinteReferentiel;
use Illuminate\Support\Carbon;

/**
 * P10b-1 — La chaîne d'audit de gouvernance des protocoles (CDC_08 §10 ; CDC_10 ; loi 2013-450).
 *
 * Chaque entrée porte l'empreinte de la précédente : supprimer ou modifier une ligne casse le
 * chaînage de tout ce qui suit, et l'altération devient détectable. Motif `JournalReferentiel`
 * (P6.3), lui-même porté de la chaîne `audit_entries` du paiement (P5.1).
 *
 * ═══ POURQUOI UNE CHAÎNE PROPRE, ET NON CELLE DU SOCLE ═══
 *
 * Le socle et le registre des protocoles sont deux systèmes de gouvernance frères mais distincts
 * (décision G1 N2). Mélanger leurs journaux dans une chaîne unique lierait la validité de l'audit
 * des protocoles à celle de l'audit des référentiels : une altération dans l'un ferait crier
 * l'autre, et il deviendrait impossible de dire lequel a réellement bougé.
 *
 * En revanche le CALCUL est le même — `EmpreinteReferentiel` est réutilisé tel quel. Un second
 * algorithme de hachage aurait été deux endroits où « comment on hache » peut diverger.
 *
 * ═══ `acteur_nom` ENTRE DANS L'EMPREINTE ═══
 *
 * Le test d'altération de P6.3 l'a prouvé : sans lui, réécrire le nom d'un agent en « Système » ne
 * rompait pas la chaîne — or c'est ce nom-là qu'un humain lit dans un audit. Le §7 exige d'ailleurs
 * que chaque validation nomme son validateur et son rôle : protéger l'identifiant technique en
 * laissant le nom modifiable protégerait la mauvaise moitié.
 */
final class JournalProtocole
{
    /**
     * Ajoute un maillon à la chaîne. À appeler DANS une transaction déjà ouverte.
     *
     * @param  array<string, mixed>  $details  Empreinte, motif, volumétrie, nom du validateur —
     *                                         JAMAIS le contenu clinique lui-même : celui-ci vit
     *                                         dans l'instantané de la version, et deux copies
     *                                         feraient deux vérités.
     */
    public function inscrire(
        Protocole $protocole,
        string $action,
        ?User $acteur,
        ?int $versionNumero = null,
        array $details = [],
    ): ProtocoleJournal {
        // Le dernier maillon, verrouillé : deux inscriptions simultanées ne peuvent pas partir de
        // la même empreinte précédente et produire deux branches parallèles.
        //
        // ORDRE DES VERROUS : la transaction de gouvernance a déjà verrouillé la ligne `protocoles`
        // concernée. On prend donc toujours protocole PUIS journal, jamais l'inverse — une
        // inversion entre deux transactions concurrentes est la définition d'un interblocage,
        // exactement ce qui avait mordu en P6.1.
        $precedent = ProtocoleJournal::query()
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $horodatage = Carbon::now();

        $charge = [
            'protocole_code' => $protocole->code,
            'pays_code'      => $protocole->pays_code,
            'version_numero' => $versionNumero,
            'action'         => $action,
            'acteur_id'      => $acteur?->id,
            'acteur_nom'     => $acteur?->nomLisible() ?? 'Système',
            'cree_le'        => $horodatage->toIso8601String(),
            'details'        => $details,
        ];

        return ProtocoleJournal::create([
            'protocole_code'       => $protocole->code,
            'pays_code'            => $protocole->pays_code,
            'protocole_id'         => $protocole->id,
            'version_numero'       => $versionNumero,
            'action'               => $action,
            'acteur_id'            => $acteur?->id,
            'acteur_nom'           => $acteur?->nomLisible() ?? 'Système',
            'details_json'         => $details,
            'empreinte_precedente' => $precedent?->empreinte,
            'empreinte'            => EmpreinteReferentiel::duMaillon($precedent?->empreinte, $charge),
            'cree_le'              => $horodatage,
        ]);
    }

    /**
     * Recalcule toute la chaîne et signale la première rupture.
     *
     * Deux ruptures distinguées, parce qu'elles ne disent pas la même chose :
     *  - CHAINAGE : une entrée a été supprimée, ou insérée hors du moteur ;
     *  - CONTENU  : une entrée a été modifiée en base après son écriture.
     *
     * @return array{intacte: bool, entrees: int, rupture: ?array<string, mixed>}
     */
    public function verifierChaine(): array
    {
        $attendue = null;
        $nombre = 0;

        foreach (ProtocoleJournal::query()->orderBy('id')->cursor() as $entree) {
            $nombre++;

            if ($entree->empreinte_precedente !== $attendue) {
                return [
                    'intacte' => false,
                    'entrees' => $nombre,
                    'rupture' => [
                        'id'      => $entree->id,
                        'type'    => 'CHAINAGE',
                        'message' => "L'entrée #{$entree->id} ne s'enchaîne pas au maillon précédent "
                            .'(entrée supprimée ou insérée hors du moteur).',
                    ],
                ];
            }

            $recalculee = EmpreinteReferentiel::duMaillon($entree->empreinte_precedente, [
                'protocole_code' => $entree->protocole_code,
                'pays_code'      => $entree->pays_code,
                'version_numero' => $entree->version_numero,
                'action'         => $entree->action,
                'acteur_id'      => $entree->acteur_id,
                'acteur_nom'     => $entree->acteur_nom,
                'cree_le'        => $entree->cree_le->toIso8601String(),
                'details'        => $entree->details_json ?? [],
            ]);

            if (! hash_equals($entree->empreinte, $recalculee)) {
                return [
                    'intacte' => false,
                    'entrees' => $nombre,
                    'rupture' => [
                        'id'      => $entree->id,
                        'type'    => 'CONTENU',
                        'message' => "L'entrée #{$entree->id} a été modifiée après son écriture.",
                    ],
                ];
            }

            $attendue = $entree->empreinte;
        }

        return ['intacte' => true, 'entrees' => $nombre, 'rupture' => null];
    }
}
