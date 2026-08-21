@extends('portail.layout')

@section('titre', $protocole->titre)

@section('content')
<div class="mb-3">
  <a href="{{ route('portail.protocoles.index') }}" class="small text-decoration-none">
    <i class="bi bi-arrow-left"></i> Tous les protocoles
  </a>
</div>

<h1 class="h4 mb-1" style="color:var(--ms-blue-dark)">{{ $protocole->titre }}</h1>
<p class="text-muted">
  <code>{{ $protocole->code }}</code> · version <strong>{{ $version->libelle }}</strong>
  · état <strong>{{ $version->etat }}</strong>
  · niveau de preuve <strong>{{ $version->niveau_preuve ?? '—' }}</strong>
</p>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif
@if (session('erreur'))
  <div class="alert alert-danger">{{ session('erreur') }}</div>
@endif

@if ($version->niveau_preuve === 'D')
  <div class="alert alert-warning small">
    <strong>Contenu de démonstration.</strong> Niveau de preuve « D », et aucune autorité sanitaire
    n'est nommée comme source. Ce texte est relisible et corrigible — il n'est pas validé
    cliniquement du seul fait d'être ici.
  </div>
@endif

<div class="row g-4">
  <div class="col-lg-7">
    <h2 class="h6 text-uppercase text-muted">Population et conditions d'utilisation</h2>
    <p class="small">{{ $version->population ?? '—' }}</p>
    <p class="small">{{ $version->conditions_utilisation ?? '—' }}</p>

    @if ($questions)
      <h2 class="h6 text-uppercase text-muted mt-4">Questions posées au patient</h2>
      <ul class="small">
        @foreach ($questions as $question)
          <li>
            <strong>{{ $question['libelle'] }}</strong>
            <span class="text-muted">({{ $question['type'] }}@if(!empty($question['unite'])), {{ $question['unite'] }}@endif)</span>
          </li>
        @endforeach
      </ul>
    @endif

    <h2 class="h6 text-uppercase text-muted mt-4">Règles ({{ count($regles) }})</h2>

    {{--
      Rendues en français à partir des libellés des trois listes blanches : un relecteur clinique
      ne doit pas avoir à lire du JSON pour signer une pièce que le §7 qualifie d'opposable.
    --}}
    <ol class="small">
      @foreach ($regles as $regle)
        <li class="mb-2">
          <strong>{{ $regle['libelle'] }}</strong><br>
          <span class="text-muted">Si</span> {{ implode(' ET ', $regle['conditions']) }}
          <span class="text-muted">alors</span> {{ implode(' ; ', $regle['actions']) }}
          @foreach ($regle['justifications'] as $justification)
            <div class="fst-italic text-muted">« {{ $justification }} »</div>
          @endforeach
        </li>
      @endforeach
    </ol>

    <h2 class="h6 text-uppercase text-muted mt-4">Références (§4.1)</h2>
    <ul class="small">
      @forelse ($version->references as $reference)
        <li>{{ $reference->libelle }} <span class="text-muted">— {{ $reference->citation }}</span></li>
      @empty
        <li class="text-danger">Aucune : la publication sera refusée.</li>
      @endforelse
    </ul>
  </div>

  <div class="col-lg-5">
    <h2 class="h6 text-uppercase text-muted">Dossier de validation (§7)</h2>

    @if ($anomalies)
      <div class="alert alert-danger small">
        <strong>Contrôles du §7.4 en échec.</strong> La publication sera refusée tant qu'ils
        subsistent — ils sont montrés ici pour qu'on ne les découvre pas après avoir relu :
        <ul class="mb-0 mt-1">
          @foreach ($anomalies as $anomalie)<li>{{ $anomalie }}</li>@endforeach
        </ul>
      </div>
    @endif

    @foreach ($types as $type => $permission)
      @php($dossier = $validations[$type] ?? null)
      <div class="card mb-3">
        <div class="card-body py-2">
          <div class="d-flex justify-content-between align-items-center">
            <strong class="text-capitalize">{{ $type }}</strong>
            @if ($dossier === null)
              <span class="badge bg-secondary">non signée</span>
            @elseif ($dossier['avis'] !== 'favorable')
              <span class="badge bg-danger">défavorable</span>
            @elseif (! $dossier['porte_sur_le_contenu_actuel'])
              {{--
                ═══ LE POINT LE PLUS IMPORTANT DE CET ÉCRAN ═══
                Une validation caduque doit AVOIR L'AIR caduque. C'est ce qui dit au signataire que
                le texte a bougé depuis qu'un confrère l'a relu ; l'afficher discrètement
                laisserait signer par-dessus une relecture périmée.
              --}}
              <span class="badge bg-warning text-dark">caduque</span>
            @else
              <span class="badge bg-success">favorable</span>
            @endif
          </div>

          @if ($dossier !== null)
            <div class="small text-muted">
              {{ $dossier['validateur'] }} — {{ $dossier['role'] }}
              @if ($dossier['valide_le']) · {{ $dossier['valide_le']->format('d/m/Y H:i') }} @endif
            </div>
            @if ($dossier['commentaires'])
              <div class="small fst-italic">« {{ $dossier['commentaires'] }} »</div>
            @endif
            @if ($dossier['avis'] === 'favorable' && ! $dossier['porte_sur_le_contenu_actuel'])
              <div class="small text-warning-emphasis">
                Le contenu a été modifié après cette signature : elle ne vaut plus pour le texte
                ci-contre et doit être renouvelée.
              </div>
            @endif
          @endif

          @can($permission)
            <form method="POST" action="{{ route('portail.protocoles.valider', [$protocole, $version]) }}" class="mt-2">
              @csrf
              <input type="hidden" name="type" value="{{ $type }}">
              <div class="input-group input-group-sm mb-1">
                <span class="input-group-text">Votre rôle</span>
                <input type="text" name="role" class="form-control" required
                       placeholder="ex. Médecin urgentiste, CHU de Cocody">
              </div>
              <textarea name="commentaires" class="form-control form-control-sm mb-1" rows="2"
                        placeholder="Commentaires (facultatif)"></textarea>
              <button name="avis" value="favorable" class="btn btn-sm btn-success">Signer favorable</button>
              <button name="avis" value="defavorable" class="btn btn-sm btn-outline-danger">Défavorable</button>
            </form>
          @endcan
        </div>
      </div>
    @endforeach

    @can('protocole.publier')
      @if ($version->etat === 'brouillon')
        <form method="POST" action="{{ route('portail.protocoles.publier', [$protocole, $version]) }}">
          @csrf
          <button class="btn btn-primary w-100">Mettre en vigueur</button>
          <div class="form-text">
            Refusé si les quatre validations ne portent pas sur le contenu actuel, ou si vous êtes
            le rédacteur de cette version (§10).
          </div>
        </form>
      @endif
    @endcan

    <div class="small text-muted mt-3">
      Empreinte du contenu : <code>{{ substr($empreinte, 0, 16) }}…</code>
    </div>
  </div>
</div>
@endsection
