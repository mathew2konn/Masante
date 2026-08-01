@extends('portail.layout')

@section('titre', 'Modération')

@section('content')
{{--
  Tous les textes affichés ici (commentaires d'avis, descriptions de signalements) sont du
  contenu utilisateur : ils passent par {{ }}, que Blade échappe. Aucun {!! !!} (Sécurité §A03).
--}}
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-shield-check"></i> Modération</h1>
    <p class="text-muted mb-0">Avis patients et signalements citoyens.</p>
  </div>
  <a href="{{ route('portail.dashboard') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Tableau de bord
  </a>
</div>

@error('publication')
  <div class="alert alert-danger">{{ $message }}</div>
@enderror

<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link {{ $onglet === 'signalements' ? 'active' : '' }}"
       href="{{ route('portail.moderation.index', ['onglet' => 'signalements']) }}">
      Signalements
      @if ($enAttente > 0)<span class="badge bg-danger ms-1">{{ $enAttente }}</span>@endif
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link {{ $onglet === 'avis' ? 'active' : '' }}"
       href="{{ route('portail.moderation.index', ['onglet' => 'avis']) }}">
      Avis
      @if ($aExaminer > 0)<span class="badge bg-warning text-dark ms-1">{{ $aExaminer }}</span>@endif
    </a>
  </li>
</ul>

@php
  $filtres = $onglet === 'avis'
    ? ['signales' => 'Signalés', 'masques' => 'Masqués', 'tous' => 'Tous']
    : ['en_attente' => 'En attente', 'valide' => 'Validés', 'rejete' => 'Rejetés', 'tous' => 'Tous'];
  $filtreActif = $filtre ?: array_key_first($filtres);
@endphp

<div class="btn-group btn-group-sm mb-3">
  @foreach ($filtres as $cle => $libelle)
    <a href="{{ route('portail.moderation.index', ['onglet' => $onglet, 'filtre' => $cle]) }}"
       class="btn {{ $filtreActif === $cle ? 'btn-ms' : 'btn-outline-secondary' }}">{{ $libelle }}</a>
  @endforeach
</div>

@if ($onglet === 'signalements')

  @forelse ($signalements as $s)
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3">
          <div>
            <span class="badge bg-secondary-subtle text-secondary-emphasis border">{{ $types[$s->type] ?? $s->type }}</span>
            @if ($s->statut === 'valide')
              <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Validé</span>
            @elseif ($s->statut === 'rejete')
              <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Rejeté</span>
            @else
              <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">En attente</span>
            @endif
            @if ($s->visible_publiquement)
              <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                <i class="bi bi-eye"></i> Public
              </span>
            @endif
            <h2 class="h6 mt-2 mb-1">{{ $s->structure->nom ?? 'Structure supprimée' }}
              <span class="text-muted fw-normal small">· {{ $s->structure->commune ?? '' }}</span>
            </h2>
          </div>
          <span class="text-muted small text-nowrap">{{ $s->created_at->format('d/m/Y H:i') }}</span>
        </div>

        <p class="mb-3">{{ $s->description }}</p>

        @if ($s->estTraite())
          <div class="d-flex flex-wrap gap-2 align-items-center">
            @if ($s->statut === 'valide')
              <form method="POST" action="{{ route('portail.moderation.publication', $s) }}" class="m-0">
                @csrf @method('PATCH')
                <button class="btn btn-sm {{ $s->visible_publiquement ? 'btn-outline-secondary' : 'btn-ms' }}">
                  <i class="bi bi-{{ $s->visible_publiquement ? 'eye-slash' : 'megaphone' }}"></i>
                  {{ $s->visible_publiquement ? 'Retirer de l\'historique public' : 'Publier' }}
                </button>
              </form>
            @endif
            <span class="text-muted small">
              <i class="bi bi-clock-history"></i>
              Modéré le {{ optional($s->modere_at)->format('d/m/Y H:i') ?? '—' }}
            </span>
          </div>
        @else
          <form method="POST" action="{{ route('portail.moderation.trancher', $s) }}" class="row g-2 align-items-start">
            @csrf @method('PATCH')
            <div class="col-md-7">
              <input type="text" name="motif" class="form-control form-control-sm @error('motif') is-invalid @enderror"
                     placeholder="Motif (obligatoire pour rejeter)" value="{{ old('motif') }}" maxlength="500">
              @error('motif')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-5 d-flex gap-2">
              <button class="btn btn-sm btn-success flex-fill" name="decision" value="valide">
                <i class="bi bi-check-lg"></i> Valider
              </button>
              <button class="btn btn-sm btn-outline-danger flex-fill" name="decision" value="rejete">
                <i class="bi bi-x-lg"></i> Rejeter
              </button>
            </div>
          </form>
          <p class="text-muted small mt-2 mb-0">
            <i class="bi bi-info-circle"></i> Valider ne publie pas : la publication est une décision séparée.
          </p>
        @endif
      </div>
    </div>
  @empty
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted">
      <i class="bi bi-inbox"></i> Aucun signalement dans cette vue.
    </div></div>
  @endforelse

  {{ $signalements->links() }}

@else

  @forelse ($avis as $a)
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3">
          <div>
            <span class="text-warning">{{ str_repeat('★', $a->note) }}{{ str_repeat('☆', 5 - $a->note) }}</span>
            @if ($a->consultation_verifiee)
              <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                <i class="bi bi-patch-check"></i> Consultation vérifiée
              </span>
            @endif
            @if (! $a->visible)
              <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Masqué</span>
            @endif
            @if ($a->signale)
              <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                <i class="bi bi-flag"></i> Signalé automatiquement
              </span>
            @endif
            <h2 class="h6 mt-2 mb-1">{{ $a->structure->nom ?? 'Structure supprimée' }}
              <span class="text-muted fw-normal small">· par {{ $a->auteur }}</span>
            </h2>
          </div>
          <span class="text-muted small text-nowrap">{{ $a->created_at->format('d/m/Y H:i') }}</span>
        </div>

        <p class="mb-3">{{ $a->commentaire ?: 'Note sans commentaire.' }}</p>

        <form method="POST" action="{{ route('portail.moderation.avis', $a) }}" class="row g-2 align-items-start">
          @csrf @method('PATCH')
          <div class="col-md-8">
            <input type="text" name="motif" class="form-control form-control-sm @error('motif') is-invalid @enderror"
                   placeholder="{{ $a->visible ? 'Motif du masquage (obligatoire)' : 'Motif du rétablissement (facultatif)' }}"
                   value="{{ old('motif') }}" maxlength="500">
            @error('motif')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <button class="btn btn-sm {{ $a->visible ? 'btn-outline-danger' : 'btn-success' }} w-100">
              <i class="bi bi-{{ $a->visible ? 'eye-slash' : 'arrow-counterclockwise' }}"></i>
              {{ $a->visible ? 'Masquer l\'avis' : 'Rétablir l\'avis' }}
            </button>
          </div>
        </form>

        @if ($a->modere_at)
          <p class="text-muted small mt-2 mb-0">
            <i class="bi bi-clock-history"></i> Dernière décision le {{ $a->modere_at->format('d/m/Y H:i') }}
          </p>
        @endif
      </div>
    </div>
  @empty
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted">
      <i class="bi bi-inbox"></i> Aucun avis dans cette vue.
    </div></div>
  @endforelse

  {{ $avis->links() }}

@endif

<p class="text-muted small mt-4">
  <i class="bi bi-shield-lock"></i>
  Les signalements sont <strong>anonymes</strong> : l'identité du signalant n'est jamais affichée.
  Masquer un avis recalcule automatiquement la note publique de la structure.
</p>
@endsection
