{{-- Champs d'un numéro d'urgence, partagés par create et edit. $numero = null en création. --}}
<div class="row g-3">
  <div class="col-md-4">
    <label class="form-label" for="code">Code <span class="text-danger">*</span></label>
    @if ($numero)
      {{-- IMMUABLE : voir le bandeau de l'écran de création. Affiché, jamais soumis. --}}
      <input type="text" class="form-control" value="{{ $numero->code }}" disabled>
      <div class="form-text">Le code d'un numéro publié ne se modifie pas.</div>
    @else
      <input type="text" name="code" id="code" required maxlength="40"
             class="form-control @error('code') is-invalid @enderror"
             value="{{ old('code') }}" placeholder="samu">
      <div class="form-text">
        Minuscules et soulignés. C'est par lui que le mobile et le triage demandent un numéro précis.
      </div>
      @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
    @endif
  </div>

  <div class="col-md-4">
    <label class="form-label" for="numero">Numéro composé <span class="text-danger">*</span></label>
    <input type="text" name="numero" id="numero" required maxlength="20"
           class="form-control @error('numero') is-invalid @enderror"
           value="{{ old('numero', $numero->numero ?? '') }}" placeholder="185">
    <div class="form-text">
      Chiffres, « + » international, « * », « # ». <strong>C'est ce qui sera réellement composé.</strong>
    </div>
    @error('numero') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label" for="ordre">Ordre d'affichage</label>
    <input type="number" name="ordre" id="ordre" min="0" max="9999"
           class="form-control @error('ordre') is-invalid @enderror"
           value="{{ old('ordre', $numero->ordre ?? 100) }}">
    <div class="form-text">
      Il n'est pas décoratif : c'est lui qui met le secours <em>médical</em> en tête sur une
      application de santé.
    </div>
    @error('ordre') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label" for="libelle">Libellé <span class="text-danger">*</span></label>
    <input type="text" name="libelle" id="libelle" required maxlength="120"
           class="form-control @error('libelle') is-invalid @enderror"
           value="{{ old('libelle', $numero->libelle ?? '') }}" placeholder="SAMU">
    @error('libelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label" for="description">À quoi il sert</label>
    <input type="text" name="description" id="description" maxlength="255"
           class="form-control @error('description') is-invalid @enderror"
           value="{{ old('description', $numero->description ?? '') }}"
           placeholder="Malaise, accident, détresse vitale.">
    <div class="form-text">
      C'est ce qu'un citoyen lit pour savoir <em>lequel</em> composer — et se tromper de numéro dans
      une urgence coûte des minutes.
    </div>
    @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label" for="source">Provenance <span class="text-danger">*</span></label>
    <select name="source" id="source" class="form-select @error('source') is-invalid @enderror" required>
      @foreach ($sources as $cle => $libelle)
        <option value="{{ $cle }}" @selected(old('source', $numero->source ?? 'declaration_projet') === $cle)>
          {{ $libelle }}
        </option>
      @endforeach
    </select>
    <div class="form-text">
      Obligatoire. Ne choisissez « autorité nationale » ou « publication officielle » que si vous
      avez <strong>réellement vu le document</strong> : un numéro présenté comme officiel sans l'être
      ne se fait jamais corriger.
    </div>
    @error('source') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label" for="source_detail">Référence de la provenance</label>
    <input type="text" name="source_detail" id="source_detail" maxlength="200"
           class="form-control @error('source_detail') is-invalid @enderror"
           value="{{ old('source_detail', $numero->source_detail ?? '') }}"
           placeholder="Arrêté n° … du …">
    @error('source_detail') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  @if ($numero)
    <div class="col-12">
      <div class="form-check">
        <input type="hidden" name="actif" value="0">
        <input class="form-check-input" type="checkbox" name="actif" id="actif" value="1"
               @checked(old('actif', $numero->actif))>
        <label class="form-check-label" for="actif">
          Numéro actif — décocher le retire des applications à la prochaine publication.
          <strong>Si plus aucun numéro n'est actif, la publication sera refusée</strong> : une
          version sans secours joignable ferait retomber les téléphones sur la valeur livrée avec
          l'application, sans que personne ne l'ait décidé.
        </label>
      </div>
    </div>
  @endif
</div>
