<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1-b — Fiche RDV enrichie (D5/D6) : photo du médecin, orientation libre du patient.
 *
 * `medecins` gagne trois colonnes NULLABLES pour une photo UNIQUE (patron allégé de
 * `EtablissementImage`/P6.4c : pas de table séparée, pas de quota — un praticien n'a qu'une
 * photo, pas une galerie). HORS PROJECTION GOUVERNÉE (P6.5a) : même famille que biographie/tarif,
 * n'engage aucune autorité nationale.
 *
 * `rendez_vous` gagne `motif_orientation`/`message_orientation` : texte libre, facultatif, que le
 * patient peut joindre à la réservation — DISTINCT du médecin référent (table `referents`,
 * inchangée) et sans aucune conséquence sur le workflow ou le tarif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medecins', function (Blueprint $table) {
            $table->uuid('photo_uuid')->nullable()->after('tarif_consultation');
            $table->string('photo_mime', 20)->nullable()->after('photo_uuid');
            $table->string('photo_empreinte_sha256', 64)->nullable()->after('photo_mime');
        });

        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->string('motif_orientation', 150)->nullable()->after('triage_id');
            $table->string('message_orientation', 1000)->nullable()->after('motif_orientation');
        });
    }

    public function down(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropColumn(['motif_orientation', 'message_orientation']);
        });

        Schema::table('medecins', function (Blueprint $table) {
            $table->dropColumn(['photo_uuid', 'photo_mime', 'photo_empreinte_sha256']);
        });
    }
};
