{{-- Champs d'un terme du vocabulaire, partagés par create et edit. $terme = null en création. --}}
<div class="row g-3">
  <div class="col-md-4">
    <label class="form-label" for="code">Code <span class="text-danger">*</span></label>
    @if ($terme)
      {{-- IMMUABLE : voir le bandeau de l'écran de création. Affiché, jamais soumis. --}}
      <input type="text" class="form-control" value="{{ $terme->code }}" disabled>
      <div class="form-text">Le code d'un terme publié ne se modifie pas.</div>
    @else
      <input type="text" name="code" id="code" required maxlength="60"
             class="form-control @error('code') is-invalid @enderror"
             value="{{ old('code') }}" placeholder="medecine_generale">
      <div class="form-text">Minuscules, chiffres et soulignés, commençant par une lettre.</div>
      @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
    @endif
  </div>

  <div class="col-md-8">
    <label class="form-label" for="libelle">Libellé officiel <span class="text-danger">*</span></label>
    <input type="text" name="libelle" id="libelle" required maxlength="120"
           class="form-control @error('libelle') is-invalid @enderror"
           value="{{ old('libelle', $terme->libelle ?? '') }}" placeholder="Médecine générale">
    <div class="form-text">C'est ce libellé que le citoyen lit dans l'annuaire.</div>
    @error('libelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label" for="nature">Nature <span class="text-danger">*</span></label>
    <select name="nature" id="nature" class="form-select @error('nature') is-invalid @enderror" required>
      @foreach (['specialite_medicale' => 'Spécialité médicale', 'activite' => 'Activité de service'] as $cle => $libelle)
        <option value="{{ $cle }}" @selected(old('nature', $terme->nature ?? 'specialite_medicale') === $cle)>
          {{ $libelle }}
        </option>
      @endforeach
    </select>
    <div class="form-text">
      Une pharmacie ou une collecte de sang est une <em>activité</em> : la ranger parmi les
      spécialités médicales ferait dire au référentiel national quelque chose de faux.
    </div>
    @error('nature') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label" for="profession">Profession qui l'exerce</label>
    <select name="profession" id="profession" class="form-select @error('profession') is-invalid @enderror">
      <option value="">— Aucune en propre —</option>
      @foreach ($professions as $cle => $libelle)
        <option value="{{ $cle }}" @selected(old('profession', $terme->profession ?? '') === $cle)>{{ $libelle }}</option>
      @endforeach
    </select>
    <div class="form-text">Facultatif (CDC_09 §5.1).</div>
    @error('profession') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label" for="ordre">Ordre d'affichage</label>
    <input type="number" name="ordre" id="ordre" min="0" max="9999"
           class="form-control @error('ordre') is-invalid @enderror"
           value="{{ old('ordre', $terme->ordre ?? 100) }}">
    @error('ordre') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-12">
    <label class="form-label" for="description">Description</label>
    <input type="text" name="description" id="description" maxlength="255"
           class="form-control @error('description') is-invalid @enderror"
           value="{{ old('description', $terme->description ?? '') }}">
    @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  @if ($terme)
    <div class="col-12">
      <div class="form-check">
        <input type="hidden" name="actif" value="0">
        <input class="form-check-input" type="checkbox" name="actif" id="actif" value="1"
               @checked(old('actif', $terme->actif))>
        <label class="form-check-label" for="actif">
          Terme actif — décocher le retire des formulaires <strong>sans</strong> délier les services
          et les fiches déjà rattachés.
        </label>
      </div>
    </div>
  @endif
</div>
