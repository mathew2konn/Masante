<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1-a — Le workflow RDV à deux étapes (CDC_11 §9.1 : « le médecin fait la validation finale »).
 *
 * `rendez_vous.statut` gagne `prevalide`, ENUM ÉLARGI JAMAIS RÉÉCRIT (précédent P6.4a/P10b-1) :
 * les RDV déjà `confirme`/`refuse`/`annule`/`honore` ne bougent pas, seul le défaut change.
 *
 * Le tarif se déplace vers `services_etablissement` (D3 du plan G1) : jusqu'ici porté par le
 * médecin, avec repli sur le plancher tarifaire de la structure (`RecuRdvService::montantPour()`).
 * Le repli RESTE — un refus bruyant casserait tous les établissements dont le service n'a pas
 * encore de tarif configuré — mais la source est désormais TRACÉE sur la facture
 * (`factures_patient.tarif_source`) : précédent `delai_source` (P6.7b), `provenance` (P6.8d) —
 * un montant ne doit jamais mentir sur d'où il vient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->enum('statut', ['en_attente', 'prevalide', 'confirme', 'refuse', 'annule', 'honore'])
                ->default('en_attente')
                ->change();
        });

        Schema::table('services_etablissement', function (Blueprint $table) {
            $table->unsignedInteger('tarif_consultation_cfa')->nullable()->after('specialite_id');
        });

        Schema::table('factures_patient', function (Blueprint $table) {
            $table->enum('tarif_source', ['service', 'medecin', 'structure'])->nullable()->after('montant_brut');
        });
    }

    public function down(): void
    {
        Schema::table('factures_patient', function (Blueprint $table) {
            $table->dropColumn('tarif_source');
        });

        Schema::table('services_etablissement', function (Blueprint $table) {
            $table->dropColumn('tarif_consultation_cfa');
        });

        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->enum('statut', ['en_attente', 'confirme', 'refuse', 'annule', 'honore'])
                ->default('en_attente')
                ->change();
        });
    }
};
