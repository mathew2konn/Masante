{{-- Champs d'un agent, partagés par create et edit. $a = agent (ou null en création). --}}
@php($a = $agent ?? null)

<div class="row g-3">
  <div class="col-md-6">
    <label class="form-label">Prénom <span class="text-danger">*</span></label>
    <input type="text" name="prenom" class="form-control @error('prenom') is-invalid @enderror"
           value="{{ old('prenom', $a->prenom ?? '') }}" required maxlength="100">
    @error('prenom') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-6">
    <label class="form-label">Nom <span class="text-danger">*</span></label>
    <input type="text" name="nom" class="form-control @error('nom') is-invalid @enderror"
           value="{{ old('nom', $a->nom ?? '') }}" required maxlength="100">
    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-7">
    <label class="form-label">E-mail <span class="text-danger">*</span></label>
    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
           value="{{ old('email', $a->email ?? '') }}" required maxlength="190">
    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-5">
    <label class="form-label">Service affecté <span class="text-danger">*</span></label>
    <select name="service_id" class="form-select @error('service_id') is-invalid @enderror" required>
      <option value="">— Choisir —</option>
      @foreach ($services as $service)
        <option value="{{ $service->id }}" @selected((int) old('service_id', $a->service_id ?? 0) === $service->id)>
          {{ $service->nom_service }}@unless($service->actif) (désactivé)@endunless
        </option>
      @endforeach
    </select>
    @error('service_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
</div>
