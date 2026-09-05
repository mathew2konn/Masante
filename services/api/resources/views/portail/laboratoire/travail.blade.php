@extends('portail.layout')

@section('titre', 'Travail en cours')

@section('content')
<div class="card border-0 shadow-sm">
  <div class="card-body">
    <h1 class="h4 mb-3" style="color:var(--ms-blue-dark)">
      <i class="bi bi-list-check"></i> Travail en cours de mon laboratoire
    </h1>

    @if ($prelevements->isEmpty())
      <p class="text-muted mb-0"><i class="bi bi-inbox"></i> Aucun prélèvement en cours.</p>
    @else
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Identifiant</th><th>Patient</th><th>Prélevé le</th><th>Statut</th><th></th></tr></thead>
        <tbody>
          @foreach ($prelevements as $p)
            <tr>
              <td class="font-monospace">{{ $p->identifiant }}</td>
              <td>{{ $p->demande?->membre?->prenom }} {{ $p->demande?->membre?->nom }}</td>
              <td>{{ $p->preleve_le?->format('d/m/Y H:i') }}</td>
              <td><span class="badge bg-secondary-subtle text-secondary-emphasis border">{{ $p->statut->libelle() }}</span></td>
              <td>
                <a href="{{ route('portail.laboratoire.prelevement', $p) }}" class="btn btn-outline-secondary btn-sm">
                  Ouvrir
                </a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>
@endsection
