@extends('portail.layout')

@section('titre', 'Nouveau service')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.services.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Nouveau service</h1>
</div>

<form method="POST" action="{{ route('portail.services.store') }}">
  @csrf
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      @include('portail.services._form', ['service' => null])
    </div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Créer le service</button>
    <a href="{{ route('portail.services.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
