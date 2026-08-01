<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 / 5.6 — Désignation du médecin référent (voie 2 ; Sécurité §4.4, Note_Continuite §2).
 *
 * ÉCART ASSUMÉ AU CdC. Le CdC §8.1 range le référent dans une simple colonne
 * `membres_famille.medecin_referent_id`. Une colonne ne sait pas dire QUI a désigné, QUAND, ni
 * garder trace d'une révocation — or §4.4 exige « désigné explicitement par le patient et révocable
 * à tout moment », et la loi n°2013-450 impose la traçabilité. On stocke donc la désignation dans
 * une table dédiée, calquée sur `delegations` (voie 3) : même vocabulaire, même cycle de vie.
 *
 * La règle « un seul référent actif par membre » (que la colonne du CdC imposait de fait) est
 * appliquée côté applicatif ({@see App\Services\ReferentService}) : MySQL ne sait pas poser un
 * index unique partiel « ... WHERE revoquee_at IS NULL », et les NULL échappent à l'unicité.
 * Les désignations révoquées restent en base : elles forment l'historique consultable par le patient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();
            // Fiche de l'annuaire public (Module 3 / F3.5), pas un compte : c'est ce que le patient voit.
            $table->foreignId('medecin_id')->constrained('medecins')->cascadeOnDelete();
            // Qui a désigné : le titulaire du carnet (jamais le membre, qui n'a pas de compte).
            $table->foreignId('designe_par_user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('designe_at');
            $table->timestamp('revoquee_at')->nullable();   // NULL = désignation active

            $table->timestamps();

            // Recherche du référent actif d'un membre, et des patients suivis par un médecin.
            $table->index(['membre_id', 'revoquee_at']);
            $table->index(['medecin_id', 'revoquee_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referents');
    }
};
