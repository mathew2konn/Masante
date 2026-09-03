<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Services\ServiceConsultation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * B2-a — mener une consultation depuis le portail (CDC_11 §5.2).
 *
 * AUCUN IDENTIFIANT DE MEMBRE DANS L'URL, ni de consultation : la consultation sur laquelle on
 * agit est celle de la SESSION de dossier ouverte. C'est la règle héritée de la lecture (Module 4)
 * et de l'écriture (P7-D0) — l'anti-IDOR reste STRUCTUREL plutôt que vérifié. Un soignant ne peut
 * pas nommer la consultation d'un autre patient : il n'y a pas de champ pour le faire.
 *
 * Le contrôleur ne décide de RIEN : il traduit en HTTP ce que `ServiceConsultation` a jugé. La
 * permission est déclarée sur la route ET vérifiée dans le service ; celle qui fait autorité est
 * celle du service (les permissions spatie sont sur le guard `web`, piège de P4).
 */
class ConsultationController extends Controller
{
    public function __construct(private readonly ServiceConsultation $consultations) {}

    /** Ouvre la consultation du dossier en cours. */
    public function ouvrir(Request $request): RedirectResponse
    {
        $valide = $request->validate([
            'motif' => ['nullable', 'string', 'max:500'],
        ], [
            'motif.max' => 'Le motif de consultation ne peut pas dépasser 500 caractères.',
        ]);

        try {
            $this->consultations->ouvrir($request->user(), $valide['motif'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('portail.dossier.show')
            ->with('succes', 'Consultation ouverte.');
    }

    /** Consigne une observation dans la consultation en cours. */
    public function observer(Request $request): RedirectResponse
    {
        // MESSAGES EN FRANÇAIS, et ce n'est pas cosmétique : le G2 live a montré qu'un médecin
        // saisissant une observation vide lisait « The contenu field is required. » sur un portail
        // entièrement francophone. Ce projet n'a pas de répertoire `lang/` — le défaut est
        // TRANSVERSE et préexistant —, mais plusieurs FormRequest le corrigent déjà chez elles
        // (`LoginRequest`, `AnalyserTriageRequest`). On suit ce patron plutôt que d'en inventer un.
        //
        // À NOTER : `TrimStrings` puis `ConvertEmptyStringsToNull` transforment une saisie de
        // seuls espaces en `null`, donc c'est `required` qui refuse ici, jamais la garde du
        // service. Celle-ci reste atteignable par un appel direct (import, autre surface) et a son
        // propre vecteur — parade du « une couche, un vecteur » (P6.6b).
        $valide = $request->validate([
            'contenu' => ['required', 'string', 'max:5000'],
        ], [
            'contenu.required' => 'Une observation ne peut pas être vide.',
            'contenu.max' => 'Une observation ne peut pas dépasser 5000 caractères.',
        ]);

        $consultation = $this->consultations->enCoursPourLaSession();

        if ($consultation === null) {
            return back()->withErrors([
                'consultation' => 'Aucune consultation n\'est ouverte pour cet accès.',
            ])->withInput();
        }

        try {
            $this->consultations->observer($request->user(), $consultation, $valide['contenu']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('portail.dossier.show')
            ->with('succes', 'Observation consignée.');
    }

    /** Clôture la consultation en cours. */
    public function cloturer(Request $request): RedirectResponse
    {
        $consultation = $this->consultations->enCoursPourLaSession();

        if ($consultation === null) {
            return back()->withErrors([
                'consultation' => 'Aucune consultation n\'est ouverte pour cet accès.',
            ]);
        }

        try {
            $this->consultations->cloturer($request->user(), $consultation);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()
            ->route('portail.dossier.show')
            ->with('succes', 'Consultation clôturée.');
    }
}
