<?php

namespace App\Services\Urgence;

use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\ReferentielException;
use App\Services\Referentiel\SourceNumerosUrgence;
use Illuminate\Support\Facades\Log;

/**
 * P6.8e — « Que compose-t-on ? », lu au référentiel publié (CDC_09 §8).
 *
 * ═══ IL LIT LA VERSION PUBLIÉE, PAS LA TABLE ═══
 *
 * Même décision qu'en P6.8b et qu'en L1+L2 : corriger un numéro par un `UPDATE` direct n'a aucun
 * effet avant publication. C'est le but (§1.2.4) — un numéro d'urgence est exactement le genre de
 * valeur qu'on ne veut pas voir changer sans que deux personnes l'aient décidé.
 *
 * ═══ MAIS ICI L'ÉCHEC N'EST PAS BRUYANT DE LA MÊME FAÇON — ET C'EST LA DÉCISION DU MODULE ═══
 *
 * `ServiceCalendrierVaccinal` lève un 503 quand rien n'est publié, et c'est juste : personne n'est
 * en danger parce qu'un calendrier vaccinal est indisponible dix minutes. **Un texte de triage sans
 * numéro de secours, si.**
 *
 * Ce service porte donc un **repli déclaré** : la valeur livrée avec l'application, celle-là même
 * qui vivait en dur dans `TriageService` avant cet incrément. Ce n'est pas un relâchement du motif,
 * c'est son déplacement — et il s'accompagne de deux obligations qui le distinguent d'un silence :
 *
 *   1. **le repli est JOURNALISÉ en warning**, à chaque fois. L'honnêteté sur le repli est due à
 *      l'exploitant, pas au citoyen en train de composer un numéro (décision propriétaire C1) ;
 *   2. **{@see estEnVigueur()} dit la vérité sans repli**, et c'est ce que l'écran du portail lit
 *      pour afficher « aucune version en vigueur ».
 *
 * *Un repli qu'on peut voir dans les journaux et sur un écran d'exploitation n'est pas un repli
 * silencieux ; c'est le seul point du projet où la disponibilité passe devant la traçabilité, et il
 * est écrit.*
 *
 * ═══ CE QUE CE SERVICE NE FAIT PAS ═══
 *
 * Il ne décide de rien : ni quel numéro appeler pour quel symptôme, ni dans quel ordre. Il expose
 * une donnée. Le triage compose son texte, le mobile affiche ses boutons — aucune règle médicale ne
 * vit ici (CDC_00 §4).
 */
final class ServiceNumerosUrgence
{
    /**
     * LE REPLI DE DERNIER RECOURS, ET IL N'EN CONTIENT QU'UN.
     *
     * Le SAMU, parce que c'est le seul numéro que le corpus nomme (CDC_00 §4 l'oppose explicitement
     * au « 15 » français) et parce que ceci est une application de santé. **Le 100 et le 180 n'y
     * figurent délibérément pas** : ils ont été déclarés par le propriétaire sans être confrontés à
     * un arrêté, et compiler dans l'application une valeur qu'on n'a pas vérifiée reviendrait à
     * refaire, en plus discret, exactement le défaut que cet incrément referme.
     *
     * Ils vivent donc dans le référentiel, d'où ils peuvent être corrigés sans republier quoi que ce
     * soit — ce qui est tout l'objet du module.
     */
    public const REPLI = [
        'code'    => 'samu',
        'numero'  => '185',
        'libelle' => 'SAMU',
    ];

    /** @var array<string, mixed>|false|null Instantané en vigueur ; `false` = cherché, aucun. */
    private array|false|null $publie = null;

    /**
     * Le repli n'est journalisé qu'UNE FOIS par requête.
     *
     * Sans ce garde-fou, un écran qui demande trois numéros écrirait trois lignes identiques : le
     * journal cesserait de dire « une version manque » pour dire « il s'est passé beaucoup de
     * choses », et c'est ainsi qu'un avertissement devient invisible.
     */
    private bool $repliJournalise = false;

    public function __construct(private readonly DiffusionReferentiel $diffusion) {}

    /**
     * Le numéro à composer pour ce code, jamais vide.
     *
     * C'est LE point d'entrée du backend : `TriageService` l'appelle, et plus aucune ligne de code
     * ne porte « 185 » en dur hors de {@see REPLI}.
     */
    public function numero(string $code): string
    {
        foreach ($this->actifs() as $entree) {
            if (($entree['code'] ?? null) === $code) {
                return (string) $entree['numero'];
            }
        }

        if ($code === self::REPLI['code']) {
            // Deux causes distinctes, et le journal doit les distinguer : soit rien n'est publié —
            // `contenu()` l'a déjà écrit —, soit une version est bien en vigueur mais ne porte pas
            // ce code, ce qui est un tout autre problème et se corrige ailleurs.
            if ($this->lireSiPubliee() !== null) {
                $this->journaliserRepli("une version est en vigueur mais ne porte pas le code « {$code} »");
            }

            return self::REPLI['numero'];
        }

        // AUCUN REPLI INVENTÉ pour les autres codes : *un numéro d'urgence faux est plus dangereux
        // qu'un numéro absent, parce qu'il sera composé.* L'appelant doit gérer l'absence.
        throw new \RuntimeException("Aucun numéro d'urgence publié pour le code « {$code} ».");
    }

    /**
     * Les numéros actifs de la version en vigueur, dans l'ordre du référentiel.
     *
     * Tableau VIDE si rien n'est publié — c'est {@see numero()} qui décide de replier, pas cette
     * lecture : un appelant qui veut afficher une liste doit pouvoir constater qu'elle est vide.
     *
     * @return array<int, array<string, mixed>>
     */
    public function actifs(): array
    {
        return array_values(array_filter(
            $this->contenu(),
            static fn (array $entree): bool => (bool) ($entree['actif'] ?? false),
        ));
    }

    /** Une version est-elle en vigueur ? NE REPLIE PAS, ne journalise pas — c'est la vérité brute. */
    public function estEnVigueur(): bool
    {
        return $this->lireSiPubliee() !== null;
    }

    /** Le numéro de la version en vigueur, ou `null`. */
    public function version(): ?int
    {
        $publie = $this->lireSiPubliee();

        return $publie === null ? null : (int) $publie['version'];
    }

    /**
     * Le contenu publié, ou un tableau vide — avec journalisation du repli.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contenu(): array
    {
        $publie = $this->lireSiPubliee();

        if ($publie === null) {
            $this->journaliserRepli('aucune version du référentiel n\'est en vigueur');

            return [];
        }

        return $publie['contenu'];
    }

    /**
     * Le seul endroit qui écrit la trace du repli.
     *
     * `warning` et non `error` : ce n'est pas une panne — le système continue de rendre le service
     * attendu. Mais ce n'est pas non plus une information : quelqu'un doit publier une version.
     */
    private function journaliserRepli(string $raison): void
    {
        if ($this->repliJournalise) {
            return;
        }

        $this->repliJournalise = true;

        Log::warning('Numéros d\'urgence : repli sur la valeur livrée avec l\'application.', [
            'raison'      => $raison,
            'referentiel' => SourceNumerosUrgence::CODE,
            'repli'       => self::REPLI['numero'],
        ]);
    }

    /**
     * L'instantané en vigueur, ou `null`.
     *
     * MÉMOÏSÉ, et `false` distingue « pas encore cherché » de « cherché, rien trouvé » — sans quoi
     * une absence de publication ferait rejouer la lecture, et journaliser un repli, à chaque appel
     * dans la même requête (motif `ServiceCalendrierVaccinal`).
     *
     * @return array<string, mixed>|null
     */
    private function lireSiPubliee(): ?array
    {
        if ($this->publie !== null) {
            return $this->publie === false ? null : $this->publie;
        }

        try {
            return $this->publie = $this->diffusion->lire(SourceNumerosUrgence::CODE);
        } catch (ReferentielException) {
            $this->publie = false;

            return null;
        }
    }
}
