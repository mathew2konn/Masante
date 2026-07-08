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
  // Le socle 4.1 pose l'accès ; les écrans (routes) arrivent aux sous-étapes 4.2 → 4.6.
  $cartes = [
    ['perm' => 'etablissement.manage', 'icone' => 'bi-hospital',        'titre' => 'Établissements',  'desc' => 'Créer et gérer les établissements partenaires.'],
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
      <div class="col-sm-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-ms mb-2" style="font-size:1.75rem"><i class="bi {{ $c['icone'] }}"></i></div>
            <h2 class="h6 mb-1">{{ $c['titre'] }}</h2>
            <p class="text-muted small mb-2">{{ $c['desc'] }}</p>
            <span class="badge bg-light text-muted border">Bientôt</span>
          </div>
        </div>
      </div>
    @endcan
  @endforeach
</div>

<p class="text-muted small mt-4">
  <i class="bi bi-info-circle"></i>
  Socle du portail (4.1). Les fonctions ci-dessus seront activées aux prochaines étapes du Module 4.
</p>
@endsection
