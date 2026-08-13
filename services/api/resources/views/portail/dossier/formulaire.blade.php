{{--
  D0 — Consigner un acte dans le carnet, pendant la fenêtre ouverte.

  AUCUN IDENTIFIANT DE MEMBRE N'EST POSTÉ, et ce n'est pas un oubli : le dossier écrit est celui que
  porte la session. Ajouter un champ caché `membre_id` ici rouvrirait précisément la faille que
  l'absence d'identifiant dans l'URL ferme depuis le Module 4.

  `source` et `added_by` ne sont pas non plus dans ce formulaire : le serveur les impose. Les y
  mettre laisserait croire qu'ils se négocient.

  Les libellés et les listes de valeurs suivent les règles de validation des contrôleurs d'API
  (source unique) — s'ils divergent, c'est le serveur qui a raison, et il le dira en 422.
--}}
@php
  $agent = auth()->user();
  $moi   = trim(($agent->prenom ?? '').' '.($agent->nom ?? ''));
  $lieu  = $agent->structure?->nom;
@endphp

<hr class="my-4">

<h3 class="h6 text-ms mb-3">
  <i class="bi bi-plus-circle"></i> Consigner dans le carnet
</h3>

@if ($errors->any())
  <div class="alert alert-danger py-2">
    <ul class="mb-0 small">
      @foreach ($errors->all() as $erreur)
        <li>{{ $erreur }}</li>
      @endforeach
    </ul>
  </div>
@endif

<form method="POST" action="{{ route('portail.dossier.enregistrer', $section) }}" class="row g-2">
  @csrf

  @if ($section === 'antecedents')
    <div class="col-md-4">
      <label class="form-label small">Type <span class="text-danger">*</span></label>
      <select name="type" class="form-select form-select-sm" required>
        <option value="maladie_chronique">Maladie chronique</option>
        <option value="allergie">Allergie</option>
        <option value="chirurgie">Chirurgie</option>
        <option value="hospitalisation">Hospitalisation</option>
        <option value="autre">Autre</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small">Date du diagnostic</label>
      <input type="date" name="date_diagnostic" value="{{ old('date_diagnostic') }}"
             max="{{ now()->toDateString() }}" class="form-control form-control-sm">
    </div>
    <div class="col-md-4">
      <label class="form-label small">Établissement</label>
      <input type="text" name="structure_sanitaire" value="{{ old('structure_sanitaire', $lieu) }}"
             maxlength="200" class="form-control form-control-sm">
    </div>
    <div class="col-12">
      <label class="form-label small">Description <span class="text-danger">*</span></label>
      <textarea name="description" rows="2" maxlength="2000" required
                class="form-control form-control-sm">{{ old('description') }}</textarea>
    </div>
    <div class="col-12">
      <label class="form-label small">Traitement en cours</label>
      <textarea name="traitement_actuel" rows="2" maxlength="2000"
                class="form-control form-control-sm">{{ old('traitement_actuel') }}</textarea>
    </div>
    <input type="hidden" name="medecin_nom" value="{{ $moi }}">

  @elseif ($section === 'vaccinations')
    <div class="col-md-6">
      <label class="form-label small">Vaccin <span class="text-danger">*</span></label>
      <input type="text" name="vaccin_nom" value="{{ old('vaccin_nom') }}" maxlength="200" required
             class="form-control form-control-sm">
    </div>
    <div class="col-md-3">
      <label class="form-label small">Statut <span class="text-danger">*</span></label>
      <select name="statut" class="form-select form-select-sm" required>
        <option value="fait">Fait</option>
        <option value="a_faire">À faire</option>
        <option value="en_retard">En retard</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small">Date d'administration</label>
      <input type="date" name="date_administration" value="{{ old('date_administration') }}"
             class="form-control form-control-sm">
    </div>
    <div class="col-md-3">
      <label class="form-label small">Rappel prévu</label>
      <input type="date" name="date_rappel" value="{{ old('date_rappel') }}"
             class="form-control form-control-sm">
    </div>
    <div class="col-md-5">
      <label class="form-label small">Centre de vaccination</label>
      <input type="text" name="centre_vaccination" value="{{ old('centre_vaccination', $lieu) }}"
             maxlength="200" class="form-control form-control-sm">
    </div>
    <div class="col-md-4">
      <label class="form-label small">Numéro de lot</label>
      <input type="text" name="numero_lot" value="{{ old('numero_lot') }}" maxlength="100"
             class="form-control form-control-sm">
    </div>
    <input type="hidden" name="medecin_nom" value="{{ $moi }}">

  @elseif ($section === 'ordonnances')
    <div class="col-md-4">
      <label class="form-label small">Prescripteur <span class="text-danger">*</span></label>
      <input type="text" name="medecin_nom" value="{{ old('medecin_nom', $moi) }}" maxlength="200"
             required class="form-control form-control-sm">
    </div>
    <div class="col-md-4">
      <label class="form-label small">Établissement <span class="text-danger">*</span></label>
      <input type="text" name="structure_sanitaire" value="{{ old('structure_sanitaire', $lieu) }}"
             maxlength="200" required class="form-control form-control-sm">
    </div>
    <div class="col-md-4">
      <label class="form-label small">Date de prescription <span class="text-danger">*</span></label>
      <input type="date" name="date_prescription" value="{{ old('date_prescription', now()->toDateString()) }}"
             required class="form-control form-control-sm">
    </div>
    <div class="col-12">
      <label class="form-label small">
        Médicaments <span class="text-danger">*</span>
        <span class="text-muted">— au moins un</span>
      </label>
      @for ($i = 0; $i < 4; $i++)
        <input type="text" name="medicaments_json[{{ $i }}][nom]"
               value="{{ old("medicaments_json.$i.nom") }}" maxlength="200"
               class="form-control form-control-sm mb-1"
               placeholder="{{ $i === 0 ? 'Ex. Paracétamol 500 mg — 3×/jour, 5 jours' : 'Médicament suivant (facultatif)' }}"
               @if ($i === 0) required @endif>
      @endfor
    </div>

  @elseif ($section === 'analyses')
    <div class="col-md-4">
      <label class="form-label small">Type <span class="text-danger">*</span></label>
      <select name="type_analyse" class="form-select form-select-sm" required>
        <option value="biologique">Biologique</option>
        <option value="radiologique">Radiologique</option>
        <option value="cardiologique">Cardiologique</option>
        <option value="autre">Autre</option>
      </select>
    </div>
    <div class="col-md-5">
      <label class="form-label small">Intitulé <span class="text-danger">*</span></label>
      <input type="text" name="intitule" value="{{ old('intitule') }}" maxlength="200" required
             class="form-control form-control-sm">
    </div>
    <div class="col-md-3">
      <label class="form-label small">Date <span class="text-danger">*</span></label>
      <input type="date" name="date_analyse" value="{{ old('date_analyse', now()->toDateString()) }}"
             required class="form-control form-control-sm">
    </div>
    <div class="col-md-6">
      <label class="form-label small">Laboratoire</label>
      <input type="text" name="laboratoire" value="{{ old('laboratoire', $lieu) }}" maxlength="200"
             class="form-control form-control-sm">
    </div>
    <div class="col-md-6">
      <label class="form-label small">Médecin prescripteur</label>
      <input type="text" name="medecin_prescripteur" value="{{ old('medecin_prescripteur', $moi) }}"
             maxlength="200" class="form-control form-control-sm">
    </div>
  @endif

  {{-- P6.5b — signature électronique de la prescription (CDC_09 §5.3).

       PROPOSÉE, PAS IMPOSÉE : un praticien sans certificat — ou dont le certificat vient
       d'expirer — doit continuer d'écrire au carnet. Laisser le champ vide enregistre une
       ordonnance non signée, ce qui reste licite et se voit.

       Ce qui est INCONDITIONNEL, en revanche, c'est que le nom du prescripteur vienne désormais de
       la fiche professionnelle et non de ce formulaire : c'est pour cela qu'aucun champ
       « médecin » n'apparaît ci-dessus pour une ordonnance. --}}
  @if (($peutSigner ?? false) && $section === 'ordonnances')
    <div class="col-12 mt-3">
      <div class="border rounded p-3 bg-light">
        <label class="form-label small mb-1" for="secret_signature">
          <i class="bi bi-pen"></i> Secret de signature <span class="text-muted">(facultatif)</span>
        </label>
        <input type="password" name="secret_signature" id="secret_signature"
               autocomplete="off" class="form-control form-control-sm" style="max-width:320px">
        <div class="form-text">
          Saisi ici, il signe la prescription à votre nom : elle devient vérifiable et toute
          modification ultérieure sera détectée. Laissé vide, l'ordonnance est enregistrée sans
          signature. Votre secret n'est stocké nulle part.
        </div>
      </div>
    </div>
  @endif

  <div class="col-12 d-flex align-items-center gap-3 mt-3">
    <button class="btn btn-ms btn-sm" type="submit">
      <i class="bi bi-check2"></i> Ajouter au carnet
    </button>
    <span class="text-muted small">
      Cet ajout sera marqué comme venant d'un soignant, journalisé, et le patient ainsi que sa
      famille en seront informés.
    </span>
  </div>
</form>
