@extends('portail.layout')

@section('titre', $alerte->exists ? 'Modifier une alerte' : 'Nouvelle alerte')

@section('content')
@php
  $estNationale = $alerte->commune === \App\Models\AlerteEpidemique::NATIONALE;
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">
    <i class="bi bi-broadcast"></i> {{ $alerte->exists ? 'Modifier l\'alerte' : 'Nouvelle alerte épidémique' }}
  </h1>
  <a href="{{ route('portail.sante-publique.index') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Retour
  </a>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body">
    <form method="POST" action="{{ $alerte->exists ? route('portail.sante-publique.update', $alerte) : route('portail.sante-publique.store') }}">
      @csrf
      @if ($alerte->exists) @method('PUT') @endif

      <div class="row g-3">
        <div class="col-md-6">
          <label for="maladie" class="form-label">Maladie</label>
          <input type="text" id="maladie" name="maladie" list="maladies" maxlength="100" required
                 class="form-control @error('maladie') is-invalid @enderror" value="{{ old('maladie', $alerte->maladie) }}">
          <datalist id="maladies">
            @foreach ($maladies as $m)<option value="{{ $m }}">@endforeach
          </datalist>
          @error('maladie')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-md-6">
          <label for="niveau_alerte" class="form-label">Niveau</label>
          <select id="niveau_alerte" name="niveau_alerte" class="form-select @error('niveau_alerte') is-invalid @enderror" required>
            @foreach ($niveaux as $cle => $libelle)
              <option value="{{ $cle }}" @selected(old('niveau_alerte', $alerte->niveau_alerte) === $cle)>{{ $libelle }}</option>
            @endforeach
          </select>
          @error('niveau_alerte')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-12">
          <label for="titre" class="form-label">Titre</label>
          <input type="text" id="titre" name="titre" maxlength="300" required
                 class="form-control @error('titre') is-invalid @enderror" value="{{ old('titre', $alerte->titre) }}"
                 placeholder="Ex. : Recrudescence du paludisme à Cocody">
          @error('titre')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-12">
          <label for="description" class="form-label">Description et consignes</label>
          <textarea id="description" name="description" rows="4" maxlength="5000" required
                    class="form-control @error('description') is-invalid @enderror"
                    placeholder="Symptômes à surveiller, gestes de prévention, où consulter…">{{ old('description', $alerte->description) }}</textarea>
          @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        {{-- Portée : une commune précise, ou toute la Côte d'Ivoire (alerte nationale). --}}
        <div class="col-12">
          <label class="form-label d-block">Portée</label>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="portee" id="portee_commune" value="commune"
                   {{ $estNationale ? '' : 'checked' }} onchange="document.getElementById('champ-commune').style.display='block'">
            <label class="form-check-label" for="portee_commune">Une commune</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="portee" id="portee_nationale" value="nationale"
                   {{ $estNationale ? 'checked' : '' }} onchange="document.getElementById('champ-commune').style.display='none'">
            <label class="form-check-label" for="portee_nationale">Nationale (toutes communes)</label>
          </div>

          <div id="champ-commune" style="{{ $estNationale ? 'display:none' : '' }}" class="mt-2">
            <input type="text" name="commune_saisie" maxlength="100"
                   class="form-control @error('commune') is-invalid @enderror"
                   value="{{ old('commune_saisie', $estNationale ? '' : $alerte->commune) }}" placeholder="Ex. : Cocody">
            @error('commune')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
          </div>
        </div>

        <div class="col-md-6">
          <label for="date_debut" class="form-label">Date de début</label>
          <input type="date" id="date_debut" name="date_debut" required
                 class="form-control @error('date_debut') is-invalid @enderror" value="{{ old('date_debut', optional($alerte->date_debut)->format('Y-m-d')) }}">
          @error('date_debut')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-md-6">
          <label for="date_fin" class="form-label">Date de fin <span class="text-muted small">(vide = en cours)</span></label>
          <input type="date" id="date_fin" name="date_fin"
                 class="form-control @error('date_fin') is-invalid @enderror" value="{{ old('date_fin', optional($alerte->date_fin)->format('Y-m-d')) }}">
          @error('date_fin')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-12">
          <label for="source" class="form-label">Source</label>
          <input type="text" id="source" name="source" maxlength="200" required
                 class="form-control @error('source') is-invalid @enderror" value="{{ old('source', $alerte->source ?? 'Ministère de la Santé CI') }}">
          @error('source')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
      </div>

      <button type="submit" class="btn btn-ms mt-4">
        <i class="bi bi-check-lg"></i> {{ $alerte->exists ? 'Enregistrer' : 'Publier l\'alerte' }}
      </button>
    </form>
  </div>
</div>
@endsection
