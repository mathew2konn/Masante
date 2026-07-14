@extends('portail.layout')

@section('titre', 'Modifier · ' . $medecin->nom_complet)

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.medecins.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Modifier le praticien</h1>
</div>

<form method="POST" action="{{ route('portail.medecins.update', $medecin) }}">
  @csrf @method('PUT')
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      @include('portail.medecins._form', ['medecin' => $medecin])
    </div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
    <a href="{{ route('portail.medecins.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
