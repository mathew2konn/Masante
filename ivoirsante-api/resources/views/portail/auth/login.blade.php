@extends('portail.layout')

@section('titre', 'Connexion')

@section('content')
<div class="row justify-content-center mt-4">
  <div class="col-md-5 col-lg-4">
    <div class="text-center mb-4">
      <div class="text-ms" style="font-size:2.5rem"><i class="bi bi-heart-pulse-fill"></i></div>
      <h1 class="h4 mt-2 mb-0" style="color:var(--ms-blue-dark)">MaSanté · Portail</h1>
      <p class="text-muted small">Espace administratif des établissements</p>
    </div>

    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        @if ($errors->any())
          <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('portail.login.attempt') }}">
          @csrf
          <div class="mb-3">
            <label class="form-label" for="email">Adresse e-mail</label>
            <input type="email" class="form-control" id="email" name="email"
                   value="{{ old('email') }}" required autofocus autocomplete="username">
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">Mot de passe</label>
            <input type="password" class="form-control" id="password" name="password"
                   required autocomplete="current-password">
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="remember" name="remember">
            <label class="form-check-label small" for="remember">Rester connecté</label>
          </div>
          <button type="submit" class="btn btn-ms w-100">
            <i class="bi bi-box-arrow-in-right"></i> Se connecter
          </button>
        </form>
      </div>
    </div>
    <p class="text-center text-muted small mt-3">Accès réservé au personnel autorisé.</p>
  </div>
</div>
@endsection
