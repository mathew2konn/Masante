@extends('portail.layout')

@section('titre', $organisme->nom)

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">{{ $organisme->nom }}</h1>
    <p class="text-muted mb-0">
      <code>{{ $organisme->code ?? 'code national non attribué' }}</code>
      · {{ $types[$organisme->type] ?? $organisme->type }}
      · {{ $organisme->pays_code }}
    </p>
  </div>
  <a href="{{ route('portail.assurances.index') }}" class="btn btn-outline-secondary">Retour au registre</a>
</div>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-shield-lock"></i>
  <div class="small">
    Vous modifiez le <strong>contenu de travail</strong>. Rien n'est diffusé avant la publication d'une
    nouvelle version du référentiel (CDC_09 §10, quatre-yeux).
  </div>
</div>

@if ($organisme->numero_agrement === null)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-file-earmark-x"></i>
    <div class="small">
      Cet organisme n'a <strong>aucun numéro d'agrément</strong> enregistré. Sa présence dans ce registre
      <strong>ne prouve donc pas qu'il est agréé</strong>.
    </div>
  </div>
@endif

@if ($couvertures > 0)
  <div class="alert alert-info d-flex gap-2">
    <i class="bi bi-people"></i>
    <div class="small">
      <strong>{{ $couvertures }}</strong> couverture(s) d'assurés désignent cet organisme. Le désactiver le
      retire des choix proposés <em>sans</em> toucher aux couvertures existantes ; le <strong>supprimer est
      refusé par la base</strong> tant qu'il en reste une — sinon personne ne saurait plus chez qui ces
      assurés étaient couverts.
    </div>
  </div>
@endif

<form method="POST" action="{{ route('portail.assurances.update', $organisme) }}" class="card p-4">
  @csrf
  @method('PUT')
  @include('portail.assurances._form', ['organisme' => $organisme])

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Enregistrer</button>
    <a href="{{ route('portail.assurances.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
