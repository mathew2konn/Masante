@extends('portail.layout')

@section('titre', 'Modèles IA — triage')

@section('content')
<div class="mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">
    <i class="bi bi-cpu"></i> Gouvernance des modèles IA (triage)
  </h1>
  <p class="text-muted mb-0">
    CDC_05 §7.2/§8/§9 — export anonymisant, entraînement réel, validation à quatre yeux.
    Aucun modèle n'est branché sur un triage réel dans cet incrément (P10c-3-i) : c'est P10c-3-ii.
  </p>
</div>

@if (session('statut'))
  <div class="alert alert-success">{{ session('statut') }}</div>
@endif
@error('gouvernance')
  <div class="alert alert-danger">{{ $message }}</div>
@enderror

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-shield-lock"></i>
  <div class="small">
    Un modèle entraîné sur peu de retours réels est réel dans son <strong>mécanisme</strong>
    (données réelles, export anonymisé, entraînement réel), pas nécessairement validé
    <strong>statistiquement</strong> — c'est à ce titre qu'un relecteur humain doit en décider.
  </div>
</div>

<div class="d-flex gap-2 mb-4">
  <form method="POST" action="{{ route('portail.modeles-ia.exporter') }}">
    @csrf
    <button type="submit" class="btn btn-outline-primary">
      <i class="bi bi-box-arrow-down"></i> Produire un nouvel export anonymisé
    </button>
  </form>
</div>

<h2 class="h5">Exports</h2>
<div class="table-responsive mb-4">
  <table class="table table-sm align-middle">
    <thead>
      <tr>
        <th>#</th><th>Pays</th><th>Numéro</th><th>Lignes</th><th>k estimé</th><th></th>
      </tr>
    </thead>
    <tbody>
      @forelse ($exports as $exp)
        <tr>
          <td class="text-muted">{{ $exp->id }}</td>
          <td>{{ $exp->pays_code }}</td>
          <td>{{ $exp->numero_export }}</td>
          <td>{{ $exp->nb_lignes }}</td>
          <td>{{ $exp->k_estime ?? '—' }}</td>
          <td class="text-end">
            <form method="POST" action="{{ route('portail.modeles-ia.entrainer', $exp->id) }}" class="d-inline">
              @csrf
              <button type="submit" class="btn btn-sm btn-outline-success">Lancer un entraînement</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="6" class="text-muted text-center py-4">Aucun export produit.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<h2 class="h5">Versions de modèle</h2>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead>
      <tr>
        <th>#</th><th>Pays</th><th>Version</th><th>Statut</th><th>Run MLflow</th><th>Métriques</th><th></th>
      </tr>
    </thead>
    <tbody>
      @forelse ($versions as $version)
        <tr>
          <td class="text-muted">{{ $version->id }}</td>
          <td>{{ $version->pays_code }}</td>
          <td>{{ $version->numero_version }}</td>
          <td>
            <span class="badge {{ $version->statut === 'valide' ? 'bg-success' : 'bg-secondary' }}">
              {{ $version->statut }}
            </span>
          </td>
          <td class="small text-muted">{{ $version->mlflow_run_id }}</td>
          <td class="small">
            @foreach ($version->metriques as $m)
              {{ $m->cle }}={{ number_format((float) $m->valeur, 3) }}
            @endforeach
          </td>
          <td class="text-end">
            @if ($version->statut === 'candidat')
              <form method="POST" action="{{ route('portail.modeles-ia.valider', $version->id) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-success">Valider</button>
              </form>
            @endif

            {{--
              P10c-3-ii (F24) — LE MÊME BOUTON MET EN SERVICE ET REVIENT EN ARRIÈRE.
              Réactiver une version archivée EST le rollback du §8 ; en faire deux actions
              distinctes suggérerait qu'un retour arrière est une opération d'exception, alors que
              le corpus le demande comme une capacité normale.
            --}}
            @if (in_array($version->statut, ['valide', 'archive'], true))
              <form method="POST" action="{{ route('portail.modeles-ia.activer', $version->id) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary">
                  {{ $version->statut === 'archive' ? 'Remettre en service' : 'Mettre en service' }}
                </button>
              </form>
            @endif

            {{-- La comparaison n'a de sens que pour une version qui a réellement prédit. --}}
            @if (in_array($version->statut, ['actif', 'archive'], true))
              <a href="{{ route('portail.modeles-ia.comparaison', $version->id) }}"
                 class="btn btn-sm btn-outline-secondary">Comparaison</a>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="text-muted text-center py-4">Aucune version entraînée.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
@endsection
