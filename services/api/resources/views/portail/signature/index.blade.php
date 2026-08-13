@extends('portail.layout')

@section('titre', 'Ma signature')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Ma signature électronique</h1>
  <div class="d-flex gap-2">
    <a href="{{ route('portail.signature.journal') }}" class="btn btn-sm btn-outline-secondary">Journal</a>
    <a href="{{ route('portail.signature.historique') }}" class="btn btn-sm btn-outline-secondary">Mes certificats</a>
  </div>
</div>

@if (session('statut'))
  <div class="alert alert-success py-2">{{ session('statut') }}</div>
@endif

{{-- Identité professionnelle. Sans fiche reliée, rien n'est possible : une signature désignerait
     un compte et non un praticien — c'est l'état que le G0 de P6.5 avait trouvé. --}}
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 text-muted mb-3">Identité professionnelle</h2>
    @if ($professionnel)
      <div class="row g-2 small">
        <div class="col-md-4"><span class="text-muted">Praticien</span><br><strong>{{ $professionnel->nom_complet }}</strong></div>
        <div class="col-md-3"><span class="text-muted">N° national</span><br>
          <code>{{ $professionnel->numero_professionnel ?? '—' }}</code></div>
        <div class="col-md-5"><span class="text-muted">Autorisation d'exercer</span><br>
          @if ($professionnel->autorisation_statut)
            {{ \App\Support\ProfessionsSante::STATUTS_AUTORISATION[$professionnel->autorisation_statut] ?? $professionnel->autorisation_statut }}
            @if ($professionnel->autorisation_expire_le)
              <span class="text-muted">— jusqu'au {{ $professionnel->autorisation_expire_le->format('d/m/Y') }}</span>
            @endif
          @else
            <span class="text-muted">Non enregistrée</span>
          @endif
        </div>
      </div>
    @else
      <p class="mb-0 text-muted">
        Ce compte n'est relié à aucune fiche professionnelle. Le gestionnaire de votre établissement
        doit faire ce lien depuis <strong>Praticiens</strong> avant que vous puissiez signer.
      </p>
    @endif
  </div>
</div>

{{-- Le certificat. --}}
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 text-muted mb-3">Certificat numérique</h2>

    @if ($certificat)
      <div class="row g-2 small mb-3">
        <div class="col-md-3"><span class="text-muted">N° de série</span><br><code>{{ $certificat->numero_serie }}</code></div>
        <div class="col-md-3"><span class="text-muted">Valide jusqu'au</span><br>{{ $certificat->valide_jusqu_a->format('d/m/Y') }}</div>
        <div class="col-md-6"><span class="text-muted">Empreinte SHA-256</span><br>
          <code class="small">{{ $certificat->empreinte }}</code></div>
      </div>

      @if ($verdictSignature->autorise)
        <div class="alert alert-success py-2 mb-3">
          <strong>Vous pouvez signer vos prescriptions.</strong>
          Les cinq contrôles obligatoires (identité, certificat, autorisation d'exercer, expiration,
          révocation) sont réunis.
        </div>
      @else
        {{-- Le motif exact, jamais un « non » sec : il indique quoi corriger, et à qui s'adresser. --}}
        <div class="alert alert-warning py-2 mb-3">
          <strong>Signature indisponible.</strong> {{ $verdictSignature->motif }}
        </div>
      @endif

      <form method="POST" action="{{ route('portail.signature.revoquer') }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-md-8">
          <label class="form-label small">Révoquer ce certificat — motif</label>
          <input type="text" name="motif" maxlength="300" required
                 class="form-control form-control-sm @error('motif') is-invalid @enderror"
                 placeholder="Secret compromis, changement de poste…">
          @error('motif') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-4">
          <button class="btn btn-outline-danger btn-sm" type="submit">Révoquer</button>
        </div>
        <div class="col-12">
          <div class="form-text">
            La révocation est <strong>définitive</strong>. Les prescriptions déjà signées restent
            vérifiables : elles ont été signées quand le certificat était valide.
          </div>
        </div>
      </form>
    @else
      {{-- Dire ce qui s'est passé plutôt que « aucun certificat » : un praticien dont le
           certificat vient d'être révoqué lirait sinon une phrase fausse. --}}
      @if ($dernier && $dernier->statut === 'revoque')
        <div class="alert alert-secondary py-2 small">
          Votre dernier certificat (<code>{{ $dernier->numero_serie }}</code>) a été
          <strong>révoqué</strong>@if ($dernier->revoque_le) le {{ $dernier->revoque_le->format('d/m/Y') }}@endif.
          @if ($dernier->revocation_motif) Motif : {{ $dernier->revocation_motif }}@endif
          Les prescriptions signées avec lui restent vérifiables.
        </div>
      @endif

      @if ($verdictEmission->autorise)
        <p class="small text-muted">
          Choisissez un secret de signature. Il vous sera demandé à <strong>chaque</strong>
          prescription signée.
        </p>
        <div class="alert alert-warning py-2 small">
          <strong>Il n'est stocké nulle part et ne peut pas être retrouvé.</strong> C'est ce qui
          garantit que personne — pas même la plateforme — ne peut signer à votre place. Si vous le
          perdez, il faudra révoquer ce certificat et en demander un nouveau.
        </div>

        <form method="POST" action="{{ route('portail.signature.creer') }}" class="row g-2">
          @csrf
          <div class="col-md-4">
            <label class="form-label small">Secret de signature</label>
            <input type="password" name="secret" autocomplete="new-password" required
                   minlength="{{ $longueurMin }}"
                   class="form-control form-control-sm @error('secret') is-invalid @enderror">
            @error('secret') <div class="invalid-feedback">{{ $message }}</div> @enderror
          </div>
          <div class="col-md-4">
            <label class="form-label small">Confirmation</label>
            <input type="password" name="secret_confirmation" autocomplete="new-password" required
                   minlength="{{ $longueurMin }}" class="form-control form-control-sm">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <button class="btn btn-ms btn-sm" type="submit">
              <i class="bi bi-shield-lock"></i> Demander mon certificat
            </button>
          </div>
        </form>
      @else
        <div class="alert alert-warning py-2 mb-0">
          <strong>Aucun certificat ne peut être émis.</strong> {{ $verdictEmission->motif }}
          <div class="small mt-1">
            L'autorité ne certifie que ce que le référentiel affirme déjà : l'autorisation d'exercer
            est déclarée par un compte habilité, jamais par vous-même.
          </div>
        </div>
      @endif
    @endif
  </div>
</div>

{{-- Ce que la signature couvre RÉELLEMENT. On n'affiche pas « les documents médicaux » quand un
     seul des sept types de CDC_10 §4.5 est branché. --}}
<div class="card border-0 shadow-sm">
  <div class="card-body">
    <h2 class="h6 text-muted mb-3">Ce qui est signable aujourd'hui</h2>
    <ul class="list-group list-group-flush small">
      @foreach ($documents as $doc)
        <li class="list-group-item px-0">
          @if ($doc['branche'])
            <span class="badge text-bg-success">Signable</span>
          @else
            <span class="badge text-bg-secondary">Pas encore</span>
          @endif
          <strong class="ms-1">{{ $doc['libelle'] }}</strong>
          @if ($doc['raison'])
            <div class="text-muted mt-1">{{ $doc['raison'] }}</div>
          @endif
        </li>
      @endforeach
    </ul>
  </div>
</div>

<p class="text-muted small mt-3 mb-0">
  L'autorité de certification de MaSanté est <strong>auto-signée</strong> : elle lie une
  prescription à un praticien identifié dans cette plateforme. Elle ne vaut pas certification par
  une autorité nationale, dont aucune n'a été consultée (ADR-032).
</p>
@endsection
