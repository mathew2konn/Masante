@extends('portail.layout')

@section('titre', 'Modifier · ' . $medecin->nom_complet)

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.medecins.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Modifier le praticien</h1>
  @if ($medecin->numero_professionnel)
    <span class="badge text-bg-light border ms-2" title="Numéro national — attribué par la plateforme, non modifiable">
      {{ $medecin->numero_professionnel }}
    </span>
  @endif
</div>

<form method="POST" action="{{ route('portail.medecins.update', $medecin) }}">
  @csrf @method('PUT')
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      @include('portail.medecins._form', ['medecin' => $medecin])
    </div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
    <a href="{{ route('portail.medecins.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>

{{-- ═══ Lieux d'exercice (CDC_09 §5.2) ═══

     Formulaire SÉPARÉ de la fiche, et pas par confort de mise en page : fondus, un exercice
     refusé (doublon, dates incohérentes) ferait perdre les trente champs déjà saisis au-dessus.
     Même raisonnement qu'en P6.4c, où le dépôt d'image a été séparé de la fiche d'établissement. --}}
<div class="card border-0 shadow-sm mt-4">
  <div class="card-body">
    <h2 class="h5 mb-1" style="color:var(--ms-blue-dark)">Lieux d'exercice</h2>
    <p class="small text-muted">
      L'établissement <strong>principal</strong> vient de la fiche ci-dessus : il ne se retire pas ici.
    </p>

    @error('exercice') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

    <ul class="list-group list-group-flush mb-3">
      @forelse ($medecin->exercices as $exercice)
        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
          <span>
            {{ $exercice->structure?->nom ?? 'Établissement supprimé' }}
            @if ($exercice->structure?->identifiant_national)
              <span class="text-muted small">({{ $exercice->structure->identifiant_national }})</span>
            @endif
            @if ($exercice->est_principal)
              <span class="badge text-bg-primary ms-1">Principal</span>
            @endif
            @unless ($exercice->actif)
              <span class="badge text-bg-secondary ms-1">Inactif</span>
            @endunless
          </span>
          @if ($peutHabiliter && ! $exercice->est_principal)
            <form method="POST" action="{{ route('portail.medecins.exercices.destroy', [$medecin, $exercice]) }}">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-outline-danger" type="submit">Retirer</button>
            </form>
          @endif
        </li>
      @empty
        <li class="list-group-item px-0 text-muted">
          Aucun lieu d'exercice enregistré — lancez
          <code>php artisan masante:professionnels:backfill</code>.
        </li>
      @endforelse
    </ul>

    @if ($peutHabiliter)
      <form method="POST" action="{{ route('portail.medecins.exercices.store', $medecin) }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-md-6">
          <label class="form-label">Ajouter un établissement</label>
          <select name="structure_id" class="form-select @error('structure_id') is-invalid @enderror" required>
            <option value="">— Choisir —</option>
            @foreach ($structures as $structure)
              <option value="{{ $structure->id }}">
                {{ $structure->nom }}@if ($structure->identifiant_national) — {{ $structure->identifiant_national }}@endif
              </option>
            @endforeach
          </select>
          @error('structure_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-2">
          <label class="form-label">Début</label>
          <input type="date" name="debut_le" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Fin</label>
          <input type="date" name="fin_le" class="form-control @error('fin_le') is-invalid @enderror">
          @error('fin_le') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-2">
          <button class="btn btn-outline-primary w-100" type="submit">Ajouter</button>
        </div>
      </form>
    @else
      <p class="small text-muted mb-0">
        Déclarer un lieu d'exercice supplémentaire relève d'un compte habilité : un établissement ne
        se rattache pas seul le praticien d'un autre.
      </p>
    @endif
  </div>
</div>
@endsection
