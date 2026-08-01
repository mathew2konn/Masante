@extends('portail.layout')

@section('titre', 'Accueil patient')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">
      <i class="bi bi-person-check"></i> Accueil patient
    </h1>
    <p class="text-muted mb-0">{{ $structure->nom }}</p>
  </div>
  <a href="{{ route('portail.rdv.index') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-calendar-check"></i> Rendez-vous
  </a>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        @include('portail.scan._lecteur', [
          'action' => route('portail.scan.checkin'),
          'champ'  => 'code',
          'aide'   => 'Présentez le QR du reçu de rendez-vous affiché par le patient.',
        ])
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms"><i class="bi bi-info-circle"></i> Enregistrement d'arrivée</h2>
        <ul class="small text-muted ps-3 mb-3">
          <li>Marque le patient <strong>présent à l'accueil</strong> ; le rendez-vous reste confirmé.</li>
          <li>Le rendez-vous doit avoir été <strong>confirmé</strong> au préalable.</li>
          <li>Un reçu déjà enregistré ne peut pas servir deux fois.</li>
        </ul>
        <div class="alert alert-secondary small mb-0">
          <i class="bi bi-shield-check"></i>
          Ce QR ne contient <strong>aucune donnée médicale</strong> et n'ouvre pas le dossier du patient.
          Pour consulter un dossier, passez par
          <a href="{{ route('portail.scan.index') }}" class="alert-link">Scanner le carnet</a>.
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
