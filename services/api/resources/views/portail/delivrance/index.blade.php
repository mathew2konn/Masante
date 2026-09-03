@extends('portail.layout')

@section('titre', 'Servir une ordonnance')

@section('content')
<div class="card border-0 shadow-sm">
  <div class="card-body">
    <h1 class="h4 mb-1" style="color:var(--ms-blue-dark)">
      <i class="bi bi-capsule"></i> Servir une ordonnance
    </h1>
    <p class="text-muted small">
      Saisissez le code que le patient vous présente. Vous ne verrez que cette ordonnance —
      aucune autre partie de son dossier n'est accessible depuis cet écran.
    </p>

    @if ($errors->any())
      <div class="alert alert-danger py-2">
        <ul class="mb-0 small">
          @foreach ($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach
        </ul>
      </div>
    @endif

    <form method="GET" action="{{ route('portail.delivrance.montrer') }}" class="row g-2 align-items-end">
      <div class="col-md-9">
        <label for="jeton" class="form-label small mb-1">Code de l'ordonnance</label>
        <input type="text" class="form-control font-monospace" id="jeton" name="jeton"
               maxlength="64" required autofocus placeholder="Le code figurant sur l'ordonnance du patient">
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-ms" type="submit"><i class="bi bi-search"></i> Ouvrir</button>
      </div>
    </form>
  </div>
</div>
@endsection
