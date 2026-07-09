{{-- Champs d'un service, partagés par create et edit. $s = service (ou null en création). --}}
@php($s = $service ?? null)

<div class="row g-3">
  <div class="col-md-7">
    <label class="form-label">Nom du service <span class="text-danger">*</span></label>
    <input type="text" name="nom_service" class="form-control @error('nom_service') is-invalid @enderror"
           value="{{ old('nom_service', $s->nom_service ?? '') }}" required maxlength="200"
           placeholder="Urgences, ORL, Médecine générale…">
    @error('nom_service') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-5">
    <label class="form-label">Code spécialité <span class="text-danger">*</span></label>
    <input type="text" name="specialite" class="form-control @error('specialite') is-invalid @enderror"
           value="{{ old('specialite', $s->specialite ?? '') }}" required maxlength="100"
           list="specialites-connues" placeholder="medecine_generale">
    <datalist id="specialites-connues">
      @foreach ($specialites as $code) <option value="{{ $code }}"></option> @endforeach
    </datalist>
    <div class="form-text">Minuscules + underscores. Sert au rapprochement avec le triage (F1.5).</div>
    @error('specialite') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
</div>
