@extends('portail.layout')

@section('titre', 'Fiche analyse')

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">{{ $analyse->libelle }}</h1>
    <p class="text-muted mb-0">
      @if ($analyse->code)<code>{{ $analyse->code }}</code> —@endif
      catalogue national des analyses (CDC_09 §7.3)
    </p>
  </div>
  <a class="btn btn-outline-secondary" href="{{ route('portail.analyses.index') }}">
    <i class="bi bi-arrow-left"></i> Retour
  </a>
</div>

@if (session('succes'))<div class="alert alert-success">{{ session('succes') }}</div>@endif

@if ($errors->any())
  <div class="alert alert-danger">
    <ul class="mb-0">@foreach ($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach</ul>
  </div>
@endif

<form method="POST" action="{{ route('portail.analyses.update', $analyse) }}" class="card border-0 shadow-sm mb-4">
  @csrf @method('PUT')
  <div class="card-body">
    <h2 class="h6 text-uppercase text-muted mb-3">Identification</h2>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Code national</label>
        {{-- Affiché, jamais saisissable : l'identifiant national se reçoit. --}}
        <input type="text" class="form-control" value="{{ $analyse->code ?? 'attribué à l\'enregistrement' }}" disabled>
      </div>
      <div class="col-md-4">
        <label class="form-label">Code LOINC</label>
        <input type="text" name="loinc" class="form-control" value="{{ old('loinc', $analyse->loinc) }}" maxlength="20" placeholder="non renseigné">
        <div class="form-text">Standard international recommandé par CDC_09 §9.1. Vide tant que le jeu LOINC n'est pas chargé.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Catégorie</label>
        <select name="categorie" class="form-select">
          <option value="">— non renseignée —</option>
          @foreach ($categories as $valeur => $libelle)
            <option value="{{ $valeur }}" @selected(old('categorie', $analyse->categorie) === $valeur)>{{ $libelle }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Libellé *</label>
        <input type="text" name="libelle" class="form-control @error('libelle') is-invalid @enderror"
               value="{{ old('libelle', $analyse->libelle) }}" required maxlength="200">
        @error('libelle')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-3">
        <label class="form-label">Milieu prélevé</label>
        <select name="milieu_preleve" class="form-select">
          <option value="">— non renseigné —</option>
          @foreach ($milieux as $valeur => $libelle)
            <option value="{{ $valeur }}" @selected(old('milieu_preleve', $analyse->milieu_preleve) === $valeur)>{{ $libelle }}</option>
          @endforeach
        </select>
        <div class="form-text">Il fait partie de l'identité : deux milieux = deux analyses.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Unité *</label>
        <input type="text" name="unite" class="form-control @error('unite') is-invalid @enderror"
               value="{{ old('unite', $analyse->unite) }}" required maxlength="40" placeholder="g/dL">
        @error('unite')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>
      <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2" maxlength="5000">{{ old('description', $analyse->description) }}</textarea>
      </div>
    </div>

    <hr class="my-4">
    <h2 class="h6 text-uppercase text-muted mb-3">Réalisation</h2>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Méthode analytique</label>
        <input type="text" name="methode" class="form-control" value="{{ old('methode', $analyse->methode) }}" maxlength="200">
      </div>
      <div class="col-md-3">
        <label class="form-label">Délai de rendu (heures)</label>
        <input type="number" name="delai_rendu_heures" class="form-control" min="0" max="8760"
               value="{{ old('delai_rendu_heures', $analyse->delai_rendu_heures) }}">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="actif" value="1" id="actif" @checked(old('actif', $analyse->actif))>
          <label class="form-check-label" for="actif">Analyse active</label>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label">Conditions de prélèvement</label>
        <textarea name="conditions_prelevement" class="form-control" rows="2" maxlength="2000">{{ old('conditions_prelevement', $analyse->conditions_prelevement) }}</textarea>
      </div>
      <div class="col-md-6">
        <label class="form-label">Conservation</label>
        <textarea name="conservation" class="form-control" rows="2" maxlength="2000">{{ old('conservation', $analyse->conservation) }}</textarea>
      </div>
    </div>
  </div>
  <div class="card-footer bg-white text-end">
    <button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button>
  </div>
</form>

{{-- Strates de référence — formulaire SÉPARÉ (un refus ici ne fait pas perdre la fiche). --}}
<div class="card border-0 shadow-sm">
  <div class="card-body">
    <h2 class="h6 text-uppercase text-muted mb-3">Valeurs de référence</h2>

    <div class="alert alert-secondary small d-flex gap-2">
      <i class="bi bi-info-circle"></i>
      <div>
        Une plage biologique <strong>dépend de la personne</strong> : le même résultat peut être normal
        chez l'un et bas chez l'autre. On déclare donc une <strong>strate</strong> par population.
        La plateforme <strong>affiche</strong> les strates applicables à côté d'un résultat ; elle ne
        conclut jamais « normal » ou « anormal ».
      </div>
    </div>

    <table class="table align-middle">
      <thead class="table-light">
        <tr><th>Strate</th><th>Sexe</th><th>Âge (jours)</th><th>État</th><th>Plage</th><th>Source</th><th></th></tr>
      </thead>
      <tbody>
        @forelse ($strates as $strate)
          <tr>
            <td class="small fw-medium">{{ $strate->libelle_strate }}</td>
            <td class="small">{{ $sexes[$strate->sexe] ?? $strate->sexe }}</td>
            <td class="small">
              {{ $strate->age_min_jours ?? '—' }} → {{ $strate->age_max_jours ?? '—' }}
            </td>
            <td class="small">
              {{ $etats[$strate->etat_physiologique] ?? $strate->etat_physiologique }}
              @if ($strate->estConditionnelle())
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">conditionnelle</span>
              @endif
            </td>
            <td class="small">{{ $strate->plageLisible() }}</td>
            <td class="small">
              @if ($strate->source === 'demonstration')
                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">démonstration</span>
              @else
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                  {{ $sources[$strate->source] ?? $strate->source }}
                </span>
              @endif
            </td>
            <td class="text-end">
              <form method="POST" action="{{ route('portail.analyses.strates.retirer', [$analyse, $strate]) }}">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-3">Aucune valeur de référence déclarée.</td></tr>
        @endforelse
      </tbody>
    </table>

    <form method="POST" action="{{ route('portail.analyses.strates.ajouter', $analyse) }}" class="row g-2 align-items-end">
      @csrf
      <div class="col-md-3">
        <label class="form-label small">Libellé de la strate *</label>
        <input type="text" name="libelle_strate" class="form-control form-control-sm" required maxlength="120" placeholder="Femme adulte">
      </div>
      <div class="col-md-1">
        <label class="form-label small">Sexe *</label>
        <select name="sexe" class="form-select form-select-sm" required>
          @foreach ($sexes as $valeur => $libelle)
            <option value="{{ $valeur }}">{{ $libelle }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label small">Âge min (j)</label>
        <input type="number" name="age_min_jours" class="form-control form-control-sm" min="0" max="45000">
      </div>
      <div class="col-md-1">
        <label class="form-label small">Âge max (j)</label>
        <input type="number" name="age_max_jours" class="form-control form-control-sm" min="0" max="45000">
      </div>
      <div class="col-md-2">
        <label class="form-label small">État *</label>
        <select name="etat_physiologique" class="form-select form-select-sm" required>
          @foreach ($etats as $valeur => $libelle)
            <option value="{{ $valeur }}">{{ $libelle }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label small">Min</label>
        <input type="number" step="any" name="valeur_min" class="form-control form-control-sm">
      </div>
      <div class="col-md-1">
        <label class="form-label small">Max</label>
        <input type="number" step="any" name="valeur_max" class="form-control form-control-sm">
      </div>
      <div class="col-md-2">
        <label class="form-label small">Source *</label>
        <select name="source" class="form-select form-select-sm" required>
          @foreach ($sources as $valeur => $libelle)
            <option value="{{ $valeur }}">{{ $libelle }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12">
        <div class="d-flex gap-2 align-items-end">
          <div class="flex-grow-1">
            <label class="form-label small">Détail de la source</label>
            <input type="text" name="source_detail" class="form-control form-control-sm" maxlength="200"
                   placeholder="Référence de la publication ou de l'arrêté">
          </div>
          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Ajouter la strate</button>
        </div>
      </div>
    </form>
  </div>
</div>
@endsection
