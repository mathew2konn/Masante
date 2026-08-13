<?php

namespace App\Support;

use App\Services\Pki\DocumentOrdonnance;
use App\Services\Pki\DocumentSignable;

/**
 * Liste blanche FERMÉE des documents que la PKI sait signer (CDC_10 §4.5 ; P6.5b).
 *
 * ═══ POURQUOI FERMÉE ═══
 *
 * Le type de document arrive par l'appelant, et finira par arriver par une URL. Sans liste
 * blanche, ce code deviendrait un choix libre du client, donc une porte vers n'importe quelle
 * table. Même raison qu'en P7-C pour les sections du carnet et qu'en P6.3 pour les référentiels :
 * la fermeture n'est pas de la rigueur, c'est la garde.
 *
 * ═══ LES SEPT TYPES DU §4.5 SONT TOUS NOMMÉS ICI ═══
 *
 * Un seul est branché. Les cinq autres n'ont pas d'entité dans cette base, et la facture est
 * signée ailleurs. **Nommer un manque ne le comble pas, mais un manque nommé ne s'oublie pas** —
 * et surtout on ne prétend nulle part que « la signature couvre les documents médicaux ».
 *
 * Brancher un type = **une classe et une ligne**. Le moteur de signature ne bouge pas.
 */
final class RegistreDocumentsSignables
{
    /**
     * Les documents effectivement signables aujourd'hui.
     *
     * @var array<string, class-string<DocumentSignable>>
     */
    public const SIGNABLES = [
        DocumentOrdonnance::CODE => DocumentOrdonnance::class,
    ];

    /**
     * Les six autres types de CDC_10 §4.5, avec la raison exacte pour laquelle ils ne sont pas
     * branchés. Cette constante n'a aucun effet à l'exécution : elle existe pour qu'un lecteur du
     * code — et le test qui la vérifie — sache que le corpus en demande sept, pas un.
     *
     * @var array<string, string>
     */
    public const NON_BRANCHES = [
        'compte_rendu_medical' => "Entité inexistante. `notes_observations` est le plus proche mais "
            ."reste réservée au patient (exclue de SECTIONS_SOIGNANT) : aucun soignant ne peut en "
            .'écrire. Module « Documents médicaux signés », après P6.7.',
        'certificat_medical' => "Entité inexistante. `documents_medicaux.categorie='certificat_medical'` "
            ."est un FICHIER IMPORTÉ par le patient, pas un certificat émis par un professionnel. "
            .'Module « Documents médicaux signés », après P6.7.',
        'prescription_biologique' => "Entité inexistante — et ce n'est pas un document mais une DEMANDE "
            ."qui ouvre un circuit (médecin → laboratoire → résultat, §7.4). Sans le catalogue "
            ."national des analyses (étape 7), elle prescrirait des examens en texte libre.",
        'rapport_radiologie' => 'Entité inexistante. Suppose l\'imagerie et DICOM (§9.1). '
            .'`resultats_analyses.type_analyse=radiologique` porte un RÉSULTAT saisi, pas un rapport.',
        'document_administratif' => 'Entité inexistante comme document produit. `documents_medicaux` '
            .'est un dépôt de fichiers.',
        'facture' => 'SIGNÉE AILLEURS, par nature et non par oubli : les factures vivent dans le '
            .'microservice de paiement et sont signées en RSA-SHA256 depuis P5.2b. CDC_09 §10 '
            .'attribue d\'ailleurs la facture à « l\'administration », pas au médecin — la signer '
            .'avec le certificat d\'un praticien serait factuellement faux.',
    ];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::SIGNABLES);
    }

    public static function existe(string $code): bool
    {
        return isset(self::SIGNABLES[$code]);
    }

    public static function document(string $code): DocumentSignable
    {
        if (! self::existe($code)) {
            throw new \InvalidArgumentException("Type de document non signable : {$code}");
        }

        return app(self::SIGNABLES[$code]);
    }

    /**
     * L'état de chacun des sept types du §4.5 — de quoi rendre compte sans rien enjoliver.
     *
     * @return array<int, array{code: string, libelle: string, branche: bool, raison: ?string}>
     */
    public static function etatDuCorpus(): array
    {
        $etat = [];

        foreach (self::SIGNABLES as $code => $classe) {
            $etat[] = [
                'code'    => $code,
                'libelle' => app($classe)->libelle(),
                'branche' => true,
                'raison'  => null,
            ];
        }

        foreach (self::NON_BRANCHES as $code => $raison) {
            $etat[] = [
                'code'    => $code,
                'libelle' => ucfirst(str_replace('_', ' ', $code)),
                'branche' => false,
                'raison'  => $raison,
            ];
        }

        return $etat;
    }
}
