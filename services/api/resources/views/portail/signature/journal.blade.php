@extends('portail.layout')

@section('titre', 'Journal de mes signatures')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.signature.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Journal de mes signatures</h1>
</div>

{{-- §5.4 : « une signature est refusée si l'un de ces contrôles échoue, et l'échec est
     journalisé ». Les refus sont donc ici au même titre que les succès — un journal qui ne
     garderait que les signatures abouties ne répondrait pas à la question qui compte en litige. --}}
<p class="text-muted small">
  Les <strong>refus</strong> y figurent autant que les signatures posées. Aucun contenu médical n'y
  est recopié : le journal porte l'empreinte du document, jamais les médicaments.
</p>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Date</th>
          <th>Événement</th>
          <th>Document</th>
          <th>Motif</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($entrees as $entree)
          <tr>
            <td class="text-nowrap small">{{ $entree->cree_le->format('d/m/Y H:i') }}</td>
            <td>
              @php($succes = in_array($entree->action, ['signature_reussie', 'certificat_emis'], true))
              <span class="badge text-bg-{{ $succes ? 'success' : 'warning' }}">
                {{ str_replace('_', ' ', $entree->action) }}
              </span>
            </td>
            <td class="small">
              @if ($entree->type_document)
                <code>{{ $entree->type_document }}</code> n° {{ $entree->document_id }}
              @else
                <span class="text-muted">—</span>
              @endif
            </td>
            <td class="small">{{ $entree->motif ?? '—' }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="4" class="text-center text-muted py-4">
              Aucun événement de signature pour ce compte.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<p class="text-muted small mt-3 mb-0">
  Ce journal est <strong>append-only</strong> et chaîné : chaque ligne porte l'empreinte de la
  précédente. Supprimer ou modifier une entrée casse tout ce qui suit, et l'altération devient
  détectable.
</p>
@endsection
