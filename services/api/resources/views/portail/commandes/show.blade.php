@extends('portail.layout')

@section('titre', 'Commande '.$commande->reference)

@section('content')
@if (session('succes'))
  <div class="alert alert-success py-2">{{ session('succes') }}</div>
@endif

@if ($errors->any())
  <div class="alert alert-danger py-2">
    <ul class="mb-0 small">
      @foreach ($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach
    </ul>
  </div>
@endif

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h1 class="h5 mb-1" style="color:var(--ms-blue-dark)">
          <i class="bi bi-basket"></i> Commande {{ $commande->reference }}
        </h1>
        <p class="text-muted small mb-0">
          {{ $commande->membre?->prenom }} {{ $commande->membre?->nom }} ·
          {{ $commande->mode_retrait === \App\Support\ModeRetraitCommande::LIVRAISON ? 'Livraison' : 'Retrait en officine' }}
        </p>
      </div>
      <span class="badge bg-{{ $commande->statut->couleur() }} fs-6">{{ $commande->statut->libelle() }}</span>
    </div>

    @if ($commande->statut === \App\Support\StatutCommande::REFUSEE && $commande->motif_refus)
      <div class="alert alert-danger py-2 mt-3 mb-0 small">Motif du refus : {{ $commande->motif_refus }}</div>
    @endif

    @if ($commande->mode_retrait === \App\Support\ModeRetraitCommande::LIVRAISON)
      <p class="small mt-2 mb-0"><i class="bi bi-geo-alt"></i> {{ $commande->adresse_livraison }}</p>
    @endif

    @if ($commande->commentaire)
      <p class="small mt-2 mb-0 fst-italic">« {{ $commande->commentaire }} »</p>
    @endif

    @if ($commande->ordonnance)
      <p class="small mt-2 mb-0">
        <i class="bi bi-file-earmark-medical"></i> Commande rattachée à une ordonnance —
        vérifiez qu'elle prescrit bien les produits ci-dessous avant d'accepter.
      </p>
    @endif
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">Articles</h2>
    <table class="table table-sm">
      <thead>
        <tr><th>Produit</th><th>Dosage</th><th>Ordonnance</th><th>Quantité</th><th>Prix unitaire</th></tr>
      </thead>
      <tbody>
        @foreach ($commande->lignes as $ligne)
          <tr>
            <td>{{ $ligne->nom }}</td>
            <td>{{ $ligne->dosage ?? '—' }}</td>
            <td>
              @if ($ligne->ordonnance_requise)
                <span class="badge bg-warning-subtle text-warning-emphasis">Requise</span>
              @else
                <span class="text-muted small">Vente libre</span>
              @endif
            </td>
            <td>{{ $ligne->quantite }}</td>
            <td>
              @if ($ligne->prix_unitaire_indicatif_cfa !== null)
                {{ number_format($ligne->prix_unitaire_indicatif_cfa, 0, ',', ' ') }} FCFA
              @else
                <span class="text-muted">non connu</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <p class="text-end fw-semibold mb-0">
      Total indicatif :
      {{ $commande->montant_indicatif_cfa !== null ? number_format($commande->montant_indicatif_cfa, 0, ',', ' ').' FCFA' : 'non connu' }}
    </p>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body d-flex gap-2 flex-wrap">
    @if ($commande->statut === \App\Support\StatutCommande::EN_ATTENTE)
      <form method="POST" action="{{ route('portail.commandes.accepter', $commande) }}">
        @csrf
        <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Accepter</button>
      </form>
      <button class="btn btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#refuser-form">
        <i class="bi bi-x-lg"></i> Refuser
      </button>
      <div id="refuser-form" class="collapse w-100 mt-2">
        <form method="POST" action="{{ route('portail.commandes.refuser', $commande) }}" class="d-flex gap-2">
          @csrf
          <input type="text" name="motif" class="form-control form-control-sm" maxlength="300"
                 placeholder="Motif du refus" required>
          <button class="btn btn-sm btn-danger" type="submit">Confirmer le refus</button>
        </form>
      </div>
    @elseif ($commande->statut === \App\Support\StatutCommande::ACCEPTEE)
      <form method="POST" action="{{ route('portail.commandes.preparer', $commande) }}">
        @csrf
        <button class="btn btn-ms" type="submit"><i class="bi bi-box-seam"></i> Marquer prête</button>
      </form>
    @elseif ($commande->statut === \App\Support\StatutCommande::PRETE)
      <form method="POST" action="{{ route('portail.commandes.remettre', $commande) }}">
        @csrf
        <button class="btn btn-ms" type="submit"><i class="bi bi-bag-check"></i> Remettre au patient</button>
      </form>
    @else
      <p class="text-muted small mb-0">Aucune action possible dans cet état.</p>
    @endif
  </div>
</div>
@endsection
