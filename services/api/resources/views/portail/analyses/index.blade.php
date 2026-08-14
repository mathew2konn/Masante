@extends('portail.layout')

@section('titre', 'Catalogue des analyses')

@section('content')
<div class="mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-eyedropper"></i> Catalogue national des analyses</h1>
  <p class="text-muted mb-0">Code national, unité, conditions de prélèvement et valeurs de référence — CDC_09 §7.3.</p>
</div>

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-shield-lock"></i>
  <div class="small">
    Ce que vous modifiez ici est le <strong>contenu de travail</strong>. Il ne sera diffusé qu'après
    <strong>publication d'une nouvelle version</strong> du catalogue, laquelle demande une proposition
    puis une validation par une <strong>seconde personne</strong> (CDC_09 §10).
  </div>
</div>

{{--
  LE BANDEAU LE PLUS IMPORTANT DE L'ÉCRAN. Il dit ce que valent les valeurs livrées avec le projet.
  Sans lui, un agent — ou un jury — pourrait croire que ces plages font autorité.
--}}
@if ($demonstration > 0)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-exclamation-octagon"></i>
    <div class="small">
      <strong>{{ $demonstration }}</strong> valeur(s) de référence sur {{ $totalStrates }} proviennent du
      <strong>jeu de démonstration</strong> : elles ne sont ni validées cliniquement, ni attribuées à une
      autorité sanitaire, ni établies sur la population ivoirienne.
      <br>
      Elles doivent être remplacées par un référentiel biologique réel avant tout usage clinique —
      idéalement par des intervalles <strong>établis localement</strong> : plusieurs paramètres
      hématologiques ont des valeurs usuelles différentes en Afrique subsaharienne, au point qu'un
      intervalle établi ailleurs classerait « anormaux » des sujets sains.
    </div>
  </div>
@endif

@if ($sansCode > 0)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-exclamation-triangle"></i>
    <div class="small">
      <strong>{{ $sansCode }}</strong> analyse(s) sans code national. Le catalogue ne peut pas être publié
      tant qu'il en reste : lancez <code>php artisan masante:analyses:backfill</code>.
    </div>
  </div>
@endif

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-6">
    <input type="text" name="q" class="form-control" placeholder="Code national ou libellé…" value="{{ $filtres['q'] ?? '' }}">
  </div>
  <div class="col-md-3">
    <select name="categorie" class="form-select">
      <option value="">Toutes les catégories</option>
      @foreach ($categories as $valeur => $libelle)
        <option value="{{ $valeur }}" @selected(($filtres['categorie'] ?? '') === $valeur)>{{ $libelle }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-md-2 d-grid">
    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrer</button>
  </div>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Code</th>
          <th>Analyse</th>
          <th>Unité</th>
          <th>Références</th>
          <th class="text-end"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($analyses as $analyse)
          <tr>
            <td>
              @if ($analyse->code)<code>{{ $analyse->code }}</code>@else<span class="badge bg-danger">sans code</span>@endif
              @if (! $analyse->actif)<span class="badge bg-secondary ms-1">inactive</span>@endif
            </td>
            <td>
              <div class="fw-medium">{{ $analyse->libelle }}</div>
              <div class="small text-muted">
                {{ \App\Support\Analyses::libelleMilieu($analyse->milieu_preleve) ?? 'milieu non renseigné' }}
                @if ($analyse->loinc) · LOINC {{ $analyse->loinc }} @endif
              </div>
            </td>
            <td class="small">{{ $analyse->unite }}</td>
            <td>
              @if ($analyse->references_count > 0)
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                  {{ $analyse->references_count }} strate(s)
                </span>
              @else
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">aucune</span>
              @endif
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="{{ route('portail.analyses.edit', $analyse) }}">
                <i class="bi bi-pencil"></i> Éditer
              </a>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-4">Aucune analyse ne correspond.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $analyses->links() }}</div>
@endsection
