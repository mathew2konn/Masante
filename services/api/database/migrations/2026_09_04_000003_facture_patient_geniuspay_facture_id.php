<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B4-b — écart trouvé EN LISANT LE CODE JAVA, avant le G2 (pas pendant).
 *
 * Le G1 (`plan.md` PLAN 3 §9) prévoyait un `factureId` envoyé à Java purement OPAQUE, dérivé d'un
 * hachage de l'id Laravel, jamais résolu contre une vraie `Facture` du domaine facturation Java
 * (P5.2a) — au motif que `ServiceGeniusPay::executer()` ne le résout contre aucun dépôt à l'OUVERTURE
 * du checkout. **C'est exact, mais incomplet** : `ServiceWebhookGeniusPay::appliquer()`, sur un
 * succès, appelle INCONDITIONNELLEMENT `ServiceFacturation::enregistrerReglement(factureId, …)` dès
 * que `factureId` n'est pas nul — et cette méthode fait `trouver(factureId)`, qui **lève** si aucune
 * `Facture` ne porte cet id. L'appel vit dans la MÊME transaction `@Transactional` que
 * `paiement.setStatut(SUCCESS, …)` : une exception y fait tout annuler, y compris la transition —
 * aucune notification ne serait jamais partie vers Laravel.
 *
 * Le `factureId` envoyé à Java DOIT donc être une vraie `Facture` Java, créée par
 * `POST /api/v1/invoices` (une ligne, TVA 0 % — la simulation RDV n'en a jamais porté). Cette
 * colonne stocke son id pour que retaper « Payer en ligne » réutilise la MÊME facture Java, comme
 * elle réutilise déjà la même `FacturePatient` (S12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures_patient', function (Blueprint $table) {
            $table->string('facture_geniuspay_id', 36)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('factures_patient', function (Blueprint $table) {
            $table->dropColumn('facture_geniuspay_id');
        });
    }
};
