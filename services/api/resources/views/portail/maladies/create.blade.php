@extends('portail.layout')

@section('titre', 'Ajouter une maladie')

@section('content')
<h1 class="h3 mb-4" style="color:var(--ms-blue-dark)"><i class="bi bi-plus-circle"></i> Ajouter une maladie au référentiel</h1>

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-exclamation-triangle"></i>
  <div class="small">
    Le <strong>code national</strong> n'est pas saisi ici : il est attribué par la plateforme
    (<code>masante:maladies:backfill</code>), comme pour les établissements, les praticiens, les
    médicaments, les analyses et les vaccins. Les <strong>codes CIM</strong> ne se saisissent pas non
    plus : ce sont des codes de l'OMS, et en inventer un produirait une donnée fausse qui a l'air
    juste. Après l'enregistrement, ajoutez les <strong>libellés alternatifs</strong> et la
    <strong>surveillance</strong> pays par pays.
  </div>
</div>

<form method="POST" action="{{ route('portail.maladies.store') }}" class="card p-4">
  @csrf
  @include('portail.maladies._form', ['maladie' => null])

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Ajouter au contenu de travail</button>
    <a href="{{ route('portail.maladies.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
