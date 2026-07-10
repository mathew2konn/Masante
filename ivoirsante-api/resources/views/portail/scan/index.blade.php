@extends('portail.layout')

@section('titre', 'Scanner un carnet')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">
      <i class="bi bi-qr-code-scan"></i> Scanner le carnet
    </h1>
    <p class="text-muted mb-0">{{ $structure->nom }}</p>
  </div>
  <a href="{{ route('portail.dashboard') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Tableau de bord
  </a>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        @include('portail.scan._lecteur', [
          'action' => route('portail.scan.carnet'),
          'champ'  => 'token',
          'aide'   => 'Présentez le QR affiché par le patient dans son application MaSanté.',
        ])
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms"><i class="bi bi-shield-lock"></i> Ce que fait ce scan</h2>
        <ul class="small text-muted ps-3 mb-3">
          <li>Le QR du patient est <strong>à usage unique</strong> et expire au bout de 10 minutes.</li>
          <li>Il ouvre son dossier en <strong>lecture seule pendant 30 minutes</strong>, puis se referme.</li>
          <li>L'accès est <strong>journalisé</strong> : votre nom, l'établissement, l'heure, la durée et les
              sections consultées. Le patient peut consulter ce journal.</li>
        </ul>
        <div class="alert alert-warning small mb-0">
          <i class="bi bi-exclamation-triangle"></i>
          Pour enregistrer l'arrivée d'un patient qui a un rendez-vous, utilisez plutôt
          <a href="{{ route('portail.scan.rdv') }}" class="alert-link">Accueil patient</a> :
          le QR d'un reçu n'ouvre pas de dossier médical.
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
