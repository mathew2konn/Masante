@extends('portail.layout')

@section('titre', 'Rendez-vous')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.rdv.index', ['statut' => $rdv->statut]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Demande de rendez-vous</h1>
</div>

<div class="row g-3">
  {{-- Détails de la demande --}}
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4 text-muted">Patient</dt>
          <dd class="col-sm-8">{{ $rdv->membre?->prenom }} {{ $rdv->membre?->nom }}</dd>

          <dt class="col-sm-4 text-muted">Service</dt>
          <dd class="col-sm-8">{{ $rdv->service?->nom_service }} <span class="text-muted small">({{ $rdv->service?->specialite }})</span></dd>

          <dt class="col-sm-4 text-muted">Date souhaitée</dt>
          <dd class="col-sm-8">{{ \Illuminate\Support\Carbon::parse($rdv->date_souhaitee)->format('d/m/Y') }}</dd>

          <dt class="col-sm-4 text-muted">Attribution</dt>
          <dd class="col-sm-8">
            {{ $rdv->mode_attribution === 'patient_choisit' ? 'Médecin choisi par le patient' : 'Attribué par l\'établissement' }}
            @if ($rdv->medecin) — <strong>{{ $rdv->medecin->titre }} {{ $rdv->medecin->nom }}</strong> @endif
          </dd>

          <dt class="col-sm-4 text-muted">Motif</dt>
          <dd class="col-sm-8">{{ $rdv->motif }}</dd>

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

  {{-- Actions --}}
  <div class="col-lg-5">
    @if ($rdv->statut === 'en_attente')
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-medium text-success"><i class="bi bi-check-circle"></i> Confirmer</div>
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
    @else
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
    @endif
  </div>
</div>
@endsection
