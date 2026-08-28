@extends('portail.layout')

@section('titre', "Jeu d'apprentissage IA")

@section('content')
<div class="mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">
    <i class="bi bi-clipboard2-pulse"></i> Revue du jeu d'apprentissage
  </h1>
  <p class="text-muted mb-0">
    CDC_05 §7.2 — un médecin juge un cas <strong>pseudonymisé</strong>, sans savoir de qui il s'agit.
    Une ligne non validée n'entrera jamais dans un export d'entraînement.
  </p>
</div>

@if (session('statut'))
  <div class="alert alert-success">{{ session('statut') }}</div>
@endif
@error('decision')
  <div class="alert alert-danger">{{ $message }}</div>
@enderror

<div class="alert alert-warning d-flex gap-2">
  <i class="bi bi-shield-lock"></i>
  <div class="small">
    Aucune identité n'est affichée ici, et ne peut pas l'être : cette table ne porte ni nom, ni
    identifiant de compte, ni NIS. C'est cela, la pseudonymisation.
  </div>
</div>

<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead>
      <tr>
        <th>#</th>
        <th>Âge / Sexe</th>
        <th>Symptômes</th>
        <th>Constantes</th>
        <th>Niveau rendu</th>
        <th>Label proposé</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @forelse ($lignes as $ligne)
        <tr>
          <td class="text-muted">{{ $ligne->id }}</td>
          <td>{{ $ligne->age ?? '—' }} / {{ $ligne->sexe ?? '—' }}</td>
          <td class="small">{{ implode(', ', $ligne->symptomes_json ?? []) }}</td>
          <td class="small">
            @foreach (['temperature', 'pouls', 'saturation_o2', 'tension_systolique', 'tension_diastolique', 'poids'] as $type)
              @if ($ligne->{$type} !== null)
                {{ $type }}={{ $ligne->{$type} }}
              @endif
            @endforeach
          </td>
          <td><span class="badge bg-secondary">{{ $ligne->niveau_protocole }}</span></td>
          <td>{{ $ligne->label }}</td>
          <td class="text-end">
            <form method="POST" action="{{ route('portail.apprentissage.valider', $ligne->id) }}" class="d-inline">
              @csrf
              <button type="submit" class="btn btn-sm btn-outline-success">Valider</button>
            </form>
            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse"
                    data-bs-target="#rejet-{{ $ligne->id }}">Rejeter</button>
            <div class="collapse mt-2" id="rejet-{{ $ligne->id }}">
              <form method="POST" action="{{ route('portail.apprentissage.rejeter', $ligne->id) }}">
                @csrf
                <div class="input-group input-group-sm">
                  <input type="text" name="motif" class="form-control" placeholder="Motif du rejet" required>
                  <button type="submit" class="btn btn-danger">Confirmer</button>
                </div>
              </form>
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="text-muted text-center py-4">Aucune ligne en attente de revue.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
@endsection
