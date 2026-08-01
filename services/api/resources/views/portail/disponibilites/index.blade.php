@extends('portail.layout')

@section('titre', 'Disponibilité')

@php
  $badges = [
    'disponible'           => 'bg-success',
    'disponible_apres_14h' => 'bg-info text-dark',
    'complet'              => 'bg-warning text-dark',
    'ferme'                => 'bg-secondary',
  ];
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-toggles"></i> Disponibilité</h1>
    <p class="text-muted mb-0">Mettez à jour la disponibilité de vos services pour la journée.</p>
  </div>
  <form method="GET" class="d-flex align-items-center gap-2">
    <label class="form-label mb-0 small text-muted">Date</label>
    <input type="date" name="date" value="{{ $date }}" min="{{ now()->toDateString() }}"
           class="form-control form-control-sm" onchange="this.form.submit()">
  </form>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Service</th>
          <th>Statut du {{ \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') }}</th>
          <th>Places</th>
          <th>Note</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($services as $service)
          @php($d = $dispos->get($service->id))
          <tr>
            <td class="fw-medium">{{ $service->nom_service }} <span class="text-muted small">({{ $service->specialite }})</span></td>
            <td>
              @if ($d)
                <span class="badge {{ $badges[$d->statut] ?? 'bg-light text-dark' }}">{{ $statuts[$d->statut] ?? $d->statut }}</span>
                @if ($d->heure_debut_dispo) <span class="small text-muted">dès {{ \Illuminate\Support\Carbon::parse($d->heure_debut_dispo)->format('H:i') }}</span> @endif
              @else
                <span class="text-muted small"><i class="bi bi-dash-circle"></i> Non renseigné</span>
              @endif
            </td>
            <td>{{ $d?->nb_places_restantes ?? '—' }}</td>
            <td class="small text-muted">{{ \Illuminate\Support\Str::limit($d?->note, 40) ?: '—' }}</td>
            <td class="text-end">
              <a href="{{ route('portail.disponibilites.edit', ['service' => $service, 'date' => $date]) }}"
                 class="btn btn-sm btn-ms"><i class="bi bi-pencil"></i> Modifier</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-4">Aucun service à gérer.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
