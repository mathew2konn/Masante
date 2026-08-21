<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use App\Services\Protocole\CompilateurProtocole;
use App\Services\Protocole\ControleQualiteProtocole;
use App\Services\Protocole\ProtocoleException;
use App\Services\Protocole\ServiceGouvernanceProtocole;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;
use App\Support\RegistreFaitsProtocole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * P10b-3-ii — L'écran des quatre validations du §7 : LIRE ET SIGNER, jamais éditer.
 *
 * ═══ POURQUOI CET ÉCRAN EXISTE ═══
 *
 * Depuis P10b-1, les quatre validations s'obtiennent en `curl`. Or le §7 qualifie le dossier de
 * validation d'**opposable** : c'est la pièce qu'on produirait devant un tribunal. Demander à un
 * médecin spécialiste de signer une posologie par une commande shell n'est pas seulement peu
 * pratique — cela revient à lui faire signer un texte qu'il n'a pas lu **sous une forme lisible**.
 *
 * ═══ POURQUOI IL N'ÉDITE PAS (décision Q2 du G1 de P10b-3) ═══
 *
 * Un éditeur de règles complet serait le plus gros investissement Blade du projet, dans un portail
 * qu'ADR-011 condamne. Il n'y a donc **aucun bouton « modifier »** : les brouillons se construisent
 * par seeder ou par API, et cet écran sert ce que le §7 demande — relire et signer.
 *
 * ═══ CE QU'IL DOIT MONTRER SANS QUE PERSONNE N'AIT À LE DÉDUIRE ═══
 *
 * Qu'une validation **porte encore sur le contenu actuel**. C'est l'information dont un signataire a
 * le plus besoin : elle lui dit que le texte a bougé depuis qu'un confrère l'a relu. L'afficher
 * discrètement reviendrait à laisser signer par-dessus une relecture périmée — exactement ce que
 * l'anti-substitution de P10b-1 existe pour empêcher.
 */
class ProtocoleValidationController extends Controller
{
    public function __construct(
        private readonly ServiceGouvernanceProtocole $gouvernance,
        private readonly CompilateurProtocole $compilateur,
        private readonly ControleQualiteProtocole $qualite,
    ) {}

    public function index(): View
    {
        $protocoles = Protocole::query()
            ->with(['versions' => fn ($q) => $q->orderByDesc('numero')])
            ->orderBy('code')
            ->get();

        return view('portail.protocoles.index', [
            'protocoles' => $protocoles,
            'contextes' => RegistreContextesProtocole::CONTEXTES,
        ]);
    }

    public function show(Protocole $protocole, ProtocoleVersion $version): View
    {
        abort_unless($version->protocole_id === $protocole->id, 404);

        $empreinte = $this->compilateur->empreinte($version);
        $instantane = $this->compilateur->extraire($version);

        return view('portail.protocoles.show', [
            'protocole' => $protocole,
            'version' => $version->load(['validations', 'references']),
            'regles' => $this->reglesLisibles($version),
            'questions' => $instantane['questions'] ?? [],
            'empreinte' => $empreinte,
            'validations' => $this->dossier($version, $empreinte),
            'types' => ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION,
            // Les défauts du §7.4 sont montrés AVANT la signature : les découvrir au moment de
            // publier ferait relire pour rien.
            'anomalies' => $this->qualite->controler($instantane),
        ]);
    }

    public function valider(Request $request, Protocole $protocole, ProtocoleVersion $version): RedirectResponse
    {
        abort_unless($version->protocole_id === $protocole->id, 404);

        $data = $request->validate([
            'type' => ['required', 'string'],
            'avis' => ['required', 'in:favorable,defavorable'],
            'role' => ['required', 'string', 'max:150'],
            'commentaires' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->gouvernance->valider(
                $version,
                $request->user(),
                $data['type'],
                $data['avis'],
                $data['role'],
                $data['commentaires'] ?? null,
            );
        } catch (ProtocoleException $e) {
            // Le motif du refus est rendu tel quel : c'est lui qui apprend au relecteur ce qui
            // manque (une signature d'un autre type, une habilitation, un contenu modifié depuis).
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', 'Validation enregistrée. Elle nomme votre compte et votre rôle.');
    }

    public function publier(Protocole $protocole, ProtocoleVersion $version, Request $request): RedirectResponse
    {
        abort_unless($version->protocole_id === $protocole->id, 404);

        try {
            $this->gouvernance->publier($version, $request->user());
        } catch (ProtocoleException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', 'Version mise en vigueur.');
    }

    /**
     * Le dossier de validation, avec la seule information qu'un signataire ne doit pas déduire.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function dossier(ProtocoleVersion $version, string $empreinte): array
    {
        $par = [];

        foreach ($version->validations as $v) {
            $par[$v->type] = [
                'avis' => $v->avis,
                'validateur' => $v->validateur_nom,
                'role' => $v->validateur_role,
                'commentaires' => $v->commentaires,
                'valide_le' => $v->valide_le,
                'porte_sur_le_contenu_actuel' => $v->avis === 'favorable'
                    && $v->autorisePublication($empreinte),
            ];
        }

        return $par;
    }

    /**
     * Les règles rendues en français, à partir des libellés des trois listes blanches.
     *
     * Un relecteur clinique ne doit pas avoir à lire du JSON pour signer une pièce opposable.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reglesLisibles(ProtocoleVersion $version): array
    {
        $lignes = [];

        foreach ($version->regles()->with(['conditions', 'actions'])->orderBy('ordre')->get() as $regle) {
            $conditions = [];

            foreach ($regle->conditions as $condition) {
                $valeur = $condition->valeur();

                $conditions[] = trim(sprintf(
                    '%s %s %s',
                    RegistreFaitsProtocole::libelle($condition->fait),
                    $condition->operateur,
                    is_array($valeur) ? implode(' et ', $valeur) : var_export($valeur, true),
                ));
            }

            $actions = [];

            foreach ($regle->actions as $action) {
                $valeur = $action->valeur();

                $actions[] = trim(
                    RegistreActionsProtocole::libelle($action->type)
                    .' '.(is_array($valeur) ? implode(', ', $valeur) : (string) $valeur)
                );
            }

            $lignes[] = [
                'ordre' => $regle->ordre,
                'libelle' => $regle->libelle,
                // Une règle sans condition s'applique toujours — le dire plutôt que d'afficher un
                // vide, qu'un relecteur lirait comme une omission.
                'conditions' => $conditions === [] ? ['(toujours)'] : $conditions,
                'actions' => $actions,
                'justifications' => $regle->actions->pluck('justification')->filter()->values()->all(),
            ];
        }

        return $lignes;
    }
}
