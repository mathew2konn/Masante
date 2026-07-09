@extends('portail.layout')

@section('titre', 'Modifier · ' . $etablissement->nom)

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.etablissements.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Modifier l'établissement</h1>
</div>

<form method="POST" action="{{ route('portail.etablissements.update', $etablissement) }}">
  @csrf @method('PUT')

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-medium"><i class="bi bi-hospital text-ms"></i> Établissement</div>
    <div class="card-body">
      @include('portail.etablissements._form', ['etablissement' => $etablissement])
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
    <a href="{{ route('portail.etablissements.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>

<div class="card border-0 shadow-sm mt-4">
  <div class="card-header bg-white fw-medium"><i class="bi bi-person-badge text-ms"></i> Compte gestionnaire</div>
  <div class="card-body">
    @if ($gestionnaire)
      <p class="mb-2">
        <strong>{{ $gestionnaire->prenom }} {{ $gestionnaire->nom }}</strong>
        — {{ $gestionnaire->email }}
        @if ($gestionnaire->password === null)
          <span class="badge bg-warning text-dark ms-1">Activation en attente</span>
        @else
          <span class="badge bg-success ms-1">Activé</span>
        @endif
      </p>
      @if ($gestionnaire->password === null)
        <form method="POST" action="{{ route('portail.etablissements.lien', $etablissement) }}">
          @csrf
          <button class="btn btn-sm btn-outline-primary" type="submit">
            <i class="bi bi-arrow-repeat"></i> Régénérer le lien d'activation
          </button>
        </form>
      @endif
    @else
      <p class="text-muted mb-0">Aucun gestionnaire rattaché.</p>
    @endif
    @error('gestionnaire') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
  </div>
</div>
@endsection
