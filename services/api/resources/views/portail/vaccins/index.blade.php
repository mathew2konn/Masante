@extends('portail.layout')

@section('titre', 'Vaccins et calendrier vaccinal')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-shield-plus"></i> Vaccins et calendrier vaccinal national</h1>
    <p class="text-muted mb-0">Vaccins disponibles et âges auxquels chaque dose est due — CDC_09 §8.</p>
  </div>
  <a href="{{ route('portail.vaccins.create') }}" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Ajouter un vaccin
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
    ni le carnet ni le calendrier d'un membre ne voient quoi que ce soit changer.
  </div>
</div>

{{--
  LE TÉMOIN DU REMPLACEMENT. Motif de P6.7a : une donnée de démonstration qui ne se signale pas finit
  par être prise pour une donnée de référence. Le compte est EXACT, jamais estimé — et il diminue à
  mesure que le calendrier officiel est chargé.
--}}
@if ($echeancesDemonstration > 0)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-exclamation-octagon"></i>
    <div class="small">
      <strong>{{ $echeancesDemonstration }}</strong> échéance(s) sur {{ $echeancesTotal }} portent la
      provenance <strong>« Démonstration »</strong> : elles reprennent la structure du calendrier élargi
      de vaccination de l'OMS mais <strong>n'ont pas été vérifiées contre le calendrier officiel du PEV
      Côte d'Ivoire</strong>, et aucune autorité sanitaire ne les a validées. Tant que ce nombre n'est
      pas à zéro, ce n'est pas un calendrier national.
    </div>
  </div>
@endif

@if ($sansCode > 0)
  <div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle"></i>
    <div class="small">
      <strong>{{ $sansCode }}</strong> vaccin(s) n'ont pas encore de code national. La publication les
      refusera : lancez <code>php artisan masante:vaccins:backfill</code>.
    </div>
  </div>
@endif

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Code</th><th>Vaccin</th><th>Voie</th>
          <th class="text-end">Doses</th><th>Calendrier</th><th>Marché</th><th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($vaccins as $vaccin)
          <tr class="{{ $vaccin->actif ? '' : 'opacity-50' }}">
            <td><code>{{ $vaccin->code ?? '—' }}</code></td>
            <td>
              {{ $vaccin->libelle }}
              @if ($vaccin->abreviation)
                <span class="text-muted small">({{ $vaccin->abreviation }})</span>
              @endif
              @unless ($vaccin->actif) <span class="badge bg-secondary">retiré</span> @endunless
            </td>
            <td class="text-muted small">{{ str_replace('_', ' ', $vaccin->voie_administration ?? '—') }}</td>
            <td class="text-end">{{ $vaccin->nb_doses }}</td>
            <td>
              {{-- Le schéma annoncé et le calendrier saisi doivent coïncider : c'est ce que le
                   contrôle qualité refuse de publier s'il y a un écart. On le montre ici avant. --}}
              @if ($vaccin->echeances->count() === $vaccin->nb_doses)
                <span class="badge bg-success-subtle text-success-emphasis">
                  {{ $vaccin->echeances->count() }} échéance(s)
                </span>
              @else
                <span class="badge bg-danger-subtle text-danger-emphasis">
                  {{ $vaccin->echeances->count() }} / {{ $vaccin->nb_doses }} — incomplet
                </span>
              @endif
            </td>
            <td>
              @if ($vaccin->statut_marche === 'disponible')
                <span class="badge bg-success-subtle text-success-emphasis">Disponible</span>
              @elseif ($vaccin->statut_marche === 'rupture')
                <span class="badge bg-warning-subtle text-warning-emphasis">Rupture</span>
              @else
                <span class="badge bg-danger-subtle text-danger-emphasis">Retiré</span>
              @endif
            </td>
            <td class="text-end">
              <a href="{{ route('portail.vaccins.edit', $vaccin) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">Aucun vaccin au référentiel.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $vaccins->links() }}</div>
@endsection
