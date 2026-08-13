<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une signature électronique posée sur un document (CDC_04 §5.2 ; CDC_10 §4.5 ; P6.5b).
 *
 * ═══ CE QUE PORTE UNE LIGNE, ET CE QU'ELLE NE PORTE PAS ═══
 *
 * Elle porte l'EMPREINTE d'une canonicalisation du contenu en clair, la signature RSA, et le
 * contexte tel qu'il était **au moment de signer**. Elle ne porte AUCUN contenu clinique : le
 * document vit dans sa table, et deux copies feraient deux vérités (P6.3, miroir de P7-D0).
 *
 * ═══ POURQUOI LE CONTEXTE EST DÉNORMALISÉ ═══
 *
 * `signataire_numero`, `signataire_nom` et `signataire_etablissement` sont recopiés à l'écriture.
 * Les relire depuis la fiche des mois plus tard donnerait l'état d'AUJOURD'HUI — un praticien muté
 * ferait changer d'hôpital toutes ses prescriptions passées. C'est le motif de l'établissement
 * copié sur `acces_dossier` en P7-D2, et il vaut d'autant plus ici qu'une signature est censée
 * attester d'un instant.
 *
 * ═══ UNE SIGNATURE NE SE MODIFIE PAS ═══
 *
 * Rien n'est `$fillable` pour la mise à jour côté métier : le service la crée, personne ne la
 * retouche. Une signature corrigée ne serait plus une signature.
 */
class SignatureElectronique extends Model
{
    protected $table = 'signatures_electroniques';

    protected $fillable = [
        'type_document',
        'document_id',
        'certificat_id',
        'medecin_id',
        'empreinte_contenu',
        'signature',
        'algorithme',
        'signataire_numero',
        'signataire_nom',
        'signataire_etablissement',
        'signe_le',
    ];

    protected function casts(): array
    {
        return [
            'signe_le'    => 'datetime',
            'document_id' => 'integer',
        ];
    }

    public function certificat(): BelongsTo
    {
        return $this->belongsTo(CertificatNumerique::class, 'certificat_id');
    }

    public function professionnel(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }
}
