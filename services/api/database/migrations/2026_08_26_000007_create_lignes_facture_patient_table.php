<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation patient — détail des actes facturés (lot B, migration 7/8).
 *
 * ⚠️ `libelle_acte` ET `code_acte_national` SONT DES DONNÉES MÉDICALES. « Consultation
 * cardiologie », « Test VIH », « IRM cérébrale » : un libellé d'acte révèle une pathologie, une
 * suspicion, parfois une situation intime. Ces deux colonnes ne quittent JAMAIS la couche
 * authentifiée — ni notification (elles s'affichent sur un écran verrouillé), ni log applicatif,
 * ni message d'erreur HTTP, ni requête sortante vers une passerelle de paiement (règle R14).
 * Le libellé envoyé au prestataire de paiement est générique et constant.
 *
 * `cascadeOnDelete` ici, et c'est la SEULE exception du lot au `restrictOnDelete` : une ligne n'a
 * aucune existence hors de sa facture, elle n'est pas un fait financier autonome. Le garde-fou
 * d'immuabilité vit un cran au-dessus — une facture PAYEE refuse toute modification, donc ses
 * lignes ne peuvent plus être détruites par ce chemin.
 *
 * `taux_cmu_bps` en points de base entiers (voir `baremes_commission`). `montant_ligne` est stocké
 * plutôt que recalculé : le prix unitaire d'un acte peut changer au catalogue, une facture émise
 * ne change jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_facture_patient', function (Blueprint $table) {
            $table->id();

            $table->foreignId('facture_patient_id')->constrained('factures_patient')->cascadeOnDelete();

            // ⚠️ Données médicales — voir en-tête.
            $table->string('libelle_acte');
            $table->string('code_acte_national')->nullable();

            $table->unsignedInteger('quantite')->default(1);
            $table->unsignedBigInteger('prix_unitaire');       // FCFA entiers
            $table->unsignedSmallInteger('taux_cmu_bps')->default(0);
            $table->unsignedBigInteger('montant_ligne');       // figé à l'émission

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_facture_patient');
    }
};
