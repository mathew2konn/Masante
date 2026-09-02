<?php

namespace App\Services\Triage;

use App\Models\RetourCliniqueTriage;
use App\Services\Audit\ChaineAudit;
use App\Services\Audit\JournalChaine;
use App\Services\Referentiel\EmpreinteReferentiel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * P10c-3-ii (F32→F34) — La chaîne des précisions cliniques d'un retour (§5.5.4 ; CDC_10).
 *
 * ═══ UNE CHAÎNE SÉPARÉE DE CELLE DU §10, ET C'EST LA RÈGLE DE CE PROJET ═══
 *
 * « Mêler deux journaux lierait la validité de l'un à celle de l'autre, et une altération ferait
 * crier les deux sans qu'on puisse dire lequel a bougé » (P10b-1, repris en b-2). Ici la raison est
 * doublée d'une contrainte mécanique : ajouter ces faits à la charge hachée de
 * `protocole_applications` recalculerait l'empreinte de toutes ses entrées existantes.
 *
 * ═══ `soignant_nom` ENTRE DANS L'EMPREINTE ═══
 *
 * Leçon de P6.3, payée deux fois : sans le nom, réécrire « Dr X » en « Système » ne romprait pas la
 * chaîne — or c'est ce nom qu'un humain lit dans un audit, et c'est un diagnostic qu'il signe ici.
 *
 * ═══ CE QUI EST FIGÉ, ET CE QUI NE L'EST PAS ═══
 *
 * Codes ET libellés du diagnostic et de la spécialité entrent dans l'empreinte. Le référentiel peut
 * corriger un libellé demain sans que ce qu'un médecin a consigné ne change — et une modification
 * de la ligne, elle, se voit.
 */
final class JournalRetourClinique implements JournalChaine
{
    public function nomJournal(): string
    {
        return 'retours_cliniques_triage';
    }

    public function requete(): Builder
    {
        return RetourCliniqueTriage::query();
    }

    /**
     * La charge hachée d'une précision relue — identique, clé pour clé, à celle de l'écriture.
     *
     * @return array<string, mixed>
     */
    public function charge(object $entree): array
    {
        return [
            'application_id' => self::entierOuNull($entree->application_id),
            'triage_id' => self::entierOuNull($entree->triage_id),
            'soignant_id' => self::entierOuNull($entree->soignant_id),
            'soignant_nom' => $entree->soignant_nom,
            'niveau_reel' => $entree->niveau_reel,
            'maladie' => $entree->maladie_code === null ? null : [
                'code' => $entree->maladie_code,
                'libelle' => $entree->maladie_libelle,
            ],
            'specialite' => $entree->specialite_code === null ? null : [
                'code' => $entree->specialite_code,
                'libelle' => $entree->specialite_libelle,
            ],
            'cree_le' => $entree->cree_le->toIso8601String(),
        ];
    }

    /**
     * Inscrit les précisions d'un retour. À appeler DANS la transaction du retour lui-même.
     *
     * Le retour §10 et sa précision sont **un seul acte** : les écrire dans deux transactions
     * laisserait exister un verdict sans son diagnostic, ou l'inverse.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function inscrire(array $donnees): RetourCliniqueTriage
    {
        $chaine = ChaineAudit::numeroCourant($this->nomJournal(), $this->requete());

        $precedent = RetourCliniqueTriage::query()
            ->where('chaine', $chaine)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $horodatage = Carbon::now();

        $charge = [
            'application_id' => self::entierOuNull($donnees['application_id']),
            'triage_id' => self::entierOuNull($donnees['triage_id']),
            'soignant_id' => self::entierOuNull($donnees['soignant_id'] ?? null),
            'soignant_nom' => $donnees['soignant_nom'],
            'niveau_reel' => $donnees['niveau_reel'] ?? null,
            'maladie' => ($donnees['maladie_code'] ?? null) === null ? null : [
                'code' => $donnees['maladie_code'],
                'libelle' => $donnees['maladie_libelle'] ?? null,
            ],
            'specialite' => ($donnees['specialite_code'] ?? null) === null ? null : [
                'code' => $donnees['specialite_code'],
                'libelle' => $donnees['specialite_libelle'] ?? null,
            ],
            'cree_le' => $horodatage->toIso8601String(),
        ];

        $retour = RetourCliniqueTriage::create($donnees + [
            'chaine' => $chaine,
            'cree_le' => $horodatage,
            'empreinte_precedente' => $precedent?->empreinte,
            'empreinte' => EmpreinteReferentiel::duMaillon($precedent?->empreinte, $charge),
        ]);

        if ($precedent === null) {
            ChaineAudit::ancrer($this->nomJournal(), $chaine, $retour->empreinte);
        }

        return $retour;
    }

    /** @return array{intacte: bool, entrees: int, rupture: ?array<string, mixed>} */
    public function verifierChaine(): array
    {
        return ChaineAudit::verifier($this);
    }

    private static function entierOuNull(mixed $valeur): ?int
    {
        return $valeur === null || $valeur === '' ? null : (int) $valeur;
    }
}
