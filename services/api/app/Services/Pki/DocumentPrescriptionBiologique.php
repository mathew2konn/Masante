<?php

namespace App\Services\Pki;

use App\Models\DemandeAnalyse;
use Illuminate\Database\Eloquent\Model;

/**
 * B5-a — la demande d'examen électronique, TROISIÈME entité branchée du registre (L8, K2).
 *
 * `RegistreDocumentsSignables::NON_BRANCHES['prescription_biologique']` disait depuis P6.5b que
 * cette entité n'existait pas, et que « sans le catalogue national des analyses (étape 7), elle
 * prescrirait des examens en texte libre ». L'étape 7 est faite (P6.7) : la condition posée par le
 * code lui-même est remplie, et brancher un type reste ce que le registre annonce — une classe et
 * une ligne.
 */
final class DocumentPrescriptionBiologique implements DocumentSignable
{
    public const CODE = 'prescription_biologique';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Demande d\'examen';
    }

    public function trouver(int $id): ?Model
    {
        return DemandeAnalyse::find($id);
    }

    /**
     * Ce qui est signé d'une demande d'examen.
     *
     * ═══ CE QUI ENTRE ═══
     *
     * Le patient, le prescripteur tel qu'il est écrit, la structure, la date, et les examens
     * demandés EN CLAIR (code national figé quand il y en a un). Tout ce dont la modification
     * changerait le sens de la prescription.
     *
     * ═══ CE QUI N'ENTRE PAS, ET C'EST LE POINT LE PLUS SENSIBLE (leçon B2-c) ═══
     *
     * · `consultation_id` — un rattachement, comme `triage_id` sur `DocumentOrdonnance`.
     * · `jeton_partage` — le secret d'accès, jamais une donnée du contenu.
     * · `statut` — l'ÉTAT DU CIRCUIT (émise, servie, annulée). Un prélèvement enregistré en B5-b
     *   fera passer `statut` de `emise` à `servie` : l'inclure ferait passer TOUTE demande pour
     *   altérée au premier acte du laboratoire, alors que rien de ce que le médecin a prescrit
     *   n'a changé. *Une signature qui casse toute seule ne prouve plus rien, et pire, elle
     *   accuse* (P6.5b).
     * · `source`/`added_by` — réécrits par le serveur, disent d'où vient la ligne, pas ce que le
     *   médecin a demandé.
     *
     * `analyses_json` est un cast `encrypted:array` : Eloquent le déchiffre à la lecture, ce
     * tableau est donc le CLAIR — même mécanisme que `DocumentOrdonnance::contenuCanonique()`.
     *
     * @param  DemandeAnalyse  $document
     * @return array<string, mixed>
     */
    public function contenuCanonique(Model $document): array
    {
        return [
            'type' => self::CODE,
            'membre_id' => $document->membre_id,
            'medecin_nom' => $document->medecin_nom,
            'structure_sanitaire' => $document->structure_sanitaire,
            'date_demande' => $document->date_demande?->toDateString(),
            'renseignements_cliniques' => $document->renseignements_cliniques,
            'analyses' => $document->analyses_json,
        ];
    }
}
