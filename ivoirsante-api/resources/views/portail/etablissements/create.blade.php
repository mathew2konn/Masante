@extends('portail.layout')

@section('titre', 'Nouvel établissement')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.etablissements.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Nouvel établissement</h1>
</div>

<form method="POST" action="{{ route('portail.etablissements.store') }}">
  @csrf

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-medium"><i class="bi bi-hospital text-ms"></i> Établissement</div>
    <div class="card-body">
      @include('portail.etablissements._form', ['etablissement' => null])
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-medium"><i class="bi bi-person-badge text-ms"></i> Compte gestionnaire</div>
    <div class="card-body">
      <p class="text-muted small">
        Le gestionnaire recevra un <strong>lien d'activation</strong> pour créer lui-même son mot de passe
        (aucun mot de passe temporaire — sécurité renforcée, §5.4.1).
      </p>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Prénom <span class="text-danger">*</span></label>
          <input type="text" name="gestionnaire_prenom" class="form-control @error('gestionnaire_prenom') is-invalid @enderror"
                 value="{{ old('gestionnaire_prenom') }}" required maxlength="100">
          @error('gestionnaire_prenom') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-4">
          <label class="form-label">Nom <span class="text-danger">*</span></label>
          <input type="text" name="gestionnaire_nom" class="form-control @error('gestionnaire_nom') is-invalid @enderror"
                 value="{{ old('gestionnaire_nom') }}" required maxlength="100">
          @error('gestionnaire_nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-4">
          <label class="form-label">E-mail <span class="text-danger">*</span></label>
          <input type="email" name="gestionnaire_email" class="form-control @error('gestionnaire_email') is-invalid @enderror"
                 value="{{ old('gestionnaire_email') }}" required maxlength="190">
          @error('gestionnaire_email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Créer l'établissement</button>
    <a href="{{ route('portail.etablissements.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
