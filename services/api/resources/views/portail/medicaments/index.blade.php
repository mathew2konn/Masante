@extends('portail.layout')

@section('titre', 'Référentiel des médicaments')

@section('content')
<div class="mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-capsule-pill"></i> Référentiel national des médicaments</h1>
  <p class="text-muted mb-0">Code national, DCI, forme, dosage, voie, interactions — CDC_09 §6.</p>
</div>

{{--
  Le bandeau le plus important de l'écran. Sans lui, un agent croirait qu'enregistrer suffit à
  diffuser : c'est faux depuis la mise sous gouvernance, et le découvrir après coup ferait croire
  à une panne.
--}}
<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-shield-lock"></i>
  <div class="small">
    Ce que vous modifiez ici est le <strong>contenu de travail</strong>. Il ne sera diffusé
    qu'après <strong>publication d'une nouvelle version</strong> du référentiel, laquelle demande
    une proposition puis une validation par une <strong>seconde personne</strong> (CDC_09 §10).
  </div>
</div>

@if ($sansCode > 0)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-exclamation-triangle"></i>
    <div class="small">
      <strong>{{ $sansCode }}</strong> médicament(s) sans code national. Le référentiel ne peut pas
      être publié tant qu'il en reste : lancez <code>php artisan masante:medicaments:backfill</code>.
    </div>
  </div>
@endif

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-6">
    <input type="text" name="q" class="form-control" placeholder="Code national, DCI ou nom commercial…" value="{{ $filtres['q'] ?? '' }}">
  </div>
  <div class="col-md-3">
    <select name="statut" class="form-select">
      <option value="">Tous les statuts</option>
      @foreach ($statutsMarche as $valeur => $libelle)
        <option value="{{ $valeur }}" @selected(($filtres['statut'] ?? '') === $valeur)>{{ $libelle }}</option>
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
          <th>DCI / nom commercial</th>
          <th>Forme &amp; dosage</th>
          <th>Statut</th>
          <th class="text-end"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($medicaments as $medicament)
          <tr>
            <td>
              @if ($medicament->code)
                <code>{{ $medicament->code }}</code>
              @else
                <span class="badge bg-danger">sans code</span>
              @endif
            </td>
            <td>
              <div class="fw-medium">{{ $medicament->nom_generique }}</div>
              @if ($medicament->nom_commercial)
                <div class="small text-muted">{{ $medicament->nom_commercial }}</div>
              @endif
            </td>
            <td class="small">
              @if ($medicament->forme || $medicament->dosage)
                {{ \App\Support\Medicaments::libelleForme($medicament->forme) }}
                @if ($medicament->dosage) — {{ $medicament->dosage }} @endif
              @else
                <span class="text-muted">non renseigné</span>
              @endif
            </td>
            <td>
              @php($statut = $medicament->statut_marche)
              <span class="badge bg-{{ $statut === 'autorise' ? 'success' : ($statut === 'suspendu' ? 'warning text-dark' : 'danger') }}">
                {{ $statutsMarche[$statut] ?? $statut }}
              </span>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="{{ route('portail.medicaments.edit', $medicament) }}">
                <i class="bi bi-pencil"></i> Éditer
              </a>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-4">Aucun médicament ne correspond.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $medicaments->links() }}</div>
@endsection
