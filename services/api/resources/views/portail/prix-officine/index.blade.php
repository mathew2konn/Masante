@extends('portail.layout')

@section('titre', 'Prix & stock')

@section('content')
<div class="mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)"><i class="bi bi-capsule"></i> Prix &amp; stock</h1>
  <p class="text-muted mb-0">{{ $pharmacie->nom }} — vos prix apparaissent au comparateur des patients.</p>
</div>

{{-- Le pharmacien fait autorité sur SA pharmacie : sa déclaration prime sur les relevés des patients. --}}
<div class="alert alert-info d-flex gap-2">
  <i class="bi bi-info-circle"></i>
  <div class="small">
    Vos déclarations font <strong>autorité</strong> sur votre officine : elles priment sur les prix rapportés
    par les patients. Une rupture déclarée ici évite des déplacements inutiles à vos clients.
  </div>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-8">
    <input type="text" name="q" class="form-control" placeholder="Rechercher un médicament (DCI ou marque)…" value="{{ $recherche }}">
  </div>
  <div class="col-md-2 d-grid">
    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrer</button>
  </div>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Médicament</th>
          <th class="text-end">Référence CENAME</th>
          <th>Chez vous</th>
          <th style="width:38%">Déclarer</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($medicaments as $medicament)
          @php($etat = $etats[$medicament->id] ?? null)
          <tr>
            <td>
              <div class="fw-medium">{{ $medicament->libelle }}</div>
              <div class="small text-muted">
                {{ $medicament->categorie }}
                @if ($medicament->ordonnance_requise)
                  · <span class="badge bg-secondary-subtle text-secondary-emphasis border">Sur ordonnance</span>
                @endif
              </div>
            </td>
            <td class="text-end text-nowrap">
              {{ $medicament->prix_reference_cfa ? number_format($medicament->prix_reference_cfa, 0, ',', ' ').' F' : '—' }}
            </td>
            <td class="text-nowrap">
              @if ($etat === null)
                <span class="text-muted small">Non déclaré</span>
              @elseif (! $etat->disponible)
                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">En rupture</span>
                <div class="small text-muted">depuis le {{ $etat->date_mise_a_jour->format('d/m/Y') }}</div>
              @else
                <span class="fw-semibold">{{ number_format($etat->prix_cfa ?? 0, 0, ',', ' ') }} F</span>
                <div class="small text-muted">
                  {{ $etat->date_mise_a_jour->format('d/m/Y') }}
                  @if ($etat->source === 'crowdsource_patient') · rapporté par un patient @endif
                </div>
              @endif
            </td>
            <td>
              <form method="POST" action="{{ route('portail.prix-officine.declarer', $medicament) }}" class="d-flex gap-2">
                @csrf
                <select name="etat" class="form-select form-select-sm" style="max-width:9rem">
                  <option value="en_stock">En stock</option>
                  <option value="rupture">En rupture</option>
                </select>
                <input type="number" name="prix_cfa" class="form-control form-control-sm" placeholder="Prix FCFA"
                       min="1" value="{{ $etat?->prix_cfa }}">
                <button class="btn btn-sm btn-ms" type="submit"><i class="bi bi-check-lg"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-muted py-4">Aucun médicament au catalogue.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@error('prix_cfa') <div class="alert alert-danger mt-3">{{ $message }}</div> @enderror

<div class="mt-3">{{ $medicaments->links() }}</div>
@endsection
