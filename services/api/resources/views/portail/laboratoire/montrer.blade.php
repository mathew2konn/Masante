@extends('portail.layout')

@section('titre', 'Demande d\'examen')

@section('content')
@if (session('succes'))
  <div class="alert alert-success py-2">{{ session('succes') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger py-2">
    <ul class="mb-0 small">
      @foreach ($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach
    </ul>
  </div>
@endif

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h1 class="h5 mb-1" style="color:var(--ms-blue-dark)">
      <i class="bi bi-clipboard2-pulse"></i> Demande d'examen
    </h1>
    <p class="text-muted small mb-0">
      {{ $demande->membre?->prenom }} {{ $demande->membre?->nom }} ·
      demandée le {{ $demande->date_demande?->format('d/m/Y') }} ·
      {{ $demande->medecin_nom }}
      @if ($demande->structure_sanitaire) · {{ $demande->structure_sanitaire }} @endif
    </p>
    <p class="text-muted small mb-0 mt-2">
      <i class="bi bi-shield-lock"></i> Vous ne voyez que cette demande. Le reste du dossier du
      patient ne vous est pas accessible.
    </p>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">Examens demandés</h2>
    @if ($demande->lignes->isEmpty())
      <p class="text-muted small mb-0">Aucun examen exploitable sur cette demande.</p>
    @else
      <ul class="list-group list-group-flush">
        @foreach ($demande->lignes as $ligne)
          <li class="list-group-item px-0">
            {{ $ligne->libelle }}
            @if ($ligne->estCodee())
              <span class="badge bg-info-subtle text-info-emphasis ms-1">{{ $ligne->code_national }}</span>
            @else
              <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">hors référentiel</span>
            @endif
          </li>
        @endforeach
      </ul>
    @endif
  </div>
</div>

<form method="POST" action="{{ route('portail.laboratoire.enregistrer') }}" class="m-0">
  @csrf
  <input type="hidden" name="jeton" value="{{ $jeton }}">
  <button class="btn btn-ms" type="submit">
    <i class="bi bi-plus-circle"></i> Enregistrer le prélèvement
  </button>
</form>
@endsection
