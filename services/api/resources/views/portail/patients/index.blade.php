@extends('portail.layout')

@section('titre', 'Mes patients suivis')

@section('content')
{{-- Module 5 / 5.6 — Voie 2 : les patients qui ont désigné ce praticien médecin référent.
     Pas de recherche, pas d'annuaire : cette liste n'existe que parce que des patients l'ont
     ouverte. Chaque ouverture de dossier est journalisée et notifiée au titulaire. --}}
<div class="d-flex justify-content-between align-items-center mb-1">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Mes patients suivis</h1>
  <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
    {{ $medecin->nom_complet }} · {{ $medecin->specialite }}
  </span>
</div>
<p class="text-muted">
  Ces patients vous ont désigné comme <strong>médecin référent</strong> depuis leur application.
  Vous pouvez ouvrir leur dossier sans QR Code, pour 30 minutes. Chaque accès est journalisé et le
  titulaire du carnet en est informé. Une révocation par le patient retire immédiatement le dossier de cette liste.
</p>

@if ($patients->isEmpty())
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">
      <div class="mb-2" style="font-size:2rem"><i class="bi bi-clipboard2-heart"></i></div>
      <p class="mb-1">Aucun patient ne vous a désigné comme médecin référent.</p>
      <p class="small mb-0">La désignation se fait depuis l'application du patient, dans la fiche du membre concerné.</p>
    </div>
  </div>
@else
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Patient</th>
            <th>Né(e) le</th>
            <th>Groupe sanguin</th>
            <th class="text-end">Dossier</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($patients as $patient)
            <tr>
              <td class="fw-semibold">{{ $patient->prenom }} {{ $patient->nom }}</td>
              <td>{{ $patient->date_naissance?->format('d/m/Y') }}</td>
              <td>
                @if ($patient->groupe_sanguin)
                  <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                    {{ $patient->groupe_sanguin }}
                  </span>
                @else
                  <span class="text-muted">—</span>
                @endif
              </td>
              <td class="text-end">
                <form method="POST" action="{{ route('portail.patients.ouvrir', $patient) }}" class="m-0">
                  @csrf
                  <button class="btn btn-sm btn-ms" type="submit">
                    <i class="bi bi-folder2-open"></i> Ouvrir (30 min)
                  </button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif
@endsection
