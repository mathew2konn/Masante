@extends('portail.layout')

@section('titre', 'Référentiel des organismes d\'assurance')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-shield-plus"></i> Organismes d'assurance agréés</h1>
    <p class="text-muted mb-0">CNAM et assureurs privés agréés — CDC_09 §8, familles de prise en charge du CDC_06 §8.2.</p>
  </div>
  <a href="{{ route('portail.assurances.create') }}" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Ajouter un organisme
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
    ni la liste que voient les assurés, ni la carte, ni l'API ne voient quoi que ce soit changer.
  </div>
</div>

{{--
  LES TÉMOINS. Motif de P6.7a : une donnée de démonstration qui ne se signale pas finit par être prise
  pour une donnée de référence. Les comptes sont EXACTS, jamais estimés.
--}}
@if ($demonstration > 0)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-exclamation-octagon"></i>
    <div class="small">
      <strong>{{ $demonstration }}</strong> organisme(s) sur {{ $total }} portent la provenance
      <strong>« Démonstration »</strong>. Hormis la CNAM, que le corpus nomme, <strong>aucun assureur réel
      n'a été inscrit</strong> : écrire le nom d'une compagnie dans un registre intitulé « organismes agréés »
      affirmerait un agrément que personne n'a vu. Tant que ce nombre n'est pas à zéro, ce n'est pas un
      registre national.
    </div>
  </div>
@endif

@if ($sansAgrement > 0)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-file-earmark-x"></i>
    <div class="small">
      <strong>{{ $sansAgrement }}</strong> organisme(s) sur {{ $total }} n'ont <strong>aucun numéro
      d'agrément</strong> enregistré. Ces numéros sont délivrés par une autorité et n'ont pas été chargés
      dans ce projet ; <strong>aucun n'a été inventé</strong>. La publication ne l'exige pas — l'exiger
      rendrait le référentiel impubliable — mais <strong>cette liste ne prouve donc aucun agrément</strong>.
    </div>
  </div>
@endif

@if ($sansCode > 0)
  <div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle"></i>
    <div class="small">
      <strong>{{ $sansCode }}</strong> organisme(s) n'ont pas encore de code national. La publication les
      refusera : lancez <code>php artisan masante:assurances:backfill</code>.
    </div>
  </div>
@endif

@if ($horsReferentiel > 0)
  <div class="alert alert-secondary d-flex gap-2">
    <i class="bi bi-people"></i>
    <div class="small">
      <strong>{{ $horsReferentiel }}</strong> couverture(s) déclarée(s) par des assurés nomment un organisme
      <strong>absent de ce registre</strong> : ils ont dû saisir son nom à la main. Ce n'est pas une erreur de
      leur part — c'est la mesure de ce qui manque ici. <strong>Ce nombre doit tendre vers zéro</strong> à
      mesure que le registre réel est chargé.
    </div>
  </div>
@endif

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Code</th><th>Organisme</th><th>Famille</th><th>Agrément</th><th>Provenance</th><th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($organismes as $organisme)
          <tr class="{{ $organisme->actif ? '' : 'opacity-50' }}">
            <td>
              <code>{{ $organisme->code ?? '—' }}</code>
              <span class="text-muted small">{{ $organisme->pays_code }}</span>
            </td>
            <td>
              {{ $organisme->nom }}
              @if ($organisme->sigle)
                <span class="badge bg-light text-dark border">{{ $organisme->sigle }}</span>
              @endif
              @unless ($organisme->actif) <span class="badge bg-secondary">retiré</span> @endunless
            </td>
            <td class="small">{{ $types[$organisme->type] ?? $organisme->type }}</td>
            <td class="small">
              @if ($organisme->agrement_statut === null)
                <span class="text-muted">non renseigné</span>
              @else
                <span class="badge {{ $organisme->agrement_statut === 'valide' ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                  {{ \App\Http\Controllers\Portail\ReferentielAssuranceController::STATUTS_AGREMENT[$organisme->agrement_statut] }}
                </span>
              @endif
              @if ($organisme->agrement_fin)
                <span class="text-muted d-block">jusqu'au {{ $organisme->agrement_fin->format('d/m/Y') }}</span>
              @endif
            </td>
            <td>
              @if ($organisme->source === 'demonstration')
                <span class="badge bg-danger-subtle text-danger-emphasis">Démonstration</span>
              @else
                <span class="badge bg-success-subtle text-success-emphasis">
                  {{ \App\Http\Controllers\Portail\ReferentielAssuranceController::SOURCES[$organisme->source] ?? $organisme->source }}
                </span>
              @endif
            </td>
            <td class="text-end">
              <a href="{{ route('portail.assurances.edit', $organisme) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-muted py-4">Aucun organisme au registre.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $organismes->links() }}</div>
@endsection
