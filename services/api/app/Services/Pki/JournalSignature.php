<?php

namespace App\Services\Pki;

use App\Models\Medecin;
use App\Models\SignatureJournal;
use App\Models\User;
use App\Services\Referentiel\EmpreinteReferentiel;
use Illuminate\Support\Carbon;

/**
 * La chaîne d'audit des signatures (CDC_09 §5.4 ; CDC_10 §6 ; loi 2013-450).
 *
 * ═══ LES REFUS Y SONT AUTANT QUE LES SUCCÈS ═══
 *
 * Le §5.4 l'exige en toutes lettres : « une signature est refusée si l'un de ces contrôles échoue,
 * et **l'échec est journalisé** ». Un journal qui ne garderait que les signatures abouties ne
 * répondrait pas à la question qui compte en litige : « a-t-on tenté de signer avec un certificat
 * révoqué, et combien de fois ? ».
 *
 * ═══ CHAÎNE GLOBALE, PAS UNE PAR PRATICIEN ═══
 *
 * Chaque entrée porte l'empreinte de la précédente ; supprimer ou modifier une ligne casse tout ce
 * qui suit. Une chaîne par praticien laisserait effacer sans trace l'historique complet d'un
 * praticien — exactement le raisonnement de P6.3.
 *
 * `acteur_nom` ENTRE DANS L'EMPREINTE, et pas seulement `acteur_id` : le test d'altération de P6.3
 * l'a prouvé nécessaire — sans lui, réécrire le nom d'un agent en « Système » ne rompait rien, or
 * c'est ce nom-là qu'un humain lit.
 *
 * ═══ CE QUI N'Y ENTRE JAMAIS ═══
 *
 * Aucun contenu clinique. Le journal porte l'EMPREINTE du contenu, jamais les médicaments : il
 * prouve, il ne recopie pas. Deux copies feraient deux vérités (P6.3), et celle-ci serait en clair.
 */
final class JournalSignature
{
    public const SIGNATURE_REUSSIE  = 'signature_reussie';
    public const SIGNATURE_REFUSEE  = 'signature_refusee';
    public const SECRET_INVALIDE    = 'secret_invalide';
    public const CERTIFICAT_EMIS    = 'certificat_emis';
    public const CERTIFICAT_REVOQUE = 'certificat_revoque';

    /**
     * Ajoute un maillon. À appeler dans une transaction déjà ouverte quand il y en a une.
     *
     * @param  array<string, mixed>  $details  empreinte, numéro de série, contrôle en échec —
     *                                        JAMAIS le contenu du document.
     */
    public function inscrire(
        string $action,
        ?User $acteur,
        ?Medecin $professionnel = null,
        ?string $typeDocument = null,
        ?int $documentId = null,
        ?string $motif = null,
        array $details = [],
    ): SignatureJournal {
        // Le dernier maillon, verrouillé : deux inscriptions simultanées ne peuvent pas partir de
        // la même empreinte précédente et produire deux branches parallèles.
        $precedent = SignatureJournal::query()->orderByDesc('id')->lockForUpdate()->first();

        $horodatage = Carbon::now();

        $charge = [
            'action'         => $action,
            'type_document'  => $typeDocument,
            'document_id'    => $documentId,
            'medecin_id'     => $professionnel?->getKey(),
            'acteur_id'      => $acteur?->id,
            'acteur_nom'     => $this->nomLisible($acteur),
            'motif'          => $motif,
            'cree_le'        => $horodatage->toIso8601String(),
            'details'        => $details,
        ];

        return SignatureJournal::create([
            'action'               => $action,
            'type_document'        => $typeDocument,
            'document_id'          => $documentId,
            'medecin_id'           => $professionnel?->getKey(),
            'acteur_id'            => $acteur?->id,
            'acteur_nom'           => $this->nomLisible($acteur),
            'motif'                => $motif,
            'details'              => $details,
            'empreinte'            => EmpreinteReferentiel::duMaillon($precedent?->empreinte, $charge),
            'empreinte_precedente' => $precedent?->empreinte,
            'cree_le'              => $horodatage,
        ]);
    }

    /**
     * La chaîne est-elle intacte ? Recalcule chaque maillon depuis l'origine.
     *
     * Renvoie l'identifiant du PREMIER maillon rompu, ou `null` si tout tient. Le premier, et non
     * la liste : une rupture invalide tout ce qui suit, énumérer la suite noierait le seul fait
     * utile — où l'altération a commencé.
     */
    public function premierMaillonRompu(): ?int
    {
        $precedente = null;

        foreach (SignatureJournal::orderBy('id')->cursor() as $maillon) {
            $charge = [
                'action'        => $maillon->action,
                'type_document' => $maillon->type_document,
                'document_id'   => $maillon->document_id,
                'medecin_id'    => $maillon->medecin_id,
                'acteur_id'     => $maillon->acteur_id,
                'acteur_nom'    => $maillon->acteur_nom,
                'motif'         => $maillon->motif,
                'cree_le'       => $maillon->cree_le->toIso8601String(),
                'details'       => $maillon->details ?? [],
            ];

            if (! hash_equals(EmpreinteReferentiel::duMaillon($precedente, $charge), $maillon->empreinte)) {
                return $maillon->id;
            }

            $precedente = $maillon->empreinte;
        }

        return null;
    }

    /** « Système » quand l'acte n'a pas d'auteur humain (tâche planifiée, commande). */
    private function nomLisible(?User $acteur): string
    {
        if ($acteur === null) {
            return 'Système';
        }

        return trim(($acteur->prenom ?? '').' '.($acteur->nom ?? '')) ?: ($acteur->email ?? 'Compte '.$acteur->id);
    }
}
