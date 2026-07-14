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
  // `structure` = carte réservée aux comptes rattachés à un établissement (gestionnaire/agent) :
  // l'admin possède toutes les permissions mais n'a pas d'établissement → on masque « Mes … ».
  $rattacheEtab = $utilisateur->structure_id !== null;
  $cartes = [
    ['perm' => 'etablissement.manage', 'icone' => 'bi-hospital',        'titre' => 'Établissements',  'desc' => 'Créer et gérer les établissements partenaires.', 'route' => 'portail.etablissements.index'],
    ['perm' => 'compte.manage',        'icone' => 'bi-people',           'titre' => 'Comptes',         'desc' => 'Gérer les comptes du portail (staff).', 'route' => 'portail.comptes.index'],
    ['perm' => 'moderation.manage',    'icone' => 'bi-shield-check',      'titre' => 'Modération',      'desc' => 'Modérer les avis et signalements.', 'route' => 'portail.moderation.index'],
    ['perm' => 'stats.global',         'icone' => 'bi-graph-up',          'titre' => 'Statistiques',    'desc' => 'Vue globale de la plateforme.', 'route' => 'portail.statistiques.global'],
    ['perm' => 'sante_publique.manage','icone' => 'bi-broadcast',         'titre' => 'Alertes épidémiques', 'desc' => 'Publier les bulletins sanitaires par commune.', 'route' => 'portail.sante-publique.index'],
    ['perm' => 'stats.etablissement',  'icone' => 'bi-bar-chart-line',    'titre' => 'Mes statistiques', 'desc' => 'Rendez-vous et avis de votre établissement.', 'route' => 'portail.statistiques.etablissement', 'structure' => true],
    ['perm' => 'service.manage',       'icone' => 'bi-clipboard2-pulse',  'titre' => 'Mes services',    'desc' => 'Gérer les services de votre établissement.', 'route' => 'portail.services.index', 'structure' => true],
    ['perm' => 'agent.manage',         'icone' => 'bi-person-badge',      'titre' => 'Mes agents',      'desc' => 'Créer et gérer les agents de garde.', 'route' => 'portail.agents.index', 'structure' => true],
    ['perm' => 'medecin.manage',       'icone' => 'bi-person-vcard',      'titre' => 'Mes médecins',    'desc' => 'Praticiens visibles des patients (RDV, médecin référent).', 'route' => 'portail.medecins.index', 'structure' => true],
    ['perm' => 'don_sang.manage',      'icone' => 'bi-droplet-half',      'titre' => 'Don de sang',     'desc' => 'Publier vos besoins ; alerter les donneurs compatibles.', 'route' => 'portail.don-sang.index', 'structure' => true],
    ['perm' => 'disponibilite.manage', 'icone' => 'bi-toggles',          'titre' => 'Disponibilité',   'desc' => 'Mettre à jour la disponibilité de votre service.', 'route' => 'portail.disponibilites.index', 'structure' => true],
    ['perm' => 'rdv.validate',         'icone' => 'bi-calendar-check',    'titre' => 'Rendez-vous',     'desc' => 'Valider ou refuser les demandes de RDV.', 'route' => 'portail.rdv.index', 'structure' => true],
    ['perm' => 'qr.scan',              'icone' => 'bi-qr-code-scan',      'titre' => 'Scan carnet',     'desc' => 'Scanner le QR du carnet et ouvrir le dossier (30 min).', 'route' => 'portail.scan.index', 'structure' => true],
    ['perm' => 'qr.scan',              'icone' => 'bi-person-check',      'titre' => 'Accueil patient', 'desc' => 'Enregistrer l\'arrivée par le QR du reçu de RDV.', 'route' => 'portail.scan.rdv', 'structure' => true],
    ['perm' => 'dossier.referent',     'icone' => 'bi-clipboard2-heart',  'titre' => 'Mes patients suivis', 'desc' => 'Patients qui vous ont désigné médecin référent.', 'route' => 'portail.patients.index', 'structure' => true],
    ['perm' => 'urgence.bris_de_glace','icone' => 'bi-exclamation-octagon', 'titre' => 'Accès d\'urgence', 'desc' => 'Patient inconscient : ouvrir ses informations vitales.', 'route' => 'portail.urgence.bris', 'structure' => true, 'danger' => true],
  ];
@endphp

<div class="row g-3">
  @foreach ($cartes as $c)
    @continue(($c['structure'] ?? false) && ! $rattacheEtab)
    @can($c['perm'])
      @php($lien = isset($c['route']) ? route($c['route']) : null)
      <div class="col-sm-6 col-lg-4">
        <a href="{{ $lien ?? '#' }}" class="text-decoration-none text-reset {{ $lien ? '' : 'pe-none' }}">
          {{-- La carte d'accès d'urgence est bordée de rouge : c'est une procédure d'exception,
               elle ne doit pas se confondre avec une fonction ordinaire du portail. --}}
          <div class="card h-100 shadow-sm {{ ($c['danger'] ?? false) ? 'border-danger' : 'border-0' }} {{ $lien ? 'card-active' : '' }}">
            <div class="card-body">
              <div class="{{ ($c['danger'] ?? false) ? 'text-danger' : 'text-ms' }} mb-2" style="font-size:1.75rem"><i class="bi {{ $c['icone'] }}"></i></div>
              <h2 class="h6 mb-1">{{ $c['titre'] }}</h2>
              <p class="text-muted small mb-2">{{ $c['desc'] }}</p>
              @if ($lien && ($c['danger'] ?? false))
                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Procédure justifiée et auditée</span>
              @elseif ($lien)
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

@endsection
