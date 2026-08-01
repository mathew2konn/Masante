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

  {{-- 5.6 — Lien vers l'annuaire : c'est lui qui permet à un praticien désigné référent par un
       patient de voir ce patient dans « Mes patients suivis ». Facultatif : tous les agents de
       garde ne sont pas médecins. Une fiche déjà reliée à un autre compte n'apparaît pas. --}}
  <div class="col-12">
    <label class="form-label">Fiche de praticien (annuaire)</label>
    <select name="medecin_id" class="form-select @error('medecin_id') is-invalid @enderror">
      <option value="">— Ce compte n'est pas un médecin de l'annuaire —</option>
      @foreach ($medecins as $medecin)
        <option value="{{ $medecin->id }}"
          @selected((int) old('medecin_id', optional($a)->medecin?->id ?? 0) === $medecin->id)>
          {{ $medecin->nom_complet }} — {{ $medecin->specialite }}
        </option>
      @endforeach
    </select>
    @error('medecin_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">
      Relier le compte à sa fiche permet aux patients qui l'ont désigné <strong>médecin référent</strong>
      d'apparaître dans « Mes patients suivis ». Chaque consultation reste journalisée et notifiée au patient.
    </div>
  </div>
</div>
