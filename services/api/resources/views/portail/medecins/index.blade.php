@extends('portail.layout')

@section('titre', 'Mes médecins')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-person-vcard"></i> Mes médecins</h1>
    <p class="text-muted mb-0">Les praticiens de votre établissement, visibles des patients (rendez-vous et médecin référent).</p>
  </div>
  <a href="{{ route('portail.medecins.create') }}" class="btn btn-ms"><i class="bi bi-plus-lg"></i> Nouveau praticien</a>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-8">
    <input type="text" name="q" class="form-control" placeholder="Rechercher (nom, prénom ou spécialité)…" value="{{ $recherche }}">
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
          <th>N° national</th>
          <th>Praticien</th>
          <th>Spécialité</th>
          <th>Service</th>
          <th>Compte relié</th>
          <th class="text-center">Annuaire</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($medecins as $medecin)
          <tr class="{{ $medecin->actif ? '' : 'table-secondary' }}">
            <td class="text-nowrap">
              @if ($medecin->numero_professionnel)
                <code>{{ $medecin->numero_professionnel }}</code>
              @else
                {{-- Absence dite plutôt que comblée : la fiche existe, le référentiel ne la
                     connaît pas encore. `masante:professionnels:backfill` la sert. --}}
                <span class="text-muted small">Non attribué</span>
              @endif
            </td>
            <td class="fw-medium">
              {{ $medecin->nom_complet }}
              @if ($medecin->profession)
                <div class="text-muted small">{{ \App\Support\ProfessionsSante::libelle($medecin->profession) }}</div>
              @endif
            </td>
            <td>{{ $medecin->specialite }}</td>
            <td>{{ $medecin->service?->nom_service ?? '—' }}</td>
            <td>
              @if ($medecin->user)
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                  <i class="bi bi-link-45deg"></i> {{ $medecin->user->prenom }} {{ $medecin->user->nom }}
                </span>
              @else
                {{-- Sans compte, la fiche est visible mais muette : le praticien ne peut ouvrir aucun dossier. --}}
                <span class="text-muted small">Aucun — ne peut pas suivre de patient référent</span>
              @endif
            </td>
            <td class="text-center">
              @if ($medecin->actif)
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Visible</span>
              @else
                <span class="badge bg-secondary">Retiré</span>
              @endif
            </td>
            <td class="text-end text-nowrap">
              <a href="{{ route('portail.medecins.edit', $medecin) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
              <form method="POST" action="{{ route('portail.medecins.toggle', $medecin) }}" class="d-inline">
                @csrf @method('PATCH')
                <button class="btn btn-sm btn-outline-{{ $medecin->actif ? 'warning' : 'success' }}" type="submit">
                  <i class="bi bi-{{ $medecin->actif ? 'eye-slash' : 'eye' }}"></i>
                </button>
              </form>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" class="text-center text-muted py-4">
              Aucun praticien dans l'annuaire de votre établissement.
              <a href="{{ route('portail.medecins.create') }}">Ajoutez le premier</a> pour que vos patients
              puissent le choisir en rendez-vous ou le désigner médecin référent.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $medecins->links() }}</div>
@endsection
