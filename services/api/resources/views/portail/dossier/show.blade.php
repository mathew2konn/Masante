@extends('portail.layout')

@section('titre', 'Dossier patient')

@section('content')
@php
  $niveaux = ['leger' => 'success', 'modere' => 'warning', 'urgent' => 'danger'];
@endphp

{{-- Bandeau : à qui appartient ce dossier, et combien de temps il reste ouvert. --}}
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
      <h1 class="h4 mb-1" style="color:var(--ms-blue-dark)">
        <i class="bi bi-folder2-open"></i> {{ $membre->prenom }} {{ $membre->nom }}
      </h1>
      <p class="text-muted small mb-0">
        Dossier ouvert · accès journalisé ·
        @if ($peutEcrire)
          <span class="text-success fw-semibold">vous pouvez consigner un acte</span>
        @else
          lecture seule
        @endif
      </p>
    </div>
    <div class="d-flex align-items-center gap-3">
      <div class="text-end">
        <div class="small text-muted">Fermeture automatique dans</div>
        <div class="fs-5 fw-semibold text-ms" id="compte-a-rebours">--:--</div>
      </div>
      <form method="POST" action="{{ route('portail.dossier.fermer') }}" class="m-0">
        @csrf
        <button class="btn btn-outline-danger" type="submit">
          <i class="bi bi-x-circle"></i> Fermer le dossier
        </button>
      </form>
    </div>
  </div>
</div>

{{-- B2-a — LA CONSULTATION (CDC_11 §5.2).

     Distincte du bandeau ci-dessus, qui parle de l'ACCÈS au dossier (une fenêtre de lecture,
     journalisée). Celle-ci parle de l'ACTE DE SOIN : ce que le médecin fait pendant que la
     fenêtre est ouverte. Les deux ne se confondent pas — un accès existe sans consultation. --}}
@if (session('succes'))
  <div class="alert alert-success py-2">{{ session('succes') }}</div>
@endif

@if ($peutMenerConsultation)
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      {{-- Défaut trouvé AU G2 LIVE, invisible en test : le service refusait correctement une
           observation vide, la base le confirmait — et l'écran ne disait RIEN. Le médecin voyait
           sa page recharger sans son texte et sans explication. Les clés sont NOMMÉES plutôt que
           `$errors->any()` : `formulaire.blade.php`, incluse plus bas, affiche déjà les erreurs
           de section, et un bloc global les montrerait deux fois. --}}
      @foreach (['consultation', 'motif', 'contenu'] as $cle)
        @error($cle)
          <div class="alert alert-danger py-2">{{ $message }}</div>
        @enderror
      @endforeach
      @if ($consultation === null)
        <h2 class="h6 text-ms mb-2"><i class="bi bi-clipboard2-pulse"></i> Consultation</h2>
        <p class="text-muted small">
          Ouvrez une consultation pour rattacher à un même acte les observations et ce que vous
          consignerez dans le carnet.
        </p>
        <form method="POST" action="{{ route('portail.dossier.consultation.ouvrir') }}"
              class="row g-2 align-items-end m-0">
          @csrf
          <div class="col-md-9">
            <label for="motif" class="form-label small mb-1">Motif de consultation (facultatif)</label>
            <input type="text" class="form-control" id="motif" name="motif" maxlength="500"
                   value="{{ old('motif') }}" placeholder="Ce que le patient rapporte">
          </div>
          <div class="col-md-3 d-grid">
            <button class="btn btn-ms" type="submit">
              <i class="bi bi-play-circle"></i> Ouvrir la consultation
            </button>
          </div>
        </form>
      @else
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
          <div>
            <h2 class="h6 text-ms mb-1">
              <i class="bi bi-clipboard2-pulse"></i> Consultation
              @if ($consultation->estEnCours())
                <span class="badge bg-success-subtle text-success-emphasis">En cours</span>
              @else
                <span class="badge bg-secondary-subtle text-secondary-emphasis">Clôturée</span>
              @endif
            </h2>
            <p class="text-muted small mb-0">
              Menée par {{ $consultation->soignant_nom }}
              @if ($consultation->structure_nom)· {{ $consultation->structure_nom }}@endif
              · ouverte à {{ $consultation->debutee_le?->format('H:i') }}
              @if ($consultation->cloturee_le)
                · clôturée à {{ $consultation->cloturee_le->format('H:i') }}
              @endif
            </p>
            @if ($consultation->motif)
              <p class="small mb-0 mt-1"><span class="text-muted">Motif :</span> {{ $consultation->motif }}</p>
            @endif
          </div>
          @if ($consultation->estEnCours())
            <form method="POST" action="{{ route('portail.dossier.consultation.cloturer') }}" class="m-0">
              @csrf
              <button class="btn btn-outline-secondary btn-sm" type="submit">
                <i class="bi bi-check2-circle"></i> Clôturer la consultation
              </button>
            </form>
          @endif
        </div>

        @if ($consultation->estEnCours())
          <form method="POST" action="{{ route('portail.dossier.consultation.observer') }}" class="m-0">
            @csrf
            <label for="contenu" class="form-label small mb-1">Observation</label>
            <div class="input-group">
              <textarea class="form-control" id="contenu" name="contenu" rows="2" maxlength="5000"
                        placeholder="Ce que vous constatez">{{ old('contenu') }}</textarea>
              <button class="btn btn-ms" type="submit"><i class="bi bi-plus-lg"></i> Consigner</button>
            </div>
          </form>
        @endif

        @if ($consultation->observations->isNotEmpty())
          <ul class="list-group list-group-flush mt-3">
            @foreach ($consultation->observations->sortByDesc('created_at') as $observation)
              <li class="list-group-item px-0">
                <div class="small text-muted">{{ $observation->created_at?->format('d/m/Y H:i') }}</div>
                <div>{{ $observation->contenu }}</div>
              </li>
            @endforeach
          </ul>
        @endif
      @endif
    </div>
  </div>
@endif

<div class="row g-3">
  {{-- Sections : chaque visite est notée puis inscrite au journal d'audit à la clôture. --}}
  <div class="col-lg-3">
    <div class="list-group shadow-sm">
      @foreach ($sections as $cle => $libelle)
        <a href="{{ route('portail.dossier.section', $cle) }}"
           class="list-group-item list-group-item-action {{ $section === $cle ? 'active' : '' }}">
          {{ $libelle }}
        </a>
      @endforeach
    </div>
  </div>

  <div class="col-lg-9">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h2 class="h5 text-ms mb-3">{{ $sections[$section] }}</h2>

        @if ($section === 'identite')
          <dl class="row mb-0">
            <dt class="col-sm-4">Nom complet</dt><dd class="col-sm-8">{{ $membre->prenom }} {{ $membre->nom }}</dd>
            <dt class="col-sm-4">Date de naissance</dt><dd class="col-sm-8">{{ optional($membre->date_naissance)->format('d/m/Y') ?? '—' }}</dd>
            <dt class="col-sm-4">Sexe</dt><dd class="col-sm-8">{{ $membre->sexe ?? '—' }}</dd>
            <dt class="col-sm-4">Groupe sanguin</dt>
            <dd class="col-sm-8">
              @if ($membre->groupe_sanguin)
                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle fs-6">{{ $membre->groupe_sanguin }}</span>
              @else — @endif
            </dd>
            <dt class="col-sm-4">Couverture CMU</dt>
            <dd class="col-sm-8">{{ $membre->cmu_statut ?? '—' }} <span class="text-muted small font-monospace">{{ $membre->cmu_numero_masque }}</span></dd>
          </dl>
          <p class="text-muted small mt-3 mb-0">
            <i class="bi bi-shield-lock"></i> Le matricule interne et le numéro CMU complet ne sont jamais affichés.
          </p>

        @elseif ($donnees->isEmpty())
          <p class="text-muted mb-0"><i class="bi bi-inbox"></i> Aucune donnée dans cette section.</p>

        @elseif ($section === 'antecedents')
          @foreach ($donnees as $a)
            <div class="border-bottom py-2">
              <strong>{{ $a->type }}</strong>
              <span class="text-muted small">· {{ optional($a->date_diagnostic)->format('d/m/Y') ?? 'date inconnue' }}</span>
              <div>{{ $a->description }}</div>
              @if ($a->traitement_actuel)<div class="small text-muted">Traitement : {{ $a->traitement_actuel }}</div>@endif
            </div>
          @endforeach

        @elseif ($section === 'vaccinations')
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Vaccin</th><th>Administré le</th><th>Rappel</th><th>Statut</th></tr></thead>
            <tbody>
              @foreach ($donnees as $v)
                <tr>
                  <td>{{ $v->vaccin_nom }}</td>
                  <td>{{ optional($v->date_administration)->format('d/m/Y') ?? '—' }}</td>
                  <td>{{ optional($v->date_rappel)->format('d/m/Y') ?? '—' }}</td>
                  <td>{{ $v->statut }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>

        @elseif ($section === 'ordonnances')
          @foreach ($donnees as $o)
            <div class="border-bottom py-2">
              <strong>{{ optional($o->date_prescription)->format('d/m/Y') ?? 'date inconnue' }}</strong>
              <span class="text-muted small">· {{ $o->medecin_nom ?? 'praticien non précisé' }} · {{ $o->structure_sanitaire ?? '—' }}</span>
              @if (is_array($o->medicaments_json))
                <ul class="small mb-0 mt-1">
                  @foreach ($o->medicaments_json as $m)
                    <li>{{ is_array($m) ? implode(' — ', array_filter($m, 'is_scalar')) : $m }}</li>
                  @endforeach
                </ul>
              @endif
            </div>
          @endforeach

        @elseif ($section === 'analyses')
          @foreach ($donnees as $r)
            <div class="border-bottom py-2">
              <strong>{{ $r->intitule }}</strong>
              <span class="text-muted small">· {{ $r->type_analyse }} · {{ optional($r->date_analyse)->format('d/m/Y') ?? '—' }}</span>
              <div class="small text-muted">{{ $r->laboratoire ?? 'Laboratoire non précisé' }}</div>
            </div>
          @endforeach

        @elseif ($section === 'mesures')
          {{-- FN5 — Journal de bord du patient (90 derniers jours). Le statut est celui calculé par
               le serveur à partir du référentiel de seuils : le portail ne rejuge rien. --}}
          <table class="table table-sm align-middle mb-2">
            <thead><tr><th>Date</th><th>Mesure</th><th class="text-end">Valeur</th><th>Statut</th><th>Note</th></tr></thead>
            <tbody>
              @foreach ($donnees as $m)
                @php($couleur = match ($m->statut_norme) {
                  'critique' => 'danger',
                  'eleve', 'bas' => 'warning',
                  default => 'success',
                })
                <tr>
                  <td class="text-nowrap">{{ $m->date_mesure->format('d/m/Y H:i') }}</td>
                  <td>{{ str_replace('_', ' ', $m->type_mesure) }}</td>
                  <td class="text-end fw-semibold text-nowrap">{{ rtrim(rtrim(number_format($m->valeur, 2, ',', ' '), '0'), ',') }} {{ $m->unite }}</td>
                  <td>
                    <span class="badge bg-{{ $couleur }}-subtle text-{{ $couleur }}-emphasis border border-{{ $couleur }}-subtle">
                      {{ $m->statut_norme }}
                    </span>
                  </td>
                  <td class="small text-muted">{{ $m->note ?? '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>

        @elseif ($section === 'notes')
          @foreach ($donnees as $n)
            <div class="border-bottom py-2">
              <div class="small text-muted">{{ $n->created_at->format('d/m/Y H:i') }} · {{ $n->auteur_type }}</div>
              <div>{{ $n->contenu }}</div>
            </div>
          @endforeach

        @elseif ($section === 'contacts')
          @foreach ($donnees as $c)
            <div class="border-bottom py-2 d-flex justify-content-between align-items-center">
              <div>
                <strong>{{ $c->nom }}</strong> <span class="text-muted small">· {{ $c->lien_parente }}</span>
                <div class="font-monospace">{{ $c->telephone }}</div>
              </div>
              @if ($c->est_principal)
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Principal</span>
              @endif
            </div>
          @endforeach

        @elseif ($section === 'documents')
          <table class="table table-sm align-middle mb-2">
            <thead><tr><th>Titre</th><th>Catégorie</th><th>Date</th><th>Analyse antivirus</th></tr></thead>
            <tbody>
              @foreach ($donnees as $d)
                <tr>
                  <td>{{ $d->titre }}</td>
                  <td class="text-muted small">{{ $d->categorie }}</td>
                  <td>{{ optional($d->date_document)->format('d/m/Y') ?? '—' }}</td>
                  <td><span class="badge bg-light text-muted border">{{ $d->statut_antivirus }}</span></td>
                </tr>
              @endforeach
            </tbody>
          </table>
          <p class="text-muted small mb-0">
            <i class="bi bi-shield-lock"></i> Les documents sont chiffrés au repos et ne sont pas téléchargeables
            depuis le portail (minimisation des données, loi n°2013-450).
          </p>

        @elseif ($section === 'triage')
          @error('retour')
            <div class="alert alert-danger py-2 small">{{ $message }}</div>
          @enderror

          @foreach ($donnees as $t)
            <div class="border-bottom py-3">
              <span class="badge bg-{{ $niveaux[$t->niveau] ?? 'secondary' }}">{{ strtoupper($t->niveau) }}</span>
              <span class="text-muted small">· score {{ $t->score_severite }}/100 · {{ $t->created_at->format('d/m/Y H:i') }}</span>
              @if ($t->specialite_requise)<div class="small">Spécialité orientée : <strong>{{ $t->specialite_requise }}</strong></div>@endif
              <div class="small text-muted">{{ $t->recommandation_texte }}</div>

              {{-- P10c-2-i — LES RETOURS DÉJÀ DONNÉS.
                   Affichés même quand un praticien s'est ravisé : le journal du §10 est
                   append-only, et un avis retiré reste une information. Le dernier fait foi. --}}
              @foreach (($retoursDonnes[$t->id] ?? []) as $r)
                <div class="small mt-2 ps-2 border-start border-3
                            {{ $r->decision_finale === 'adaptee' ? 'border-success' : 'border-warning' }}">
                  <strong>{{ $retoursPossibles[$r->decision_finale] ?? $r->decision_finale }}</strong>
                  <span class="text-muted">· {{ $r->cree_le->format('d/m/Y H:i') }}</span>
                  @if ($r->ecart_justification)
                    <div class="text-muted">{{ $r->ecart_justification }}</div>
                  @endif
                </div>
              @endforeach

              {{-- Le retour clinique (CDC_05 §5.5.4, §9.1 « supervision humaine »).
                   Proposé au seul compte habilité ; le service revérifie de toute façon, et c'est
                   sa garde qui fait autorité (piège P4 : un middleware au mauvais guard laisse
                   passer). --}}
              @if ($peutDonnerRetour)
                <form method="POST" action="{{ route('portail.dossier.triage.retour', $t->id) }}"
                      class="mt-2">
                  @csrf
                  <div class="row g-2 align-items-start">
                    <div class="col-md-5">
                      <label class="form-label small mb-1" for="retour-{{ $t->id }}">
                        L'orientation était-elle adaptée&nbsp;?
                      </label>
                      <select class="form-select form-select-sm" name="retour" id="retour-{{ $t->id }}" required>
                        <option value="">— choisir —</option>
                        @foreach ($retoursPossibles as $valeur => $libelle)
                          <option value="{{ $valeur }}">{{ $libelle }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label small mb-1" for="justification-{{ $t->id }}">
                        Ce que l'orientation n'a pas vu
                        <span class="text-muted">(obligatoire en cas d'écart)</span>
                      </label>
                      <textarea class="form-control form-control-sm" name="justification"
                                id="justification-{{ $t->id }}" rows="1"
                                placeholder="Ex. : détresse respiratoire non signalée au questionnaire"></textarea>
                    </div>
                    <div class="col-md-2 d-grid">
                      <label class="form-label small mb-1 d-none d-md-block">&nbsp;</label>
                      <button type="submit" class="btn btn-sm btn-outline-primary">Enregistrer</button>
                    </div>
                  </div>

                  {{--
                    P10c-3-ii (F32→F34) — ce que le §5.5.4 demande d'accumuler : « le diagnostic
                    final posé par le médecin ». Les trois champs sont FACULTATIFS ; le diagnostic
                    et la spécialité se CHOISISSENT dans une liste, jamais en texte libre.
                  --}}
                  <div class="row g-2 align-items-start mt-1">
                    <div class="col-md-4">
                      <label class="form-label small mb-1" for="niveau-reel-{{ $t->id }}">
                        Le niveau que vous auriez retenu <span class="text-muted">(facultatif)</span>
                      </label>
                      <select class="form-select form-select-sm" name="niveau_reel" id="niveau-reel-{{ $t->id }}">
                        <option value="">— non renseigné —</option>
                        @foreach (\App\Support\NiveauTriage::PATIENT as $niveau)
                          <option value="{{ $niveau }}">{{ $niveauxReels[$niveau] ?? $niveau }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small mb-1" for="maladie-{{ $t->id }}">
                        Diagnostic final <span class="text-muted">(facultatif)</span>
                      </label>
                      <select class="form-select form-select-sm" name="maladie_id" id="maladie-{{ $t->id }}">
                        <option value="">— non renseigné —</option>
                        @foreach ($maladiesReferentiel as $maladie)
                          <option value="{{ $maladie['id'] ?? $maladie->id }}">{{ $maladie['libelle'] ?? $maladie->libelle }}</option>
                        @endforeach
                      </select>
                      @if (count($maladiesReferentiel) === 0)
                        <div class="form-text small text-warning">
                          Aucune version du référentiel des maladies n'est en vigueur : le diagnostic
                          ne peut pas être rattaché tant qu'elle n'est pas publiée.
                        </div>
                      @endif
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small mb-1" for="specialite-{{ $t->id }}">
                        Spécialité ayant pris en charge <span class="text-muted">(facultatif)</span>
                      </label>
                      <select class="form-select form-select-sm" name="specialite_id" id="specialite-{{ $t->id }}">
                        <option value="">— non renseigné —</option>
                        @foreach ($specialitesReferentiel as $specialite)
                          <option value="{{ $specialite->id }}">{{ $specialite->libelle }}</option>
                        @endforeach
                      </select>
                    </div>
                  </div>
                  <div class="form-text small">
                    Le niveau que vous indiquez doit s'accorder avec votre verdict : si les deux se
                    contredisent, l'enregistrement est refusé plutôt qu'arbitré — vous seul savez
                    lequel des deux dit ce que vous pensez.
                  </div>
                  <div class="form-text small">
                    Votre retour n'est pas un diagnostic&nbsp;: il dit si le niveau proposé au patient
                    correspondait à son état réel. Il est journalisé à votre nom et servira à améliorer
                    les prochaines orientations.
                  </div>
                </form>
              @endif
            </div>
          @endforeach
        @endif

        {{-- D0 — écriture du soignant. Proposée seulement si les trois conditions sont réunies :
             section ouverte, compte habilité (`dossier.ecrire`), voie consentie (jamais le bris de
             glace). Le serveur revérifie tout : ceci n'évite qu'un formulaire voué au refus. --}}
        @if ($peutEcrire)
          @include('portail.dossier.formulaire', [
            'section' => $section,
            'peutSigner' => $peutSigner ?? false,
            'maladiesReferentiel' => $maladiesReferentiel ?? [],
          ])
        @endif
      </div>
    </div>
  </div>
</div>

<script>
  // Compte à rebours de la fenêtre de 30 minutes. À zéro, la page se recharge : le middleware
  // `dossier.actif` clôt la session (ligne d'audit) et renvoie l'agent vers le scanner.
  (function () {
    let restant = {{ $restant }};
    const cible = document.getElementById('compte-a-rebours');

    setInterval(function () {
      if (restant <= 0) { window.location.reload(); return; }
      restant--;
      const m = String(Math.floor(restant / 60)).padStart(2, '0');
      const s = String(restant % 60).padStart(2, '0');
      cible.textContent = m + ':' + s;
      if (restant < 300) cible.classList.add('text-danger');
    }, 1000);
  })();
</script>
@endsection
