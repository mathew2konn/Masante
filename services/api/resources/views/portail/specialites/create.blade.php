@extends('portail.layout')

@section('titre', 'Ajouter un terme')

@section('content')
<h1 class="h3 mb-4" style="color:var(--ms-blue-dark)"><i class="bi bi-plus-circle"></i> Ajouter un terme au vocabulaire</h1>

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-exclamation-triangle"></i>
  <div class="small">
    <strong>Le code ne pourra plus être modifié.</strong> Les services d'établissement le portent en
    texte, et le filtre public de l'annuaire compare dessus en égalité exacte : le renommer plus tard
    laisserait tous les services existants désigner un terme qui n'existe plus. Un terme qui ne
    convient plus se <em>désactive</em>, et un autre le remplace.
  </div>
</div>

<form method="POST" action="{{ route('portail.specialites.store') }}" class="card p-4">
  @csrf
  @include('portail.specialites._form', ['terme' => null])

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Ajouter au contenu de travail</button>
    <a href="{{ route('portail.specialites.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
