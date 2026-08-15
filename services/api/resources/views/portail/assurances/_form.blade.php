{{--
  Formulaire d'un organisme d'assurance (P6.8d).

  CE QUI NE S'Y SAISIT PAS, ET POURQUOI :
   - le CODE NATIONAL, attribué par la plateforme (précédent ETS/PRO/MED/ANA/VAC/MAL) ;
   - le NUMÉRO D'AGRÉMENT, qui désigne un acte administratif délivré par une autorité. Le laisser
     saisir reviendrait à laisser FABRIQUER un agrément plutôt qu'à l'enregistrer.
--}}
<div class="row g-3">
  <div class="col-md-8">
    <label class="form-label">Nom de l'organisme <span class="text-danger">*</span></label>
    <input type="text" name="nom" required maxlength="200"
           class="form-control @error('nom') is-invalid @enderror"
           value="{{ old('nom', $organisme->nom ?? '') }}"
           placeholder="Caisse Nationale d'Assurance Maladie">
    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">C'est ce nom que l'assuré lit et choisit : deux organismes ne peuvent pas le partager dans un même pays.</div>
  </div>

  <div class="col-md-4">
    <label class="form-label">Sigle</label>
    <input type="text" name="sigle" maxlength="30"
           class="form-control @error('sigle') is-invalid @enderror"
           value="{{ old('sigle', $organisme->sigle ?? '') }}" placeholder="CNAM">
    @error('sigle') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Famille de prise en charge <span class="text-danger">*</span></label>
    <select name="type" required class="form-select @error('type') is-invalid @enderror">
      <option value="">— choisir —</option>
      @foreach ($types as $code => $libelle)
        <option value="{{ $code }}" @selected(old('type', $organisme->type ?? '') === $code)>{{ $libelle }}</option>
      @endforeach
    </select>
    @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Les six familles du CDC_06 §8.2 — les mêmes que celles du moteur de prise en charge.</div>
  </div>

  <div class="col-md-6">
    <label class="form-label">Pays</label>
    <input type="text" name="pays_code" maxlength="2"
           class="form-control @error('pays_code') is-invalid @enderror"
           value="{{ old('pays_code', $organisme->pays_code ?? config('referentiels.pays_defaut', 'CI')) }}">
    @error('pays_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Un agrément est délivré par un État : deux pays peuvent porter le même code national.</div>
  </div>

  <div class="col-12"><hr class="my-2"></div>

  <div class="col-md-4">
    <label class="form-label">État de l'agrément</label>
    <select name="agrement_statut" class="form-select @error('agrement_statut') is-invalid @enderror">
      <option value="">Non renseigné</option>
      @foreach ($statuts as $code => $libelle)
        <option value="{{ $code }}" @selected(old('agrement_statut', $organisme->agrement_statut ?? '') === $code)>{{ $libelle }}</option>
      @endforeach
    </select>
    @error('agrement_statut') <div class="invalid-feedback">{{ $message }}</div> @enderror
    {{-- L'absence est une réponse légitime : un organisme sans agrément renseigné n'est pas « probablement agréé ». --}}
    <div class="form-text">Laissez « non renseigné » si vous ne disposez pas de l'acte : c'est ce que l'écran affichera, sans rien affirmer.</div>
  </div>

  <div class="col-md-4">
    <label class="form-label">Agrément — début</label>
    <input type="date" name="agrement_debut"
           class="form-control @error('agrement_debut') is-invalid @enderror"
           value="{{ old('agrement_debut', optional($organisme->agrement_debut ?? null)->format('Y-m-d')) }}">
    @error('agrement_debut') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Agrément — fin</label>
    <input type="date" name="agrement_fin"
           class="form-control @error('agrement_fin') is-invalid @enderror"
           value="{{ old('agrement_fin', optional($organisme->agrement_fin ?? null)->format('Y-m-d')) }}">
    @error('agrement_fin') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-12"><hr class="my-2"></div>

  <div class="col-md-6">
    <label class="form-label">Provenance <span class="text-danger">*</span></label>
    <select name="source" required class="form-select @error('source') is-invalid @enderror">
      @foreach ($sources as $code => $libelle)
        <option value="{{ $code }}" @selected(old('source', $organisme->source ?? 'demonstration') === $code)>{{ $libelle }}</option>
      @endforeach
    </select>
    @error('source') <div class="invalid-feedback">{{ $message }}</div> @enderror
    {{-- La garde centrale du module : une entrée sans provenance ne peut pas être publiée. --}}
    <div class="form-text">Obligatoire : une entrée de référentiel dont on ne sait pas d'où elle vient ne peut pas être publiée.</div>
  </div>

  <div class="col-md-6">
    <label class="form-label">Détail de la provenance</label>
    <input type="text" name="source_detail" maxlength="200"
           class="form-control @error('source_detail') is-invalid @enderror"
           value="{{ old('source_detail', $organisme->source_detail ?? '') }}"
           placeholder="Arrêté n° … du …">
    @error('source_detail') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  @isset($organisme)
    @if ($organisme)
      <div class="col-12">
        <div class="form-check">
          <input type="hidden" name="actif" value="0">
          <input class="form-check-input" type="checkbox" name="actif" value="1" id="actif"
                 @checked(old('actif', $organisme->actif))>
          <label class="form-check-label" for="actif">
            Organisme actif
            <span class="text-muted small d-block">
              Décocher le retire des choix proposés aux assurés. Les couvertures déjà déclarées le
              conservent : on désactive, on ne supprime pas.
            </span>
          </label>
        </div>
      </div>
    @endif
  @endisset
</div>
