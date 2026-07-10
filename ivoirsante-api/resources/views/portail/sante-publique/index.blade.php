@extends('portail.layout')

@section('titre', 'Alertes épidémiques')

@section('content')
@php
  $badge = ['information' => 'info', 'vigilance' => 'warning', 'alerte' => 'danger'];
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-broadcast"></i> Alertes épidémiques</h1>
    <p class="text-muted mb-0">Bulletins sanitaires diffusés aux patients par commune.</p>
  </div>
  <a href="{{ route('portail.sante-publique.create') }}" class="btn btn-ms btn-sm">
    <i class="bi bi-plus-lg"></i> Nouvelle alerte
  </a>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Maladie</th><th>Commune</th><th>Niveau</th><th>Période</th><th>État</th><th class="text-end">Actions</th></tr>
      </thead>
      <tbody>
        @forelse ($alertes as $a)
          <tr>
            <td>
              <strong>{{ $a->maladie }}</strong>
              <div class="text-muted small">{{ $a->titre }}</div>
            </td>
            <td>
              @if ($a->commune === \App\Models\AlerteEpidemique::NATIONALE)
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Nationale</span>
              @else
                {{ $a->commune }}
              @endif
            </td>
            <td><span class="badge bg-{{ $badge[$a->niveau_alerte] }}">{{ ucfirst($a->niveau_alerte) }}</span></td>
            <td class="small">
              {{ $a->date_debut->format('d/m/Y') }}
              @if ($a->date_fin) → {{ $a->date_fin->format('d/m/Y') }} @else <span class="text-muted">en cours</span> @endif
            </td>
            <td>
              @if ($a->actif)
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Diffusée</span>
              @else
                <span class="badge bg-secondary">Retirée</span>
              @endif
            </td>
            <td class="text-end text-nowrap">
              <a href="{{ route('portail.sante-publique.edit', $a) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
              <form method="POST" action="{{ route('portail.sante-publique.toggle', $a) }}" class="d-inline">
                @csrf @method('PATCH')
                <button class="btn btn-sm {{ $a->actif ? 'btn-outline-danger' : 'btn-outline-success' }}">
                  <i class="bi {{ $a->actif ? 'bi-slash-circle' : 'bi-broadcast' }}"></i>
                  {{ $a->actif ? 'Retirer' : 'Diffuser' }}
                </button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox"></i> Aucune alerte publiée.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $alertes->links() }}</div>

<p class="text-muted small mt-3">
  <i class="bi bi-info-circle"></i>
  Chaque patient ne voit que les alertes <strong>diffusées</strong> de sa commune de résidence, plus les
  alertes nationales. Reportez fidèlement les bulletins de l'OMS et du Ministère de la Santé.
</p>
@endsection
