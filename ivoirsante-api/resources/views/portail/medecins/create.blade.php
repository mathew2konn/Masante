@extends('portail.layout')

@section('titre', 'Nouveau praticien')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.medecins.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Nouveau praticien</h1>
</div>

<form method="POST" action="{{ route('portail.medecins.store') }}">
  @csrf
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      @include('portail.medecins._form')
    </div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Ajouter à l'annuaire</button>
    <a href="{{ route('portail.medecins.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
