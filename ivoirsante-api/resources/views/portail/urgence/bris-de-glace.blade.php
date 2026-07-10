@extends('portail.layout')

@section('titre', 'Accès d\'urgence')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-0 text-danger"><i class="bi bi-exclamation-octagon"></i> Accès d'urgence au dossier</h1>
    <p class="text-muted mb-0">{{ $agent->structure->nom ?? '' }} · {{ $agent->service->nom_service ?? '' }}</p>
  </div>
  <a href="{{ route('portail.dashboard') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Tableau de bord
  </a>
</div>

{{-- L'agent doit comprendre ce qu'il engage AVANT de remplir le formulaire, pas après. --}}
<div class="alert alert-danger">
  <h2 class="h6 mb-2"><i class="bi bi-shield-exclamation"></i> Procédure exceptionnelle</h2>
  <p class="small mb-2">
    Réservée au patient <strong>hors d'état de consentir</strong>, lorsque ni le titulaire du carnet ni un
    délégué n'est joignable. En temps normal, demandez au patient de présenter son QR Code.
  </p>
  <ul class="small mb-0">
    <li>Le <strong>titulaire du carnet et ses contacts d'urgence sont prévenus immédiatement</strong>.</li>
    <li>Votre nom, l'heure, votre justification et les données consultées sont <strong>inscrits au journal
        d'audit</strong>, que le patient peut consulter.</li>
    <li>Vous n'obtiendrez que les <strong>informations vitales</strong> (groupe sanguin, allergies, traitements,
        contacts) pendant <strong>15 minutes</strong>. Ni l'historique des consultations, ni les documents,
        ni les notes médicales.</li>
  </ul>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body">
    <form method="POST" action="{{ route('portail.urgence.bris.ouvrir') }}">
      @csrf

      <h2 class="h6 text-ms mb-3">1. Identifier le patient</h2>
      <p class="text-muted small">
        Les trois informations doivent correspondre <strong>exactement</strong> à celles du carnet. Aucune
        recherche par approximation n'est possible : relevez-les sur une pièce d'identité, une carte CMU, ou
        auprès d'un proche présent.
      </p>

      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label for="nom" class="form-label">Nom</label>
          <input type="text" id="nom" name="nom" maxlength="100" required
                 class="form-control @error('nom') is-invalid @enderror" value="{{ old('nom') }}">
          @error('nom')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
          <label for="prenom" class="form-label">Prénom</label>
          <input type="text" id="prenom" name="prenom" maxlength="100" required
                 class="form-control @error('prenom') is-invalid @enderror" value="{{ old('prenom') }}">
          @error('prenom')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
          <label for="date_naissance" class="form-label">Date de naissance</label>
          <input type="date" id="date_naissance" name="date_naissance" required max="{{ date('Y-m-d') }}"
                 class="form-control @error('date_naissance') is-invalid @enderror" value="{{ old('date_naissance') }}">
          @error('date_naissance')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
      </div>

      <h2 class="h6 text-ms mb-3">2. Justifier l'urgence</h2>
      <div class="mb-4">
        <label for="motif" class="form-label">
          Motif de l'urgence et mode d'identification du patient
        </label>
        <textarea id="motif" name="motif" rows="3" minlength="20" maxlength="1000" required
                  class="form-control @error('motif') is-invalid @enderror"
                  placeholder="Ex. : Patiente inconsciente admise aux urgences à 14h20 après un accident de la voie publique. Identifiée par sa carte nationale d'identité. Titulaire du carnet injoignable.">{{ old('motif') }}</textarea>
        @error('motif')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">
          Cette justification est conservée dans le journal d'audit et lisible par le patient. Soyez précis.
        </div>
      </div>

      <button type="submit" class="btn btn-danger btn-lg w-100">
        <i class="bi bi-unlock"></i> Ouvrir l'accès d'urgence (15 minutes)
      </button>
    </form>
  </div>
</div>
@endsection
