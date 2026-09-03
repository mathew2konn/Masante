@extends('portail.layout')

@section('titre', 'Mon stock')

@section('content')
@if (session('succes'))
  <div class="alert alert-success py-2">{{ session('succes') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger py-2">
    <ul class="mb-0 small">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
  </div>
@endif

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h1 class="h4 mb-1" style="color:var(--ms-blue-dark)">
      <i class="bi bi-box-seam"></i> Mon stock · {{ $officine->nom }}
    </h1>
    <p class="text-muted small mb-0">
      Entrées, sorties, péremptions et seuils d'alerte. Le stock affiché est la <strong>somme des
      mouvements</strong> : rien ne se corrige en écrasant une valeur, tout se corrige par un
      ajustement, qui reste visible.
    </p>
  </div>
</div>

@if ($alertes->isNotEmpty())
  <div class="alert alert-warning py-2">
    <div class="fw-semibold small mb-1"><i class="bi bi-exclamation-triangle"></i> Sous le seuil d'alerte</div>
    <ul class="mb-0 small">
      @foreach ($alertes as $a)
        <li>{{ $a->medicament?->nom_generique }} — {{ $a->stockCourant() }} en rayon (seuil {{ $a->seuil_alerte }})</li>
      @endforeach
    </ul>
  </div>
@endif

@if ($peremptions->isNotEmpty())
  <div class="alert alert-secondary py-2">
    <div class="fw-semibold small mb-1"><i class="bi bi-calendar-x"></i> Lots périmés ou proches de la péremption</div>
    <ul class="mb-0 small">
      @foreach ($peremptions as $m)
        <li>
          {{ $m->stock?->medicament?->nom_generique }} · lot {{ $m->lot ?? '—' }} ·
          {{ $m->date_peremption?->format('d/m/Y') }}
          @if ($m->date_peremption?->isPast()) <span class="text-danger fw-semibold">périmé</span> @endif
        </li>
      @endforeach
    </ul>
  </div>
@endif

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end mb-3">
      <div class="col-md-9">
        <label for="q" class="form-label small mb-1">Rechercher dans mon inventaire</label>
        <input type="text" class="form-control" id="q" name="q" value="{{ $recherche }}">
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrer</button>
      </div>
    </form>

    <form method="POST" action="{{ route('portail.stock-officine.ajouter') }}" class="row g-2 align-items-end m-0">
      @csrf
      <div class="col-md-9">
        <label for="medicament_id" class="form-label small mb-1">Ajouter un produit à l'inventaire</label>
        <input type="number" class="form-control" id="medicament_id" name="medicament_id"
               placeholder="Identifiant du médicament au référentiel national">
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-ms" type="submit"><i class="bi bi-plus-lg"></i> Ajouter</button>
      </div>
    </form>
  </div>
</div>

@forelse ($articles as $article)
  <div class="card border-0 shadow-sm mb-2">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
          <div class="fw-semibold">
            {{ $article->medicament?->nom_generique }}
            @if ($article->medicament?->dosage) <span class="text-muted">{{ $article->medicament->dosage }}</span> @endif
          </div>
          <div class="small text-muted">
            En rayon : <strong>{{ $article->stockCourant() }}</strong>
            @if ($article->seuil_alerte !== null) · seuil {{ $article->seuil_alerte }} @endif
            @if ($article->prix_cfa !== null) · {{ number_format($article->prix_cfa, 0, ',', ' ') }} FCFA @endif
            @if ($article->sousLeSeuil() === true)
              <span class="badge bg-warning-subtle text-warning-emphasis">sous le seuil</span>
            @endif
          </div>
        </div>
      </div>

      <div class="row g-3 mt-1">
        <div class="col-lg-7">
          <form method="POST" action="{{ route('portail.stock-officine.mouvement', $article) }}" class="row g-2 align-items-end m-0">
            @csrf
            <div class="col-4">
              <label class="form-label small mb-1">Mouvement</label>
              <select name="type" class="form-select form-select-sm">
                <option value="entree">Entrée</option>
                <option value="sortie">Sortie</option>
                <option value="peremption">Péremption</option>
                <option value="ajustement">Ajustement</option>
              </select>
            </div>
            <div class="col-3">
              <label class="form-label small mb-1">Quantité</label>
              <input type="number" name="quantite" class="form-control form-control-sm" value="1">
            </div>
            <div class="col-3">
              <label class="form-label small mb-1">Lot</label>
              <input type="text" name="lot" class="form-control form-control-sm" maxlength="60">
            </div>
            <div class="col-2 d-grid">
              <button class="btn btn-outline-primary btn-sm" type="submit">Enregistrer</button>
            </div>
            <div class="col-12">
              <input type="date" name="date_peremption" class="form-control form-control-sm"
                     aria-label="Date de péremption du lot">
              <div class="form-text small">
                Le signe est déduit de la nature : une entrée ajoute, une sortie retire. Seul un
                ajustement va dans les deux sens.
              </div>
            </div>
          </form>
        </div>

        <div class="col-lg-5">
          <form method="POST" action="{{ route('portail.stock-officine.parametrer', $article) }}" class="row g-2 align-items-end m-0">
            @csrf
            <div class="col-5">
              <label class="form-label small mb-1">Prix (FCFA)</label>
              <input type="number" name="prix_cfa" class="form-control form-control-sm" value="{{ $article->prix_cfa }}">
            </div>
            <div class="col-4">
              <label class="form-label small mb-1">Seuil</label>
              <input type="number" name="seuil_alerte" class="form-control form-control-sm" value="{{ $article->seuil_alerte }}">
            </div>
            <div class="col-3 d-grid">
              <button class="btn btn-outline-secondary btn-sm" type="submit">Fixer</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
@empty
  <div class="alert alert-secondary py-2 small">
    Votre inventaire est vide. Ajoutez un produit ci-dessus pour commencer à en suivre le stock.
  </div>
@endforelse
@endsection
