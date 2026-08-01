<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 / F2.10 — Table `documents_medicaux` (import universel sécurisé).
 *
 * Chaque document de soin (certificat, fiche de sortie, compte rendu, imagerie, résultat labo
 * externe, assurance, ordonnance externe, autre) est catégorisé, daté et rattaché à un membre.
 *
 * Robustesse / sécurité (RAPPORT.md §F2.10) :
 *  - `uploaded_by_user_id` : audit de l'auteur de l'import (loi 2013-450), distinct du membre.
 *    nullOnDelete pour conserver le document si le compte uploadeur est supprimé.
 *  - `nom_fichier_original` conservé pour l'affichage / `Content-Disposition` ; le blob est stocké
 *    sous un nom UUID chiffré au repos (anti path-traversal) → chemin dans `fichier_url`.
 *  - `mime_type` = type MIME RÉEL déterminé serveur (finfo), pas l'extension déclarée.
 *  - `hash_sha256` : intégrité + détection de doublon.
 *  - `statut_antivirus` : verrou de téléchargement (un doc non `sain` n'est jamais servi — ClamAV).
 *  - `source` : provenance F2.13. `triage_id` : rattachement à un épisode de triage.
 *  - `softDeletes` : rétention médicale (pas de suppression dure).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents_medicaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membre_id')->constrained('membres_famille')->cascadeOnDelete();

            // Auteur de l'import (audit) : conservé même si le compte uploadeur disparaît.
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('categorie', [
                'certificat_medical',
                'fiche_sortie',
                'compte_rendu',
                'imagerie',
                'resultat_labo',
                'assurance',
                'ordonnance_externe',
                'autre',
            ]);
            $table->string('titre', 200);
            $table->string('nom_fichier_original', 255);
            $table->string('fichier_url', 500);          // chemin du blob chiffré au repos (nom UUID)
            $table->string('mime_type', 150);            // type MIME réel validé serveur
            $table->string('extension', 20);
            $table->unsignedBigInteger('taille_octets');
            $table->char('hash_sha256', 64)->nullable(); // intégrité + détection doublon
            $table->enum('statut_antivirus', ['en_attente', 'sain', 'infecte'])->default('en_attente');
            $table->enum('source', ['patient', 'medecin', 'structure'])->default('patient');
            $table->date('date_document')->nullable();
            $table->foreignId('triage_id')->nullable()->constrained('triages')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['membre_id', 'categorie']);
            $table->index(['membre_id', 'date_document']);
            $table->index('statut_antivirus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_medicaux');
    }
};
