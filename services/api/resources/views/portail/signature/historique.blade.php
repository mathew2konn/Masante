@extends('portail.layout')

@section('titre', 'Mes certificats')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.signature.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Mes certificats</h1>
</div>

{{-- Un certificat révoqué N'EST PAS supprimé : les signatures déjà posées le référencent, et une
     signature dont le certificat aurait disparu deviendrait invérifiable — ce qui reviendrait à
     l'effacer. --}}
<p class="text-muted small">
  Les certificats révoqués restent listés : les prescriptions signées avec eux doivent rester
  vérifiables.
</p>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>N° de série</th>
          <th>Émis le</th>
          <th>Expire le</th>
          <th>Statut</th>
          <th>Motif de révocation</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($certificats as $certificat)
          <tr class="{{ $certificat->statut === 'revoque' ? 'table-secondary' : '' }}">
            <td><code class="small">{{ $certificat->numero_serie }}</code></td>
            <td class="small">{{ $certificat->valide_du->format('d/m/Y') }}</td>
            <td class="small">{{ $certificat->valide_jusqu_a->format('d/m/Y') }}</td>
            <td>
              @if ($certificat->statut === 'actif')
                <span class="badge text-bg-success">Actif</span>
              @else
                <span class="badge text-bg-secondary">Révoqué</span>
                @if ($certificat->revoque_le)
                  <div class="small text-muted">{{ $certificat->revoque_le->format('d/m/Y') }}</div>
                @endif
              @endif
            </td>
            <td class="small">{{ $certificat->revocation_motif ?? '—' }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="text-center text-muted py-4">Aucun certificat émis.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
