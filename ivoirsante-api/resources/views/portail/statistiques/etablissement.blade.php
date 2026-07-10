@extends('portail.layout')

@section('titre', 'Statistiques de mon établissement')

@section('content')
@php
  $libellesRdv = [
    'en_attente' => 'En attente', 'confirme' => 'Confirmés', 'refuse' => 'Refusés',
    'annule' => 'Annulés', 'honore' => 'Honorés',
  ];
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-graph-up"></i> Mes statistiques</h1>
    <p class="text-muted mb-0">{{ $structure->nom }} · {{ $structure->commune }}</p>
  </div>
  <a href="{{ route('portail.dashboard') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Tableau de bord
  </a>
</div>

<div class="row g-3 mb-4">
  @include('portail.statistiques._kpi', ['valeur' => $rdvTotal, 'libelle' => 'Rendez-vous reçus', 'icone' => 'bi-calendar-check', 'couleur' => 'text-ms'])
  @include('portail.statistiques._kpi', ['valeur' => $enAttente, 'libelle' => 'En attente de validation', 'icone' => 'bi-hourglass-split', 'couleur' => $enAttente > 0 ? 'text-warning' : 'text-ms'])
  @include('portail.statistiques._kpi', ['valeur' => $tauxConfirmation.' %', 'libelle' => 'Taux de confirmation', 'icone' => 'bi-check2-circle', 'couleur' => 'text-success'])
  @include('portail.statistiques._kpi', ['valeur' => $servicesActifs.' / '.$servicesTotal, 'libelle' => 'Services ouverts', 'icone' => 'bi-clipboard2-pulse', 'couleur' => 'text-ms'])
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms mb-3">Rendez-vous par statut</h2>
        <canvas id="graphRdv" height="200"></canvas>
        <p class="text-muted small mb-0 mt-3">
          Le taux de confirmation ne porte que sur les demandes <strong>tranchées</strong>
          (confirmées ou refusées) : les demandes en attente n'y entrent pas.
        </p>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms mb-3">Réputation</h2>
        <div class="d-flex align-items-baseline gap-2">
          <span class="display-5 fw-semibold">{{ $noteMoyenne !== null ? number_format($noteMoyenne, 2) : '—' }}</span>
          <span class="text-warning fs-4">★</span>
          <span class="text-muted">sur {{ $avisVisibles }} avis publié(s)</span>
        </div>

        <hr>

        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-semibold">{{ $signalementsPublies }}</div>
            <div class="text-muted small">Signalement(s) publié(s) sur votre fiche</div>
          </div>
          <i class="bi bi-flag text-muted fs-3"></i>
        </div>

        <p class="text-muted small mb-0 mt-3">
          <i class="bi bi-info-circle"></i>
          Les avis et signalements sont modérés par l'administration MaSanté. Vous ne pouvez ni les
          masquer ni les publier vous-même.
        </p>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  new Chart(document.getElementById('graphRdv'), {
    type: 'bar',
    data: {
      labels: @json(array_values($libellesRdv)),
      datasets: [{
        label: 'Rendez-vous',
        data: @json(array_values($rdvParStatut)),
        backgroundColor: ['#E8911F', '#2E9E5B', '#DC2626', '#94A3B8', '#1E6BB8'],
      }],
    },
    options: {
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });
</script>
@endsection
