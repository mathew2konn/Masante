{{--
  P6.8c — Champs communs de la fiche maladie.

  `code` n'y figure PAS : le code national est ATTRIBUÉ par `masante:maladies:backfill`, jamais
  choisi au formulaire (précédent ETS/PRO/MED/ANA/VAC). Un code envoyé quand même est écarté par
  `validate()` puis jamais repris.

  `code_cim10` / `code_cim11` n'y figurent pas non plus, et pour une autre raison : ce sont des codes
  de l'OMS. Les laisser saisir ici reviendrait à laisser INVENTER une classification internationale —
  or un code faux qui a l'air juste ne se fait jamais corriger. Ils se chargeront par import.
--}}
@php($m = $maladie ?? null)

@if ($errors->any())
  <div class="alert alert-danger">
    <ul class="mb-0 small">
      @foreach ($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach
    </ul>
  </div>
@endif

<div class="row g-3">
  <div class="col-md-8">
    <label class="form-label">Maladie <span class="text-danger">*</span></label>
    <input name="libelle" class="form-control" maxlength="200" required
           value="{{ old('libelle', $m->libelle ?? '') }}" placeholder="Fièvre typhoïde">
    <div class="form-text">
      Le <strong>libellé officiel français</strong>. Il est <strong>unique</strong> : deux maladies
      portant le même libellé seraient indiscernables dans la liste d'une alerte. Les autres façons
      de la nommer — autres langues, synonymes — s'ajoutent après l'enregistrement.
    </div>
  </div>

  <div class="col-md-4">
    <label class="form-label">Provenance <span class="text-danger">*</span></label>
    <select name="source" class="form-select" required>
      @foreach ($sources as $cle => $lib)
        <option value="{{ $cle }}" @selected(old('source', $m->source ?? 'demonstration') === $cle)>{{ $lib }}</option>
      @endforeach
    </select>
    <div class="form-text">
      <strong>Obligatoire</strong> : une entrée de référentiel sans provenance est une rumeur, et la
      publication la refuse.
    </div>
  </div>

  <div class="col-12">
    <label class="form-label">Détail de la provenance</label>
    <input name="source_detail" class="form-control" maxlength="200"
           value="{{ old('source_detail', $m->source_detail ?? '') }}"
           placeholder="Arrêté n° … du Ministère de la Santé, ou publication de référence">
  </div>

  <div class="col-12">
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control" rows="2" maxlength="2000"
              placeholder="Infection à Salmonella Typhi, transmission oro-fécale.">{{ old('description', $m->description ?? '') }}</textarea>
    <div class="form-text">
      Une phrase de repère pour l'agent qui choisit dans une liste. Ce n'est pas une fiche clinique :
      ce référentiel est un <strong>vocabulaire</strong>, il ne pose aucun diagnostic.
    </div>
  </div>

  @if ($m !== null)
    <div class="col-12 form-check ms-2">
      <input type="checkbox" name="actif" value="1" class="form-check-input" id="actif"
             @checked(old('actif', $m->actif))>
      <label class="form-check-label" for="actif">
        Active — décochez pour <strong>retirer du vocabulaire</strong> sans supprimer.
        Les alertes et les antécédents qui la référencent restent lisibles, et sont signalés.
      </label>
    </div>
  @endif
</div>
