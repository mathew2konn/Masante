@extends('portail.layout')

@section('titre', 'Comptes du portail')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-people"></i> Comptes du portail</h1>
    <p class="text-muted mb-0">Administrateurs, gestionnaires et agents de garde.</p>
  </div>
  <a href="{{ route('portail.dashboard') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Tableau de bord
  </a>
</div>

@error('compte')
  <div class="alert alert-danger">{{ $message }}</div>
@enderror

{{-- Filtres : rôle, établissement, recherche libre. --}}
<form method="GET" class="row g-2 mb-3">
  <div class="col-sm-3">
    <select name="role" class="form-select form-select-sm">
      <option value="">Tous les rôles</option>
      @foreach ($roles as $cle => $libelle)
        <option value="{{ $cle }}" @selected($role === $cle)>{{ $libelle }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-sm-4">
    <select name="structure" class="form-select form-select-sm">
      <option value="">Tous les établissements</option>
      @foreach ($etablissements as $e)
        <option value="{{ $e->id }}" @selected($structureId === $e->id)>{{ $e->nom }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-sm-3">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Nom, prénom ou e-mail" value="{{ $recherche }}">
  </div>
  <div class="col-sm-2">
    <button class="btn btn-ms btn-sm w-100"><i class="bi bi-funnel"></i> Filtrer</button>
  </div>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Compte</th><th>Rôle</th><th>Établissement</th><th>État</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($comptes as $c)
          <tr>
            <td>
              <strong>{{ $c->prenom }} {{ $c->nom }}</strong>
              <div class="text-muted small">{{ $c->email }}</div>
            </td>
            <td><span class="badge badge-role">{{ $roles[$c->getRoleNames()->first()] ?? '—' }}</span></td>
            <td>
              {{ $c->structure->nom ?? '—' }}
              @if ($c->service)
                <div class="text-muted small">{{ $c->service->nom_service }}</div>
              @endif
            </td>
            <td>
              @if (! $c->actif)
                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Suspendu</span>
              @elseif ($c->password === null)
                {{-- Compte créé, lien d'activation envoyé, mot de passe jamais défini (4.2/4.3). --}}
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">En attente d'activation</span>
              @else
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Actif</span>
              @endif
            </td>
            <td class="text-end">
              <div class="d-inline-flex gap-2">
                @if ($c->password === null)
                  <form method="POST" action="{{ route('portail.comptes.lien', $c) }}" class="m-0">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary" title="Régénérer le lien d'activation">
                      <i class="bi bi-link-45deg"></i> Lien
                    </button>
                  </form>
                @endif
                <form method="POST" action="{{ route('portail.comptes.toggle', $c) }}" class="m-0">
                  @csrf @method('PATCH')
                  <button class="btn btn-sm {{ $c->actif ? 'btn-outline-danger' : 'btn-outline-success' }}">
                    <i class="bi bi-{{ $c->actif ? 'pause-circle' : 'play-circle' }}"></i>
                    {{ $c->actif ? 'Suspendre' : 'Réactiver' }}
                  </button>
                </form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-4">
            <i class="bi bi-inbox"></i> Aucun compte ne correspond à ces filtres.
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $comptes->links() }}</div>

<p class="text-muted small mt-3">
  <i class="bi bi-shield-lock"></i>
  Seuls les comptes du <strong>portail</strong> figurent ici. Les comptes patients, rattachés à des carnets de
  santé, ne sont pas administrables depuis cet écran. Un gestionnaire se crée depuis
  <a href="{{ route('portail.etablissements.index') }}">Établissements</a>, un agent depuis son établissement.
</p>
@endsection
