{{-- Tuile d'indicateur. Paramètres : $valeur, $libelle, $icone, $couleur (classe texte Bootstrap). --}}
<div class="col-6 col-lg-3">
  <div class="card border-0 shadow-sm h-100">
    <div class="card-body">
      <div class="{{ $couleur ?? 'text-ms' }} mb-1" style="font-size:1.5rem"><i class="bi {{ $icone }}"></i></div>
      <div class="fs-3 fw-semibold lh-1">{{ $valeur }}</div>
      <div class="text-muted small mt-1">{{ $libelle }}</div>
    </div>
  </div>
</div>
