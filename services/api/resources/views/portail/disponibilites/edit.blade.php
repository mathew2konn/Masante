@extends('portail.layout')

@section('titre', 'Disponibilité · ' . $service->nom_service)

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.disponibilites.index', ['date' => $date]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">{{ $service->nom_service }}</h1>
</div>

<form method="POST" action="{{ route('portail.disponibilites.update', $service) }}">
  @csrf @method('PUT')
  <input type="hidden" name="date" value="{{ $date }}">

  <div class="card border-0 shadow-sm mb-3" style="max-width:640px">
    <div class="card-body">
      <p class="text-muted small mb-3">
        Disponibilité du <strong>{{ \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') }}</strong>.
        Visible en temps réel par les patients sur la carte.
      </p>

      <div class="mb-3">
        <label class="form-label">Statut <span class="text-danger">*</span></label>
        <select name="statut" class="form-select @error('statut') is-invalid @enderror" required>
          @foreach ($statuts as $cle => $libelle)
            <option value="{{ $cle }}" @selected(old('statut', $dispo->statut ?? 'disponible') === $cle)>{{ $libelle }}</option>
          @endforeach
        </select>
        @error('statut') <div class="invalid-feedback">{{ $message }}</div> @enderror
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Places restantes</label>
          <input type="number" min="0" max="1000" name="nb_places_restantes" class="form-control @error('nb_places_restantes') is-invalid @enderror"
                 value="{{ old('nb_places_restantes', $dispo->nb_places_restantes ?? '') }}" placeholder="Optionnel">
          @error('nb_places_restantes') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6">
          <label class="form-label">Heure de début</label>
          <input type="time" name="heure_debut_dispo" class="form-control @error('heure_debut_dispo') is-invalid @enderror"
                 value="{{ old('heure_debut_dispo', $dispo && $dispo->heure_debut_dispo ? \Illuminate\Support\Carbon::parse($dispo->heure_debut_dispo)->format('H:i') : '') }}">
          @error('heure_debut_dispo') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
      </div>

      <div class="mt-3">
        <label class="form-label">Note <span class="text-muted small">(optionnel)</span></label>
        <textarea name="note" class="form-control @error('note') is-invalid @enderror" rows="2" maxlength="500"
                  placeholder="Ex. Urgences saturées, privilégier l'après-midi.">{{ old('note', $dispo->note ?? '') }}</textarea>
        @error('note') <div class="invalid-feedback">{{ $message }}</div> @enderror
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
    <a href="{{ route('portail.disponibilites.index', ['date' => $date]) }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>
@endsection
