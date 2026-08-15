@extends('portail.layout')

@section('titre', "Numéros d'urgence")

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">
      <i class="bi bi-telephone-fill"></i> Numéros d'urgence nationaux
    </h1>
    <p class="text-muted mb-0">
      Ce que composent l'écran SOS, la carte vitale d'urgence et le texte de triage — CDC_09 §8.
    </p>
  </div>
  <a href="{{ route('portail.numeros-urgence.create') }}" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Ajouter un numéro
  </a>
</div>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-shield-lock"></i>
  <div class="small">
    Ce que vous modifiez ici est le <strong>contenu de travail</strong>. Il ne sera composé qu'après
    <strong>publication d'une nouvelle version</strong> du référentiel, laquelle demande une
    proposition puis une validation par une <strong>seconde personne</strong> (CDC_09 §10).
  </div>
</div>

{{--
  ═══ LE FAIT D'EXPLOITATION QUE PERSONNE NE VERRAIT AUTREMENT ═══

  C'est le seul écran du projet qui doit annoncer « aucune version publiée » comme un ÉTAT, et non
  comme une erreur. Dans cet état les applications ne tombent pas en panne : elles composent la
  valeur qu'elles ont en mémoire — donc tout a l'air de fonctionner, et c'est précisément ce qui
  rend l'oubli invisible partout ailleurs. Ici il est écrit.
--}}
@if ($enVigueur)
  <div class="alert alert-success d-flex gap-2">
    <i class="bi bi-broadcast"></i>
    <div class="small">
      <strong>Version {{ $version }} en vigueur.</strong> C'est elle que servent l'API publique et le
      texte de triage. Les téléphones la mettent en cache et la conservent hors connexion.
    </div>
  </div>
@else
  <div class="alert alert-danger d-flex gap-2">
    <i class="bi bi-exclamation-octagon"></i>
    <div class="small">
      <strong>Aucune version n'est en vigueur.</strong> Rien de ce tableau n'est diffusé.
      Les applications ne sont pas en panne pour autant : elles composent
      <strong>{{ $repli['numero'] }}</strong> ({{ $repli['libelle'] }}), la valeur livrée avec
      l'application — et un avertissement est écrit dans les journaux du serveur à chaque fois.
      <em>C'est voulu : dans une urgence, un refus vaudrait moins qu'un numéro connu.</em>
      Proposez puis publiez une version pour que ce tableau prenne effet.
    </div>
  </div>
@endif

{{--
  Le témoin d'honnêteté du module. Il ne bloque pas la publication — l'exiger rendrait le
  référentiel impubliable dès le premier jour (motif `code_cim10`, P6.8c) — mais il dit ce que ce
  contenu est réellement.
--}}
@if ($nonVerifies > 0)
  <div class="alert alert-info d-flex gap-2">
    <i class="bi bi-patch-question"></i>
    <div class="small">
      <strong>{{ $nonVerifies }}</strong> numéro(s) sur {{ $total }} n'ont été confrontés à
      <strong>aucune publication officielle</strong>. Le SAMU 185 provient du corpus du projet ; les
      autres ont été déclarés lors de la conception. <em>Un numéro d'urgence faux est plus dangereux
      qu'un numéro absent, parce qu'il sera composé</em> — charger les valeurs officielles est de la
      donnée, sans aucune ligne de code, et tant que ce n'est pas fait, ceci n'est pas un référentiel
      national.
    </div>
  </div>
@endif

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Code</th><th>Numéro</th><th>Libellé</th><th>À quoi il sert</th>
          <th>Provenance</th><th class="text-end">Ordre</th><th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($numeros as $n)
          <tr class="{{ $n->actif ? '' : 'opacity-50' }}">
            <td><code>{{ $n->code }}</code></td>
            <td><span class="fs-5 fw-bold">{{ $n->numero }}</span></td>
            <td>
              {{ $n->libelle }}
              @unless ($n->actif) <span class="badge bg-secondary">retiré</span> @endunless
            </td>
            <td class="text-muted small">{{ $n->description ?? '—' }}</td>
            <td>
              @if (in_array($n->source, ['autorite_nationale', 'publication'], true))
                <span class="badge bg-success-subtle text-success-emphasis">{{ $sources[$n->source] }}</span>
              @else
                <span class="badge bg-warning-subtle text-warning-emphasis">Non vérifiée</span>
              @endif
              @if ($n->source_detail)
                <div class="text-muted small">{{ $n->source_detail }}</div>
              @endif
            </td>
            <td class="text-end">{{ $n->ordre }}</td>
            <td class="text-end">
              <a href="{{ route('portail.numeros-urgence.edit', $n) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">Aucun numéro d'urgence enregistré.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<p class="text-muted small mt-3">
  {{ $actifs }} numéro(s) actif(s) sur {{ $total }}. Repli livré avec l'application :
  <strong>{{ $repli['numero'] }}</strong> ({{ $repli['libelle'] }}) — composé uniquement lorsqu'un
  téléphone n'a jamais reçu de version publiée.
</p>

<div class="mt-3">{{ $numeros->links() }}</div>
@endsection
