{{-- Champs d'une fiche de praticien, partagés par create et edit. $m = fiche (ou null en création). --}}
@php($m = $medecin ?? null)

<div class="row g-3">
  <div class="col-md-2">
    <label class="form-label">Titre <span class="text-danger">*</span></label>
    <select name="titre" class="form-select @error('titre') is-invalid @enderror" required>
      @foreach (['Dr', 'Pr'] as $titre)
        <option value="{{ $titre }}" @selected(old('titre', $m->titre ?? 'Dr') === $titre)>{{ $titre }}</option>
      @endforeach
    </select>
    @error('titre') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-5">
    <label class="form-label">Prénom <span class="text-danger">*</span></label>
    <input type="text" name="prenom" class="form-control @error('prenom') is-invalid @enderror"
           value="{{ old('prenom', $m->prenom ?? '') }}" required maxlength="120">
    @error('prenom') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-5">
    <label class="form-label">Nom <span class="text-danger">*</span></label>
    <input type="text" name="nom" class="form-control @error('nom') is-invalid @enderror"
           value="{{ old('nom', $m->nom ?? '') }}" required maxlength="120">
    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Spécialité <span class="text-danger">*</span></label>
    <input type="text" name="specialite" class="form-control @error('specialite') is-invalid @enderror"
           value="{{ old('specialite', $m->specialite ?? '') }}" required maxlength="100"
           placeholder="Cardiologie, Pédiatrie…">
    @error('specialite') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Libellé affiché aux patients dans l'annuaire.</div>
  </div>
  <div class="col-md-6">
    <label class="form-label">Service <span class="text-danger">*</span></label>
    <select name="service_id" class="form-select @error('service_id') is-invalid @enderror" required>
      <option value="">— Choisir —</option>
      @foreach ($services as $service)
        <option value="{{ $service->id }}" @selected((int) old('service_id', $m->service_id ?? 0) === $service->id)>
          {{ $service->nom_service }}@unless($service->actif) (désactivé)@endunless
        </option>
      @endforeach
    </select>
    @error('service_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Tarif de consultation (FCFA)</label>
    <input type="number" name="tarif_consultation" class="form-control @error('tarif_consultation') is-invalid @enderror"
           value="{{ old('tarif_consultation', $m->tarif_consultation ?? '') }}" min="0" max="1000000">
    @error('tarif_consultation') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Indicatif : aucun règlement n'est encaissé par la plateforme.</div>
  </div>

  {{-- 5.6 — Le lien qui rend la voie du médecin référent opérante. Sans compte, la fiche reste
       visible dans l'annuaire (RDV) mais son titulaire ne peut consulter aucun dossier. --}}
  <div class="col-md-6">
    <label class="form-label">Compte du praticien (portail)</label>
    <select name="user_id" class="form-select @error('user_id') is-invalid @enderror">
      <option value="">— Aucun compte relié —</option>
      @foreach ($agents as $agent)
        <option value="{{ $agent->id }}" @selected((int) old('user_id', $m->user_id ?? 0) === $agent->id)>
          {{ $agent->prenom }} {{ $agent->nom }} ({{ $agent->email }})
        </option>
      @endforeach
    </select>
    @error('user_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">
      Reliez la fiche au compte d'agent du praticien : les patients qui le désignent
      <strong>médecin référent</strong> apparaîtront alors dans son écran « Mes patients suivis ».
      Chaque consultation reste journalisée et notifiée au patient.
    </div>
  </div>
</div>
