@extends('portail.layout')

@section('titre', "Ajouter un numéro d'urgence")

@section('content')
<h1 class="h3 mb-4" style="color:var(--ms-blue-dark)">
  <i class="bi bi-telephone-plus"></i> Ajouter un numéro d'urgence
</h1>

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-exclamation-triangle"></i>
  <div class="small">
    <strong>Le code ne pourra plus être modifié.</strong> Le mobile et le texte de triage demandent
    un numéro <em>par son code</em> (<code>samu</code>) : le renommer plus tard les laisserait
    désigner un terme disparu <strong>sans lever d'erreur</strong>, et l'application retomberait en
    silence sur la valeur qu'elle a en mémoire. Un code qui ne convient plus se <em>désactive</em>.
  </div>
</div>

<form method="POST" action="{{ route('portail.numeros-urgence.store') }}" class="card p-4">
  @csrf
  @include('portail.numeros-urgence._form', ['numero' => null])

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Ajouter au contenu de travail</button>
    <a href="{{ route('portail.numeros-urgence.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
