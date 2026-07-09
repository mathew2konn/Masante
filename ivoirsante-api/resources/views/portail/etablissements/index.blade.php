@extends('portail.layout')

@section('titre', 'Établissements')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-hospital"></i> Établissements</h1>
    <p class="text-muted mb-0">Inscrire et gérer les établissements partenaires.</p>
  </div>
  <a href="{{ route('portail.etablissements.create') }}" class="btn btn-ms">
    <i class="bi bi-plus-lg"></i> Nouvel établissement
  </a>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-6">
    <input type="text" name="q" class="form-control" placeholder="Rechercher par nom ou commune…" value="{{ $recherche }}">
  </div>
  <div class="col-md-4">
    <select name="type" class="form-select">
      <option value="">Tous les types</option>
      @foreach ($types as $cle => $libelle)
        <option value="{{ $cle }}" @selected($typeActif === $cle)>{{ $libelle }}</option>
      @endforeach
    </select>
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
          <th>Nom</th>
          <th>Type</th>
          <th>Commune</th>
          <th class="text-center">Services</th>
          <th>Gestionnaire</th>
          <th class="text-center">Statut</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($etablissements as $etab)
          @php($gest = $etab->staff->first())
          <tr class="{{ $etab->actif ? '' : 'table-secondary' }}">
            <td class="fw-medium">{{ $etab->nom }}</td>
            <td>{{ $types[$etab->type] ?? $etab->type }}</td>
            <td>{{ $etab->commune }}</td>
            <td class="text-center">{{ $etab->services_count }}</td>
            <td>
              @if ($gest)
                {{ $gest->prenom }} {{ $gest->nom }}<br>
                @if ($gest->password === null)
                  <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Activation en attente</span>
                @else
                  <span class="badge bg-success"><i class="bi bi-check-circle"></i> Activé</span>
                @endif
              @else
                <span class="text-muted small">—</span>
              @endif
            </td>
            <td class="text-center">
              @if ($etab->actif)
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Actif</span>
              @else
                <span class="badge bg-secondary">Désactivé</span>
              @endif
            </td>
            <td class="text-end text-nowrap">
              <a href="{{ route('portail.etablissements.edit', $etab) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-pencil"></i>
              </a>
              <form method="POST" action="{{ route('portail.etablissements.toggle', $etab) }}" class="d-inline"
                    onsubmit="return confirm('{{ $etab->actif ? 'Désactiver' : 'Réactiver' }} cet établissement ?');">
                @csrf @method('PATCH')
                <button class="btn btn-sm {{ $etab->actif ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit">
                  <i class="bi {{ $etab->actif ? 'bi-slash-circle' : 'bi-arrow-counterclockwise' }}"></i>
                  {{ $etab->actif ? 'Désactiver' : 'Réactiver' }}
                </button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">Aucun établissement trouvé.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">
  {{ $etablissements->links() }}
</div>
@endsection
