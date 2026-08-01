@extends('portail.layout')

@section('titre', 'Modifier · ' . $agent->prenom . ' ' . $agent->nom)

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.agents.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Modifier l'agent</h1>
</div>

<form method="POST" action="{{ route('portail.agents.update', $agent) }}">
  @csrf @method('PUT')
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      @include('portail.agents._form', ['agent' => $agent])
      <p class="text-muted small mt-3 mb-0">
        État du compte :
        @if ($agent->password === null)
          <span class="badge bg-warning text-dark">Activation en attente</span>
        @else
          <span class="badge bg-success">Activé</span>
        @endif
      </p>
    </div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
    <a href="{{ route('portail.agents.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
