@extends('portail.layout')

@section('titre', 'Spécialités médicales')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-diagram-3"></i> Vocabulaire national des spécialités</h1>
    <p class="text-muted mb-0">Termes reconnus pour les services d'établissement et les fiches de praticiens — CDC_09 §8.</p>
  </div>
  <a href="{{ route('portail.specialites.create') }}" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Ajouter un terme
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
    puis une validation par une <strong>seconde personne</strong> (CDC_09 §10).
  </div>
</div>

{{--
  Les deux compteurs d'honnêteté de l'écran. Aucun des deux n'est une anomalie de saisie : ils
  disent l'état réel de la reprise de l'existant, là où quelqu'un peut agir en connaissance de cause.
--}}
@if ($servicesOrphelins > 0)
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-exclamation-octagon"></i>
    <div class="small">
      <strong>{{ $servicesOrphelins }}</strong> service(s) d'établissement portent un code qui
      <strong>ne figure pas dans ce vocabulaire</strong>. Ils existent parce que le formulaire acceptait
      autrefois n'importe quel mot en minuscules. Ajoutez le terme manquant, ou corrigez le service
      depuis son établissement — tant qu'ils sont là, ils échappent au référentiel.
    </div>
  </div>
@endif

@if ($praticiensDesynchronises > 0)
  <div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle"></i>
    <div class="small">
      <strong>{{ $praticiensDesynchronises }}</strong> fiche(s) de praticien affichent un libellé
      différent de celui de leur terme (par exemple « Maternité » là où le vocabulaire dit
      « Gynécologie-obstétrique »). Ce libellé <strong>n'a délibérément pas été réécrit</strong> :
      c'est ce que l'établissement a saisi. Rouvrir la fiche et l'enregistrer l'alignera.
    </div>
  </div>
@endif

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Code</th><th>Libellé</th><th>Nature</th><th>Profession</th>
          <th class="text-end">Services</th><th class="text-end">Praticiens</th><th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($termes as $terme)
          <tr class="{{ $terme->actif ? '' : 'opacity-50' }}">
            <td><code>{{ $terme->code }}</code></td>
            <td>
              {{ $terme->libelle }}
              @unless ($terme->actif) <span class="badge bg-secondary">retiré</span> @endunless
            </td>
            <td>
              @if ($terme->nature === 'specialite_medicale')
                <span class="badge bg-primary-subtle text-primary-emphasis">Spécialité médicale</span>
              @else
                <span class="badge bg-secondary-subtle text-secondary-emphasis">Activité de service</span>
              @endif
            </td>
            <td class="text-muted small">{{ $professions[$terme->profession] ?? '—' }}</td>
            <td class="text-end">{{ $terme->services_count }}</td>
            <td class="text-end">{{ $terme->praticiens_count }}</td>
            <td class="text-end">
              <a href="{{ route('portail.specialites.edit', $terme) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">Aucun terme au vocabulaire.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">{{ $termes->links() }}</div>
@endsection
