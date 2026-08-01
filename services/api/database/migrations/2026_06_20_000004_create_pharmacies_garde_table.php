<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 / étape 3A.1 — Table `pharmacies_garde` (CdC F3.8).
 *
 * Pharmacies de garde du jour / de la nuit. Dans le CdC, la liste est mise à jour
 * quotidiennement par l'Ordre des Pharmaciens CI ; ici elle est seedée pour la démo.
 * On référence une `structures_sanitaires` de type `pharmacie`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacies_garde', function (Blueprint $table) {
            $table->id();

            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();
            $table->date('date');
            $table->enum('periode', ['jour', 'nuit', 'jour_nuit'])->default('jour_nuit');

            $table->timestamps();

            $table->index('date');
            $table->unique(['structure_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacies_garde');
    }
};
