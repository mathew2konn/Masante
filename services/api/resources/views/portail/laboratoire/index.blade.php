@extends('portail.layout')

@section('titre', 'Lire une demande d\'examen')

@section('content')
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h1 class="h4 mb-1" style="color:var(--ms-blue-dark)">
      <i class="bi bi-clipboard2-pulse"></i> Lire une demande d'examen
    </h1>
    <p class="text-muted small">
      Saisissez le code que le patient vous présente. Vous ne verrez que cette demande — aucune
      autre partie de son dossier n'est accessible depuis cet écran.
    </p>

    @if ($errors->any())
      <div class="alert alert-danger py-2">
        <ul class="mb-0 small">
          @foreach ($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach
        </ul>
      </div>
    @endif

    <form method="GET" action="{{ route('portail.laboratoire.montrer') }}" class="row g-2 align-items-end">
      <div class="col-md-9">
        <label for="jeton" class="form-label small mb-1">Code de la demande</label>
        <input type="text" class="form-control font-monospace" id="jeton" name="jeton"
               maxlength="64" required autofocus placeholder="Le code figurant sur la demande du patient">
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-ms" type="submit"><i class="bi bi-search"></i> Ouvrir</button>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body">
    <a href="{{ route('portail.laboratoire.travail') }}" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-list-check"></i> Voir le travail en cours de mon laboratoire
    </a>
  </div>
</div>
@endsection
