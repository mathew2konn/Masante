<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / étape 2A.4 — Table `ordonnances` (CdC §8.3, F2.5).
 *
 * `medicaments_json` (liste des médicaments) est chiffré AES-256 (cast `encrypted:array`,
 * §6 Sécurité) → colonne `text`. `triage_id` relie une ordonnance au triage à l'origine
 * (nullable, FK posée maintenant que `triages` et le lien membre existent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordonnances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();
            $table->foreignId('triage_id')->nullable()->constrained('triages')->nullOnDelete();

            $table->string('medecin_nom', 200);
            $table->string('structure_sanitaire', 200);
            $table->date('date_prescription');
            $table->text('medicaments_json');                  // chiffré AES-256 (JSON)
            $table->string('photo_url', 500)->nullable();
            $table->string('pdf_url', 500)->nullable();
            $table->enum('added_by', ['patient', 'medecin'])->default('patient');

            $table->timestamps();
            $table->index('membre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordonnances');
    }
};
