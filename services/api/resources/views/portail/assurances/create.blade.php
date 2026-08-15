@extends('portail.layout')

@section('titre', 'Ajouter un organisme d\'assurance')

@section('content')
<h1 class="h3 mb-4" style="color:var(--ms-blue-dark)"><i class="bi bi-plus-circle"></i> Ajouter un organisme au registre</h1>

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-exclamation-triangle"></i>
  <div class="small">
    Le <strong>code national</strong> n'est pas saisi ici : il est attribué par la plateforme
    (<code>masante:assurances:backfill</code>), comme pour les établissements, les praticiens, les
    médicaments, les analyses, les vaccins et les maladies. Le <strong>numéro d'agrément</strong> ne se
    saisit pas non plus : il désigne un acte délivré par une autorité, et le taper ici reviendrait à
    <strong>fabriquer un agrément</strong> plutôt qu'à l'enregistrer.
  </div>
</div>

<form method="POST" action="{{ route('portail.assurances.store') }}" class="card p-4">
  @csrf
  @include('portail.assurances._form', ['organisme' => null])

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Ajouter au contenu de travail</button>
    <a href="{{ route('portail.assurances.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
