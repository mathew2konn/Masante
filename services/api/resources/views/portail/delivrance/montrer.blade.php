@extends('portail.layout')

@section('titre', 'Ordonnance à servir')

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
    <h1 class="h5 mb-1" style="color:var(--ms-blue-dark)">
      <i class="bi bi-file-earmark-medical"></i> Ordonnance
    </h1>
    <p class="text-muted small mb-0">
      {{ $ordonnance->membre?->prenom }} {{ $ordonnance->membre?->nom }} ·
      prescrite le {{ $ordonnance->date_prescription?->format('d/m/Y') }} ·
      {{ $ordonnance->medecin_nom }}
      @if ($ordonnance->structure_sanitaire) · {{ $ordonnance->structure_sanitaire }} @endif
    </p>
    <p class="text-muted small mb-0 mt-2">
      <i class="bi bi-shield-lock"></i> Vous ne voyez que cette ordonnance. Le reste du dossier du
      patient ne vous est pas accessible.
    </p>
  </div>
</div>

{{-- §7.2 — les interactions sont CONSULTABLES, jamais calculées à la place du pharmacien.
     Le choix de P6.6b (consultation explicite) n'est pas rouvert : calculer rapprocherait ce
     module d'une aide à la décision, terrain de CDC_05 et CDC_08. --}}
@if (! empty($interactions) && count($interactions) > 0)
  <div class="alert alert-warning py-2">
    <div class="fw-semibold small mb-1">
      <i class="bi bi-exclamation-triangle"></i> Interactions déclarées entre ces médicaments
    </div>
    <ul class="mb-0 small">
      @foreach ($interactions as $interaction)
        <li>{{ $interaction->description ?? 'Interaction déclarée au référentiel national.' }}</li>
      @endforeach
    </ul>
    <div class="small mt-1 text-muted">
      Cette liste ne remplace pas votre jugement professionnel.
    </div>
  </div>
@endif

@if (! $ordonnance->estDelivrable())
  <div class="alert alert-secondary py-2 small">
    Cette ordonnance a été écrite avant la délivrance électronique : elle est consultable, mais
    ne peut pas être servie depuis cet écran.
  </div>
@else
  <form method="POST" action="{{ route('portail.delivrance.servir') }}">
    @csrf
    <input type="hidden" name="jeton" value="{{ $jeton }}">

    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Médicament</th>
              <th>Posologie</th>
              <th class="text-end">Prescrit</th>
              <th class="text-end">Déjà servi</th>
              <th style="width:9rem">À servir</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($ordonnance->lignes as $ligne)
              @php $reste = $ligne->resteAServir(); @endphp
              <tr>
                <td>
                  <div class="fw-semibold">{{ $ligne->nom }}</div>
                  @if ($ligne->estCodee())
                    <div class="small text-muted">{{ $ligne->dci }} {{ $ligne->dosage }} · {{ $ligne->code_national }}</div>
                  @else
                    <span class="badge bg-secondary-subtle text-secondary-emphasis">hors référentiel</span>
                  @endif
                </td>
                <td class="small">{{ $ligne->posologie ?? '—' }}</td>
                <td class="text-end">{{ $ligne->quantite_prescrite ?? '—' }}</td>
                <td class="text-end">{{ $ligne->quantiteDelivree() }}</td>
                <td>
                  @if ($reste === 0)
                    <span class="small text-success"><i class="bi bi-check2"></i> servi</span>
                  @else
                    <input type="number" min="0" class="form-control form-control-sm"
                           name="quantites[{{ $ligne->id }}]" value="0"
                           @if ($reste !== null) max="{{ $reste }}" @endif>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="card-body d-flex justify-content-between align-items-center">
        <div class="small text-muted">
          Une délivrance partielle est possible : le patient pourra repasser chercher le reste.
        </div>
        <button class="btn btn-ms" type="submit"><i class="bi bi-check2-circle"></i> Enregistrer la délivrance</button>
      </div>
    </div>
  </form>
@endif

@if ($ordonnance->delivrances->isNotEmpty())
  <div class="card border-0 shadow-sm mt-3">
    <div class="card-body">
      <h2 class="h6 text-ms mb-2">Délivrances déjà enregistrées</h2>
      <ul class="list-group list-group-flush">
        @foreach ($ordonnance->delivrances as $delivrance)
          <li class="list-group-item px-0 small">
            {{ $delivrance->delivree_le?->format('d/m/Y H:i') }} ·
            {{ $delivrance->pharmacien_nom }} ·
            {{ $delivrance->lignes->count() }} médicament(s)
          </li>
        @endforeach
      </ul>
    </div>
  </div>
@endif
@endsection
