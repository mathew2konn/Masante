@extends('portail.layout')

@section('titre', 'Prélèvement '.$prelevement->identifiant)

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
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
      <div>
        <h1 class="h5 mb-1" style="color:var(--ms-blue-dark)">
          <i class="bi bi-droplet"></i> Prélèvement <span class="font-monospace">{{ $prelevement->identifiant }}</span>
        </h1>
        <p class="text-muted small mb-0">
          {{ $prelevement->demande?->membre?->prenom }} {{ $prelevement->demande?->membre?->nom }} ·
          statut : <span class="badge bg-secondary-subtle text-secondary-emphasis border">{{ $prelevement->statut->libelle() }}</span>
        </p>
      </div>
      <a href="{{ route('portail.laboratoire.etiquette', $prelevement) }}" target="_blank"
         class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-upc"></i> Étiquette imprimable
      </a>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">Examens demandés</h2>
    <ul class="list-group list-group-flush">
      @foreach ($prelevement->demande?->lignes ?? [] as $ligne)
        <li class="list-group-item px-0">{{ $ligne->libelle }}</li>
      @endforeach
    </ul>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body d-flex flex-wrap gap-2">
    @if ($prelevement->statut === \App\Support\StatutPrelevement::PRELEVE)
      <form method="POST" action="{{ route('portail.laboratoire.expedier', $prelevement) }}" class="m-0">
        @csrf
        <button class="btn btn-outline-secondary btn-sm" type="submit">
          <i class="bi bi-truck"></i> Marquer expédié
        </button>
      </form>
    @endif

    @if (in_array($prelevement->statut, [\App\Support\StatutPrelevement::PRELEVE, \App\Support\StatutPrelevement::EXPEDIE], true))
      <form method="POST" action="{{ route('portail.laboratoire.recevoir', $prelevement) }}" class="m-0">
        @csrf
        <button class="btn btn-ms btn-sm" type="submit">
          <i class="bi bi-box-seam"></i> Marquer reçu
        </button>
      </form>
    @endif

    @if ($prelevement->statut === \App\Support\StatutPrelevement::RECU)
      <form method="POST" action="{{ route('portail.laboratoire.mettre-en-analyse', $prelevement) }}" class="m-0">
        @csrf
        <button class="btn btn-ms btn-sm" type="submit">
          <i class="bi bi-cpu"></i> Mettre en analyse
        </button>
      </form>
    @endif

    @if ($prelevement->statut === \App\Support\StatutPrelevement::EN_ANALYSE)
      <span class="text-muted small align-self-center">
        <i class="bi bi-hourglass-split"></i> En attente du résultat et de la validation biologique
        (à venir).
      </span>
    @endif
  </div>
</div>
@endsection
