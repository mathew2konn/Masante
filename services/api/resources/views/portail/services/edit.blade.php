@extends('portail.layout')

@section('titre', 'Modifier · ' . $service->nom_service)

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.services.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Modifier le service</h1>
</div>

<form method="POST" action="{{ route('portail.services.update', $service) }}">
  @csrf @method('PUT')
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      @include('portail.services._form', ['service' => $service])
    </div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
    <a href="{{ route('portail.services.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
