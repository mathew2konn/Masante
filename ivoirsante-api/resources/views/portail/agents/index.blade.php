@extends('portail.layout')

@section('titre', 'Mes agents')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-person-badge"></i> Mes agents de garde</h1>
    <p class="text-muted mb-0">Chaque agent est affecté à un service et gère sa disponibilité.</p>
  </div>
  <a href="{{ route('portail.agents.create') }}" class="btn btn-ms"><i class="bi bi-plus-lg"></i> Nouvel agent</a>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Agent</th>
          <th>E-mail</th>
          <th>Service</th>
          <th class="text-center">Compte</th>
          <th class="text-center">Statut</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($agents as $agent)
          <tr class="{{ $agent->actif ? '' : 'table-secondary' }}">
            <td class="fw-medium">{{ $agent->prenom }} {{ $agent->nom }}</td>
            <td>{{ $agent->email }}</td>
            <td>{{ $agent->service?->nom_service ?? '—' }}</td>
            <td class="text-center">
              @if ($agent->password === null)
                <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> À activer</span>
              @else
                <span class="badge bg-success"><i class="bi bi-check-circle"></i> Activé</span>
              @endif
            </td>
            <td class="text-center">
              @if ($agent->actif)
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Actif</span>
              @else
                <span class="badge bg-secondary">Désactivé</span>
              @endif
            </td>
            <td class="text-end text-nowrap">
              <a href="{{ route('portail.agents.edit', $agent) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
              @if ($agent->password === null)
                <form method="POST" action="{{ route('portail.agents.lien', $agent) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-outline-primary" type="submit" title="Régénérer le lien d'activation">
                    <i class="bi bi-arrow-repeat"></i>
                  </button>
                </form>
              @endif
              <form method="POST" action="{{ route('portail.agents.toggle', $agent) }}" class="d-inline"
                    onsubmit="return confirm('{{ $agent->actif ? 'Désactiver' : 'Réactiver' }} cet agent ?');">
                @csrf @method('PATCH')
                <button class="btn btn-sm {{ $agent->actif ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit">
                  <i class="bi {{ $agent->actif ? 'bi-slash-circle' : 'bi-arrow-counterclockwise' }}"></i>
                </button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-muted py-4">Aucun agent. Créez-en un et transmettez-lui son lien d'activation.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $agents->links() }}</div>
@endsection
