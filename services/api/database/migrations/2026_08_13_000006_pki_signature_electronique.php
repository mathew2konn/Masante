<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6.5b — PKI, certificats numériques et signature électronique (CDC_09 §5.3/§5.4 ; CDC_10 §4).
 *
 * ═══ CE QUE CETTE MIGRATION REND POSSIBLE, ET CE QU'ELLE NE PROMET PAS ═══
 *
 * Elle rend une prescription juridiquement traçable : authenticité (qui), intégrité (le contenu
 * n'a pas bougé), non-répudiation (le signataire ne peut pas nier). Elle ne remplace ni un HSM
 * (CDC_10 §4.3, absent — point d'extension documenté), ni une autorité de certification nationale
 * (aucune n'a été consultée : la CA de ce projet est auto-signée, et l'ADR le dit).
 *
 * ═══ LA DÉCISION QUI STRUCTURE LES COLONNES : LE SERVEUR NE PEUT PAS SIGNER SEUL ═══
 *
 * `cle_privee_chiffree` est chiffrée en AES-256-GCM avec une clé DÉRIVÉE DU SECRET DU
 * PROFESSIONNEL, lequel n'est stocké nulle part. Il n'existe donc aucun chemin — pas même
 * l'accès root à cette base — qui permette de produire une signature sans que le praticien saisisse
 * son secret. C'est ce qui fait la différence entre une non-répudiation réelle et une
 * non-répudiation décorative.
 *
 * `secret_hash` (BCrypt) sert UNIQUEMENT à distinguer un secret erroné d'une donnée corrompue et à
 * compter les échecs. Il ne permet pas de déchiffrer : un hachage BCrypt n'est pas une clé.
 *
 * ═══ QUATRE OBJETS ═══
 *  1. `autorites_certification`  — la CA racine (une ligne active).
 *  2. `certificats_numeriques`   — nom imposé par CDC_04 §5.2. Un certificat X.509 par praticien.
 *  3. `signatures_electroniques` — nom imposé par CDC_04 §5.2. Une signature par acte signé.
 *  4. `signature_journal`        — §5.4 « l'échec est journalisé », chaîne de hachage globale.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────────────────
        // 1. Autorité de certification racine (CDC_10 §4.1).
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // AUTO-SIGNÉE, et l'ADR le dit sans détour : aucune autorité nationale ivoirienne n'a été
        // consultée. Une PKI inventée qui aurait l'air officielle ne se ferait pas corriger.
        //
        // Sa clé privée est chiffrée par une phrase de passe d'ENVIRONNEMENT, jamais du dépôt
        // (CDC_10 §5 : aucun secret dans le dépôt). Le serveur PEUT donc signer en tant que CA —
        // c'est nécessaire, émettre un certificat est une opération serveur déclenchée par un
        // humain habilité. Ce qu'il ne peut pas faire, c'est signer en tant que PRATICIEN.
        Schema::create('autorites_certification', function (Blueprint $table) {
            $table->id();

            $table->string('nom', 150);
            $table->char('pays_code', 2)->default('CI');

            $table->text('certificat_pem');
            $table->text('cle_privee_chiffree');

            // Empreinte SHA-256 du certificat, en clair : c'est l'identifiant qu'on publie et
            // qu'un vérificateur compare. Rien de secret ne s'en déduit.
            $table->char('empreinte', 64);
            $table->string('numero_serie', 64);

            $table->timestamp('valide_du');
            $table->timestamp('valide_jusqu_a');
            $table->boolean('actif')->default(true);

            $table->timestamps();

            $table->unique('numero_serie', 'uq_ca_serie');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 2. Certificats numériques des professionnels (CDC_04 §5.2, CDC_09 §5.3).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('certificats_numeriques', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medecin_id')->constrained('medecins')->cascadeOnDelete();
            $table->foreignId('autorite_id')->constrained('autorites_certification')->restrictOnDelete();

            // Dénormalisé à l'émission. Le certificat doit rester lisible et vérifiable même si la
            // fiche change de numéro plus tard — un certificat atteste d'un état à une date, il ne
            // suit pas les corrections ultérieures. Motif de l'établissement copié sur
            // `acces_dossier` en P7-D2 : on ne déduit jamais après coup ce qu'on pouvait figer.
            $table->string('numero_professionnel', 12)->nullable();
            $table->string('sujet', 300);

            $table->string('numero_serie', 64);
            $table->text('certificat_pem');
            $table->char('empreinte', 64);

            // ═══ LE COFFRE ═══
            // Chiffrée AES-256-GCM. `nonce` et `sel_kdf` sont publics par construction (ils ne
            // servent qu'à reproduire le calcul) ; c'est le SECRET du praticien, absent d'ici, qui
            // ferme le coffre.
            $table->text('cle_privee_chiffree');
            $table->string('nonce', 64);
            $table->string('sel_kdf', 64);
            $table->unsignedTinyInteger('cle_version')->default(1);

            // Vérification du secret et anti-force brute (motif du PIN wallet, P5.3b-1).
            $table->string('secret_hash', 255);
            $table->unsignedTinyInteger('echecs_secret')->default(0);
            $table->timestamp('verrouille_jusqu_a')->nullable();

            $table->enum('statut', ['actif', 'revoque'])->default('actif');
            $table->timestamp('valide_du');
            $table->timestamp('valide_jusqu_a');

            // Révocation (§5.4). La liste de révocation, c'est cette colonne : pas d'OCSP, pas de
            // CRL publiée — la vérification est locale, et l'ADR le dit.
            $table->timestamp('revoque_le')->nullable();
            $table->string('revocation_motif', 300)->nullable();
            $table->foreignId('revoque_par')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('numero_serie', 'uq_certificat_serie');
            // Un praticien n'a qu'un certificat ACTIF à la fois. MySQL n'a pas d'index unique
            // partiel : la garantie est applicative (sous verrou), et c'est dit comme tel plutôt
            // que présenté comme une garantie du moteur — même honnêteté qu'en P6.4c pour le quota
            // d'images, qu'un déclencheur ne pouvait pas porter (erreur 1442).
            $table->index(['medecin_id', 'statut'], 'idx_certificat_medecin_statut');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 3. Signatures électroniques (CDC_04 §5.2, CDC_10 §4.5).
        // ─────────────────────────────────────────────────────────────────────────────
        Schema::create('signatures_electroniques', function (Blueprint $table) {
            $table->id();

            // Le type vient du registre fermé des documents signables : il arrive par l'appelant,
            // jamais par l'URL brute (précédent de la liste blanche des sections en P7-C).
            $table->string('type_document', 40);
            $table->unsignedBigInteger('document_id');

            $table->foreignId('certificat_id')->constrained('certificats_numeriques')->restrictOnDelete();
            $table->foreignId('medecin_id')->constrained('medecins')->restrictOnDelete();

            // ═══ CE QUI EST SIGNÉ ═══
            // L'empreinte d'une CANONICALISATION DU CONTENU EN CLAIR, jamais des octets stockés :
            // `ordonnances.medicaments_json` est chiffré au repos, et un rechiffrement produirait
            // un cryptogramme différent sans qu'aucune donnée n'ait bougé. La signature casserait
            // alors sans raison — c'est le piège évité en P6.4c pour l'empreinte des images.
            $table->char('empreinte_contenu', 64);
            $table->text('signature');
            $table->string('algorithme', 40)->default('RSA-SHA256');

            // Contexte dénormalisé : ce que la signature AFFIRMAIT au moment où elle a été posée.
            // Le relire depuis la fiche des mois plus tard donnerait l'état d'aujourd'hui, pas
            // celui du jour de la prescription.
            $table->string('signataire_numero', 12)->nullable();
            $table->string('signataire_nom', 200);
            $table->string('signataire_etablissement', 200)->nullable();

            $table->timestamp('signe_le');
            $table->timestamps();

            // Un document n'est signé qu'une fois. Une seconde signature ne dirait rien de plus et
            // rendrait insoluble « laquelle fait foi ? ».
            $table->unique(['type_document', 'document_id'], 'uq_signature_document');
            $table->index('medecin_id', 'idx_signature_medecin');
        });

        // ─────────────────────────────────────────────────────────────────────────────
        // 4. Journal des signatures — §5.4 : « l'échec est journalisé ».
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // LES REFUS Y SONT AUTANT QUE LES SUCCÈS. Un journal qui ne garderait que les signatures
        // abouties ne répondrait pas à la question qui compte en cas de litige : « a-t-on tenté de
        // signer avec un certificat révoqué, et combien de fois ? ».
        //
        // Chaîne de hachage GLOBALE, motif de `referentiel_journal` (P6.3) : chaque entrée porte
        // l'empreinte de la précédente, une suppression casse tout ce qui suit. Une chaîne par
        // praticien laisserait effacer sans trace tout l'historique d'un praticien.
        Schema::create('signature_journal', function (Blueprint $table) {
            $table->id();

            $table->string('action', 40);            // signature_reussie, signature_refusee, …
            $table->string('type_document', 40)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();

            $table->foreignId('medecin_id')->nullable()->constrained('medecins')->nullOnDelete();
            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();

            // `acteur_nom` ENTRE DANS L'EMPREINTE, et pas seulement `acteur_id`. Le test
            // d'altération de P6.3 l'a prouvé : sans lui, réécrire le nom d'un agent en « Système »
            // ne rompait pas la chaîne — or c'est ce nom qu'un humain lit dans un audit.
            $table->string('acteur_nom', 150);

            // Le MOTIF du refus, en clair et non codé : c'est ce qu'un juriste lira.
            $table->string('motif', 300)->nullable();

            // Empreinte du contenu, numéro de série, contrôle qui a échoué. JAMAIS le contenu
            // clinique lui-même : le journal prouve, il ne recopie pas (P6.3, miroir de P7-D0).
            $table->json('details')->nullable();

            $table->char('empreinte', 64);
            $table->char('empreinte_precedente', 64)->nullable();

            $table->timestamp('cree_le');

            $table->index(['medecin_id', 'cree_le'], 'idx_signature_journal_medecin');
            $table->index('action', 'idx_signature_journal_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_journal');
        Schema::dropIfExists('signatures_electroniques');
        Schema::dropIfExists('certificats_numeriques');
        Schema::dropIfExists('autorites_certification');
    }
};
