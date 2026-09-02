@extends('portail.layout')

@section('titre', 'Rendez-vous')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.rdv.index', ['statut' => $rdv->statut]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Patient {{ $rdv->membre?->prenom }} {{ $rdv->membre?->nom }}</h1>
</div>

<div class="row g-3">
  {{-- Détails de la demande --}}
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4 text-muted">Service</dt>
          <dd class="col-sm-8">{{ $rdv->service?->nom_service }} <span class="text-muted small">({{ $rdv->service?->specialite }})</span></dd>

          <dt class="col-sm-4 text-muted">Date souhaitée</dt>
          <dd class="col-sm-8">{{ \Illuminate\Support\Carbon::parse($rdv->date_souhaitee)->format('d/m/Y') }}</dd>

          <dt class="col-sm-4 text-muted">Attribution</dt>
          <dd class="col-sm-8">
            {{ $rdv->mode_attribution === 'patient_choisit' ? 'Médecin choisi par le patient' : 'Attribué par l\'établissement' }}
            @if ($rdv->medecin)
              — <strong>{{ $rdv->medecin->titre }} {{ $rdv->medecin->prenom }} {{ $rdv->medecin->nom }}</strong>
              @if ($rdv->medecin->numero_professionnel)
                <span class="text-muted small">(n° {{ $rdv->medecin->numero_professionnel }})</span>
              @endif
              @if ($rdv->medecin->photo_url)
                <img src="{{ $rdv->medecin->photo_url }}" alt="" width="32" height="32" class="rounded-circle ms-1" style="object-fit:cover">
              @endif
            @endif
          </dd>

          {{-- B1-b / D7 — aperçu du tarif, avec sa source : un montant ne doit jamais mentir sur
               d'où il vient (précédent `delai_source` P6.7b, `provenance` P6.8d). --}}
          <dt class="col-sm-4 text-muted">Tarif</dt>
          <dd class="col-sm-8">
            @if ($tarif !== null)
              {{ number_format($tarif, 0, ',', ' ') }} FCFA
              <span class="text-muted small">(source : {{ $tarifSource }})</span>
            @else
              <span class="text-muted">Aucun tarif configuré</span>
            @endif
          </dd>

          {{-- B1-b / D6 — médecin référent, lu via ReferentService, aucun nouveau mécanisme. --}}
          @if ($referent)
            <dt class="col-sm-4 text-muted">Médecin référent</dt>
            <dd class="col-sm-8">
              {{ $referent->medecin?->titre }} {{ $referent->medecin?->prenom }} {{ $referent->medecin?->nom }}
              <span class="text-muted small">({{ $referent->medecin?->structure?->nom }})</span>
            </dd>
          @endif

          <dt class="col-sm-4 text-muted">Motif</dt>
          <dd class="col-sm-8">{{ $rdv->motif }}</dd>

          @if ($rdv->motif_orientation || $rdv->message_orientation)
            <dt class="col-sm-4 text-muted">Orientation (patient)</dt>
            <dd class="col-sm-8">
              @if ($rdv->motif_orientation) <strong>{{ $rdv->motif_orientation }}</strong> @endif
              @if ($rdv->message_orientation) <br><span class="small">{{ $rdv->message_orientation }}</span> @endif
            </dd>
          @endif

          @if ($rdv->message_agent)
            <dt class="col-sm-4 text-muted">Message agent</dt>
            <dd class="col-sm-8">{{ $rdv->message_agent }}</dd>
          @endif
        </dl>

        @if ($rdv->triage)
          <hr>
          <h2 class="h6"><i class="bi bi-clipboard2-pulse text-ms"></i> Fiche de triage jointe</h2>
          <p class="small mb-0">
            Niveau : <strong>{{ $rdv->triage->niveau }}</strong>
            @if ($rdv->triage->score_severite !== null) · Score {{ $rdv->triage->score_severite }}/100 @endif
            @if ($rdv->triage->specialite_requise) · Spécialité : {{ $rdv->triage->specialite_requise }} @endif
          </p>
        @endif
      </div>
    </div>
  </div>

  {{-- Actions — workflow à deux étapes (B1-a, CDC_11 §9.1) --}}
  <div class="col-lg-5">
    @if ($rdv->statut === 'en_attente')
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-medium text-primary"><i class="bi bi-clipboard-check"></i> Pré-valider (accueil)</div>
        <div class="card-body">
          <form method="POST" action="{{ route('portail.rdv.previsalider', $rdv) }}">
            @csrf @method('PATCH')
            <div class="mb-3">
              <label class="form-label">Message pour le médecin <span class="text-muted small">(optionnel)</span></label>
              <textarea name="message_agent" class="form-control" rows="2" maxlength="1000"
                        placeholder="Ex. Dossier vérifié.">{{ old('message_agent') }}</textarea>
            </div>
            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2"></i> Pré-valider</button>
          </form>
        </div>
      </div>
    @elseif ($rdv->statut === 'prevalide')
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-medium text-success"><i class="bi bi-check-circle"></i> Confirmer (médecin)</div>
        <div class="card-body">
          <form method="POST" action="{{ route('portail.rdv.confirmer', $rdv) }}">
            @csrf @method('PATCH')
            <div class="mb-3">
              <label class="form-label">Date et heure confirmées <span class="text-danger">*</span></label>
              <input type="datetime-local" name="date_confirmee" class="form-control @error('date_confirmee') is-invalid @enderror"
                     value="{{ old('date_confirmee') }}" min="{{ now()->format('Y-m-d\TH:i') }}" required>
              @error('date_confirmee') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            @if ($medecins->isNotEmpty())
              <div class="mb-3">
                <label class="form-label">Médecin {{ $rdv->mode_attribution === 'etablissement_attribue' ? '' : '(optionnel)' }}</label>
                <select name="medecin_id" class="form-select @error('medecin_id') is-invalid @enderror">
                  <option value="">— {{ $rdv->medecin ? 'Conserver : ' . $rdv->medecin->nom : 'Non assigné' }} —</option>
                  @foreach ($medecins as $medecin)
                    <option value="{{ $medecin->id }}" @selected((int) old('medecin_id') === $medecin->id)>
                      {{ $medecin->titre }} {{ $medecin->prenom }} {{ $medecin->nom }}
                    </option>
                  @endforeach
                </select>
                @error('medecin_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
              </div>
            @endif

            <div class="mb-3">
              <label class="form-label">Message au patient <span class="text-muted small">(optionnel)</span></label>
              <textarea name="message_agent" class="form-control" rows="2" maxlength="1000"
                        placeholder="Ex. Présentez-vous 15 min avant.">{{ old('message_agent') }}</textarea>
            </div>

            <button class="btn btn-success w-100" type="submit"><i class="bi bi-check-lg"></i> Confirmer le RDV</button>
          </form>
        </div>
      </div>
    @endif

    @if (in_array($rdv->statut, ['en_attente', 'prevalide'], true))
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-medium text-danger"><i class="bi bi-x-circle"></i> Refuser</div>
        <div class="card-body">
          <form method="POST" action="{{ route('portail.rdv.refuser', $rdv) }}">
            @csrf @method('PATCH')
            <div class="mb-3">
              <label class="form-label">Motif du refus <span class="text-danger">*</span></label>
              <textarea name="message_agent" class="form-control @error('message_agent') is-invalid @enderror" rows="2" maxlength="1000"
                        placeholder="Communiqué au patient.">{{ old('message_agent') }}</textarea>
              @error('message_agent') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <button class="btn btn-outline-danger w-100" type="submit"
                    onsubmit="return confirm('Refuser ce rendez-vous ?');">
              <i class="bi bi-x-lg"></i> Refuser le RDV
            </button>
          </form>
        </div>
      </div>
    @endif

    @if (! in_array($rdv->statut, ['en_attente', 'prevalide'], true))
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center text-muted">
          <i class="bi bi-info-circle"></i> Ce rendez-vous a déjà été traité ({{ $rdv->statut }}).
          @if ($rdv->date_confirmee)
            <p class="mb-0 mt-2">Confirmé pour le <strong>{{ \Illuminate\Support\Carbon::parse($rdv->date_confirmee)->format('d/m/Y H:i') }}</strong>.</p>
          @endif

          {{-- 4.5 / N6 — arrivée physique du patient, enregistrée par scan du QR de reçu. --}}
          @if ($rdv->estEnregistre())
            <p class="mb-0 mt-2">
              <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                <i class="bi bi-person-check"></i> Patient présent à l'accueil depuis {{ $rdv->checked_in_at->format('H:i') }}
              </span>
            </p>
          @endif
        </div>
      </div>

      {{-- B1-c (D8) — accès partagé de 30 min, réservé au MÉDECIN de ce rendez-vous précis.
           N'apparaît qu'une fois le RDV confirmé : sur les autres statuts (refusé, annulé...), il
           n'y a plus rien à consulter. L'API refuse (403/404/409) si ce n'est ni le bon compte,
           ni le bon état — ce bloc n'affiche que ce qui est probable, comme les actions ci-dessus. --}}
      @if ($rdv->statut === 'confirme')
        <div class="card border-0 shadow-sm mt-3">
          <div class="card-header bg-white fw-medium text-ms"><i class="bi bi-broadcast"></i> Accès partagé au dossier</div>
          <div class="card-body">
            @if ($partageActif)
              <p class="small mb-2">
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                  <i class="bi bi-unlock"></i> Accès ouvert — expire dans {{ intdiv($partageSecondesRestantes, 60) }} min
                </span>
              </p>
              <a href="{{ route('portail.dossier.show') }}" class="btn btn-primary w-100 mb-2">
                <i class="bi bi-journal-medical"></i> Écrire au carnet
              </a>
              <form method="POST" action="{{ route('portail.rdv.partage.fermer', $rdv) }}">
                @csrf @method('DELETE')
                <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-lock"></i> Terminer</button>
              </form>
            @else
              <p class="small text-muted mb-2">
                Ouvre le dossier de ce patient pour 30 minutes — visible en direct par le patient sur son téléphone.
                @unless ($rdv->estEnregistre())
                  Le patient doit d'abord être enregistré à l'accueil (scan du reçu).
                @endunless
              </p>
              <form method="POST" action="{{ route('portail.rdv.partage.ouvrir', $rdv) }}">
                @csrf
                <button class="btn btn-ms w-100" type="submit" @unless($rdv->estEnregistre()) disabled @endunless>
                  <i class="bi bi-unlock"></i> Ouvrir mon accès (30 min)
                </button>
              </form>
            @endif
          </div>
        </div>
      @endif

      {{-- B1-d (D10) — clôture du RDV lui-même, distincte de l'accès partagé ci-dessus. Le règlement
           est vérifié AVANT le bouton, jamais après : un rendez-vous qui en arrive là est réglé
           dans tous les cas que ce projet sait construire aujourd'hui (le check-in exige déjà un
           reçu payé), mais le montrer plutôt que de le supposer coûte une phrase. --}}
      <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-white fw-medium"><i class="bi bi-flag"></i> Clore le rendez-vous</div>
        <div class="card-body">
          @if ($reglementVerifie && $rdv->estEnregistre())
            <p class="small text-muted mb-2">Marque ce rendez-vous comme honoré. Action définitive.</p>
            <form method="POST" action="{{ route('portail.rdv.terminer', $rdv) }}">
              @csrf @method('PATCH')
              <button class="btn btn-outline-primary w-100" type="submit"
                      onsubmit="return confirm('Clore ce rendez-vous ?');">
                <i class="bi bi-check2-all"></i> Clore le rendez-vous
              </button>
            </form>
          @elseif (! $rdv->estEnregistre())
            <p class="small text-danger mb-0">
              <i class="bi bi-exclamation-triangle"></i> Le patient doit d'abord être enregistré à l'accueil.
            </p>
          @else
            <p class="small text-danger mb-0">
              <i class="bi bi-exclamation-triangle"></i> Le règlement de ce rendez-vous n'est pas encore vérifié — la clôture est bloquée.
            </p>
          @endif
        </div>
      </div>
    @endif
  </div>
</div>
@endsection
