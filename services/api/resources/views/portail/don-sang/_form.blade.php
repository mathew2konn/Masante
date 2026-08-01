{{-- Champs d'un besoin en sang, partagés par create et edit. $b = besoin (ou null en création). --}}
@php($b = $besoin ?? null)

<div class="row g-3">
  <div class="col-md-4">
    <label class="form-label">Groupe recherché <span class="text-danger">*</span></label>
    <select name="groupe_sanguin" class="form-select @error('groupe_sanguin') is-invalid @enderror" required>
      <option value="">— Choisir —</option>
      @foreach ($groupes as $groupe)
        <option value="{{ $groupe }}" @selected(old('groupe_sanguin', $b->groupe_sanguin ?? '') === $groupe)>{{ $groupe }}</option>
      @endforeach
    </select>
    @error('groupe_sanguin') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Groupe de la poche recherchée. Les donneurs compatibles (pas seulement du même groupe) seront concernés.</div>
  </div>

  <div class="col-md-8">
    <label class="form-label">Niveau <span class="text-danger">*</span></label>
    <select name="niveau" class="form-select @error('niveau') is-invalid @enderror" required>
      <option value="courant" @selected(old('niveau', $b->niveau ?? 'courant') === 'courant')>
        Besoin courant — apparaît dans les groupes demandés
      </option>
      <option value="urgent" @selected(old('niveau', $b->niveau ?? '') === 'urgent')>
        Urgence transfusionnelle — alerte les donneurs compatibles
      </option>
    </select>
    @error('niveau') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">
      Réservez l'urgence aux situations qui le sont : si tout alerte, plus rien n'alerte.
    </div>
  </div>

  <div class="col-12">
    <label class="form-label">Message (facultatif)</label>
    <input type="text" name="message" class="form-control @error('message') is-invalid @enderror"
           value="{{ old('message', $b->message ?? '') }}" maxlength="300"
           placeholder="Contexte : afflux de blessés, opération programmée…">
    @error('message') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Date de début <span class="text-danger">*</span></label>
    <input type="date" name="date_debut" class="form-control @error('date_debut') is-invalid @enderror"
           value="{{ old('date_debut', optional($b?->date_debut)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required>
    @error('date_debut') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-6">
    <label class="form-label">Date de fin</label>
    <input type="date" name="date_fin" class="form-control @error('date_fin') is-invalid @enderror"
           value="{{ old('date_fin', optional($b?->date_fin)->format('Y-m-d')) }}">
    @error('date_fin') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Laissez vide tant que le besoin dure. Passée cette date, plus personne n'est sollicité.</div>
  </div>
</div>
