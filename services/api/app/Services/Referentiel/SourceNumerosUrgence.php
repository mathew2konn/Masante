<?php

namespace App\Services\Referentiel;

use App\Models\NumeroUrgence;

/**
 * Référentiel national des numéros d'urgence (CDC_09 §8), sous gouvernance §10.
 *
 * ═══ POURQUOI CELUI-CI ENTRE SOUS GOUVERNANCE ═══
 *
 * C'est l'acte d'autorité le plus littéral de tout P6 : changer un numéro d'urgence, ou en retirer
 * un, décide de ce qu'une population entière composera devant un blessé. Plus qu'un agrément
 * d'assureur, plus qu'un libellé de spécialité.
 *
 * L'objection qui aurait pu s'y opposer — l'indisponibilité tant qu'aucune version n'est publiée —
 * est levée ailleurs, par la chaîne de repli côté client (décision propriétaire C1). Elle n'est pas
 * levée ICI : ce service ne sert jamais la table de travail à la place du référentiel.
 *
 * ═══ LA PROJECTION PREND LA LIGNE ENTIÈRE — question reposée pour la sixième fois ═══
 *
 * Comme en P6.6a, P6.8a, P6.8b, P6.8c et P6.8d, la question est reposée plutôt que la conclusion de
 * P6.4a recopiée : **rien n'écrit automatiquement dans `numeros_urgence`**. Et la table est
 * construite pour que cette phrase reste vraie — elle ne porte **aucun compteur d'appels**, alors
 * qu'il serait facile d'en tenir un : `alertes_sos` journalise déjà les déclenchements, et un
 * compteur ici ferait diverger l'instantané à chaque SOS, donc à chaque urgence réelle. *Le
 * référentiel dirait qu'il a changé au moment précis où il compte le plus qu'il n'ait pas bougé.*
 *
 * DEUX VECTEURS EN MIROIR, aucun ne suffit seul : un citoyen déclenche un SOS → l'empreinte NE
 * CHANGE PAS ; un numéro est modifié → elle CHANGE.
 */
final class SourceNumerosUrgence implements SourceReferentiel
{
    /** Les provenances admises — miroir de l'ENUM `source`. */
    private const SOURCES = ['demonstration', 'declaration_projet', 'autorite_nationale', 'publication'];

    /**
     * Ce qu'un téléphone sait composer : chiffres, `+` international, `*` et `#` des codes de
     * service, espaces, points et tirets de mise en forme.
     *
     * Ce contrôle vit ICI et non dans le moteur, délibérément : MySQL 8 sait le faire en `REGEXP`,
     * SQLite non — la garde serait alors **plus stricte en production qu'en test**, exactement la
     * divergence relevée en P6.8c avec la collation. Ici, elle est éprouvable dans les deux.
     */
    private const NUMERO_COMPOSABLE = '/^[+0-9][0-9 .\-*#]*$/';

    public const CODE = 'numeros_urgence';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Numéros d\'urgence nationaux';
    }

    /**
     * §10 « chaque référentiel a un responsable désigné ». Le ministère : un numéro d'urgence est
     * attribué par un plan national de numérotation, et aucun établissement, aucun opérateur, aucune
     * caisse n'a qualité pour en décider.
     */
    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        return NumeroUrgence::query()
            // Ordre stable et TOTAL, reproductible d'une base à l'autre.
            ->orderBy('pays_code')
            ->orderBy('ordre')
            ->orderBy('code')
            ->get()
            ->map(fn (NumeroUrgence $n): array => [
                'code'          => $n->code,
                'pays_code'     => $n->pays_code,
                'numero'        => $n->numero,
                'libelle'       => $n->libelle,
                'description'   => $n->description,
                'ordre'         => $n->ordre,
                'actif'         => (bool) $n->actif,
                'source'        => $n->source,
                'source_detail' => $n->source_detail,
            ])
            ->all();
    }

    /**
     * Contrôles qualité §10 — bloquants à la publication.
     *
     * Ce qu'ils garantissent : **un référentiel publié ne peut pas diffuser un numéro qu'un
     * téléphone ne saurait composer, deux numéros indiscernables, une entrée sans provenance, ni —
     * et c'est le contrôle qui compte — une liste où plus rien n'est joignable.**
     */
    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le référentiel est vide : aucun numéro d\'urgence à publier.'];
        }

        $codesVus = [];
        $actifs   = 0;

        foreach ($contenu as $ligne) {
            $code      = (string) ($ligne['code'] ?? '');
            $pays      = strtoupper((string) ($ligne['pays_code'] ?? ''));
            $numero    = trim((string) ($ligne['numero'] ?? ''));
            $etiquette = $code !== '' ? $code : '« sans code »';

            if ($code === '') {
                $erreurs[] = "{$etiquette} : code absent — c'est par lui que le mobile et le triage "
                    .'demandent un numéro précis (`samu`, `police`…).';
            } elseif (isset($codesVus[$pays.'-'.$code])) {
                // La clé porte le pays, et le contrôle est aussi strict que
                // `uq_numero_urgence_pays_code`, ni plus ni moins — leçon du G2 de P6.5a, où un
                // contrôle plus strict que le moteur rendait le référentiel impubliable dès le
                // deuxième pays.
                $erreurs[] = "Doublon : le code « {$code} » apparaît plusieurs fois pour {$pays}.";
            } else {
                $codesVus[$pays.'-'.$code] = true;
            }

            // Le déclencheur refuse déjà d'ÉCRIRE un numéro vide. Ce contrôle attrape le même défaut
            // arrivé par un autre chemin — un import, une base restaurée d'ailleurs — et il en
            // attrape un que le moteur ne voit pas : un numéro non composable.
            if ($numero === '') {
                $erreurs[] = "{$etiquette} : numéro absent — *un numéro d'urgence vide est un bouton "
                    .'qui ne compose rien*.';
            } elseif (preg_match(self::NUMERO_COMPOSABLE, $numero) !== 1) {
                $erreurs[] = "{$etiquette} : « {$numero} » n'est pas composable par un téléphone.";
            }

            if (trim((string) ($ligne['libelle'] ?? '')) === '') {
                $erreurs[] = "{$etiquette} : libellé absent — c'est lui qu'un citoyen lit pour savoir "
                    .'lequel composer, et se tromper de numéro coûte des minutes.';
            }

            if (! in_array($ligne['source'] ?? null, self::SOURCES, true)) {
                $erreurs[] = "{$etiquette} : provenance absente ou inconnue — une entrée de "
                    .'référentiel sans source ne peut pas être publiée.';
            }

            if ($ligne['actif'] ?? false) {
                $actifs++;
            }
        }

        // LE CONTRÔLE CENTRAL DE CE MODULE. Publier une liste où tout est désactivé serait pire que
        // ne rien publier : le client remplacerait son cache par une liste vide et **retomberait sur
        // la valeur livrée avec l'application**, sans que personne ne l'ait décidé.
        if ($actifs === 0) {
            $erreurs[] = 'Aucun numéro actif : la version publiée ne permettrait de joindre aucun '
                .'secours, et les téléphones retomberaient silencieusement sur la valeur livrée avec '
                .'l\'application.';
        }

        return $erreurs;
    }
}
