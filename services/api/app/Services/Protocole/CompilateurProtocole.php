<?php

namespace App\Services\Protocole;

use App\Models\ProtocoleAction;
use App\Models\ProtocoleCondition;
use App\Models\ProtocoleQuestion;
use App\Models\ProtocoleRegle;
use App\Models\ProtocoleReponse;
use App\Models\ProtocoleVersion;
use App\Services\Referentiel\EmpreinteReferentiel;

/**
 * P10b-1 — La compilation d'une version en structure exécutable (CDC_08 §11).
 *
 * Le §11 demande « la compilation des règles en structure exécutable en mémoire ». Ici la structure
 * exécutable est un tableau PHP déterministe : c'est exactement ce que `MoteurProtocole::evaluer()`
 * consomme, et c'est ce qui est figé dans `contenu_json` à la publication.
 *
 * ═══ DÉTERMINISME EXIGÉ, POUR LA MÊME RAISON QU'AU SOCLE ═══
 *
 * Deux extractions sur une base inchangée doivent produire exactement le même tableau, dans le même
 * ordre, avec les mêmes clés. Sans cela, l'empreinte changerait sans qu'aucune donnée n'ait bougé,
 * et l'anti-substitution du §7 crierait au loup à chaque publication — un contrôle qui se déclenche
 * à tort finit par être contourné. D'où les `orderBy` explicites partout, jusque sur les clés
 * secondaires.
 *
 * ═══ CE QUE LA COMPILATION FIGE, ET POURQUOI CE N'EST PAS QUE LES RÈGLES ═══
 *
 * L'instantané porte aussi les métadonnées (§4.1) et les références bibliographiques. Le §9.1
 * prévoit que le médecin voie « les recommandations, leur **niveau de preuve** et les **références
 * utilisées** » : les résoudre à la lecture obligerait à lire des tables de travail qui ont pu
 * changer depuis, et une décision archivée citerait alors un niveau de preuve qui n'était pas le
 * sien. Motif de la DCI figée dans une ordonnance (P6.6b) et du libellé d'orientation figé dans
 * l'instantané des symptômes (P10a).
 */
final class CompilateurProtocole
{
    /**
     * Extrait le contenu d'une version depuis les tables de travail.
     *
     * @return array{metadonnees: array<string, mixed>, regles: array<int, array<string, mixed>>, questions: array<int, array<string, mixed>>, references: array<int, array<string, mixed>>}
     */
    public function extraire(ProtocoleVersion $version): array
    {
        $protocole = $version->protocole;

        return [
            // §4.1 — les métadonnées obligatoires, figées avec le reste.
            'metadonnees' => [
                'code' => $protocole->code,
                'pays_code' => $protocole->pays_code,
                'titre' => $protocole->titre,
                'domaine' => $protocole->domaine,
                // P10b-2 — les contextes du §9.1. Ils entrent dans l'instantané, donc dans
                // l'empreinte : élargir le champ d'application d'un protocole en vigueur
                // devient une PUBLICATION relue par deux agents, pas un UPDATE discret.
                'contextes' => $protocole->contextes_json ?? [],
                'niveau_source' => $protocole->niveau_source,
                'organisme' => $protocole->organisme,
                'auteur' => $protocole->auteur,
                'specialite_code' => $protocole->specialite_code,
                'langue' => $protocole->langue,
                'version' => $version->libelle,
                'numero' => (int) $version->numero,
                'niveau_preuve' => $version->niveau_preuve,
                'population' => $version->population,
                'conditions_utilisation' => $version->conditions_utilisation,
                'date_expiration' => $version->date_expiration?->toDateString(),
            ],

            'regles' => $version->regles()
                ->with(['conditions', 'actions'])
                // `id` en second critère : `ordre` est unique par version (contrainte de base),
                // mais l'ordre doit rester total même si cette contrainte évoluait un jour.
                ->orderBy('ordre')
                ->orderBy('id')
                ->get()
                ->map(fn (ProtocoleRegle $regle): array => [
                    'ordre' => (int) $regle->ordre,
                    'libelle' => $regle->libelle,
                    'conditions' => $regle->conditions
                        ->sortBy([['ordre', 'asc'], ['id', 'asc']])
                        ->map(fn (ProtocoleCondition $c): array => [
                            'fait' => $c->fait,
                            'operateur' => $c->operateur,
                            'valeur' => $c->valeur(),
                            // La phrase que lit un relecteur clinique du §7. Elle est FIGÉE :
                            // c'est ce qu'il a signé, pas ce que le code saurait regénérer plus
                            // tard avec des libellés de faits qui auraient changé.
                            'phrase' => $c->enFrancais(),
                        ])->values()->all(),
                    'actions' => $regle->actions
                        ->sortBy([['ordre', 'asc'], ['id', 'asc']])
                        ->map(fn (ProtocoleAction $a): array => [
                            'type' => $a->type,
                            'valeur' => $a->valeur(),
                            'justification' => $a->justification,
                            'phrase' => $a->enFrancais(),
                        ])->values()->all(),
                ])->values()->all(),

            // ═══ P10b-3-i — LES QUESTIONS ENTRENT DANS L'INSTANTANÉ, DONC DANS L'EMPREINTE ═══
            //
            // Conséquence directe et voulue : modifier un énoncé, une borne d'échelle ou une
            // réponse possible après signature rend les quatre validations du §7 **caduques**
            // (anti-substitution). Sans cela il suffirait de faire signer un questionnaire anodin
            // puis d'en changer les bornes — le contrôle transposé de « destination révoquée
            // depuis le figeage » de P5.5b-2, ici sur ce qu'on demande à un patient.
            //
            // C'est aussi ce qui rend les bornes OPPOSABLES (R7) : le service qui refuse
            // `intensite = 100` lit l'instantané publié, pas la table de travail.
            'questions' => $version->questions()
                ->with(['reponses' => fn ($q) => $q->orderBy('ordre')->orderBy('id')])
                ->get()
                ->map(fn (ProtocoleQuestion $question): array => [
                    'cle' => $question->cle,
                    'libelle' => $question->libelle,
                    'type' => $question->type,
                    'unite' => $question->unite,
                    // Le type de FAIT, figé ici plutôt que redéduit à la lecture : c'est lui que
                    // le contrôle de compatibilité fait/opérateur utilise, et il doit rester celui
                    // qui a été signé même si la correspondance type-de-question → type-de-fait
                    // évoluait un jour.
                    'type_fait' => $question->typeDeFait(),
                    'valeur_min' => $question->valeur_min,
                    'valeur_max' => $question->valeur_max,
                    'reponses' => $question->reponses
                        ->map(fn (ProtocoleReponse $r): array => [
                            'valeur' => $r->valeur,
                            'libelle' => $r->libelle,
                        ])->values()->all(),
                ])->values()->all(),

            'references' => $version->references()
                ->orderBy('type')
                ->orderBy('libelle')
                ->orderBy('id')
                ->get()
                ->map(fn ($r): array => [
                    'type' => $r->type,
                    'libelle' => $r->libelle,
                    'url' => $r->url,
                    'citation' => $r->citation,
                ])->values()->all(),
        ];
    }

    /**
     * L'empreinte du contenu d'une version — celle que les validations figent et que la
     * publication confronte (§7, anti-substitution).
     */
    public function empreinte(ProtocoleVersion $version): string
    {
        return EmpreinteReferentiel::duContenu($this->extraire($version));
    }

    /**
     * Les règles telles que le moteur les attend, depuis un instantané déjà publié.
     *
     * On lit `contenu_json`, JAMAIS les tables : c'est tout l'objet de l'instantané. Une version
     * publiée en janvier doit s'évaluer en juin exactement comme en janvier, même si quelqu'un a
     * ouvert un brouillon entre-temps.
     *
     * @param  array<string, mixed>  $instantane
     * @return array<int, array<string, mixed>>
     */
    public function reglesDe(array $instantane): array
    {
        return $instantane['regles'] ?? [];
    }

    /**
     * P10b-3-i — Les questions d'un instantané publié, indexées par clé.
     *
     * Comme `reglesDe`, on lit `contenu_json` et **jamais** les tables : c'est ce qui rend les
     * bornes opposables sans être révisables en douce. Un `UPDATE` direct sur
     * `protocole_questions` ne change donc pas ce que le serveur accepte d'un patient — il faut
     * republier, et la publication passe par les quatre validations et le quatre-yeux.
     *
     * Une version publiée sans aucune question rend un tableau vide, pas `null` : l'absence de
     * questionnaire est un état légitime (un protocole de niveau n'en a aucune), et distinguer
     * « pas de questions » de « clé absente » ferait porter à l'appelant une nuance sans objet.
     *
     * @param  array<string, mixed>  $instantane
     * @return array<string, array<string, mixed>>
     */
    public function questionsDe(array $instantane): array
    {
        $indexees = [];

        foreach ($instantane['questions'] ?? [] as $question) {
            $cle = (string) ($question['cle'] ?? '');

            if ($cle !== '') {
                $indexees[$cle] = $question;
            }
        }

        return $indexees;
    }
}
