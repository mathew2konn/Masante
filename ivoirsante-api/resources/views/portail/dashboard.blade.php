@extends('portail.layout')

@section('titre', 'Tableau de bord')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Bonjour {{ $utilisateur->prenom }} 👋</h1>
    <p class="text-muted mb-0">Bienvenue sur le portail administratif MaSanté.</p>
  </div>
</div>

@php
  // Cartes des fonctions du portail. Chaque carte n'apparaît que si le rôle a la permission.
  // `route` = écran livré (carte cliquable) ; absent = écran à venir (badge « Bientôt »).
  $cartes = [
    ['perm' => 'etablissement.manage', 'icone' => 'bi-hospital',        'titre' => 'Établissements',  'desc' => 'Créer et gérer les établissements partenaires.', 'route' => 'portail.etablissements.index'],
    ['perm' => 'compte.manage',        'icone' => 'bi-people',           'titre' => 'Comptes',         'desc' => 'Gérer tous les comptes du portail.'],
    ['perm' => 'moderation.manage',    'icone' => 'bi-shield-check',      'titre' => 'Modération',      'desc' => 'Modérer les avis et signalements.'],
    ['perm' => 'stats.global',         'icone' => 'bi-graph-up',          'titre' => 'Statistiques',    'desc' => 'Vue globale de la plateforme.'],
    ['perm' => 'service.manage',       'icone' => 'bi-clipboard2-pulse',  'titre' => 'Mes services',    'desc' => 'Gérer les services de votre établissement.'],
    ['perm' => 'agent.manage',         'icone' => 'bi-person-badge',      'titre' => 'Mes agents',      'desc' => 'Créer et gérer les agents de garde.'],
    ['perm' => 'disponibilite.manage', 'icone' => 'bi-toggles',          'titre' => 'Disponibilité',   'desc' => 'Mettre à jour la disponibilité de votre service.'],
    ['perm' => 'rdv.validate',         'icone' => 'bi-calendar-check',    'titre' => 'Rendez-vous',     'desc' => 'Valider ou refuser les demandes de RDV.'],
    ['perm' => 'qr.scan',              'icone' => 'bi-qr-code-scan',      'titre' => 'Scan QR',         'desc' => 'Scanner le QR patient (carnet / RDV).'],
  ];
@endphp

<div class="row g-3">
  @foreach ($cartes as $c)
    @can($c['perm'])
      @php($lien = isset($c['route']) ? route($c['route']) : null)
      <div class="col-sm-6 col-lg-4">
        <a href="{{ $lien ?? '#' }}" class="text-decoration-none text-reset {{ $lien ? '' : 'pe-none' }}">
          <div class="card h-100 border-0 shadow-sm {{ $lien ? 'card-active' : '' }}">
            <div class="card-body">
              <div class="text-ms mb-2" style="font-size:1.75rem"><i class="bi {{ $c['icone'] }}"></i></div>
              <h2 class="h6 mb-1">{{ $c['titre'] }}</h2>
              <p class="text-muted small mb-2">{{ $c['desc'] }}</p>
              @if ($lien)
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Ouvrir <i class="bi bi-arrow-right"></i></span>
              @else
                <span class="badge bg-light text-muted border">Bientôt</span>
              @endif
            </div>
          </div>
        </a>
      </div>
    @endcan
  @endforeach
</div>

<style>.card-active { transition: transform .12s ease, box-shadow .12s ease; } .card-active:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.12) !important; }</style>

<p class="text-muted small mt-4">
  <i class="bi bi-info-circle"></i>
  Les fonctions restantes seront activées aux prochaines étapes du Module 4.
</p>
@endsection
