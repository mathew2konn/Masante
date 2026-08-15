@extends('portail.layout')

@section('titre', 'Référentiel des maladies')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-virus"></i> Référentiel national des maladies</h1>
    <p class="text-muted mb-0">Vocabulaire des maladies, libellés multilingues et surveillance nationale — CDC_09 §8.</p>
  </div>
  <a href="{{ route('portail.maladies.create') }}" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Ajouter une maladie
  </a>
</div>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-shield-lock"></i>
  <div class="small">
    Ce que vous modifiez ici est le <strong>contenu de travail</strong>. Il ne sera diffusé qu'après
    <strong>publication d'une nouvelle version</strong> du référentiel, laquelle demande une proposition
    puis une validation par une <strong>seconde personne</strong> (CDC_09 §10). Tant que ce n'est pas fait,
    ni la liste des alertes épidémiques, ni le carnet, ni l'API ne voient quoi que ce soit changer.
  </div>
</div>

{{--
  LES DEUX TÉMOINS. Motif de P6.7a : une donnée de démonstration qui ne se signale pas finit par être
  prise pour une donnée de référence. Les comptes sont EXACTS, jamais estimés.
--}}
@if ($demonstration > 0)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-exclamation-octagon"></i>
    <div class="small">
      <strong>{{ $demonstration }}</strong> entrée(s) sur {{ $total }} portent la provenance
      <strong>« Démonstration »</strong> : leurs libellés reprennent l'existant du projet, et
      <strong>aucune autorité sanitaire ne les a validées</strong>. Tant que ce nombre n'est pas à zéro,
      ce n'est pas un référentiel national.
    </div>
  </div>
@endif

@if ($sansCim > 0)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-upc-scan"></i>
    <div class="small">
      <strong>{{ $sansCim }}</strong> entrée(s) sur {{ $total }} n'ont <strong>aucun code CIM</strong>.
      CIM-10 et CIM-11 sont des publications de l'Organisation mondiale de la santé : elles n'ont pas
      été chargées dans ce projet, et <strong>aucun code n'a été inventé</strong>. La publication ne
      l'exige pas — l'exiger rendrait le référentiel impubliable — mais tant que ces codes manquent,
      aucun échange normalisé (HL7 FHIR, §9.1) ne peut s'appuyer dessus.
    </div>
  </div>
@endif

@if ($sansCode > 0)
  <div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle"></i>
    <div class="small">
      <strong>{{ $sansCode }}</strong> maladie(s) n'ont pas encore de code national. La publication les
      refusera : lancez <code>php artisan masante:maladies:backfill</code>.
    </div>
  </div>
@endif

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Code</th><th>Maladie</th><th>CIM-10</th><th>Autres libellés</th>
          <th>Surveillance</th><th>Provenance</th><th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($maladies as $maladie)
          <tr class="{{ $maladie->actif ? '' : 'opacity-50' }}">
            <td><code>{{ $maladie->code ?? '—' }}</code></td>
            <td>
              {{ $maladie->libelle }}
              @unless ($maladie->actif) <span class="badge bg-secondary">retirée</span> @endunless
            </td>
            <td class="text-muted small">{{ $maladie->code_cim10 ?? '—' }}</td>
            <td class="small text-muted">
              @forelse ($maladie->libelles as $libelle)
                <span class="badge bg-light text-dark border">{{ $libelle->langue }} · {{ $libelle->libelle }}</span>
              @empty
                —
              @endforelse
            </td>
            <td class="small">
              @forelse ($maladie->surveillances as $s)
                <span class="badge {{ $s->surveillance_prioritaire ? 'bg-danger-subtle text-danger-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }}">
                  {{ $s->pays_code }}{{ $s->declaration_obligatoire ? ' · déclaration obligatoire' : '' }}
                </span>
              @empty
                <span class="text-muted">—</span>
              @endforelse
            </td>
            <td>
              @if ($maladie->source === 'demonstration')
                <span class="badge bg-danger-subtle text-danger-emphasis">Démonstration</span>
              @else
                <span class="badge bg-success-subtle text-success-emphasis">
                  {{ \App\Http\Controllers\Portail\ReferentielMaladieController::SOURCES[$maladie->source] ?? $maladie->source }}
                </span>
              @endif
            </td>
            <td class="text-end">
              <a href="{{ route('portail.maladies.edit', $maladie) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">Aucune maladie au référentiel.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $maladies->links() }}</div>
@endsection
