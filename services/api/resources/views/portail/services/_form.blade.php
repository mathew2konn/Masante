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

  {{--
    P6.8a — un SELECT, plus une saisie libre assistée d'une datalist.
    La datalist proposait « les codes déjà en base » tout en laissant taper n'importe quoi : le
    vocabulaire national était donc défini par ce qui avait été saisi en premier, et une faute de
    frappe y entrait définitivement. Le terme se choisit maintenant, il ne se tape plus.
  --}}
  <div class="col-md-5">
    <label class="form-label" for="specialite">Spécialité <span class="text-danger">*</span></label>
    <select name="specialite" id="specialite" class="form-select @error('specialite') is-invalid @enderror" required>
      <option value="">— Choisir —</option>
      @foreach ($specialites as $terme)
        <option value="{{ $terme->code }}" @selected(old('specialite', $s->specialite ?? '') === $terme->code)>
          {{ $terme->libelle }} ({{ $terme->code }})
        </option>
      @endforeach
    </select>
    <div class="form-text">Vocabulaire national des spécialités (CDC_09 §8).</div>
    @error('specialite') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
</div>
