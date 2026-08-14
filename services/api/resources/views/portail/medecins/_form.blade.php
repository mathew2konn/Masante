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

  <div class="col-md-3">
    <label class="form-label">Sexe</label>
    <select name="sexe" class="form-select @error('sexe') is-invalid @enderror">
      <option value="">— Non renseigné —</option>
      @foreach (['M' => 'Masculin', 'F' => 'Féminin'] as $code => $libelle)
        <option value="{{ $code }}" @selected(old('sexe', $m->sexe ?? '') === $code)>{{ $libelle }}</option>
      @endforeach
    </select>
    @error('sexe') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-3">
    <label class="form-label">Date de naissance</label>
    <input type="date" name="date_naissance" class="form-control @error('date_naissance') is-invalid @enderror"
           value="{{ old('date_naissance', $m?->date_naissance?->format('Y-m-d') ?? '') }}">
    @error('date_naissance') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  {{-- §5.1 — la PROFESSION n'est pas la spécialité : un radiologue et un cardiologue sont tous
       deux médecins spécialistes. La liste vient d'App\Support\ProfessionsSante, jamais recopiée. --}}
  <div class="col-md-6">
    <label class="form-label">Profession</label>
    <select name="profession" class="form-select @error('profession') is-invalid @enderror">
      <option value="">— Non renseignée —</option>
      @foreach (\App\Support\ProfessionsSante::PROFESSIONS as $code => $libelle)
        <option value="{{ $code }}" @selected(old('profession', $m->profession ?? '') === $code)>{{ $libelle }}</option>
      @endforeach
    </select>
    @error('profession') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Métier au sens du référentiel national (CDC_09 §5.1).</div>
  </div>
  <div class="col-md-6">
    <label class="form-label">Sous-spécialité</label>
    <input type="text" name="sous_specialite" class="form-control @error('sous_specialite') is-invalid @enderror"
           value="{{ old('sous_specialite', $m->sous_specialite ?? '') }}" maxlength="100">
    @error('sous_specialite') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  {{--
    P6.8a — le libellé affiché au patient n'est plus saisi ici : on choisit un TERME, et le serveur
    écrit son libellé officiel. Sans cela, « Cardiologie », « cardio » et « Cardio. » coexisteraient
    dans l'annuaire national selon l'établissement qui a rempli la fiche.
  --}}
  <div class="col-md-6">
    <label class="form-label" for="specialite_code">Spécialité <span class="text-danger">*</span></label>
    <select name="specialite_code" id="specialite_code"
            class="form-select @error('specialite_code') is-invalid @enderror" required>
      <option value="">— Choisir —</option>
      @foreach ($specialites as $terme)
        <option value="{{ $terme->code }}"
          @selected(old('specialite_code', $m->specialiteReferencee?->code ?? '') === $terme->code)>
          {{ $terme->libelle }}
        </option>
      @endforeach
    </select>
    @error('specialite_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">
      Vocabulaire national (CDC_09 §8). Le libellé affiché aux patients vient du référentiel.
      @if (($m->specialite ?? null) && $m->specialiteReferencee === null)
        <span class="text-warning">Fiche actuellement non rattachée — libellé enregistré :
          « {{ $m->specialite }} ».</span>
      @endif
    </div>
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

  <div class="col-12"><hr class="my-1"></div>
  <div class="col-12"><h2 class="h6 mb-0 text-muted">Formation et contacts</h2></div>

  <div class="col-md-5">
    <label class="form-label">Université</label>
    <input type="text" name="universite" class="form-control @error('universite') is-invalid @enderror"
           value="{{ old('universite', $m->universite ?? '') }}" maxlength="150">
    @error('universite') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-3">
    <label class="form-label">Année de diplôme</label>
    <input type="number" name="annee_diplome" class="form-control @error('annee_diplome') is-invalid @enderror"
           value="{{ old('annee_diplome', $m->annee_diplome ?? '') }}" min="1900" max="{{ now()->format('Y') }}">
    @error('annee_diplome') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-4">
    <label class="form-label">Années d'expérience</label>
    <input type="number" name="experience_annees" class="form-control @error('experience_annees') is-invalid @enderror"
           value="{{ old('experience_annees', $m->experience_annees ?? '') }}" min="0" max="80">
    @error('experience_annees') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Téléphone</label>
    <input type="text" name="telephone" class="form-control @error('telephone') is-invalid @enderror"
           value="{{ old('telephone', $m->telephone ?? '') }}" maxlength="30">
    @error('telephone') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-5">
    <label class="form-label">E-mail professionnel</label>
    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
           value="{{ old('email', $m->email ?? '') }}" maxlength="190">
    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
  <div class="col-md-3 d-flex align-items-end">
    <div class="w-100">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="consultation_physique" value="1"
               id="consultation_physique" @checked(old('consultation_physique', $m->consultation_physique ?? true))>
        <label class="form-check-label" for="consultation_physique">Consultation physique</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="consultation_en_ligne" value="1"
               id="consultation_en_ligne" @checked(old('consultation_en_ligne', $m->consultation_en_ligne ?? false))>
        <label class="form-check-label" for="consultation_en_ligne">Consultation en ligne</label>
      </div>
    </div>
  </div>

  <div class="col-12">
    <label class="form-label">Biographie</label>
    <textarea name="biographie" rows="3" maxlength="2000"
              class="form-control @error('biographie') is-invalid @enderror">{{ old('biographie', $m->biographie ?? '') }}</textarea>
    @error('biographie') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  {{-- ═══ Ordre professionnel et autorisation d'exercer (CDC_09 §5.2) ═══

       CE BLOC N'APPARAÎT QUE POUR QUI PORTE `professionnel.habiliter`. Un gestionnaire
       d'établissement décrit ses praticiens ; il ne déclare pas leur droit d'exercer. Ces
       colonnes sont celles que le §5.4 interrogera avant de laisser signer une ordonnance :
       si l'employeur pouvait les écrire, le contrôle reposerait sur la déclaration de
       l'intéressé. La garde qui fait foi est côté serveur (`MedecinController::valider`) ;
       ce `@if` évite seulement d'afficher un champ dont la saisie serait ignorée. --}}
  @if ($peutHabiliter)
    <div class="col-12"><hr class="my-1"></div>
    <div class="col-12">
      <h2 class="h6 mb-0 text-muted">Ordre professionnel et autorisation d'exercer</h2>
      <p class="small text-muted mb-0">
        Réservé aux comptes habilités. Ces informations conditionneront la signature électronique
        des ordonnances.
      </p>
    </div>

    <div class="col-md-6">
      <label class="form-label">Ordre professionnel</label>
      <input type="text" name="ordre_professionnel" class="form-control @error('ordre_professionnel') is-invalid @enderror"
             value="{{ old('ordre_professionnel', $m->ordre_professionnel ?? '') }}" maxlength="150"
             placeholder="Ordre National des Médecins de Côte d'Ivoire">
      @error('ordre_professionnel') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-6">
      <label class="form-label">Numéro d'ordre</label>
      <input type="text" name="numero_ordre" class="form-control @error('numero_ordre') is-invalid @enderror"
             value="{{ old('numero_ordre', $m->numero_ordre ?? '') }}" maxlength="60">
      @error('numero_ordre') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-4">
      <label class="form-label">N° d'autorisation d'exercer</label>
      <input type="text" name="autorisation_numero" class="form-control @error('autorisation_numero') is-invalid @enderror"
             value="{{ old('autorisation_numero', $m->autorisation_numero ?? '') }}" maxlength="60">
      @error('autorisation_numero') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-4">
      <label class="form-label">Statut de l'autorisation</label>
      <select name="autorisation_statut" class="form-select @error('autorisation_statut') is-invalid @enderror">
        <option value="">— Non renseigné —</option>
        @foreach (\App\Support\ProfessionsSante::STATUTS_AUTORISATION as $code => $libelle)
          <option value="{{ $code }}" @selected(old('autorisation_statut', $m->autorisation_statut ?? '') === $code)>
            {{ $libelle }}
          </option>
        @endforeach
      </select>
      @error('autorisation_statut') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-2">
      <label class="form-label">Délivrée le</label>
      <input type="date" name="autorisation_delivree_le" class="form-control @error('autorisation_delivree_le') is-invalid @enderror"
             value="{{ old('autorisation_delivree_le', $m?->autorisation_delivree_le?->format('Y-m-d') ?? '') }}">
      @error('autorisation_delivree_le') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-2">
      <label class="form-label">Expire le</label>
      <input type="date" name="autorisation_expire_le" class="form-control @error('autorisation_expire_le') is-invalid @enderror"
             value="{{ old('autorisation_expire_le', $m?->autorisation_expire_le?->format('Y-m-d') ?? '') }}">
      @error('autorisation_expire_le') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
  @endif
</div>
