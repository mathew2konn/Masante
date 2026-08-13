@extends('portail.layout')

@section('titre', 'Vérification de signature')

@section('content')
<h1 class="h3 mb-4" style="color:var(--ms-blue-dark)">Vérification de signature</h1>

<div class="card border-0 shadow-sm">
  <div class="card-body">
    <p class="small text-muted mb-3">
      Document <code>{{ $type }}</code> n° <code>{{ $documentId }}</code>
    </p>

    @if (! $resultat['signe'])
      {{-- Non signé n'est pas « invalide » : une ordonnance non signée reste une ordonnance.
           Dire le contraire ferait douter de documents parfaitement licites. --}}
      <div class="alert alert-secondary mb-0">
        <strong>Document non signé.</strong>
        Aucune signature électronique n'a été posée sur ce document. Ce n'est pas une anomalie :
        la signature est proposée au praticien, elle n'est pas imposée.
      </div>
    @elseif ($resultat['integre'] === true)
      <div class="alert alert-success">
        <strong>Signature valide — le document est intègre.</strong>
        Son contenu n'a pas changé depuis la signature.
      </div>
    @else
      {{-- Le vecteur central de P6.5b : la signature n'empêche pas la modification, elle la
           RÉVÈLE. C'est la définition de l'intégrité au §5.3. --}}
      <div class="alert alert-danger">
        <strong>Signature invalide.</strong> {{ $resultat['motif'] }}
      </div>
    @endif

    @if ($resultat['signature'])
      @php($s = $resultat['signature'])
      <h2 class="h6 text-muted mt-4 mb-2">Ce que la signature affirme</h2>
      <div class="row g-2 small">
        <div class="col-md-4"><span class="text-muted">Signataire</span><br><strong>{{ $s->signataire_nom }}</strong></div>
        <div class="col-md-3"><span class="text-muted">N° national</span><br><code>{{ $s->signataire_numero ?? '—' }}</code></div>
        <div class="col-md-5"><span class="text-muted">Établissement</span><br>{{ $s->signataire_etablissement ?? '—' }}</div>
        <div class="col-md-4"><span class="text-muted">Signé le</span><br>{{ $s->signe_le->format('d/m/Y H:i') }}</div>
        <div class="col-md-3"><span class="text-muted">Algorithme</span><br>{{ $s->algorithme }}</div>
        <div class="col-md-5"><span class="text-muted">Certificat</span><br>
          <code>{{ $s->certificat?->numero_serie ?? '—' }}</code>
          @if ($s->certificat?->statut === 'revoque')
            <span class="badge text-bg-warning ms-1">Révoqué depuis</span>
          @endif
        </div>
      </div>

      <p class="text-muted small mt-3 mb-0">
        Ces informations sont celles du <strong>jour de la signature</strong>, figées à cet instant :
        un praticien muté depuis ne fait pas changer d'établissement ses prescriptions passées.
        @if ($s->certificat?->statut === 'revoque')
          Le certificat a été révoqué <em>après</em> cette signature — celle-ci reste valide, elle a
          été posée quand le certificat l'était.
        @endif
      </p>
    @endif
  </div>
</div>
@endsection
