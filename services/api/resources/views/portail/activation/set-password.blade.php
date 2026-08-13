<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Activation du compte · MaSanté</title>
  <link href="{{ asset('assets/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
  <link href="{{ asset('assets/bootstrap/bootstrap-icons.min.css') }}" rel="stylesheet">
  <style>
    :root { --ms-blue: #1E6BB8; --ms-blue-dark: #0C3463; }
    body { background: linear-gradient(135deg, var(--ms-blue-dark), var(--ms-blue)); min-height: 100vh; }
    .btn-ms { background: var(--ms-blue); border-color: var(--ms-blue); color: #fff; }
    .btn-ms:hover { background: var(--ms-blue-dark); border-color: var(--ms-blue-dark); color: #fff; }
  </style>
</head>
<body class="d-flex align-items-center py-5">
<div class="container" style="max-width: 460px">
  <div class="card border-0 shadow-lg">
    <div class="card-body p-4">
      <h1 class="h4 text-center mb-1" style="color:var(--ms-blue-dark)">
        <i class="bi bi-heart-pulse-fill"></i> MaSanté
      </h1>

      @if (! $valide)
        <div class="alert alert-danger mt-3">
          <i class="bi bi-x-circle"></i> Ce lien d'activation est invalide, expiré ou déjà utilisé.
          <p class="small mb-0 mt-2">Demandez un nouveau lien à l'administrateur IVOIRSANTÉ.</p>
        </div>
        <div class="text-center">
          <a href="{{ route('portail.login') }}" class="btn btn-outline-secondary btn-sm">Retour à la connexion</a>
        </div>
      @else
        <p class="text-center text-muted mb-4">
          Bonjour {{ $user->prenom }}, activez votre compte en choisissant un mot de passe.
        </p>

        @if ($errors->any())
          <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
              @foreach ($errors->all() as $erreur) <li>{{ $erreur }}</li> @endforeach
            </ul>
          </div>
        @endif

        <form method="POST" action="{{ route('portail.activation.attempt', ['token' => $token]) }}">
          @csrf
          <div class="mb-3">
            <label class="form-label">Nouveau mot de passe</label>
            <input type="password" name="password" class="form-control" required autofocus>
            <div class="form-text">≥8 caractères, majuscules + minuscules, chiffres et symboles.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Confirmer le mot de passe</label>
            <input type="password" name="password_confirmation" class="form-control" required>
          </div>
          <div class="d-grid">
            <button class="btn btn-ms" type="submit"><i class="bi bi-shield-check"></i> Activer mon compte</button>
          </div>
        </form>
      @endif
    </div>
  </div>
</div>
</body>
</html>
