<?php

namespace App\Services\Referentiel;

use App\Models\SpecialiteMedicale;
use App\Support\ProfessionsSante;

/**
 * Référentiel national des spécialités médicales (CDC_09 §8), sous gouvernance §10.
 *
 * ═══ LA PROJECTION PREND LA LIGNE ENTIÈRE — question reposée, pas recopiée ═══
 *
 * P6.4a excluait la note d'avis et les horaires parce qu'ils sont RECALCULÉS à chaque avis déposé :
 * les inclure aurait fait diverger l'instantané en permanence. La question est reposée ici, comme
 * en P6.6a, et la réponse est la même qu'en P6.6a et pour la même raison : **rien n'écrit
 * automatiquement dans `specialites_medicales`**. Aucun compteur, aucune moyenne, aucune colonne
 * alimentée par l'usage — c'est un vocabulaire, il ne bouge que quand une autorité le décide.
 *
 * Un compteur « nombre de services rattachés » aurait été utile à l'écran ; il n'a délibérément pas
 * été mis en colonne, justement pour que cette réponse reste vraie. Il se compte à la lecture.
 *
 * ═══ CE QUE LA GOUVERNANCE PROTÈGE ICI ═══
 *
 * Un vocabulaire n'a de valeur que s'il est stable. Renommer « ORL » ou retirer « cardiologie »
 * change ce que signifient les services et les fiches de praticiens **déjà rattachés** : c'est une
 * décision, pas une correction de saisie. D'où le quatre-yeux du §10, comme pour les seuils de
 * mesure et le catalogue des analyses.
 */
final class SourceSpecialites implements SourceReferentiel
{
    public const CODE = 'specialites';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Spécialités médicales et activités de service';
    }

    /**
     * §10 « chaque référentiel a un responsable désigné ». Le ministère — pour la raison qui a fait
     * naître la permission `specialite.referentiel` : un établissement qui pourrait ajouter un terme
     * déclarerait lui-même ce qu'il sait faire, et le vocabulaire national serait la somme des
     * ambitions de chacun.
     */
    public function roleResponsable(): string
    {
        return 'ministere';
    }

    public function extraire(): array
    {
        return SpecialiteMedicale::query()
            // Ordre stable et TOTAL : `code` est unique par pays, `pays_code` le complète pour que
            // deux pays ne s'entrelacent pas au gré des identifiants techniques (précédent P6.4a).
            ->orderBy('pays_code')
            ->orderBy('code')
            ->get()
            ->map(fn (SpecialiteMedicale $s): array => [
                'code'        => $s->code,
                'pays_code'   => $s->pays_code,
                'libelle'     => $s->libelle,
                'nature'      => $s->nature,
                'profession'  => $s->profession,
                'description' => $s->description,
                'ordre'       => $s->ordre,
                'actif'       => (bool) $s->actif,
            ])
            ->all();
    }

    /**
     * Contrôles qualité §10 — bloquants à la publication.
     *
     * Ce qu'ils garantissent : **le vocabulaire ne doit pas pouvoir contenir deux fois le même
     * terme, un terme illisible, ni un terme qui se rattache à une profession inexistante.**
     */
    public function controlerQualite(array $contenu): array
    {
        $erreurs = [];

        if ($contenu === []) {
            return ['Le vocabulaire est vide : aucune spécialité à publier.'];
        }

        $codesVus = [];
        $libellesVus = [];
        $actifs = 0;

        foreach ($contenu as $ligne) {
            $code = (string) $ligne['code'];
            $pays = $ligne['pays_code'] ?? '??';
            $etiquette = $code !== '' ? $code : '(sans code)';

            // — Forme du code —
            //
            // C'est la forme que `services_etablissement.specialite` porte depuis le Module 3 et sur
            // laquelle le filtre `?specialite=` de P3 compare en égalité exacte. Un terme qui ne la
            // respecte pas serait injoignable par l'annuaire : le référentiel afficherait une
            // spécialité que personne ne pourrait filtrer.
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
                $erreurs[] = "« {$etiquette} » : code mal formé — minuscules, chiffres et "
                    .'soulignés seulement, en commençant par une lettre.';
            } else {
                // LE PAYS FAIT PARTIE DE LA CLÉ. Sans lui, ce contrôle serait plus strict que
                // l'index `uq_specialite_pays_code`, et le vocabulaire deviendrait impubliable dès
                // le second pays — c'est exactement le défaut que le G2 de P6.5a a attrapé.
                $cle = $pays.'-'.$code;

                if (isset($codesVus[$cle])) {
                    $erreurs[] = "Doublon : le code « {$code} » apparaît plusieurs fois pour {$pays}.";
                }
                $codesVus[$cle] = true;
            }

            // — Libellé —
            $libelle = trim((string) $ligne['libelle']);

            if ($libelle === '') {
                $erreurs[] = "« {$etiquette} » : libellé absent — c'est lui que le citoyen lit.";
            } else {
                // Deux termes distincts portant le MÊME libellé sont indiscernables à l'écran : le
                // patient qui choisit « Cardiologie » dans une liste qui en contient deux ne peut
                // pas savoir laquelle il a désignée.
                $cleLibelle = $pays.'-'.mb_strtolower($libelle);

                if (isset($libellesVus[$cleLibelle])) {
                    $erreurs[] = "Doublon : le libellé « {$libelle} » est porté par plusieurs "
                        ."termes du pays {$pays} — ils seraient indiscernables à l'écran.";
                }
                $libellesVus[$cleLibelle] = true;
            }

            // — Nature —
            if (! in_array($ligne['nature'], ['specialite_medicale', 'activite'], true)) {
                $erreurs[] = "« {$etiquette} » : nature inconnue ({$ligne['nature']}).";
            }

            // — Profession de rattachement —
            //
            // Facultative (une activité de service n'en a pas toujours), mais si elle est déclarée
            // elle doit exister au §5.1 : un terme rattaché à une profession inconnue promet un
            // dénombrement — « combien de sages-femmes dans ce district ? » — qui ne se ferait pas.
            if ($ligne['profession'] !== null
                && ! array_key_exists($ligne['profession'], ProfessionsSante::PROFESSIONS)) {
                $erreurs[] = "« {$etiquette} » : profession inconnue ({$ligne['profession']}) — "
                    .'le §5.1 en énumère onze.';
            }

            if ($ligne['actif']) {
                $actifs++;
            }
        }

        // Publier un vocabulaire entièrement désactivé reviendrait à retirer d'un coup toute
        // possibilité de rattacher un service ou un praticien, sans qu'aucun message ne le dise.
        if ($actifs === 0) {
            $erreurs[] = 'Aucun terme actif : le vocabulaire publié ne permettrait de rattacher '
                .'ni un service, ni une fiche de praticien.';
        }

        return $erreurs;
    }
}
