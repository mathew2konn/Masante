@extends('portail.layout')

@section('titre', 'Modifier un vaccin')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-pencil"></i> {{ $vaccin->libelle }}</h1>
    <p class="text-muted mb-0"><code>{{ $vaccin->code ?? 'code national non attribué' }}</code> · {{ $vaccin->pays_code }}</p>
  </div>
  <a href="{{ route('portail.vaccins.index') }}" class="btn btn-outline-secondary">Retour</a>
</div>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif

@if ($rattachees > 0)
  <div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle"></i>
    <div class="small">
      <strong>{{ $rattachees }}</strong> ligne(s) de carnet référencent ce vaccin. Leur nom, leur code
      et leur dose ont été <strong>figés à l'inscription</strong> : modifier ce vaccin ne réécrira
      aucune vaccination déjà inscrite. En revanche, changer une échéance change ce que le
      <strong>calendrier</strong> annoncera à toutes les familles — après publication.
    </div>
  </div>
@endif

<form method="POST" action="{{ route('portail.vaccins.update', $vaccin) }}" class="card p-4 mb-4">
  @csrf
  @method('PUT')
  @include('portail.vaccins._form')

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Enregistrer</button>
    <a href="{{ route('portail.vaccins.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>

{{-- ════════════════════════ Le calendrier ════════════════════════ --}}

<div class="card p-4">
  <h2 class="h5 mb-1" style="color:var(--ms-blue-dark)"><i class="bi bi-calendar-check"></i> Calendrier vaccinal</h2>
  <p class="text-muted small">
    Une ligne par <strong>dose</strong>. Les âges sont <strong>en jours</strong> : 0 = à la naissance,
    42 = six semaines, 270 = neuf mois. Un calendrier exprimé en mois ne saurait pas dire
    « six semaines », qui est l'échéance la plus dense du schéma du nourrisson.
  </p>

  @if ($vaccin->echeances->count() !== $vaccin->nb_doses)
    <div class="alert alert-danger small">
      Le schéma annonce <strong>{{ $vaccin->nb_doses }}</strong> dose(s) mais le calendrier en porte
      <strong>{{ $vaccin->echeances->count() }}</strong>. <strong>La publication sera refusée</strong> :
      un schéma tronqué présenté comme complet ferait croire une famille à jour alors qu'une dose
      n'a jamais été proposée.
    </div>
  @endif

  <div class="table-responsive mb-4">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Dose</th><th>Échéance</th><th class="text-end">Âge dû</th>
          <th class="text-end">Grâce</th><th class="text-end">Rattrapage</th>
          <th>Obligatoire</th><th>Provenance</th><th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($vaccin->echeances as $echeance)
          <tr>
            <td><strong>{{ $echeance->numero_dose }}</strong></td>
            <td>{{ $echeance->libelle_echeance }}</td>
            <td class="text-end">{{ $echeance->age_jours_du }} j</td>
            <td class="text-end text-muted">+{{ $echeance->tolerance_jours }} j</td>
            <td class="text-end text-muted">{{ $echeance->age_jours_limite ? $echeance->age_jours_limite.' j' : 'sans limite' }}</td>
            <td>
              @if ($echeance->obligatoire)
                <span class="badge bg-primary-subtle text-primary-emphasis">Obligatoire</span>
              @else
                <span class="text-muted small">Recommandé</span>
              @endif
            </td>
            <td>
              @if ($echeance->estDeDemonstration())
                <span class="badge bg-danger-subtle text-danger-emphasis" title="{{ $echeance->source_detail }}">
                  Démonstration
                </span>
              @else
                <span class="badge bg-success-subtle text-success-emphasis">{{ $sources[$echeance->source] ?? $echeance->source }}</span>
              @endif
            </td>
            <td class="text-end">
              <form method="POST" action="{{ route('portail.vaccins.echeances.destroy', [$vaccin, $echeance]) }}"
                    onsubmit="return confirm('Retirer l’échéance de la dose n°{{ $echeance->numero_dose }} ?')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger">Retirer</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-muted py-3">Aucune échéance — ce vaccin ne peut pas être publié.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <h3 class="h6">Ajouter ou remplacer une échéance</h3>
  <form method="POST" action="{{ route('portail.vaccins.echeances.store', $vaccin) }}" class="row g-3">
    @csrf
    <div class="col-md-2">
      <label class="form-label">Dose <span class="text-danger">*</span></label>
      <input type="number" name="numero_dose" class="form-control" min="1" max="255" required value="{{ old('numero_dose') }}">
    </div>
    <div class="col-md-4">
      <label class="form-label">Libellé lu par le parent <span class="text-danger">*</span></label>
      <input name="libelle_echeance" class="form-control" maxlength="120" required
             value="{{ old('libelle_echeance') }}" placeholder="6 semaines">
    </div>
    <div class="col-md-2">
      <label class="form-label">Âge dû (jours) <span class="text-danger">*</span></label>
      <input type="number" name="age_jours_du" class="form-control" min="0" max="36500" required value="{{ old('age_jours_du') }}">
    </div>
    <div class="col-md-2">
      <label class="form-label">Délai de grâce (jours)</label>
      <input type="number" name="tolerance_jours" class="form-control" min="0" max="3650" value="{{ old('tolerance_jours', 0) }}">
      <div class="form-text">Au-delà, la dose est dite « en retard ».</div>
    </div>
    <div class="col-md-2">
      <label class="form-label">Rattrapage jusqu'à (jours)</label>
      <input type="number" name="age_jours_limite" class="form-control" min="0" max="36500" value="{{ old('age_jours_limite') }}">
      <div class="form-text">Vide = sans limite.</div>
    </div>

    <div class="col-md-3">
      <label class="form-label">Provenance <span class="text-danger">*</span></label>
      <select name="source" class="form-select" required>
        @foreach ($sources as $cle => $lib)
          <option value="{{ $cle }}" @selected(old('source') === $cle)>{{ $lib }}</option>
        @endforeach
      </select>
      <div class="form-text">
        <strong>Obligatoire.</strong> Une échéance vaccinale sans provenance est une rumeur : la
        publication la refuse.
      </div>
    </div>
    <div class="col-md-6">
      <label class="form-label">Détail de la provenance</label>
      <input name="source_detail" class="form-control" maxlength="200" value="{{ old('source_detail') }}"
             placeholder="Arrêté n° … du Ministère de la Santé, calendrier PEV 2026">
    </div>
    <div class="col-md-3 d-flex align-items-center">
      <div class="form-check mt-4">
        <input type="checkbox" name="obligatoire" value="1" class="form-check-input" id="obl" @checked(old('obligatoire'))>
        <label class="form-check-label" for="obl">
          Obligatoire
          <span class="d-block form-text">Fait de politique nationale — plus jamais déclaré par le citoyen.</span>
        </label>
      </div>
    </div>

    <div class="col-12">
      <button class="btn btn-primary">Enregistrer l'échéance</button>
    </div>
  </form>
</div>
@endsection
