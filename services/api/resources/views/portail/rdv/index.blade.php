@extends('portail.layout')

@section('titre', 'Rendez-vous')

@php
  // B1-a — `prevalide` ajouté aux DEUX tables : sans lui, ce statut (que
  // `RendezVousValidationService::STATUTS` produit désormais) tombait sur le repli
  // `$libelleStatut[$statut] ?? $statut`, affichant le mot technique brut.
  $badgeStatut = [
    'en_attente' => 'bg-warning text-dark', 'prevalide' => 'bg-info text-dark', 'confirme' => 'bg-success',
    'refuse' => 'bg-danger', 'annule' => 'bg-secondary', 'honore' => 'bg-primary',
  ];
  $libelleStatut = [
    'en_attente' => 'En attente', 'prevalide' => 'Pré-validé', 'confirme' => 'Confirmé', 'refuse' => 'Refusé',
    'annule' => 'Annulé', 'honore' => 'Honoré',
  ];
@endphp

@section('content')
<div class="mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-calendar-check"></i> Rendez-vous</h1>
  <p class="text-muted mb-0">Confirmez ou refusez les demandes de rendez-vous de vos services.</p>
</div>

<ul class="nav nav-pills mb-3 flex-wrap gap-1">
  @foreach ($statuts as $s)
    <li class="nav-item">
      <a class="nav-link {{ $statut === $s ? 'active' : '' }}" href="{{ route('portail.rdv.index', ['statut' => $s]) }}">
        {{ $libelleStatut[$s] ?? $s }}
      </a>
    </li>
  @endforeach
</ul>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Patient</th>
          <th>Service</th>
          <th>Motif</th>
          <th>Date souhaitée</th>
          <th class="text-center">Triage</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rdvs as $rdv)
          <tr>
            <td class="fw-medium">{{ $rdv->membre?->prenom }} {{ $rdv->membre?->nom }}</td>
            <td>{{ $rdv->service?->nom_service ?? '—' }}</td>
            <td class="small">{{ \Illuminate\Support\Str::limit($rdv->motif, 50) }}</td>
            <td>{{ \Illuminate\Support\Carbon::parse($rdv->date_souhaitee)->format('d/m/Y') }}</td>
            <td class="text-center">
              @if ($rdv->triage)
                <span class="badge bg-light text-dark border" title="Niveau de triage joint">
                  <i class="bi bi-clipboard2-pulse"></i> {{ $rdv->triage->niveau ?? 'joint' }}
                </span>
              @else <span class="text-muted small">—</span> @endif
            </td>
            <td class="text-end">
              <a href="{{ route('portail.rdv.show', $rdv) }}" class="btn btn-sm btn-outline-secondary">
                {{ in_array($statut, ['en_attente', 'prevalide'], true) ? 'Traiter' : 'Détails' }} <i class="bi bi-arrow-right"></i>
              </a>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-muted py-4">Aucun rendez-vous « {{ $libelleStatut[$statut] ?? $statut }} ».</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $rdvs->links() }}</div>
@endsection
