{{-- Champs de l'établissement, partagés par create et edit. $e = établissement (ou null en création). --}}
@php($e = $etablissement ?? null)

<div class="row g-3">
  <div class="col-md-8">
    <label class="form-label">Nom de l'établissement <span class="text-danger">*</span></label>
    <input type="text" name="nom" class="form-control @error('nom') is-invalid @enderror"
           value="{{ old('nom', $e->nom ?? '') }}" required maxlength="200">
    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Type <span class="text-danger">*</span></label>
    <select name="type" class="form-select @error('type') is-invalid @enderror" required>
      <option value="">— Choisir —</option>
      @foreach ($types as $cle => $libelle)
        <option value="{{ $cle }}" @selected(old('type', $e->type ?? '') === $cle)>{{ $libelle }}</option>
      @endforeach
    </select>
    @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-12">
    <label class="form-label">Adresse <span class="text-danger">*</span></label>
    <input type="text" name="adresse" class="form-control @error('adresse') is-invalid @enderror"
           value="{{ old('adresse', $e->adresse ?? '') }}" required maxlength="500">
    @error('adresse') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Commune <span class="text-danger">*</span></label>
    <input type="text" name="commune" class="form-control @error('commune') is-invalid @enderror"
           value="{{ old('commune', $e->commune ?? '') }}" required maxlength="100">
    @error('commune') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Latitude <span class="text-danger">*</span></label>
    <input type="number" step="any" name="latitude" class="form-control @error('latitude') is-invalid @enderror"
           value="{{ old('latitude', $e->latitude ?? '') }}" required placeholder="5.3599">
    @error('latitude') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Longitude <span class="text-danger">*</span></label>
    <input type="number" step="any" name="longitude" class="form-control @error('longitude') is-invalid @enderror"
           value="{{ old('longitude', $e->longitude ?? '') }}" required placeholder="-4.0083">
    @error('longitude') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Téléphone</label>
    <input type="text" name="telephone" class="form-control @error('telephone') is-invalid @enderror"
           value="{{ old('telephone', $e->telephone ?? '') }}" maxlength="20">
    @error('telephone') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">WhatsApp</label>
    <input type="text" name="whatsapp" class="form-control @error('whatsapp') is-invalid @enderror"
           value="{{ old('whatsapp', $e->whatsapp ?? '') }}" maxlength="20">
    @error('whatsapp') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-12">
    <label class="form-label">Spécialités <span class="text-muted small">(séparées par des virgules)</span></label>
    <input type="text" name="specialites" class="form-control @error('specialites') is-invalid @enderror"
           value="{{ old('specialites', $e ? implode(', ', $e->specialites_json ?? []) : '') }}"
           placeholder="ORL, Cardiologie, Pédiatrie">
    @error('specialites') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Tarif min (FCFA)</label>
    <input type="number" min="0" name="tarif_min_cfa" class="form-control @error('tarif_min_cfa') is-invalid @enderror"
           value="{{ old('tarif_min_cfa', $e->tarif_min_cfa ?? '') }}">
    @error('tarif_min_cfa') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Tarif max (FCFA)</label>
    <input type="number" min="0" name="tarif_max_cfa" class="form-control @error('tarif_max_cfa') is-invalid @enderror"
           value="{{ old('tarif_max_cfa', $e->tarif_max_cfa ?? '') }}">
    @error('tarif_max_cfa') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-12">
    <div class="form-check">
      <input type="checkbox" name="partenaire_ivoirsante" value="1" class="form-check-input" id="partenaire"
             @checked(old('partenaire_ivoirsante', $e->partenaire_ivoirsante ?? false))>
      <label class="form-check-label" for="partenaire">Établissement partenaire IVOIRSANTÉ</label>
    </div>
  </div>
</div>
