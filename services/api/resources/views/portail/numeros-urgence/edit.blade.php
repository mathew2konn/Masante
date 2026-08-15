@extends('portail.layout')

@section('titre', "Modifier un numéro d'urgence")

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">
      <i class="bi bi-pencil"></i> {{ $numero->libelle }}
    </h1>
    <p class="text-muted mb-0"><code>{{ $numero->code }}</code> · {{ $numero->pays_code }}</p>
  </div>
  <a href="{{ route('portail.numeros-urgence.index') }}" class="btn btn-outline-secondary">Retour</a>
</div>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif

<div class="alert alert-danger d-flex gap-2">
  <i class="bi bi-telephone-x"></i>
  <div class="small">
    Modifier ce numéro change ce que composera un citoyen devant un blessé. Contrairement aux autres
    référentiels, <strong>l'erreur ne produit pas une liste vide : elle produit un appel qui
    n'aboutit nulle part.</strong> Vérifiez la valeur avant d'enregistrer, et renseignez sa
    provenance.
  </div>
</div>

<form method="POST" action="{{ route('portail.numeros-urgence.update', $numero) }}" class="card p-4">
  @csrf
  @method('PUT')
  @include('portail.numeros-urgence._form')

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Enregistrer</button>
    <a href="{{ route('portail.numeros-urgence.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
