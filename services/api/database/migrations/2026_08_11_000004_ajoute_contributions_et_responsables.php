<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carnet familial partagé / incrément C — contributions au brouillon et responsables de famille.
 *
 * LE SCÉNARIO : les parents sont en voyage, un enfant est malade, la personne restée à la maison
 * l'emmène à l'hôpital. Elle doit pouvoir écrire — elle ne peut pas attendre le retour des
 * parents. Ce qu'elle écrit part au BROUILLON ; un responsable le valide après vérification.
 *
 * UNE SEULE TABLE POUR LE BROUILLON, PAS HUIT. Poser un statut sur chaque section du carnet
 * (`antecedents`, `vaccinations`, `ordonnances`, `resultats_analyses`, `rappels`…) obligerait à
 * modifier autant de tables de modules validés G5. `contributions` porte la proposition en JSON ;
 * à la validation, l'entrée est écrite dans la vraie table par le chemin existant. Aucune table du
 * carnet n'est touchée.
 *
 * CE QUI NE PASSE JAMAIS PAR ICI : les écritures du MÉDECIN (QR, référent, bris de glace —
 * `source = medecin|structure`). Une ordonnance ne peut pas attendre l'accord d'un parent en
 * voyage. Le brouillon encadre la contribution FAMILIALE auto-déclarée, pas l'acte médical.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Responsables de famille — qui peut valider une contribution.
         *
         * Le propriétaire d'un carnet est responsable de droit (aucune ligne nécessaire). Il peut
         * DÉSIGNER un second responsable. Toutes les familles n'ont pas un père et une mère : la
         * règle est « le propriétaire décide », jamais « les parents ».
         */
        Schema::create('responsables_famille', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titulaire_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('responsable_user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('designe_le');
            $table->timestamp('revoque_le')->nullable(); // NULL = actif

            $table->timestamps();

            // Une seule désignation vivante par couple ; une ligne révoquée est réarmée.
            $table->unique(['titulaire_user_id', 'responsable_user_id'], 'uq_responsable_par_titulaire');
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            // L'auteur reste nommé même si son compte disparaît ? Non : `nullOnDelete` garde la
            // contribution lisible sans inventer un auteur. Le nom réel vit dans `users`.
            $table->foreignId('auteur_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Section visée — valeur d'une liste blanche fermée (RegistreSectionsCarnet).
            $table->string('section', 40);

            // La proposition, déjà validée par les règles de la section au moment du dépôt.
            $table->json('donnees');

            $table->enum('statut', ['BROUILLON', 'VALIDEE', 'REJETEE'])->default('BROUILLON');

            $table->foreignId('decide_par_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->string('motif_rejet', 500)->nullable();

            // Ligne réellement créée à la validation — le lien entre la proposition et le fait.
            $table->unsignedBigInteger('entree_id')->nullable();

            $table->timestamps();

            $table->index(['membre_id', 'statut']);
            $table->index(['auteur_user_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('responsables_famille');
    }
};
