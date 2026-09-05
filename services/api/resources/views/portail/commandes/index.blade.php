@extends('portail.layout')

@section('titre', 'Commandes de médicaments')

@section('content')
<div class="card border-0 shadow-sm">
  <div class="card-body">
    <h1 class="h4 mb-1" style="color:var(--ms-blue-dark)">
      <i class="bi bi-basket"></i> Commandes de médicaments
    </h1>
    <p class="text-muted small">
      Les commandes de votre officine, les plus récentes en attente d'abord (CDC_11 §9.5).
    </p>

    @if ($commandes->isEmpty())
      <p class="text-muted">Aucune commande pour le moment.</p>
    @else
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>Référence</th>
              <th>Patient</th>
              <th>Articles</th>
              <th>Retrait</th>
              <th>Montant indicatif</th>
              <th>Statut</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($commandes as $commande)
              <tr>
                <td class="font-monospace small">{{ $commande->reference }}</td>
                <td>{{ $commande->membre?->prenom }} {{ $commande->membre?->nom }}</td>
                <td>{{ $commande->lignes->count() }}</td>
                <td>{{ $commande->mode_retrait === \App\Support\ModeRetraitCommande::LIVRAISON ? 'Livraison' : 'Retrait' }}</td>
                <td>
                  @if ($commande->montant_indicatif_cfa !== null)
                    {{ number_format($commande->montant_indicatif_cfa, 0, ',', ' ') }} FCFA
                  @else
                    <span class="text-muted">non connu</span>
                  @endif
                </td>
                <td><span class="badge bg-{{ $commande->statut->couleur() }}">{{ $commande->statut->libelle() }}</span></td>
                <td><a href="{{ route('portail.commandes.show', $commande) }}" class="btn btn-sm btn-outline-secondary">Ouvrir</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</div>
@endsection
