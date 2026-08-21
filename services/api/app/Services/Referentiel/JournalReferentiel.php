<?php

namespace App\Services\Referentiel;

use App\Models\Referentiel;
use App\Models\ReferentielJournal;
use App\Models\User;
use App\Services\Audit\ChaineAudit;
use App\Services\Audit\JournalChaine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * La chaîne d'audit des référentiels (CDC_09 §11 ; loi 2013-450 sur la traçabilité).
 *
 * Chaque entrée porte l'empreinte de la précédente. Supprimer ou modifier une ligne casse le
 * chaînage de tout ce qui suit : l'altération devient détectable, ce qu'une table d'audit
 * ordinaire ne permet pas.
 *
 * ORDRE DES VERROUS — et c'est important. Une écriture au journal a lieu DANS la transaction de
 * gouvernance, laquelle a déjà verrouillé la ligne `referentiels` concernée. On prend donc les
 * verrous toujours dans le même sens : référentiel, puis journal. Une inversion d'ordre entre deux
 * transactions concurrentes est la définition même d'un interblocage — c'est exactement ce qui
 * avait mordu en P6.1 (contrôle de doublon en verrou partagé, puis `FOR UPDATE` en exclusif).
 * Ici, un seul verrou est pris sur le journal, après celui du référentiel, jamais l'inverse.
 */
final class JournalReferentiel implements JournalChaine
{
    public function nomJournal(): string
    {
        return 'referentiel_journal';
    }

    public function requete(): Builder
    {
        return ReferentielJournal::query();
    }

    /**
     * La charge hachée d'une entrée relue — identique, clé pour clé, à celle de l'écriture.
     *
     * @return array<string, mixed>
     */
    public function charge(object $entree): array
    {
        return [
            'referentiel_code' => $entree->referentiel_code,
            'pays_code' => $entree->pays_code,
            'version_numero' => $entree->version_numero,
            'action' => $entree->action,
            'acteur_id' => $entree->acteur_id,
            'acteur_nom' => $entree->acteur_nom,
            'cree_le' => $entree->cree_le->toIso8601String(),
            'details' => $entree->details_json ?? [],
        ];
    }

    /**
     * Ajoute un maillon à la chaîne. À appeler dans une transaction déjà ouverte.
     *
     * @param  array<string, mixed>  $details  Empreinte, motif, volumétrie — JAMAIS le contenu
     *                                         lui-même : celui-ci vit dans l'instantané de la
     *                                         version, et deux copies feraient deux vérités.
     */
    public function inscrire(
        Referentiel $referentiel,
        string $action,
        ?User $acteur,
        ?int $versionNumero = null,
        array $details = [],
    ): ReferentielJournal {
        // Le dernier maillon, verrouillé : deux inscriptions simultanées ne peuvent pas partir
        // de la même empreinte précédente et produire deux branches parallèles.
        $chaine = ChaineAudit::numeroCourant($this->nomJournal(), $this->requete());

        $precedent = ReferentielJournal::query()
            ->where('chaine', $chaine)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $horodatage = Carbon::now();

        // `acteur_nom` FAIT PARTIE DE L'EMPREINTE, et pas seulement `acteur_id`. Le test de
        // détection d'altération l'a montré : sans lui, on pouvait réécrire « Système » à la place
        // du nom d'un agent sans rompre la chaîne. Or c'est ce nom-là qu'un humain lit dans un
        // audit — le laisser hors du calcul aurait protégé la référence technique en abandonnant
        // la seule information lisible.
        $charge = [
            'referentiel_code' => $referentiel->code,
            'pays_code' => $referentiel->pays_code,
            'version_numero' => $versionNumero,
            'action' => $action,
            'acteur_id' => $acteur?->id,
            'acteur_nom' => $acteur?->nomLisible() ?? 'Système',
            'cree_le' => $horodatage->toIso8601String(),
            'details' => $details,
        ];

        $entree = ReferentielJournal::create([
            'chaine' => $chaine,
            'referentiel_code' => $referentiel->code,
            'pays_code' => $referentiel->pays_code,
            'referentiel_id' => $referentiel->id,
            'version_numero' => $versionNumero,
            'action' => $action,
            'acteur_id' => $acteur?->id,
            // Dénormalisé à l'écriture : le compte peut être supprimé, la trace doit rester lisible.
            // « Système » couvre les écritures automatiques (enregistrement initial, commande).
            'acteur_nom' => $acteur?->nomLisible() ?? 'Système',
            'details_json' => $details,
            'empreinte_precedente' => $precedent?->empreinte,
            'empreinte' => EmpreinteReferentiel::duMaillon($precedent?->empreinte, $charge),
            'cree_le' => $horodatage,
        ]);

        // ═══ L'ANCRAGE DE TÊTE ═══
        //
        // La toute première entrée d'une chaîne fixe son point de départ. Sans lui, vider le
        // journal puis le réalimenter donnerait une chaîne qui repart d'une empreinte précédente
        // nulle et se revérifie « intacte » — le scénario exact constaté sur `referentiel_journal`.
        if ($precedent === null) {
            ChaineAudit::ancrer($this->nomJournal(), $chaine, $entree->empreinte);
        }

        return $entree;
    }

    /**
     * Recalcule toute la chaîne et signale la première rupture.
     *
     * Deux ruptures possibles, distinguées parce qu'elles ne disent pas la même chose :
     *  - CHAINAGE : `empreinte_precedente` ne correspond pas au maillon d'avant → une entrée a été
     *    supprimée, ou insérée hors du moteur ;
     *  - CONTENU  : l'empreinte de l'entrée ne correspond pas à ses propres champs → l'entrée a
     *    été modifiée en base.
     *
     * @return array{intacte: bool, entrees: int, rupture: ?array<string, mixed>}
     */
    public function verifierChaine(): array
    {
        return ChaineAudit::verifier($this);
    }
}
