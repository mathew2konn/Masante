<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P11.2 — API D'INGESTION PARTENAIRE (CDC_11 §2 « API d'intégration », §7.7, ADR-030).
 *
 * ═══ CE QUE CETTE API EST, ET CE QU'ELLE N'EST PAS ═══
 *
 * ADR-030 l'a posé avant qu'une ligne ne soit écrite : **MaSanté ne remplace pas le logiciel d'un
 * hôpital qui en a déjà un, elle s'y branche** — « le pharmacien n'a rien à ressaisir »
 * (CDC_11 §7.7). Et surtout : **l'API est un contrat d'échange, jamais un second chemin
 * d'écriture.** Une ingestion écrit là où un humain de cet établissement pouvait déjà écrire, par
 * le même service. Elle ne touche aucun carnet de patient, ne crée aucun établissement, ne publie
 * aucun référentiel.
 *
 * ═══ TROIS TABLES, ET POURQUOI CHACUNE ═══
 *
 * **`clients_api`** — la troisième population d'authentification. Ce projet en avait deux :
 * Sanctum (le citoyen) et le principal signé (nos propres services, P5.5b-1). Un partenaire
 * n'est ni l'un ni l'autre : il n'a pas de session, et son secret ne peut pas être le nôtre
 * partagé, sinon un partenaire pourrait signer au nom d'un autre. **Un secret par établissement**,
 * sur le motif du montage A de GeniusPay (P5.6b) : un **identifiant opaque** *sélectionne* le
 * secret candidat, le **HMAC décide** — jamais de boucle d'essai, qui coûterait O(n) et offrirait
 * un oracle de temps.
 *
 * **`correspondances_partenaire`** — LE POINT DE CONCEPTION. Le logiciel d'une officine a **ses**
 * codes produits. Lui demander de parler les nôtres, c'est lui demander de remapper son catalogue
 * à la main, c'est-à-dire exactement la ressaisie que le §7.7 dit supprimer. Le partenaire envoie
 * donc **sa** référence, et MaSanté la résout par une correspondance **déclarée**.
 *
 * Et le serveur **ne devine JAMAIS** : une référence inconnue est **signalée**, jamais rapprochée
 * par ressemblance de libellé (précédent P6.8c, où rapprocher une maladie d'un texte libre aurait
 * été un diagnostic posé par une machine ; ici, se tromper de produit sur un stock de médicaments
 * enverrait un patient chercher la mauvaise boîte). La correspondance se déclare d'une seule
 * façon : le partenaire envoie **une fois** notre code national à côté de sa référence — c'est une
 * affirmation d'équivalence de sa part, pas une déduction de la nôtre —, et elle est **retenue**.
 *
 * **`journal_ingestion`** — *une intégration qui échoue en silence est pire qu'une intégration qui
 * échoue.* Chaque envoi laisse une ligne : qui, quand, combien accepté, combien refusé, et
 * pourquoi. C'est ce qu'un exploitant lira le jour où un partenaire dira « je vous ai tout
 * envoyé ».
 *
 * ═══ PRÉREQUIS DE DÉPLOIEMENT, CONSTATÉ AU G0 ═══
 *
 * ADR-030 disait « le référentiel est le PIVOT — sans lui, rien vers quoi mapper ». Sur cette
 * base, `medicaments.code` était renseigné **0 fois sur 18** et `structures_sanitaires`
 * `.identifiant_national` **0 fois sur 12** : les commandes de backfill de P6.4a et P6.6a existent
 * mais n'ont pas été rejouées. **Sans elles, aucune correspondance ne peut être déclarée** — et
 * l'API le dira au partenaire plutôt que d'accepter dans le vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Les clients d'API ────────────────────────────────────────────────────────────
        Schema::create('clients_api', function (Blueprint $table) {
            $table->id();

            // L'établissement au nom duquel ce client écrit. `cascadeOnDelete` : un client d'API
            // n'a aucun sens sans son établissement, et le laisser derrière laisserait une clé
            // valide pointer vers rien.
            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();

            $table->string('libelle', 150);           // « Caisse Sage Officine v4 »
            // Identifiant OPAQUE et aléatoire, jamais `structure_id` en clair : ce dernier
            // énumérerait les partenaires de la plateforme (précédent du slug de rappel de
            // GeniusPay, P5.6b). Il SÉLECTIONNE le secret candidat ; le HMAC décide.
            $table->string('identifiant', 32)->unique();
            // Secret chiffré au repos (`encrypted` côté modèle). Il doit être RECOUVRABLE, à la
            // différence d'un mot de passe : vérifier un HMAC exige le secret lui-même, pas son
            // empreinte. Conséquence dite plutôt que tue : une fuite de la base ET de `APP_KEY`
            // exposerait les secrets partenaires — d'où la révocation, qui est un geste, pas une
            // procédure.
            $table->text('secret_chiffre');

            // Domaines que ce client a le droit d'alimenter. Liste blanche fermée, jamais un
            // « tout » implicite : une clé émise pour un logiciel d'officine ne doit pas pouvoir
            // pousser des résultats de laboratoire le jour où ce flux existera.
            $table->json('domaines_json');

            $table->timestamp('dernier_appel_le')->nullable();
            $table->timestamp('revoque_le')->nullable();
            $table->string('revoque_motif', 300)->nullable();
            $table->timestamps();

            $table->index(['structure_id', 'revoque_le'], 'idx_client_api_structure');
        });

        // ── 2. Les correspondances déclarées ────────────────────────────────────────────────
        Schema::create('correspondances_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained('structures_sanitaires')->cascadeOnDelete();

            $table->string('domaine', 40);            // 'medicament' aujourd'hui
            $table->string('reference_externe', 120); // la référence DU PARTENAIRE
            // Le code national de NOTRE référentiel. Une chaîne et non une clé étrangère : c'est
            // ce que le partenaire a déclaré, et cette déclaration reste vraie même si la ligne
            // référencée disparaît. La résolution vers l'entité se fait à la lecture.
            $table->string('code_masante', 20);

            $table->timestamps();

            // Une référence externe désigne UNE chose, par établissement et par domaine. Sans
            // cette unicité, deux déclarations contradictoires coexisteraient et la résolution
            // dépendrait de l'ordre d'insertion.
            $table->unique(['structure_id', 'domaine', 'reference_externe'], 'uq_correspondance_externe');
            // Nom EXPLICITE, et ce n'est pas de la coquetterie : le nom auto-généré
            // (`correspondances_partenaire_structure_id_domaine_code_masante_index`) fait
            // **65 caractères**, or MySQL en plafonne 64. SQLite n'a pas cette limite — la
            // migration passait donc en test et **échouait au premier contact avec la base
            // réelle**, après avoir posé une partie du schéma (le DDL MySQL n'étant pas
            // transactionnel). Même famille que la longueur de `VARCHAR` non contrainte par
            // SQLite (P10c-3-ii) : *une garantie qui ne vaut que d'un côté*.
            $table->index(['structure_id', 'domaine', 'code_masante'], 'idx_correspondance_code');
        });

        // ── 3. Le journal des envois ────────────────────────────────────────────────────────
        Schema::create('journal_ingestion', function (Blueprint $table) {
            $table->id();
            // Identifiant, PAS une relation vivante (D1 d'ADR-042) : révoquer et supprimer un
            // client d'API ne doit pas effacer la trace de ce qu'il a envoyé.
            $table->unsignedBigInteger('client_api_id')->nullable();
            $table->unsignedBigInteger('structure_id')->nullable();

            $table->string('domaine', 40);
            $table->string('idempotency_key', 120)->nullable();

            $table->unsignedInteger('lignes_recues')->default(0);
            $table->unsignedInteger('lignes_acceptees')->default(0);
            $table->unsignedInteger('lignes_refusees')->default(0);
            // Le détail des refus, ligne par ligne, avec leur motif. JAMAIS un simple compteur :
            // « 3 refusées » n'aide personne à corriger quoi que ce soit.
            $table->json('refus_json')->nullable();

            $table->boolean('rejeu')->default(false);
            $table->timestamp('cree_le')->useCurrent();

            $table->index(['structure_id', 'domaine', 'cree_le'], 'idx_ingestion_structure');
            // L'idempotence : deux envois portant la même clé pour le même client sont le MÊME
            // envoi. L'unicité est déclarative — le code la respecte, le moteur la garantit.
            $table->unique(['client_api_id', 'idempotency_key'], 'uq_ingestion_idempotence');
        });

        // ── 4. Ce qu'un logiciel d'officine sait et que nous ne stockions pas ───────────────
        Schema::table('prix_pharmacie', function (Blueprint $table) {
            // Un logiciel de caisse connaît une QUANTITÉ ; `disponible` seul perdrait cette
            // information à la porte. Nullable : les relevés antérieurs et ceux d'un citoyen
            // n'en ont pas, et leur en inventer une serait un mensonge (précédent L2 de P6.3).
            $table->unsignedInteger('quantite')->nullable()->after('disponible');
        });

        // La provenance gagne une valeur : un relevé venu du logiciel de l'officine n'est ni une
        // saisie au portail, ni un signalement de citoyen. Les trois valeurs existantes sont
        // conservées telles quelles (ADR-024, additif jamais destructif).
        $this->etendreSource(['cename', 'pharmacie_portail', 'crowdsource_patient', 'logiciel_officine']);
    }

    public function down(): void
    {
        DB::table('prix_pharmacie')->where('source', 'logiciel_officine')->delete();
        $this->etendreSource(['cename', 'pharmacie_portail', 'crowdsource_patient']);

        Schema::table('prix_pharmacie', function (Blueprint $table) {
            $table->dropColumn('quantite');
        });

        Schema::dropIfExists('journal_ingestion');
        Schema::dropIfExists('correspondances_partenaire');
        Schema::dropIfExists('clients_api');
    }

    /**
     * Étend l'énumération de `source` — DANS LES DEUX DIALECTES.
     *
     * DÉFAUT RÉEL, TROUVÉ PAR LA SUITE : la première écriture de cette migration sortait sur
     * SQLite, au motif qu'il « ne contraint pas les ENUM déclarés par Laravel ». **C'était faux** :
     * Laravel pose un `CHECK` sur SQLite, et la nouvelle valeur y était refusée. La garantie
     * aurait donc été **vraie en production et fausse en test** — l'exacte divergence refusée
     * depuis P6.8c (collation) et P6.8e (REGEXP), ici dans le sens le plus traître puisque la
     * fonctionnalité aurait marché sans qu'aucun vecteur ne puisse le prouver.
     *
     * `->change()` fait le travail des deux côtés : `MODIFY` sous MySQL, reconstruction de table
     * sous SQLite. On ne pilote plus le dialecte à la main.
     *
     * @param  array<int, string>  $valeurs
     */
    private function etendreSource(array $valeurs): void
    {
        Schema::table('prix_pharmacie', function (Blueprint $table) use ($valeurs) {
            $table->enum('source', $valeurs)->nullable(false)->change();
        });
    }
};
