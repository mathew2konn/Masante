<?php

namespace App\Services\Protocole;

use App\Services\Urgence\ServiceNumerosUrgence;

/**
 * P10b-1 — La résolution des marqueurs dans les messages d'un protocole.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LE PROBLÈME QU'IL RÉSOUT — DEUX EXIGENCES QUI SE CONTREDISENT EN APPARENCE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le texte de recommandation du triage disait :
 *
 *     'Niveau URGENT : … ou appelez le SAMU au ' . $this->numeros->numero('samu') . ' …'
 *
 * Deux règles du corpus s'y rencontrent, et elles tirent dans des directions opposées :
 *
 *   - **CDC_08 §1.2** — le texte est une consigne clinique, il doit vivre dans le protocole, pas
 *     dans le code ;
 *   - **CDC_02 §37** — le numéro d'urgence ne doit être en dur nulle part, et P6.8e l'a placé dans
 *     un référentiel national précisément pour qu'il soit corrigeable sans republier quoi que ce
 *     soit.
 *
 * Coller le numéro dans le texte du protocole satisferait la première en **détruisant la seconde** :
 * on aurait déplacé le « 185 » d'un fichier PHP vers une ligne de base, où il se périmerait tout
 * aussi silencieusement — et où le corriger exigerait cette fois de refaire passer le protocole par
 * les quatre validations du §7, pour un changement qui n'a rien de clinique.
 *
 * D'où le marqueur `{urgence:samu}` : le protocole porte la CONSIGNE, le référentiel porte le
 * NUMÉRO, et chacun se corrige par sa propre porte. La substitution est un travail de rendu, jamais
 * une règle — ce service ne décide rien, il remplace.
 *
 * ═══ UN MARQUEUR NON RÉSOLU N'EST JAMAIS AFFICHÉ TEL QUEL ═══
 *
 * « Appelez le SAMU au {urgence:samu} » sur l'écran de quelqu'un qui doit joindre des secours
 * serait pire que pas de phrase du tout. Le contrôle qualité refuse à la publication un marqueur
 * qui ne résout pas ; à l'exécution, on refuse bruyamment plutôt que d'afficher un texte cassé.
 */
final class MessagesProtocole
{
    /**
     * `{urgence:<code>}` — le seul marqueur reconnu, et la fermeture est délibérée.
     *
     * Une syntaxe générale (`{referentiel:x:y}`) ferait du contenu d'un message une expression à
     * interpréter, écrite par un rédacteur, exécutée sur l'écran d'un patient. Même raisonnement
     * que les listes blanches de faits et d'opérateurs : ce qui arrive par la donnée ne devient
     * jamais un choix libre.
     */
    private const MOTIF = '/\{urgence:([a-z_]+)\}/';

    public function __construct(private readonly ServiceNumerosUrgence $numeros) {}

    /**
     * Remplace les marqueurs par les valeurs du référentiel national.
     *
     * @throws ProtocoleException si un marqueur ne résout pas — voir l'en-tête.
     */
    public function resoudre(string $texte): string
    {
        $manquants = $this->marqueursNonResolus($texte);

        if ($manquants !== []) {
            throw new ProtocoleException(
                'Le protocole en vigueur cite un numéro d\'urgence qui n\'est plus publié : '
                .implode(', ', $manquants).'. Le message ne peut pas être affiché tel quel.',
                503,
            );
        }

        return (string) preg_replace_callback(
            self::MOTIF,
            fn (array $c): string => $this->numeros->numero($c[1]),
            $texte,
        );
    }

    /**
     * Les codes cités par le texte qui ne résolvent pas.
     *
     * Utilisé par le contrôle qualité AVANT publication et par `resoudre()` à l'exécution : un
     * seul endroit décide de ce qui résout, donc les deux ne peuvent pas diverger. C'est le motif
     * qui a manqué en P6.4d, où un test affirmait un comportement que le code n'avait plus.
     *
     * @return array<int, string>
     */
    public function marqueursNonResolus(string $texte): array
    {
        preg_match_all(self::MOTIF, $texte, $trouves);

        $manquants = [];

        foreach (array_unique($trouves[1] ?? []) as $code) {
            try {
                $this->numeros->numero($code);
            } catch (\RuntimeException) {
                // `numero()` ne replie que sur `samu` (P6.8e) : pour tout autre code il lève, et
                // *un numéro d'urgence faux est plus dangereux qu'un numéro absent*. On remonte
                // le code tel quel, à charge du rédacteur de le corriger.
                $manquants[] = $code;
            }
        }

        return $manquants;
    }
}
