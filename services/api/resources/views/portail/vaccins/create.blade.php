@extends('portail.layout')

@section('titre', 'Ajouter un vaccin')

@section('content')
<h1 class="h3 mb-4" style="color:var(--ms-blue-dark)"><i class="bi bi-plus-circle"></i> Ajouter un vaccin au référentiel</h1>

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-exclamation-triangle"></i>
  <div class="small">
    Le <strong>code national</strong> n'est pas saisi ici : il est attribué par la plateforme
    (<code>masante:vaccins:backfill</code>), comme pour les établissements, les praticiens, les
    médicaments et les analyses. Après l'enregistrement, ajoutez les <strong>échéances</strong> —
    un vaccin sans calendrier ne peut pas être publié.
  </div>
</div>

<form method="POST" action="{{ route('portail.vaccins.store') }}" class="card p-4">
  @csrf
  @include('portail.vaccins._form', ['vaccin' => null])

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Ajouter au contenu de travail</button>
    <a href="{{ route('portail.vaccins.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
