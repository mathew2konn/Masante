@extends('portail.layout')

@section('titre', 'Statistiques globales')

@section('content')
@php
  // Libellés d'affichage. Les clés viennent des enums de la base (statut RDV, niveau de triage).
  $libellesRdv = [
    'en_attente' => 'En attente', 'confirme' => 'Confirmés', 'refuse' => 'Refusés',
    'annule' => 'Annulés', 'honore' => 'Honorés',
  ];
  $libellesTriage = ['leger' => 'Léger', 'modere' => 'Modéré', 'urgent' => 'Urgent'];
  $libellesRole = [
    'admin_ivoirsante' => 'Admins', 'gestionnaire_etablissement' => 'Gestionnaires', 'personnel_accueil' => 'Accueil',
  ];
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-graph-up"></i> Statistiques globales</h1>
    <p class="text-muted mb-0">Vue d'ensemble de la plateforme MaSanté.</p>
  </div>
  <a href="{{ route('portail.dashboard') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Tableau de bord
  </a>
</div>

<div class="row g-3 mb-4">
  @include('portail.statistiques._kpi', ['valeur' => $etablissementsActifs.' / '.$etablissementsTotal, 'libelle' => 'Établissements actifs', 'icone' => 'bi-hospital', 'couleur' => 'text-ms'])
  @include('portail.statistiques._kpi', ['valeur' => array_sum($comptesParRole), 'libelle' => 'Comptes du portail', 'icone' => 'bi-people', 'couleur' => 'text-ms'])
  @include('portail.statistiques._kpi', ['valeur' => $rdvTotal, 'libelle' => 'Rendez-vous', 'icone' => 'bi-calendar-check', 'couleur' => 'text-ms'])
  @include('portail.statistiques._kpi', ['valeur' => $signalementsEnAttente + $avisAModerer, 'libelle' => 'À modérer', 'icone' => 'bi-shield-exclamation', 'couleur' => 'text-danger'])
</div>

<div class="row g-3 mb-4">
  @include('portail.statistiques._kpi', ['valeur' => $triagesTotal, 'libelle' => 'Triages réalisés', 'icone' => 'bi-activity', 'couleur' => 'text-ms'])
  @include('portail.statistiques._kpi', ['valeur' => $scansDuMois, 'libelle' => 'Scans QR en '.$moisCourant, 'icone' => 'bi-qr-code-scan', 'couleur' => 'text-ms'])
  @include('portail.statistiques._kpi', ['valeur' => $avisVisibles, 'libelle' => 'Avis publiés', 'icone' => 'bi-chat-quote', 'couleur' => 'text-ms'])
  @include('portail.statistiques._kpi', ['valeur' => $noteMoyenne !== null ? number_format($noteMoyenne, 2).' ★' : '—', 'libelle' => 'Note moyenne', 'icone' => 'bi-star', 'couleur' => 'text-warning'])
</div>

{{-- 5.3 — Revue a posteriori des accès d'exception (Note_Continuite §5.3, garde-fou n°6).
     Un taux anormal, par établissement ou dans le temps, révèle un abus. --}}
@if ($brisDeGlaceTotal > 0)
  <div class="alert {{ $brisDeGlaceDuMois > 0 ? 'alert-danger' : 'alert-secondary' }} d-flex justify-content-between align-items-center">
    <div>
      <strong><i class="bi bi-exclamation-octagon"></i> Accès d'urgence (bris de glace)</strong>
      <div class="small">
        {{ $brisDeGlaceDuMois }} en {{ $moisCourant }} · {{ $brisDeGlaceTotal }} depuis l'ouverture de la plateforme.
        Chaque accès est justifié, notifié au patient et journalisé.
      </div>
    </div>
    <span class="fs-2 fw-semibold">{{ $brisDeGlaceDuMois }}</span>
  </div>
@endif

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms mb-3">Rendez-vous par statut</h2>
        <canvas id="graphRdv" height="220"></canvas>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms mb-3">Triages par niveau de gravité</h2>
        <canvas id="graphTriage" height="220"></canvas>
        <p class="text-muted small mb-0 mt-3">
          <i class="bi bi-shield-lock"></i> Agrégats uniquement : aucun triage individuel n'est consultable ici.
        </p>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms mb-3">Comptes par rôle</h2>
        <canvas id="graphRoles" height="220"></canvas>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  // Données injectées en JSON échappé par Blade, sans interpolation de chaîne (Sécurité §A03).
  const libellesRdv = @json(array_values($libellesRdv));
  const valeursRdv  = @json(array_values($rdvParStatut));
  const valeursTriage = @json(array_values($triagesParNiveau));
  const valeursRoles  = @json(array_values($comptesParRole));

  const options = { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } };

  new Chart(document.getElementById('graphRdv'), {
    type: 'doughnut',
    data: {
      labels: libellesRdv,
      datasets: [{ data: valeursRdv, backgroundColor: ['#E8911F', '#2E9E5B', '#DC2626', '#94A3B8', '#1E6BB8'] }],
    },
    options,
  });

  new Chart(document.getElementById('graphTriage'), {
    type: 'bar',
    data: {
      labels: @json(array_values($libellesTriage)),
      datasets: [{ label: 'Triages', data: valeursTriage, backgroundColor: ['#2E9E5B', '#E8911F', '#DC2626'] }],
    },
    options: {
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });

  new Chart(document.getElementById('graphRoles'), {
    type: 'doughnut',
    data: {
      labels: @json(array_values($libellesRole)),
      datasets: [{ data: valeursRoles, backgroundColor: ['#0C3463', '#1E6BB8', '#7FB3E3'] }],
    },
    options,
  });
</script>
@endsection
