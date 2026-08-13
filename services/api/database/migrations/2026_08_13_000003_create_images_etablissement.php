<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6.4c — Images des établissements (CDC_09 §4.2, CDC_11 §3.1 « formulaire dédié »).
 *
 * STRICTEMENT ADDITIVE : `structures_sanitaires` n'est pas touchée. Une image est une ligne d'une
 * table à part, pas une colonne — le corpus en attend plusieurs par établissement, et une colonne
 * `photos_json` aurait rendu impossible la suppression d'une seule photo sans réécrire les autres.
 *
 * DEUX TABLES, ET C'EST VOULU (décision I4) :
 *
 *  · `categories_image_etablissement` est une table de RÉFÉRENCE. Les cinq sujets nommés par
 *    CDC_11 §3.1 y sont des DONNÉES, conformément au §1.2.4 (« aucune donnée de référence saisie
 *    librement »). En ajouter un sixième doit coûter une ligne, pas un déploiement.
 *
 *  · Elle porte `max_par_etablissement`. C'est ce qui fait de « un établissement n'a qu'un logo »
 *    une donnée et non un `if ($categorie === 'logo')` enfoui dans un service — la même règle que
 *    `villes.affiche_communes` en P6.4b, pour la même raison (CDC_04 §20).
 *
 * Le quota lui-même est vérifié PAR LE SERVICE sous verrou, et non par un déclencheur : sous MySQL,
 * un déclencheur ne peut pas interroger la table qu'il garde (erreur 1442). La limite est annoncée
 * plutôt que déguisée en garantie du moteur (plan G1, limite O5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories_image_etablissement', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('libelle', 100);

            // Combien d'images de cette catégorie un établissement peut publier. `logo` vaut 1 :
            // c'est la règle métier, exprimée en donnée.
            $table->unsignedSmallInteger('max_par_etablissement')->default(5);

            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('etablissement_images', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('structure_id')
                ->constrained('structures_sanitaires')
                ->cascadeOnDelete();

            // Référence par CODE et non par identifiant : le code est ce que l'API expose et ce que
            // l'instantané du référentiel publie. Une jointure de moins à chaque lecture, et une
            // catégorie renommée ne réécrit pas silencieusement des instantanés déjà publiés.
            $table->string('categorie_code', 40);
            $table->foreign('categorie_code')
                ->references('code')->on('categories_image_etablissement')
                ->restrictOnDelete();

            // Chemin interne : `<structure>/<uuid>.<ext>`. JAMAIS exposé (anti path-traversal, et
            // l'extension vient du MIME réel, jamais de ce que le client a déclaré).
            $table->string('chemin', 255);

            $table->string('mime', 100);
            $table->unsignedInteger('taille_octets');

            // SHA-256 du CONTENU. C'est elle qui entre dans le référentiel gouverné (décision I3) :
            // changer l'image fait diverger, redéposer le même octet pour octet ne fait pas
            // diverger. Sert aussi d'`ETag` à la diffusion — gratuit, puisqu'elle est déjà calculée.
            $table->char('empreinte', 64);

            $table->unsignedSmallInteger('largeur')->nullable();
            $table->unsignedSmallInteger('hauteur')->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);

            // L'auteur survit à la suppression de son compte : « qui a publié cette photo » reste
            // une question à laquelle on doit pouvoir répondre (même motif que `nis_journal`).
            $table->foreignId('depose_par')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['structure_id', 'categorie_code', 'ordre']);

            // Le MÊME fichier ne peut pas être déposé deux fois dans la même catégorie du même
            // établissement. Garanti par le moteur : c'est le seul volet du quota qu'une contrainte
            // déclarative sait exprimer, et il attrape le double-clic.
            $table->unique(['structure_id', 'categorie_code', 'empreinte'], 'uq_image_unique_par_categorie');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etablissement_images');
        Schema::dropIfExists('categories_image_etablissement');
    }
};
