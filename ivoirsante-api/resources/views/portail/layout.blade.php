<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titre', 'Portail') · MaSanté</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root { --ms-blue: #1E6BB8; --ms-blue-dark: #0C3463; }
    body { background: #F5F7FA; }
    .navbar-ms { background: var(--ms-blue-dark); }
    .navbar-ms .navbar-brand, .navbar-ms .nav-link, .navbar-ms .navbar-text { color: #fff; }
    .text-ms { color: var(--ms-blue); }
    .btn-ms { background: var(--ms-blue); border-color: var(--ms-blue); color: #fff; }
    .btn-ms:hover { background: var(--ms-blue-dark); border-color: var(--ms-blue-dark); color: #fff; }
    .badge-role { background: var(--ms-blue); }
  </style>
</head>
<body>
@auth
  <nav class="navbar navbar-ms navbar-expand-lg shadow-sm">
    <div class="container">
      <a class="navbar-brand fw-bold" href="{{ route('portail.dashboard') }}">
        <i class="bi bi-heart-pulse-fill"></i> MaSanté <span class="fw-normal opacity-75">· Portail</span>
      </a>
      <div class="d-flex align-items-center gap-3">
        <span class="navbar-text small">
          {{ auth()->user()->prenom }} {{ auth()->user()->nom }}
          <span class="badge badge-role ms-1">{{ str_replace('_', ' ', auth()->user()->getRoleNames()->first()) }}</span>
        </span>
        <form method="POST" action="{{ route('portail.logout') }}" class="m-0">
          @csrf
          <button class="btn btn-sm btn-outline-light" type="submit">
            <i class="bi bi-box-arrow-right"></i> Déconnexion
          </button>
        </form>
      </div>
    </div>
  </nav>
@endauth

<main class="container py-4">
  @if (session('statut'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      {{ session('statut') }}
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  @endif

  {{-- Lien d'activation d'un compte staff (dev : pas de passerelle mail → affiché à l'écran, §5.4.1). --}}
  @if (session('lien_activation'))
    <div class="alert alert-info alert-dismissible fade show" role="alert">
      <strong><i class="bi bi-link-45deg"></i> Lien d'activation (valable 24h, usage unique)</strong>
      <p class="small mb-2">À transmettre au titulaire du compte. En production, ce lien serait envoyé par e-mail.</p>
      <div class="input-group input-group-sm">
        <input type="text" class="form-control font-monospace" id="lien-activation" value="{{ session('lien_activation') }}" readonly>
        <button class="btn btn-outline-secondary" type="button"
                onclick="navigator.clipboard.writeText(document.getElementById('lien-activation').value)">
          <i class="bi bi-clipboard"></i> Copier
        </button>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  @endif

  @yield('content')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
