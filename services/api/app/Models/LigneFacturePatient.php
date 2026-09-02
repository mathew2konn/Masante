<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Détail des actes portés par une facture patient.
 *
 * ⚠️ `libelle_acte` ET `code_acte_national` SONT DES DONNÉES MÉDICALES.
 * « Consultation cardiologie », « Test VIH », « IRM cérébrale » : un libellé d'acte révèle une
 * pathologie, une suspicion, parfois une situation intime. Ces deux attributs ne quittent JAMAIS
 * la couche authentifiée — ni notification (elles s'afficheraient sur un écran verrouillé), ni log
 * applicatif, ni message d'erreur HTTP, ni requête sortante vers une passerelle de paiement
 * (règle R14). Le libellé transmis à un prestataire de paiement est générique et constant.
 *
 * Ils ne sont volontairement PAS placés dans `$hidden` : les masquer par défaut donnerait
 * l'illusion d'une protection là où il n'y en a pas — `$hidden` ne s'applique qu'à la
 * sérialisation, et n'empêche ni un log, ni une concaténation dans un message. La garantie est
 * dans les appelants, et c'est pourquoi elle est écrite ici en toutes lettres.
 *
 * `montant_ligne` est STOCKÉ plutôt que recalculé : le prix d'un acte peut changer au catalogue,
 * une facture émise ne change jamais.
 *
 * `cascadeOnDelete` côté migration, et c'est la seule exception du lot : une ligne n'a pas
 * d'existence hors de sa facture. Le garde-fou vit un cran au-dessus — une facture PAYEE refuse
 * toute modification, donc ses lignes ne peuvent plus disparaître par ce chemin.
 */
class LigneFacturePatient extends Model
{
    protected $table = 'lignes_facture_patient';

    protected $fillable = [
        'facture_patient_id',
        'libelle_acte',
        'code_acte_national',
        'quantite',
        'prix_unitaire',
        'taux_cmu_bps',
        'montant_ligne',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'prix_unitaire' => 'integer',
            'taux_cmu_bps' => 'integer',
            'montant_ligne' => 'integer',
        ];
    }

    /** @return BelongsTo<FacturePatient, $this> */
    public function facturePatient(): BelongsTo
    {
        return $this->belongsTo(FacturePatient::class);
    }
}
