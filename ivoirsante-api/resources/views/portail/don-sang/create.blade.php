@extends('portail.layout')

@section('titre', 'Publier un besoin en sang')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.don-sang.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Publier un besoin en sang</h1>
</div>

<form method="POST" action="{{ route('portail.don-sang.store') }}">
  @csrf
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      @include('portail.don-sang._form')
    </div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-megaphone"></i> Publier</button>
    <a href="{{ route('portail.don-sang.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
