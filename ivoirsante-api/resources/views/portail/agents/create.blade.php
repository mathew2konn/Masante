@extends('portail.layout')

@section('titre', 'Nouvel agent')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.agents.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Nouvel agent de garde</h1>
</div>

@if ($services->isEmpty())
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    Vous devez d'abord créer au moins un <a href="{{ route('portail.services.index') }}" class="alert-link">service</a>
    avant de pouvoir affecter un agent.
  </div>
@else
  <form method="POST" action="{{ route('portail.agents.store') }}">
    @csrf
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <p class="text-muted small">
          L'agent recevra un <strong>lien d'activation</strong> pour créer lui-même son mot de passe
          (aucun mot de passe temporaire — §5.4.1).
        </p>
        @include('portail.agents._form', ['agent' => null])
      </div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Créer l'agent</button>
      <a href="{{ route('portail.agents.index') }}" class="btn btn-outline-secondary">Annuler</a>
    </div>
  </form>
@endif
@endsection
