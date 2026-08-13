<?php

namespace App\Services\Pki;

use App\Models\Ordonnance;
use Illuminate\Database\Eloquent\Model;

/**
 * L'ordonnance électronique — le document que CDC_09 §5.3 nomme explicitement :
 * « **les prescriptions deviennent juridiquement traçables** ».
 *
 * C'est le seul des sept types de CDC_10 §4.5 qui existe aujourd'hui dans cette base ; les cinq
 * manquants sont créés dans un module à part (plan G1, décision P3), la facture est signée par le
 * service de paiement depuis P5.2b. Le registre les nomme tous les sept, avec l'état de chacun.
 */
final class DocumentOrdonnance implements DocumentSignable
{
    public const CODE = 'ordonnance';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Ordonnance électronique';
    }

    public function trouver(int $id): ?Model
    {
        return Ordonnance::find($id);
    }

    /**
     * Ce qui est signé d'une ordonnance.
     *
     * ═══ CE QUI ENTRE ═══
     *
     * Le patient (par son identifiant de dossier), le prescripteur tel qu'il est écrit, la
     * structure, la date, et **la liste des médicaments en clair**. Autrement dit : tout ce dont la
     * modification changerait le sens de la prescription. Changer un dosage doit casser la
     * signature — c'est même la raison d'être de l'opération.
     *
     * ═══ CE QUI N'ENTRE PAS, ET POURQUOI CHAQUE ABSENCE EST UNE DÉCISION ═══
     *
     * · `updated_at` et `created_at` — le patient peut ranger sa fiche, ajouter une photo du
     *   papier, sans que la prescription change. Les inclure ferait casser la signature sur des
     *   gestes qui ne touchent pas au contenu médical.
     * · `photo_url` et `pdf_url` — des pièces jointes, pas la prescription ; et ce sont des chemins
     *   de stockage, dont un UUID régénéré ferait diverger sans que rien n'ait bougé (leçon de
     *   P6.4c, où c'est exactement pour cela que l'empreinte porte le contenu et non le chemin).
     * · `triage_id` — un rattachement de navigation.
     * · `source` et `added_by` — ils disent d'où vient la LIGNE, pas ce que le médecin a prescrit.
     *   Ils sont d'ailleurs réécrits par le serveur (P7-C/D0), donc déjà garantis autrement.
     *
     * `medicaments_json` est un cast `encrypted:array` : Eloquent le déchiffre à la lecture, ce
     * tableau est donc le CLAIR. C'est voulu, et c'est le point central — voir
     * {@see DocumentSignable::contenuCanonique()}.
     *
     * @param  Ordonnance  $document
     * @return array<string, mixed>
     */
    public function contenuCanonique(Model $document): array
    {
        return [
            'type'                => self::CODE,
            'membre_id'           => $document->membre_id,
            'medecin_nom'         => $document->medecin_nom,
            'structure_sanitaire' => $document->structure_sanitaire,
            'date_prescription'   => $document->date_prescription?->toDateString(),
            'medicaments'         => $document->medicaments_json,
        ];
    }
}
