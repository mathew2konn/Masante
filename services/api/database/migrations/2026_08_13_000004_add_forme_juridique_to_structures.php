<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6.4d — `forme_juridique` (lève la limite M6 d'ADR-026).
 *
 * POURQUOI CETTE COLONNE MANQUAIT. CDC_11 §3.1 liste un « statut » dans le bloc légal, **à côté**
 * du type public/privé/universitaire/militaire du §4.2. P6.4a a mappé `statut_juridique` sur ce
 * second axe et n'a rien posé pour le premier — sans énoncer l'interprétation sur le moment. C'est
 * le choix le plus discutable de P6.4a ; il a été consigné après coup dans ADR-026 §4 (M6), et
 * cette migration le referme.
 *
 * LES DEUX AXES SONT DISTINCTS, et c'est tout l'intérêt de les séparer :
 *
 *   · `statut_juridique` = **qui possède** → public, privé, universitaire, militaire ;
 *   · `forme_juridique`  = **sous quelle forme de droit** → SARL, SA, association, EPN, fondation…
 *
 * Une clinique privée peut être une SARL ou une SA ; un hôpital public est un EPN. Les fondre en
 * une colonne rendrait impossible la question « combien de SARL parmi les cliniques privées ? »,
 * qui est exactement le genre de statistique que §4.4 assigne à ce référentiel.
 *
 * TEXTE LIBRE ET NON ÉNUMÉRATION, à dessein : les formes de droit varient d'un pays à l'autre, et
 * le référentiel est multi-pays (`pays_code`). Une énumération figée sur le droit ivoirien devrait
 * être migrée à chaque pays ajouté. Le contrôle qualité signale l'absence ; il ne dicte pas la
 * liste — même raisonnement que le découpage sanitaire, chargé en données plutôt qu'inventé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('structures_sanitaires', function (Blueprint $table): void {
            $table->string('forme_juridique', 80)->nullable()->after('statut_juridique');
        });
    }

    public function down(): void
    {
        Schema::table('structures_sanitaires', function (Blueprint $table): void {
            $table->dropColumn('forme_juridique');
        });
    }
};
