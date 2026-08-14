@extends('portail.layout')

@section('titre', 'Modifier un terme')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-pencil"></i> {{ $terme->libelle }}</h1>
    <p class="text-muted mb-0"><code>{{ $terme->code }}</code> · {{ $terme->pays_code }}</p>
  </div>
  <a href="{{ route('portail.specialites.index') }}" class="btn btn-outline-secondary">Retour</a>
</div>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif

@if ($services > 0 || $praticiens > 0)
  <div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle"></i>
    <div class="small">
      Ce terme est utilisé par <strong>{{ $services }}</strong> service(s) et
      <strong>{{ $praticiens }}</strong> fiche(s) de praticien. Renommer son libellé change ce que
      ces fiches affichent au citoyen — c'est une décision de gouvernance, pas une correction de
      saisie, et elle passe par la publication d'une nouvelle version (CDC_09 §10).
    </div>
  </div>
@endif

<form method="POST" action="{{ route('portail.specialites.update', $terme) }}" class="card p-4">
  @csrf
  @method('PUT')
  @include('portail.specialites._form')

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Enregistrer</button>
    <a href="{{ route('portail.specialites.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
