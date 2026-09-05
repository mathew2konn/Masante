<?php

namespace App\Models;

use App\Services\Analyse\ServiceCircuitPrelevement;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * La traçabilité du laborantin (B5-b, L13) — APPEND-ONLY, comme `traces_dispensation` (B3-c) et
 * `protocole_applications` (P10b-2).
 *
 * ═══ POURQUOI CETTE TABLE, ET PAS `acces_dossier` ═══
 *
 * Le propriétaire demande le même NIVEAU D'EXIGENCE que le journal du médecin — pas le même
 * MÉCANISME : `acces_dossier` journalise l'ouverture d'une fenêtre sur un dossier, et L3 pose
 * qu'un laboratoire n'en ouvre AUCUNE. Vérifié plutôt que supposé : `ServiceFicheParcours` apparie
 * les lignes d'`acces_dossier` sur `duree_minutes !== null` — une ligne isolée s'y afficherait
 * « consultation non clôturée », une phrase fausse.
 *
 * Ce journal trace CHAQUE acte du circuit, y compris ceux qui ne touchent aucun carnet (la simple
 * consultation d'une demande par jeton). Identifiants SANS clé étrangère (ADR-042 D1) :
 * `demande_id`, `prelevement_id`, `acteur_user_id` — un compte ou une demande supprimés ne doivent
 * pas effacer ni modifier ce que ce journal a déjà enregistré.
 *
 * **Aucune valeur clinique** : quel acte, sur quelle demande/quel prélèvement, par qui, quand.
 */
class JournalLaboratoire extends Model
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected $table = 'journal_laboratoire';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cree_le' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException(
                'Une entrée du journal du laboratoire ne se modifie pas : append-only.'
            );
        });

        static::deleting(static function (): void {
            throw new RuntimeException(
                'Une entrée du journal du laboratoire ne s\'efface pas : append-only.'
            );
        });
    }

    /**
     * Écrit une entrée. Point d'accroche unique — {@see ServiceCircuitPrelevement}
     * ne construit jamais le modèle directement, pour qu'un seul endroit décide de la forme d'une
     * ligne de ce journal.
     */
    public static function consigner(
        string $action,
        ?int $demandeId,
        ?int $prelevementId,
        ?int $acteurUserId,
        string $acteurNom,
        ?int $laboratoireStructureId,
    ): self {
        return self::create([
            'action' => $action,
            'demande_id' => $demandeId,
            'prelevement_id' => $prelevementId,
            'acteur_user_id' => $acteurUserId,
            'acteur_nom' => $acteurNom,
            'laboratoire_structure_id' => $laboratoireStructureId,
        ]);
    }
}
