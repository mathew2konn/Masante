@extends('portail.layout')

@section('titre', 'Don de sang')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-droplet-half"></i> Don de sang</h1>
    <p class="text-muted mb-0">Les besoins de votre établissement, visibles des donneurs de l'application.</p>
  </div>
  <a href="{{ route('portail.don-sang.create') }}" class="btn btn-ms"><i class="bi bi-megaphone"></i> Publier un besoin</a>
</div>

{{-- Le point à défendre en soutenance : l'établissement voit COMBIEN de donneurs peuvent répondre,
     jamais QUI ils sont. Aucun nom, aucun numéro, aucun export (minimisation, loi 2013-450). --}}
<div class="alert alert-info d-flex gap-2">
  <i class="bi bi-shield-lock"></i>
  <div class="small">
    Les donneurs compatibles sont alertés dans leur application et se présentent d'eux-mêmes.
    Vous voyez le <strong>nombre</strong> de donneurs mobilisables, jamais leur identité : donner reste
    une décision, pas une convocation.
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Groupe</th>
          <th>Niveau</th>
          <th>Période</th>
          <th>Message</th>
          <th class="text-center">Donneurs mobilisables</th>
          <th class="text-center">Statut</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($besoins as $besoin)
          <tr class="{{ $besoin->actif ? '' : 'table-secondary' }}">
            <td>
              <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle fs-6">
                {{ $besoin->groupe_sanguin }}
              </span>
            </td>
            <td>
              @if ($besoin->niveau === 'urgent')
                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Urgence</span>
              @else
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Courant</span>
              @endif
            </td>
            <td class="small text-nowrap">
              {{ $besoin->date_debut->format('d/m/Y') }} →
              {{ $besoin->date_fin?->format('d/m/Y') ?? 'en cours' }}
            </td>
            <td class="small text-muted">{{ $besoin->message ?? '—' }}</td>
            <td class="text-center fw-semibold">{{ $viviers[$besoin->id] ?? 0 }}</td>
            <td class="text-center">
              @if ($besoin->actif)
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Publié</span>
              @else
                <span class="badge bg-secondary">Clôturé</span>
              @endif
            </td>
            <td class="text-end text-nowrap">
              <a href="{{ route('portail.don-sang.edit', $besoin) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
              <form method="POST" action="{{ route('portail.don-sang.toggle', $besoin) }}" class="d-inline">
                @csrf @method('PATCH')
                <button class="btn btn-sm btn-outline-{{ $besoin->actif ? 'warning' : 'success' }}" type="submit">
                  <i class="bi bi-{{ $besoin->actif ? 'x-lg' : 'arrow-clockwise' }}"></i>
                </button>
              </form>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" class="text-center text-muted py-4">
              Aucun besoin publié. <a href="{{ route('portail.don-sang.create') }}">Publiez-en un</a> pour
              qu'il apparaisse dans les groupes demandés de l'application.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $besoins->links() }}</div>
@endsection
