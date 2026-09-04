@extends('portail.layout')

@section('titre', 'Fiche médicament')

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">{{ $medicament->nom_generique }}</h1>
    <p class="text-muted mb-0">
      @if ($medicament->code)<code>{{ $medicament->code }}</code> —@endif
      référentiel national des médicaments (CDC_09 §6.2)
    </p>
  </div>
  <a class="btn btn-outline-secondary" href="{{ route('portail.medicaments.index') }}">
    <i class="bi bi-arrow-left"></i> Retour
  </a>
</div>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger">
    <ul class="mb-0">@foreach ($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach</ul>
  </div>
@endif

{{-- Formulaire d'identité — séparé de celui des interactions (précédent P6.4c/d : un refus sur un
     bloc ne doit pas faire perdre la saisie de l'autre). --}}
<form method="POST" action="{{ route('portail.medicaments.update', $medicament) }}" class="card border-0 shadow-sm mb-4">
  @csrf
  @method('PUT')
  <div class="card-body">
    <h2 class="h6 text-uppercase text-muted mb-3">Identification</h2>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Code national</label>
        {{-- Affiché, jamais saisissable : l'identifiant national se reçoit, il ne se choisit pas. --}}
        <input type="text" class="form-control" value="{{ $medicament->code ?? 'attribué à l\'enregistrement' }}" disabled>
      </div>
      <div class="col-md-6">
        <label class="form-label">DCI (dénomination commune internationale) *</label>
        <input type="text" name="nom_generique" class="form-control @error('nom_generique') is-invalid @enderror"
               value="{{ old('nom_generique', $medicament->nom_generique) }}" required maxlength="200">
        @error('nom_generique')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-6">
        <label class="form-label">Nom commercial</label>
        <input type="text" name="nom_commercial" class="form-control"
               value="{{ old('nom_commercial', $medicament->nom_commercial) }}" maxlength="200">
      </div>
      <div class="col-md-6">
        <label class="form-label">Laboratoire fabricant</label>
        <input type="text" name="laboratoire" class="form-control"
               value="{{ old('laboratoire', $medicament->laboratoire) }}" maxlength="200">
      </div>
    </div>

    <hr class="my-4">
    <h2 class="h6 text-uppercase text-muted mb-3">Présentation</h2>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Forme pharmaceutique</label>
        <select name="forme" class="form-select">
          <option value="">— non renseignée —</option>
          @foreach ($formes as $valeur => $libelle)
            <option value="{{ $valeur }}" @selected(old('forme', $medicament->forme) === $valeur)>{{ $libelle }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Dosage</label>
        <input type="text" name="dosage" class="form-control" placeholder="500 mg"
               value="{{ old('dosage', $medicament->dosage) }}" maxlength="100">
        <div class="form-text">Texte libre : « 500 mg », « 1 g / 5 mL », « 40 UI/mL ».</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Voie d'administration</label>
        <select name="voie_administration" class="form-select">
          <option value="">— non renseignée —</option>
          @foreach ($voies as $valeur => $libelle)
            <option value="{{ $valeur }}" @selected(old('voie_administration', $medicament->voie_administration) === $valeur)>{{ $libelle }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Classe thérapeutique *</label>
        <input type="text" name="categorie" class="form-control @error('categorie') is-invalid @enderror"
               value="{{ old('categorie', $medicament->categorie) }}" required maxlength="100">
        @error('categorie')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-3">
        <label class="form-label">Statut générique</label>
        <select name="statut_generique" class="form-select">
          <option value="">— non renseigné —</option>
          @foreach ($statutsGenerique as $valeur => $libelle)
            <option value="{{ $valeur }}" @selected(old('statut_generique', $medicament->statut_generique) === $valeur)>{{ $libelle }}</option>
          @endforeach
        </select>
        <div class="form-text">Ce produit <em>est</em> un générique.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Statut de commercialisation *</label>
        <select name="statut_marche" class="form-select">
          @foreach ($statutsMarche as $valeur => $libelle)
            <option value="{{ $valeur }}" @selected(old('statut_marche', $medicament->statut_marche) === $valeur)>{{ $libelle }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <hr class="my-4">
    <h2 class="h6 text-uppercase text-muted mb-3">Information clinique</h2>
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label">Indications</label>
        <textarea name="indications" class="form-control" rows="3" maxlength="5000">{{ old('indications', $medicament->indications) }}</textarea>
      </div>
      <div class="col-12">
        <label class="form-label">Contre-indications</label>
        <textarea name="contre_indications" class="form-control" rows="3" maxlength="5000">{{ old('contre_indications', $medicament->contre_indications) }}</textarea>
      </div>
      <div class="col-12">
        <label class="form-label">Effets secondaires</label>
        <textarea name="effets_secondaires" class="form-control" rows="3" maxlength="5000">{{ old('effets_secondaires', $medicament->effets_secondaires) }}</textarea>
      </div>
    </div>

    <hr class="my-4">
    <h2 class="h6 text-uppercase text-muted mb-3">Délivrance et prix</h2>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Prix homologué (FCFA)</label>
        <input type="number" name="prix_reference_cfa" class="form-control" min="0"
               value="{{ old('prix_reference_cfa', $medicament->prix_reference_cfa) }}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Référence CENAME</label>
        <input type="text" name="cename_reference" class="form-control"
               value="{{ old('cename_reference', $medicament->cename_reference) }}" maxlength="50">
      </div>
      <div class="col-md-4">
        <label class="form-label">Code-barres (GTIN)</label>
        <input type="text" name="code_barres" class="form-control @error('code_barres') is-invalid @enderror"
               value="{{ old('code_barres', $medicament->code_barres) }}" maxlength="20"
               placeholder="8, 12, 13 ou 14 chiffres">
        @error('code_barres')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">
          {{-- B3-c (E5) — dit ce que le code-barres prouve, jamais plus : le scan reconnaît un
               code au référentiel, il ne certifie pas l'authenticité de la boîte. --}}
          Sert à reconnaître le produit au comptoir, jamais à en certifier l'authenticité.
        </div>
      </div>
      <div class="col-md-4 d-flex flex-column justify-content-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="ordonnance_requise" value="1" id="ordo"
                 @checked(old('ordonnance_requise', $medicament->ordonnance_requise))>
          <label class="form-check-label" for="ordo">Ordonnance requise</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="disponible_generique" value="1" id="gen"
                 @checked(old('disponible_generique', $medicament->disponible_generique))>
          <label class="form-check-label" for="gen">Un générique existe</label>
        </div>
      </div>
    </div>
  </div>
  <div class="card-footer bg-white text-end">
    <button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button>
  </div>
</form>

{{-- Interactions — formulaire SÉPARÉ (voir plus haut). --}}
<div class="card border-0 shadow-sm">
  <div class="card-body">
    <h2 class="h6 text-uppercase text-muted mb-3">Interactions déclarées</h2>

    <div class="alert alert-secondary small d-flex gap-2">
      <i class="bi bi-info-circle"></i>
      <div>
        Le référentiel <strong>constate</strong> une interaction, il ne décide rien : même une
        contre-indication ne bloque aucune prescription. L'analyse, les alternatives et l'adaptation
        de doses relèvent du service d'interactions (CDC_05).
      </div>
    </div>

    <table class="table align-middle">
      <thead class="table-light">
        <tr><th>Avec</th><th>Niveau</th><th>Description</th><th>Source</th><th></th></tr>
      </thead>
      <tbody>
        @forelse ($interactions as $interaction)
          @php($autre = $interaction->autreQue($medicament->id))
          <tr>
            <td>
              <div class="fw-medium">{{ $autre?->nom_generique ?? '—' }}</div>
              @if ($autre?->code)<code class="small">{{ $autre->code }}</code>@endif
            </td>
            <td>
              <span class="badge bg-{{ $interaction->niveau === 'contre_indication' ? 'danger' : ($interaction->niveau === 'association_deconseillee' ? 'warning text-dark' : 'secondary') }}">
                {{ $niveaux[$interaction->niveau] ?? $interaction->niveau }}
              </span>
            </td>
            <td class="small">{{ $interaction->description }}</td>
            <td class="small text-muted">{{ $interaction->source ?? '—' }}</td>
            <td class="text-end">
              <form method="POST" action="{{ route('portail.medicaments.interactions.retirer', [$medicament, $interaction]) }}">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-3">Aucune interaction déclarée.</td></tr>
        @endforelse
      </tbody>
    </table>

    <form method="POST" action="{{ route('portail.medicaments.interactions.declarer', $medicament) }}" class="row g-2 align-items-end">
      @csrf
      <div class="col-md-3">
        <label class="form-label small">Médicament *</label>
        <select name="medicament_b_id" class="form-select form-select-sm" required>
          <option value="">— choisir —</option>
          @foreach (\App\Models\Medicament::where('id', '!=', $medicament->id)->orderBy('nom_generique')->get() as $autre)
            <option value="{{ $autre->id }}">{{ $autre->libelle }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Niveau *</label>
        <select name="niveau" class="form-select form-select-sm" required>
          @foreach ($niveaux as $valeur => $libelle)
            <option value="{{ $valeur }}">{{ $libelle }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small">Description *</label>
        <input type="text" name="description" class="form-control form-control-sm" required maxlength="2000">
      </div>
      <div class="col-md-2">
        <label class="form-label small">Source *</label>
        <input type="text" name="source" class="form-control form-control-sm" required maxlength="200"
               placeholder="Thesaurus ANSM 2024">
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Déclarer</button>
      </div>
    </form>
  </div>
</div>
@endsection
