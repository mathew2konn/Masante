@extends('portail.layout')

@section('titre', 'Protocoles médicaux')

@section('content')
<div class="mb-4">
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">
    <i class="bi bi-file-medical"></i> Protocoles médicaux
  </h1>
  <p class="text-muted mb-0">
    Relecture et signature des quatre validations du CDC_08 §7. Cet écran ne modifie aucun protocole.
  </p>
</div>

@if (session('succes'))
  <div class="alert alert-success">{{ session('succes') }}</div>
@endif
@if (session('erreur'))
  <div class="alert alert-danger">{{ session('erreur') }}</div>
@endif

{{--
  ═══ CE QUE CET ÉCRAN NE FAIT PAS, ET IL FAUT LE DIRE AVANT LA LISTE ═══

  Il ne rédige pas. Un éditeur de règles complet serait le plus gros investissement de ce portail,
  qu'ADR-011 destine par ailleurs à être remplacé. Ce que le §7 demande, c'est qu'un texte soit RELU
  et SIGNÉ sous une forme lisible — pas qu'il soit édité ici.
--}}
<div class="alert alert-secondary d-flex gap-2">
  <i class="bi bi-info-circle"></i>
  <div class="small">
    Les brouillons sont préparés hors de cet écran. Ici, vous les <strong>relisez</strong> et vous
    <strong>signez</strong> l'une des quatre validations du §7 — clinique, réglementaire,
    scientifique, technique. Une version ne peut être mise en vigueur qu'avec les quatre, et par
    une personne différente de son rédacteur (§10).
  </div>
</div>

<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>Code</th>
        <th>Titre</th>
        <th>Contextes</th>
        <th>Versions</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($protocoles as $protocole)
        <tr>
          <td><code>{{ $protocole->code }}</code></td>
          <td>
            {{ $protocole->titre }}
            <div class="small text-muted">{{ $protocole->domaine }} · rang {{ $protocole->niveau_source }}</div>
          </td>
          <td class="small">
            @forelse ($protocole->contextes_json ?? [] as $contexte)
              <span class="badge bg-light text-dark">{{ $contextes[$contexte] ?? $contexte }}</span>
            @empty
              {{-- Un protocole sans contexte ne serait jamais sélectionné : le dire, pas le taire. --}}
              <span class="text-danger">aucun — ne s'appliquera nulle part</span>
            @endforelse
          </td>
          <td>
            @foreach ($protocole->versions as $version)
              <a href="{{ route('portail.protocoles.show', [$protocole, $version]) }}"
                 class="badge text-decoration-none
                 @class([
                   'bg-success' => $version->etat === 'active',
                   'bg-warning text-dark' => $version->etat === 'brouillon',
                   'bg-secondary' => ! in_array($version->etat, ['active', 'brouillon'], true),
                 ])">
                {{ $version->libelle }} · {{ $version->etat }}
              </a>
            @endforeach
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="text-muted">Aucun protocole enregistré.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
@endsection
