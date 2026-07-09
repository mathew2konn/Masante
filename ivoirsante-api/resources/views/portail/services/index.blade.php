@extends('portail.layout')

@section('titre', 'Mes services')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-clipboard2-pulse"></i> Mes services</h1>
    <p class="text-muted mb-0">Les services médicaux de votre établissement.</p>
  </div>
  <a href="{{ route('portail.services.create') }}" class="btn btn-ms"><i class="bi bi-plus-lg"></i> Nouveau service</a>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-8">
    <input type="text" name="q" class="form-control" placeholder="Rechercher (nom ou spécialité)…" value="{{ $recherche }}">
  </div>
  <div class="col-md-2 d-grid">
    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrer</button>
  </div>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Service</th>
          <th>Spécialité</th>
          <th class="text-center">Agents</th>
          <th class="text-center">Statut</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($services as $service)
          <tr class="{{ $service->actif ? '' : 'table-secondary' }}">
            <td class="fw-medium">{{ $service->nom_service }}</td>
            <td><code>{{ $service->specialite }}</code></td>
            <td class="text-center">{{ $service->agents_count }}</td>
            <td class="text-center">
              @if ($service->actif)
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Actif</span>
              @else
                <span class="badge bg-secondary">Désactivé</span>
              @endif
            </td>
            <td class="text-end text-nowrap">
              <a href="{{ route('portail.services.edit', $service) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
              <form method="POST" action="{{ route('portail.services.toggle', $service) }}" class="d-inline"
                    onsubmit="return confirm('{{ $service->actif ? 'Désactiver' : 'Réactiver' }} ce service ?');">
                @csrf @method('PATCH')
                <button class="btn btn-sm {{ $service->actif ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit">
                  <i class="bi {{ $service->actif ? 'bi-slash-circle' : 'bi-arrow-counterclockwise' }}"></i>
                  {{ $service->actif ? 'Désactiver' : 'Réactiver' }}
                </button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-4">Aucun service. Créez-en un pour commencer.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $services->links() }}</div>
@endsection
