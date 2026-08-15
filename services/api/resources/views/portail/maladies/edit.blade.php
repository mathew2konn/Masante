@extends('portail.layout')

@section('titre', 'Modifier une maladie')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">
    <i class="bi bi-virus"></i> {{ $maladie->libelle }}
    <span class="text-muted fs-6"><code>{{ $maladie->code ?? 'sans code national' }}</code></span>
  </h1>
  <a href="{{ route('portail.maladies.index') }}" class="btn btn-outline-secondary">Retour</a>
</div>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif

@if ($alertes > 0 || $antecedents > 0)
  <div class="alert alert-info d-flex gap-2">
    <i class="bi bi-link-45deg"></i>
    <div class="small">
      Cette maladie est rattachée à <strong>{{ $alertes }}</strong> alerte(s) épidémique(s) et
      <strong>{{ $antecedents }}</strong> antécédent(s) de carnet. La retirer ne les efface pas :
      leurs libellés y sont <strong>figés</strong>, et l'écran signale le retrait.
    </div>
  </div>
@endif

<form method="POST" action="{{ route('portail.maladies.update', $maladie) }}" class="card p-4 mb-4">
  @csrf @method('PUT')
  @include('portail.maladies._form')

  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary">Enregistrer</button>
  </div>
</form>

{{-- ══ LES LIBELLÉS ALTERNATIFS (§8 « libellés multilingues ») ═══════════════════════════════ --}}
<div class="card p-4 mb-4">
  <h2 class="h5 mb-1">Autres façons de nommer cette maladie</h2>
  <p class="text-muted small">
    Le libellé officiel en <strong>{{ $pivot }}</strong> est celui de la fiche ci-dessus, et il vit
    là et nulle part ailleurs. Cette table porte les <strong>autres langues</strong> et les
    <strong>synonymes de recherche</strong> — « palu » retrouve « Paludisme ». Un synonyme en
    <strong>{{ $pivot }}</strong> n'est jamais « principal » : ce serait afficher le surnom à la
    place du nom.
  </p>

  <div class="table-responsive mb-3">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Langue</th><th>Libellé</th><th>Rôle</th><th>Provenance</th><th></th></tr>
      </thead>
      <tbody>
        @forelse ($maladie->libelles->sortBy('langue') as $libelle)
          <tr>
            <td><code>{{ $libelle->langue }}</code></td>
            <td>{{ $libelle->libelle }}</td>
            <td>
              @if ($libelle->langue === $pivot)
                <span class="badge bg-secondary-subtle text-secondary-emphasis">Synonyme de recherche</span>
              @elseif ($libelle->principal)
                <span class="badge bg-primary-subtle text-primary-emphasis">Affiché pour cette langue</span>
              @else
                <span class="badge bg-light text-dark border">Recherche seulement</span>
              @endif
            </td>
            <td class="small text-muted">
              {{ $sources[$libelle->source] ?? $libelle->source }}
            </td>
            <td class="text-end">
              <form method="POST" action="{{ route('portail.maladies.libelles.destroy', [$maladie, $libelle]) }}">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger">Retirer</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-muted small py-3">
            Aucun libellé alternatif. La structure les accueille ; les dénominations en langues
            nationales ivoiriennes n'ont pas été livrées — elles n'ont pas été inventées.
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <form method="POST" action="{{ route('portail.maladies.libelles.store', $maladie) }}" class="row g-2 align-items-end">
    @csrf
    <div class="col-md-2">
      <label class="form-label small">Langue</label>
      <input name="langue" class="form-control" maxlength="5" required placeholder="en" value="{{ old('langue') }}">
    </div>
    <div class="col-md-4">
      <label class="form-label small">Libellé</label>
      <input name="libelle" class="form-control" maxlength="200" required value="{{ old('libelle') }}">
    </div>
    <div class="col-md-3">
      <label class="form-label small">Provenance</label>
      <select name="source" class="form-select" required>
        @foreach ($sources as $cle => $lib)
          <option value="{{ $cle }}" @selected(old('source') === $cle)>{{ $lib }}</option>
        @endforeach
      </select>
    </div>
    <div class="col-md-2 form-check ms-2">
      <input type="checkbox" name="principal" value="1" class="form-check-input" id="principal" @checked(old('principal'))>
      <label class="form-check-label small" for="principal">Afficher pour cette langue</label>
    </div>
    <div class="col-md-12">
      <button class="btn btn-outline-primary btn-sm mt-2">Ajouter ce libellé</button>
    </div>
  </form>
</div>

{{-- ══ LA SURVEILLANCE, PAYS PAR PAYS (décision E2) ═════════════════════════════════════════ --}}
<div class="card p-4 mb-4">
  <h2 class="h5 mb-1">Surveillance nationale</h2>
  <p class="text-muted small">
    La maladie, elle, <strong>n'appartient à aucun pays</strong> : le paludisme est le paludisme
    partout. Ce qui change d'un pays à l'autre, c'est ce qu'on <strong>surveille</strong> et ce qu'on
    doit <strong>déclarer</strong>. <strong>Aucune ligne</strong> veut dire « aucune décision connue
    pour ce pays » — pas « rien à déclarer ».
  </p>

  <div class="table-responsive mb-3">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Pays</th><th>Déclaration obligatoire</th><th>Surveillance prioritaire</th><th>Provenance</th><th></th></tr>
      </thead>
      <tbody>
        @forelse ($maladie->surveillances->sortBy('pays_code') as $s)
          <tr>
            <td><code>{{ $s->pays_code }}</code></td>
            <td>{{ $s->declaration_obligatoire ? 'Oui' : 'Non' }}</td>
            <td>{{ $s->surveillance_prioritaire ? 'Oui' : 'Non' }}</td>
            <td class="small text-muted">{{ $sources[$s->source] ?? $s->source }}</td>
            <td class="text-end">
              <form method="POST" action="{{ route('portail.maladies.surveillance.destroy', [$maladie, $s]) }}">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger">Retirer</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-muted small py-3">Aucun pays ne déclare de statut de surveillance.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <form method="POST" action="{{ route('portail.maladies.surveillance.store', $maladie) }}" class="row g-2 align-items-end">
    @csrf
    <div class="col-md-2">
      <label class="form-label small">Pays</label>
      <input name="pays_code" class="form-control" maxlength="2" required
             value="{{ old('pays_code', config('referentiels.pays_defaut', 'CI')) }}">
    </div>
    <div class="col-md-3">
      <label class="form-label small">Provenance</label>
      <select name="source" class="form-select" required>
        @foreach ($sources as $cle => $lib)
          <option value="{{ $cle }}" @selected(old('source') === $cle)>{{ $lib }}</option>
        @endforeach
      </select>
    </div>
    <div class="col-md-3 form-check ms-2">
      <input type="checkbox" name="declaration_obligatoire" value="1" class="form-check-input" id="decl" @checked(old('declaration_obligatoire'))>
      <label class="form-check-label small" for="decl">Déclaration obligatoire</label>
    </div>
    <div class="col-md-3 form-check">
      <input type="checkbox" name="surveillance_prioritaire" value="1" class="form-check-input" id="prio" @checked(old('surveillance_prioritaire'))>
      <label class="form-check-label small" for="prio">Surveillance prioritaire</label>
    </div>
    <div class="col-md-12">
      <button class="btn btn-outline-primary btn-sm mt-2">Enregistrer la surveillance</button>
    </div>
  </form>
</div>

{{-- ══ LES VACCINS QUI EN PROTÈGENT (lecture seule ; le lien se pose depuis la fiche vaccin) ══ --}}
@if ($maladie->vaccins->isNotEmpty())
  <div class="card p-4">
    <h2 class="h5 mb-2">Vaccins qui en protègent</h2>
    <p class="text-muted small">Le rattachement se fait depuis la fiche du vaccin.</p>
    @foreach ($maladie->vaccins as $vaccin)
      <span class="badge bg-light text-dark border">{{ $vaccin->libelle }}</span>
    @endforeach
  </div>
@endif
@endsection
