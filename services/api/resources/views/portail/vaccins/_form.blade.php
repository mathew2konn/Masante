{{--
  P6.8b — Champs communs de la fiche vaccin. `code` et `pays_code` n'y figurent PAS : le code
  national est ATTRIBUÉ par `masante:vaccins:backfill`, jamais choisi au formulaire (précédent
  ETS/PRO/MED/ANA). Un code envoyé quand même est écarté par `validate()` puis jamais repris.
--}}
@php($v = $vaccin ?? null)

@if ($errors->any())
  <div class="alert alert-danger">
    <ul class="mb-0 small">
      @foreach ($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach
    </ul>
  </div>
@endif

<div class="row g-3">
  <div class="col-md-8">
    <label class="form-label">Vaccin <span class="text-danger">*</span></label>
    <input name="libelle" class="form-control" maxlength="200" required
           value="{{ old('libelle', $v->libelle ?? '') }}"
           placeholder="Pentavalent (DTC — hépatite B — Hib)">
    <div class="form-text">
      L'<strong>antigène</strong>, pas la marque ni le lot : le calendrier national porte sur
      « Pentavalent », et deux lots de deux fabricants ne créent pas deux échéances à six semaines.
    </div>
  </div>

  <div class="col-md-4">
    <label class="form-label">Abréviation</label>
    <input name="abreviation" class="form-control" maxlength="40"
           value="{{ old('abreviation', $v->abreviation ?? '') }}" placeholder="Penta">
    <div class="form-text">La forme employée dans les carnets papier. Sert à retrouver, pas à identifier.</div>
  </div>

  <div class="col-md-6">
    <label class="form-label">Voie d'administration</label>
    <select name="voie_administration" class="form-select">
      <option value="">—</option>
      @foreach (['intramusculaire' => 'Intramusculaire', 'sous_cutanee' => 'Sous-cutanée',
                 'intradermique' => 'Intradermique', 'orale' => 'Orale', 'nasale' => 'Nasale'] as $cle => $lib)
        <option value="{{ $cle }}" @selected(old('voie_administration', $v->voie_administration ?? '') === $cle)>{{ $lib }}</option>
      @endforeach
    </select>
  </div>

  <div class="col-md-3">
    <label class="form-label">Nombre de doses <span class="text-danger">*</span></label>
    <input type="number" name="nb_doses" class="form-control" min="1" max="20" required
           value="{{ old('nb_doses', $v->nb_doses ?? 1) }}">
    <div class="form-text">
      Le schéma complet. La publication est <strong>refusée</strong> si le calendrier ne porte pas
      exactement ce nombre d'échéances — c'est ce qui permet de dire qu'un calendrier est incomplet.
    </div>
  </div>

  <div class="col-md-3">
    <label class="form-label">Statut sur le marché <span class="text-danger">*</span></label>
    <select name="statut_marche" class="form-select" required>
      @foreach (['disponible' => 'Disponible', 'rupture' => 'Rupture', 'retire' => 'Retiré'] as $cle => $lib)
        <option value="{{ $cle }}" @selected(old('statut_marche', $v->statut_marche ?? 'disponible') === $cle)>{{ $lib }}</option>
      @endforeach
    </select>
    <div class="form-text">
      Un vaccin retiré est <strong>signalé</strong>, jamais bloqué : refuser d'inscrire au carnet une
      dose réellement administrée effacerait un fait médical.
    </div>
  </div>

  <div class="col-12">
    <label class="form-label">Maladies évitées</label>
    <textarea name="maladies_evitees" class="form-control" rows="2" maxlength="2000"
              placeholder="Diphtérie, tétanos, coqueluche, hépatite B, infections à Haemophilus influenzae de type b.">{{ old('maladies_evitees', $v->maladies_evitees ?? '') }}</textarea>
    <div class="form-text">
      Texte libre, <strong>conservé</strong> : il porte des formulations que le lien ne rend pas
      (« formes graves de… »). Le rattachement au référentiel se fait juste en dessous.
    </div>
  </div>

  {{--
    P6.8c — LA PROMESSE DE P6.8b, TENUE. La migration des vaccins disait : « TEXTE et non table de
    maladies : la CIM arrive en P6.8c, et un lien vers une table qui n'existe pas encore serait une
    promesse, pas une donnée. »

    Conséquence dite AVANT d'avoir codé : rattacher un vaccin ici FAIT CHANGER L'EMPREINTE du
    référentiel des vaccins — les codes des maladies entrent dans sa projection. Ce n'est pas une
    dérive, c'est le même cas que `forme_juridique` en P6.4d.
  --}}
  @if ($v !== null && isset($maladies))
    <div class="col-12">
      <label class="form-label">Maladies évitées — rattachement au référentiel</label>
      <select name="maladies[]" class="form-select" multiple size="8">
        @foreach ($maladies as $maladie)
          <option value="{{ $maladie->id }}"
                  @selected(in_array($maladie->id, old('maladies', $v->maladies->pluck('id')->all()), false))>
            {{ $maladie->libelle }}{{ $maladie->code ? ' ('.$maladie->code.')' : ' — sans code national' }}
          </option>
        @endforeach
      </select>
      <div class="form-text">
        Maintenez <kbd>Ctrl</kbd> (ou <kbd>Cmd</kbd>) pour en choisir plusieurs. Décocher retire le
        rattachement ; le texte ci-dessus n'est pas modifié.
      </div>
    </div>
  @endif

  <div class="col-12">
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control" rows="2" maxlength="2000">{{ old('description', $v->description ?? '') }}</textarea>
  </div>

  @if ($v !== null)
    <div class="col-12 form-check ms-2">
      <input type="checkbox" name="actif" value="1" class="form-check-input" id="actif"
             @checked(old('actif', $v->actif))>
      <label class="form-check-label" for="actif">
        Actif — décochez pour <strong>retirer du vocabulaire</strong> sans supprimer.
        Les lignes de carnet qui le référencent restent lisibles.
      </label>
    </div>
  @endif
</div>
