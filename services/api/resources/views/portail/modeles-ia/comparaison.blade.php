@extends('portail.layout')

{{--
  P10c-3-ii lot B (F29) — La confrontation prédiction ⇄ verdict du soignant.

  Sans investissement de design (K1, P6.4d) : le portail Blade est condamné par ADR-011, et y
  bâtir un second design system par-dessus Bootstrap serait un doublon pour un écran qui sera
  migré. Ce qui compte ici est ce que l'écran DIT, pas comment il le montre.
--}}

@section('titre', 'Comparaison — modèle version '.$comparaison['version']->numero_version)

@section('contenu')
<div class="container-fluid py-3">

  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h4 mb-1">
        Modèle version {{ $comparaison['version']->numero_version }}
        <span class="badge bg-secondary">{{ $comparaison['version']->statut }}</span>
      </h1>
      <div class="text-muted small">
        Run MLflow <code>{{ $comparaison['version']->mlflow_run_id }}</code>
      </div>
    </div>
    <a href="{{ route('portail.modeles-ia.index') }}" class="btn btn-sm btn-outline-secondary">Retour</a>
  </div>

  {{--
    CE BANDEAU N'EST PAS DÉCORATIF. Sans lui, un lecteur pressé prendrait cet écran pour une
    mesure de la qualité du triage. Il mesure autre chose : l'accord entre un modèle et des
    soignants, sur un échantillon qui n'a pas été constitué pour être représentatif.
  --}}
  <div class="alert alert-info small">
    <strong>Ce que cet écran mesure — et ce qu'il ne mesure pas.</strong>
    Le modèle prédit <em>si un soignant jugera l'orientation du protocole adaptée</em> ; il ne pose
    aucun diagnostic et ne décide d'aucun niveau d'urgence. Sa prédiction
    <strong>n'a influencé aucune décision de soins</strong> — elle est enregistrée en mode
    observation et n'apparaît nulle part dans le parcours du patient, précisément pour que le
    verdict du soignant reste indépendant de ce que le modèle en pensait.
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">Prédictions en observation</div>
        <div class="h4 mb-0">{{ $comparaison['nb_predictions'] }}</div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">Couples prédiction / verdict</div>
        <div class="h4 mb-0">{{ $comparaison['nb_couples'] }}</div>
        {{-- Un triage jugé deux fois compte DEUX fois : écarter l'un reviendrait à choisir à la
             place du médecin qui l'a validé (F13). --}}
        <div class="form-text small">Un triage jugé deux fois compte deux fois.</div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">Concordance</div>
        <div class="h4 mb-0">
          @if ($comparaison['concordance'] === null)
            <span class="text-muted">—</span>
          @else
            {{ number_format($comparaison['concordance'] * 100, 1) }} %
          @endif
        </div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">Latence moyenne</div>
        <div class="h4 mb-0">
          {{ $comparaison['latence_moyenne_ms'] === null ? '—' : $comparaison['latence_moyenne_ms'].' ms' }}
        </div>
      </div></div>
    </div>
  </div>

  {{--
    LE CHIFFRE QUI COMPTE VRAIMENT, ET IL EST SEUL SUR SA LIGNE.
    Un sur-triage coûte une place aux urgences ; un sous-triage peut coûter la vie. Noyer ce rappel
    parmi les autres métriques laisserait croire qu'ils se valent.
  --}}
  <div class="card mb-3 border-warning">
    <div class="card-body">
      <h2 class="h6">Rappel sur les <strong>sous-triages</strong> — le seul écart qui se paie cher</h2>
      <p class="small text-muted mb-2">
        Parmi les orientations qu'un soignant a jugées <em>trop basses</em>, combien le modèle
        avait-il vues ? C'est la seule métrique qui dit s'il rate le cas dangereux.
      </p>
      <div class="row">
        <div class="col-sm-6">
          <div class="text-muted small">Au jeu de test (à l'entraînement)</div>
          <div class="h5">
            @if ($comparaison['rappel_sous_triage_test'] === null)
              <span class="text-muted">non mesuré</span>
            @else
              {{ number_format($comparaison['rappel_sous_triage_test'] * 100, 1) }} %
            @endif
          </div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted small">En production (mesuré ici)</div>
          <div class="h5">
            @if ($comparaison['rappel_sous_triage_production'] === null)
              {{-- `null` et non « 0 % » : afficher zéro alors qu'il n'y a rien à rappeler serait
                   une accusation gratuite. --}}
              <span class="text-muted">aucun sous-triage constaté à ce jour</span>
            @else
              {{ number_format($comparaison['rappel_sous_triage_production'] * 100, 1) }} %
            @endif
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <h2 class="h6">Matrice de confusion en production <span class="text-muted small">(§8)</span></h2>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="min-width:12rem">Prédit \ Jugé par le soignant</th>
              @foreach ($classes as $classe)
                <th class="text-center">{{ $libelles[$classe] ?? $classe }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach ($classes as $predit)
              <tr>
                <th class="table-light">{{ $libelles[$predit] ?? $predit }}</th>
                @foreach ($classes as $reel)
                  <td class="text-center {{ $predit === $reel ? 'table-success' : '' }}">
                    {{ $comparaison['matrice'][$predit][$reel] }}
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="form-text small">
        La diagonale est l'accord. Hors diagonale : la ligne dit ce que le modèle a prédit, la
        colonne ce que le soignant a jugé.
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <h2 class="h6 mb-0">Dérives constatées <span class="text-muted small">(§8)</span></h2>
        <form method="POST" action="{{ route('portail.modeles-ia.derive') }}">
          @csrf
          <button class="btn btn-sm btn-outline-secondary">Analyser maintenant</button>
        </form>
      </div>

      {{-- DÉTECTION SEULE : aucune action n'est proposée depuis cet écran. --}}
      <p class="small text-muted">
        Une dérive <strong>ne retire jamais un modèle du service</strong> : elle prévient, et la
        décision reste humaine. Pour revenir à une version antérieure, utilisez la mise en service
        depuis la liste des modèles.
      </p>

      @if ($derives->isEmpty())
        <p class="text-muted small mb-0">
          Aucune dérive enregistrée. Une absence d'alerte se lit à l'absence de ligne — rien n'est
          écrit quand tout est stable.
        </p>
      @else
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Date</th><th>Nature</th><th>Indicateur</th><th>Niveau</th>
                <th class="text-end">Valeur</th><th class="text-end">Seuil</th><th class="text-end">Échantillons</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($derives as $derive)
                <tr>
                  <td>{{ $derive->date_rapport->format('d/m/Y') }}</td>
                  <td>{{ $derive->nature === 'entree' ? 'Population' : 'Performance' }}</td>
                  <td><code>{{ $derive->indicateur }}</code></td>
                  <td>
                    <span class="badge {{ $derive->niveau === 'leger' ? 'bg-warning text-dark' : 'bg-danger' }}">
                      {{ $derive->niveau }}
                    </span>
                  </td>
                  <td class="text-end">{{ $derive->valeur }}</td>
                  <td class="text-end text-muted">{{ $derive->seuil }}</td>
                  <td class="text-end text-muted small">
                    {{ $derive->nb_lignes_reference }} / {{ $derive->nb_lignes_observees }}
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <div class="form-text small">
          « Population » : les patients d'aujourd'hui ne ressemblent plus à ceux sur lesquels le
          modèle a appris. « Performance » : le rappel sur les sous-triages a chuté. Les deux ne se
          soignent pas de la même façon — c'est pourquoi ils ne sont jamais fondus en un seul chiffre.
        </div>
      @endif
    </div>
  </div>

</div>
@endsection
