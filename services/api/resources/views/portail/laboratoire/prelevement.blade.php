@extends('portail.layout')

@section('titre', 'Prélèvement '.$prelevement->identifiant)

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
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
      <div>
        <h1 class="h5 mb-1" style="color:var(--ms-blue-dark)">
          <i class="bi bi-droplet"></i> Prélèvement <span class="font-monospace">{{ $prelevement->identifiant }}</span>
        </h1>
        <p class="text-muted small mb-0">
          {{ $prelevement->demande?->membre?->prenom }} {{ $prelevement->demande?->membre?->nom }} ·
          statut : <span class="badge bg-secondary-subtle text-secondary-emphasis border">{{ $prelevement->statut->libelle() }}</span>
        </p>
      </div>
      <a href="{{ route('portail.laboratoire.etiquette', $prelevement) }}" target="_blank"
         class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-upc"></i> Étiquette imprimable
      </a>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">Examens demandés</h2>
    <ul class="list-group list-group-flush">
      @foreach ($prelevement->demande?->lignes ?? [] as $ligne)
        <li class="list-group-item px-0">{{ $ligne->libelle }}</li>
      @endforeach
    </ul>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body d-flex flex-wrap gap-2">
    @if ($prelevement->statut === \App\Support\StatutPrelevement::PRELEVE)
      <form method="POST" action="{{ route('portail.laboratoire.expedier', $prelevement) }}" class="m-0">
        @csrf
        <button class="btn btn-outline-secondary btn-sm" type="submit">
          <i class="bi bi-truck"></i> Marquer expédié
        </button>
      </form>
    @endif

    @if (in_array($prelevement->statut, [\App\Support\StatutPrelevement::PRELEVE, \App\Support\StatutPrelevement::EXPEDIE], true))
      <form method="POST" action="{{ route('portail.laboratoire.recevoir', $prelevement) }}" class="m-0">
        @csrf
        <button class="btn btn-ms btn-sm" type="submit">
          <i class="bi bi-box-seam"></i> Marquer reçu
        </button>
      </form>
    @endif

    @if ($prelevement->statut === \App\Support\StatutPrelevement::RECU)
      <form method="POST" action="{{ route('portail.laboratoire.mettre-en-analyse', $prelevement) }}" class="m-0">
        @csrf
        <button class="btn btn-ms btn-sm" type="submit">
          <i class="bi bi-cpu"></i> Mettre en analyse
        </button>
      </form>
    @endif

    @if ($prelevement->statut === \App\Support\StatutPrelevement::VALIDE)
      <form method="POST" action="{{ route('portail.laboratoire.publier', $prelevement) }}" class="m-0">
        @csrf
        <button class="btn btn-ms btn-sm" type="submit">
          <i class="bi bi-send-check"></i> Publier au dossier du patient
        </button>
      </form>
    @endif

    @if ($prelevement->statut === \App\Support\StatutPrelevement::PUBLIE)
      <span class="text-success small align-self-center">
        <i class="bi bi-check-circle-fill"></i> Résultat publié dans le carnet du patient.
      </span>
    @endif
  </div>
</div>

@if ($prelevement->statut === \App\Support\StatutPrelevement::EN_ANALYSE)
  <div class="card border-0 shadow-sm mt-3">
    <div class="card-body">
      @if ($prelevement->aUnBrouillon())
        {{-- B5-c (L7) — un brouillon existe : le biologiste juge, il ne resaisit pas. --}}
        <h2 class="h6 mb-2">Résultat en attente de validation biologique</h2>
        <ul class="list-group list-group-flush mb-3">
          @foreach ($prelevement->resultats_bruts_json as $valeur)
            <li class="list-group-item px-0 d-flex justify-content-between">
              <span>{{ $valeur['parametre'] ?? '' }}</span>
              <span class="fw-semibold">{{ $valeur['valeur'] ?? '' }} {{ $valeur['unite'] ?? '' }}</span>
            </li>
          @endforeach
        </ul>
        <p class="text-muted small">
          Origine : {{ $prelevement->resultats_bruts_origine === 'automate' ? 'importé par automate' : 'saisi manuellement' }}.
        </p>

        <div class="d-flex flex-wrap gap-2">
          <form method="POST" action="{{ route('portail.laboratoire.valider', $prelevement) }}" class="m-0">
            @csrf
            <button class="btn btn-ms btn-sm" type="submit">
              <i class="bi bi-check2-circle"></i> Valider
            </button>
          </form>

          <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse"
                  data-bs-target="#formulaire-rejet">
            <i class="bi bi-x-circle"></i> Rejeter
          </button>
        </div>

        <form method="POST" action="{{ route('portail.laboratoire.rejeter', $prelevement) }}"
              class="collapse mt-3" id="formulaire-rejet">
          @csrf
          <label class="form-label small">Motif du rejet (obligatoire)</label>
          <textarea name="motif" class="form-control form-control-sm mb-2" rows="2" required></textarea>
          <button class="btn btn-outline-danger btn-sm" type="submit">Confirmer le rejet</button>
        </form>
      @else
        {{-- L15 — un seul formulaire de saisie ; le biologiste ne saisit que la VALEUR, jamais
             l'identité de l'examen, déjà connue de la demande. --}}
        <h2 class="h6 mb-3">Saisir le résultat</h2>
        <form method="POST" action="{{ route('portail.laboratoire.resultat.saisir', $prelevement) }}">
          @csrf
          @forelse ($prelevement->demande?->lignes ?? [] as $i => $ligne)
            <div class="mb-2">
              <label class="form-label small mb-0">{{ $ligne->libelle }}</label>
              <div class="input-group input-group-sm">
                <input type="hidden" name="valeurs[{{ $i }}][parametre]" value="{{ $ligne->libelle }}">
                @if ($ligne->estCodee())
                  <input type="hidden" name="valeurs[{{ $i }}][analyse_id]" value="{{ $ligne->analyse_id }}">
                  <input type="hidden" name="valeurs[{{ $i }}][unite]" value="{{ $ligne->unite }}">
                @endif
                <input type="text" name="valeurs[{{ $i }}][valeur]" class="form-control"
                       placeholder="Valeur mesurée">
                @if ($ligne->estCodee())
                  <span class="input-group-text">{{ $ligne->unite }}</span>
                @else
                  <input type="text" name="valeurs[{{ $i }}][unite]" class="form-control"
                         placeholder="Unité" style="max-width:110px">
                @endif
              </div>
            </div>
          @empty
            <p class="text-muted small">Aucun examen exploitable sur cette demande.</p>
          @endforelse
          <p class="text-muted small mt-2">
            Les lignes laissées vides ne sont pas enregistrées : un résultat partiel reste honnête.
          </p>
          <button class="btn btn-ms btn-sm" type="submit">
            <i class="bi bi-clipboard2-check"></i> Enregistrer le résultat
          </button>
        </form>
      @endif
    </div>
  </div>
@endif
@endsection
