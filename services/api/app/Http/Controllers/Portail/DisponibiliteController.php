<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\DisponibiliteJour;
use App\Models\ServiceEtablissement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.4 — Disponibilité quotidienne d'un service (CdC §5.4.1 étape 6, F3.3).
 *
 * L'agent coche la disponibilité de SON service ; le gestionnaire, superviseur, celle de TOUS ses
 * services (périmètre {@see \App\Models\User::servicesGeresIds()}). La ligne est unique par
 * (service, date) → upsert. `updated_by_agent_id` trace l'auteur. Protégé par `permission:disponibilite.manage`.
 */
class DisponibiliteController extends Controller
{
    /** Statuts de disponibilité (miroir de l'enum de la migration disponibilites_jour). */
    public const STATUTS = [
        'disponible'            => 'Disponible',
        'disponible_apres_14h'  => 'Disponible après 14h',
        'complet'               => 'Complet',
        'ferme'                 => 'Fermé',
    ];

    /** Services que le compte connecté peut gérer ; 403 si aucun (compte non habilité). */
    private function serviceIds(): array
    {
        $ids = auth()->user()->servicesGeresIds();
        abort_if($ids === [], Response::HTTP_FORBIDDEN, 'Aucun service à gérer pour ce compte.');

        return $ids;
    }

    /** Récupère un service DE MON périmètre, ou 404. */
    private function serviceEnPerimetre(ServiceEtablissement $service): ServiceEtablissement
    {
        abort_if(! in_array($service->id, $this->serviceIds(), true), Response::HTTP_NOT_FOUND);

        return $service;
    }

    public function index(Request $request): View
    {
        $date = $this->dateChoisie($request);
        $ids = $this->serviceIds();

        $services = ServiceEtablissement::whereIn('id', $ids)->orderBy('nom_service')->get();
        $dispos = DisponibiliteJour::whereIn('service_id', $ids)
            ->whereDate('date', $date)
            ->get()
            ->keyBy('service_id');

        return view('portail.disponibilites.index', [
            'services' => $services,
            'dispos'   => $dispos,
            'date'     => $date,
            'statuts'  => self::STATUTS,
        ]);
    }

    public function edit(Request $request, ServiceEtablissement $service): View
    {
        $this->serviceEnPerimetre($service);
        $date = $this->dateChoisie($request);

        $dispo = DisponibiliteJour::where('service_id', $service->id)->whereDate('date', $date)->first();

        return view('portail.disponibilites.edit', [
            'service' => $service,
            'dispo'   => $dispo,
            'date'    => $date,
            'statuts' => self::STATUTS,
        ]);
    }

    public function update(Request $request, ServiceEtablissement $service): RedirectResponse
    {
        $this->serviceEnPerimetre($service);

        $data = $request->validate([
            'date'                => ['required', 'date', 'after_or_equal:today'],
            'statut'              => ['required', 'string', 'in:' . implode(',', array_keys(self::STATUTS))],
            'nb_places_restantes' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'heure_debut_dispo'   => ['nullable', 'date_format:H:i'],
            'note'                => ['nullable', 'string', 'max:500'],
        ]);

        DisponibiliteJour::updateOrCreate(
            ['service_id' => $service->id, 'date' => $data['date']],
            [
                'statut'              => $data['statut'],
                'nb_places_restantes' => $data['nb_places_restantes'] ?? null,
                'heure_debut_dispo'   => $data['heure_debut_dispo'] ?? null,
                'note'                => $data['note'] ?? null,
                'updated_by_agent_id' => auth()->id(),
            ],
        );

        return redirect()
            ->route('portail.disponibilites.index', ['date' => $data['date']])
            ->with('statut', 'Disponibilité mise à jour.');
    }

    /** Date choisie (query `date`), bornée à aujourd'hui au minimum ; défaut = aujourd'hui. */
    private function dateChoisie(Request $request): string
    {
        $date = $request->date('date');

        if ($date === null || $date->isPast() && ! $date->isToday()) {
            $date = now();
        }

        return $date->toDateString();
    }
}
