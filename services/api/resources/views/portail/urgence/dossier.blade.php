@extends('portail.layout')

@section('titre', 'Fiche vitale d\'urgence')

@section('content')
<div class="card border-danger shadow-sm mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
      <h1 class="h4 mb-1 text-danger">
        <i class="bi bi-exclamation-octagon"></i> {{ $fiche['prenom'] }} {{ $fiche['nom'] }}
      </h1>
      <p class="text-muted small mb-0">
        Accès d'urgence · informations vitales uniquement · le titulaire a été notifié
      </p>
    </div>
    <div class="d-flex align-items-center gap-3">
      <div class="text-end">
        <div class="small text-muted">Fermeture automatique dans</div>
        <div class="fs-5 fw-semibold text-danger" id="compte-a-rebours">--:--</div>
      </div>
      <form method="POST" action="{{ route('portail.urgence.fermer') }}" class="m-0">
        @csrf
        <button class="btn btn-outline-danger" type="submit"><i class="bi bi-x-circle"></i> Fermer</button>
      </form>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms mb-3">Identité</h2>
        <dl class="row mb-0">
          <dt class="col-6">Âge</dt><dd class="col-6">{{ $fiche['age'] !== null ? $fiche['age'].' ans' : '—' }}</dd>
          <dt class="col-6">Sexe</dt><dd class="col-6">{{ $fiche['sexe'] ?? '—' }}</dd>
        </dl>

        <div class="mt-3 p-3 rounded bg-danger-subtle d-flex justify-content-between align-items-center">
          <span class="fw-semibold text-danger-emphasis">Groupe sanguin</span>
          <span class="fs-3 fw-bold text-danger">{{ $fiche['groupe_sanguin'] ?? 'Inconnu' }}</span>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-danger shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-danger mb-3"><i class="bi bi-exclamation-triangle"></i> Allergies</h2>
        @forelse ($fiche['allergies'] as $allergie)
          <div class="fw-semibold text-danger-emphasis">• {{ $allergie }}</div>
        @empty
          <p class="text-muted mb-0">Aucune allergie connue.</p>
        @endforelse
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms mb-3"><i class="bi bi-capsule"></i> Maladies chroniques</h2>
        @forelse ($fiche['maladies_chroniques'] as $maladie)
          <div class="border-bottom py-2">
            <div>{{ $maladie['libelle'] }}</div>
            @if ($maladie['traitement'])
              <div class="small text-muted">Traitement : {{ $maladie['traitement'] }}</div>
            @endif
          </div>
        @empty
          <p class="text-muted mb-0">Aucune maladie chronique connue.</p>
        @endforelse
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms mb-3"><i class="bi bi-telephone"></i> Contacts d'urgence</h2>
        @forelse ($fiche['contacts_urgence'] as $contact)
          <div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div>
              <strong>{{ $contact['nom'] }}</strong>
              <span class="text-muted small">· {{ $contact['lien'] }}</span>
            </div>
            <a href="tel:{{ $contact['telephone'] }}" class="font-monospace text-decoration-none">
              {{ $contact['telephone'] }}
            </a>
          </div>
        @empty
          <p class="text-muted mb-0">Aucun contact d'urgence enregistré.</p>
        @endforelse
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 text-ms mb-3"><i class="bi bi-shield-check"></i> Vaccinations essentielles</h2>
        @forelse ($fiche['vaccinations_essentielles'] as $vaccin)
          <div class="border-bottom py-2">
            {{ $vaccin['vaccin'] }}
            @if ($vaccin['date'])
              <span class="text-muted small">— {{ \Illuminate\Support\Carbon::parse($vaccin['date'])->format('d/m/Y') }}</span>
            @endif
          </div>
        @empty
          <p class="text-muted mb-0">Aucune vaccination essentielle enregistrée.</p>
        @endforelse
      </div>
    </div>
  </div>
</div>

<div class="alert alert-secondary small mt-4 mb-0">
  <i class="bi bi-shield-lock"></i>
  <strong>Périmètre vital minimal.</strong> L'historique des consultations, les documents importés, les notes
  médicales et les données des autres membres du carnet ne sont pas accessibles par cette voie. Pour le dossier
  complet, demandez au patient — ou au titulaire du carnet — de présenter son QR Code.
</div>

<script>
  // À l'échéance des 15 minutes, la page se recharge : le contrôleur clôt la session (ligne
  // d'audit avec la durée réelle) et renvoie au formulaire. Un nouvel accès exige une nouvelle
  // justification.
  (function () {
    let restant = {{ $restant }};
    const cible = document.getElementById('compte-a-rebours');

    setInterval(function () {
      if (restant <= 0) { window.location.reload(); return; }
      restant--;
      const m = String(Math.floor(restant / 60)).padStart(2, '0');
      const s = String(restant % 60).padStart(2, '0');
      cible.textContent = m + ':' + s;
    }, 1000);
  })();
</script>
@endsection
