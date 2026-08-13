{{--
  Champs de l'établissement, partagés par create et edit. $e = établissement (ou null en création).

  P6.4d — Le formulaire suit les BLOCS que CDC_11 §3.1 décrit : informations générales, légales,
  coordonnées, rattachement sanitaire, capacités, description. Il collectait 11 champs quand la base
  en portait une trentaine (limite M3 d'ADR-026), et le voici aligné.

  DEUX ABSENCES VOULUES :

   · `identifiant_national` n'est PAS un champ. Il est attribué par le backfill sous verrou et vit
     hors de `$fillable` : le laisser saisir permettrait à un établissement de choisir son propre
     numéro national. Il est AFFICHÉ, en lecture seule, parce qu'un gestionnaire a besoin de le lire.
   · `specialites` a été retiré (décision K2). La colonne `specialites_json` était écrite ici et lue
     par personne — ni la fiche mobile, ni la tuile, ni aucun filtre. Le filtre `?specialite=` de
     l'annuaire passe par `services_etablissement.specialite`, gérée à l'écran Services.
--}}
@php($e = $etablissement ?? null)

@if ($e?->identifiant_national)
  <div class="alert alert-light border d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-hash text-ms"></i>
    <div>
      <div class="small text-muted">Identifiant national (attribué, non modifiable)</div>
      <strong>{{ $e->identifiant_national }}</strong>
    </div>
  </div>
@endif

<h6 class="text-uppercase text-muted small fw-bold mb-3">Informations générales</h6>
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <label class="form-label">Nom d'usage <span class="text-danger">*</span></label>
    <input type="text" name="nom" class="form-control @error('nom') is-invalid @enderror"
           value="{{ old('nom', $e->nom ?? '') }}" required maxlength="200">
    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Nom officiel <span class="text-muted small">(tel qu'il figure à l'arrêté)</span></label>
    <input type="text" name="nom_officiel" class="form-control @error('nom_officiel') is-invalid @enderror"
           value="{{ old('nom_officiel', $e->nom_officiel ?? '') }}" maxlength="200">
    @error('nom_officiel') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Catégorie <span class="text-danger">*</span></label>
    <select name="type" class="form-select @error('type') is-invalid @enderror" required>
      <option value="">— Choisir —</option>
      @foreach ($types as $cle => $libelle)
        <option value="{{ $cle }}" @selected(old('type', $e->type ?? '') === $cle)>{{ $libelle }}</option>
      @endforeach
    </select>
    @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  {{-- Deux axes distincts que CDC_11 §3.1 sépare : QUI possède, et sous quelle FORME de droit. --}}
  <div class="col-md-4">
    <label class="form-label">Statut juridique</label>
    <select name="statut_juridique" class="form-select @error('statut_juridique') is-invalid @enderror">
      <option value="">— Non renseigné —</option>
      @foreach (['public' => 'Public', 'prive' => 'Privé', 'universitaire' => 'Universitaire', 'militaire' => 'Militaire'] as $cle => $libelle)
        <option value="{{ $cle }}" @selected(old('statut_juridique', $e->statut_juridique ?? '') === $cle)>{{ $libelle }}</option>
      @endforeach
    </select>
    @error('statut_juridique') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Forme juridique <span class="text-muted small">(SARL, SA, EPN…)</span></label>
    <input type="text" name="forme_juridique" class="form-control @error('forme_juridique') is-invalid @enderror"
           value="{{ old('forme_juridique', $e->forme_juridique ?? '') }}" maxlength="80">
    @error('forme_juridique') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Niveau de soins</label>
    <select name="niveau_soins" class="form-select @error('niveau_soins') is-invalid @enderror">
      <option value="">— Non renseigné —</option>
      @foreach (['primaire' => 'Primaire', 'secondaire' => 'Secondaire', 'tertiaire' => 'Tertiaire'] as $cle => $libelle)
        <option value="{{ $cle }}" @selected(old('niveau_soins', $e->niveau_soins ?? '') === $cle)>{{ $libelle }}</option>
      @endforeach
    </select>
    @error('niveau_soins') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Directeur</label>
    <input type="text" name="directeur" class="form-control @error('directeur') is-invalid @enderror"
           value="{{ old('directeur', $e->directeur ?? '') }}" maxlength="150">
    @error('directeur') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <div class="form-check mt-4 pt-2">
      <input type="checkbox" name="partenaire_ivoirsante" value="1" class="form-check-input" id="partenaire"
             @checked(old('partenaire_ivoirsante', $e->partenaire_ivoirsante ?? false))>
      <label class="form-check-label" for="partenaire">Établissement partenaire IVOIRSANTÉ</label>
    </div>
  </div>
</div>

<h6 class="text-uppercase text-muted small fw-bold mb-3">Coordonnées et localisation</h6>
<div class="row g-3 mb-4">
  <div class="col-12">
    <label class="form-label">Adresse <span class="text-danger">*</span></label>
    <input type="text" name="adresse" class="form-control @error('adresse') is-invalid @enderror"
           value="{{ old('adresse', $e->adresse ?? '') }}" required maxlength="500">
    @error('adresse') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  {{--
    La ville vient d'une TABLE (P6.4b) : c'est elle qui décide si des communes sont proposées au
    citoyen. Jusqu'ici seul le seeder pouvait la poser — limite N5 d'ADR-027, levée ici.
  --}}
  <div class="col-md-4">
    <label class="form-label">Ville couverte</label>
    <select name="ville_id" class="form-select @error('ville_id') is-invalid @enderror">
      <option value="">— Hors des villes couvertes —</option>
      @foreach ($villes as $ville)
        <option value="{{ $ville->id }}" @selected((int) old('ville_id', $e->ville_id ?? 0) === $ville->id)>{{ $ville->nom }}</option>
      @endforeach
    </select>
    @error('ville_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Commune <span class="text-danger">*</span></label>
    <input type="text" name="commune" class="form-control @error('commune') is-invalid @enderror"
           value="{{ old('commune', $e->commune ?? '') }}" required maxlength="100">
    @error('commune') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Quartier</label>
    <input type="text" name="quartier" class="form-control @error('quartier') is-invalid @enderror"
           value="{{ old('quartier', $e->quartier ?? '') }}" maxlength="100">
    @error('quartier') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Latitude <span class="text-danger">*</span></label>
    <input type="number" step="any" name="latitude" class="form-control @error('latitude') is-invalid @enderror"
           value="{{ old('latitude', $e->latitude ?? '') }}" required placeholder="5.3599">
    @error('latitude') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Longitude <span class="text-danger">*</span></label>
    <input type="number" step="any" name="longitude" class="form-control @error('longitude') is-invalid @enderror"
           value="{{ old('longitude', $e->longitude ?? '') }}" required placeholder="-4.0083">
    @error('longitude') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-3">
    <label class="form-label">Téléphone</label>
    <input type="text" name="telephone" class="form-control @error('telephone') is-invalid @enderror"
           value="{{ old('telephone', $e->telephone ?? '') }}" maxlength="20">
    @error('telephone') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-3">
    <label class="form-label">WhatsApp</label>
    <input type="text" name="whatsapp" class="form-control @error('whatsapp') is-invalid @enderror"
           value="{{ old('whatsapp', $e->whatsapp ?? '') }}" maxlength="20">
    @error('whatsapp') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-3">
    <label class="form-label">E-mail</label>
    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
           value="{{ old('email', $e->email ?? '') }}" maxlength="190">
    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-3">
    <label class="form-label">Site web</label>
    <input type="url" name="site_web" class="form-control @error('site_web') is-invalid @enderror"
           value="{{ old('site_web', $e->site_web ?? '') }}" maxlength="190" placeholder="https://…">
    @error('site_web') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
</div>

<h6 class="text-uppercase text-muted small fw-bold mb-3">Découpage sanitaire</h6>
<div class="row g-3 mb-4">
  {{--
    Région et district viennent de TABLES (§1.2.4) : une région saisie à la main deviendrait
    « Abidjan », « ABIDJAN » et « Abidjan 1 » en trois semaines, et aucune statistique nationale ne
    serait plus possible — or c'est l'usage même que §4.4 assigne à ce référentiel.

    Le district est affiché AVEC sa région, parce que le serveur REFUSE un district qui n'appartient
    pas à la région choisie. C'est l'anomalie la plus sournoise du lot : les deux références sont
    valides séparément, seule leur combinaison est fausse.
  --}}
  <div class="col-md-6">
    <label class="form-label">Région sanitaire</label>
    <select name="region_id" class="form-select @error('region_id') is-invalid @enderror">
      <option value="">— Non renseignée —</option>
      @foreach ($regions as $region)
        <option value="{{ $region->id }}" @selected((int) old('region_id', $e->region_id ?? 0) === $region->id)>{{ $region->nom }}</option>
      @endforeach
    </select>
    @error('region_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">District sanitaire</label>
    <select name="district_id" class="form-select @error('district_id') is-invalid @enderror">
      <option value="">— Non renseigné —</option>
      @foreach ($districts as $district)
        <option value="{{ $district->id }}" @selected((int) old('district_id', $e->district_id ?? 0) === $district->id)>
          {{ $district->region?->nom }} — {{ $district->nom }}
        </option>
      @endforeach
    </select>
    @error('district_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Le district doit appartenir à la région choisie.</div>
  </div>
</div>

<h6 class="text-uppercase text-muted small fw-bold mb-3">Informations légales</h6>
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <label class="form-label">N° d'autorisation</label>
    <input type="text" name="numero_autorisation" class="form-control @error('numero_autorisation') is-invalid @enderror"
           value="{{ old('numero_autorisation', $e->numero_autorisation ?? '') }}" maxlength="60">
    @error('numero_autorisation') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">N° fiscal</label>
    <input type="text" name="numero_fiscal" class="form-control @error('numero_fiscal') is-invalid @enderror"
           value="{{ old('numero_fiscal', $e->numero_fiscal ?? '') }}" maxlength="60">
    @error('numero_fiscal') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Registre du commerce</label>
    <input type="text" name="registre_commerce" class="form-control @error('registre_commerce') is-invalid @enderror"
           value="{{ old('registre_commerce', $e->registre_commerce ?? '') }}" maxlength="60">
    @error('registre_commerce') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Licence d'exploitation</label>
    <input type="text" name="licence_exploitation" class="form-control @error('licence_exploitation') is-invalid @enderror"
           value="{{ old('licence_exploitation', $e->licence_exploitation ?? '') }}" maxlength="60">
    @error('licence_exploitation') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Autorité de tutelle</label>
    <input type="text" name="autorite_tutelle" class="form-control @error('autorite_tutelle') is-invalid @enderror"
           value="{{ old('autorite_tutelle', $e->autorite_tutelle ?? '') }}" maxlength="150">
    @error('autorite_tutelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-4">
    <label class="form-label">Date de création</label>
    <input type="date" name="date_creation" class="form-control @error('date_creation') is-invalid @enderror"
           value="{{ old('date_creation', $e?->date_creation?->format('Y-m-d') ?? '') }}">
    @error('date_creation') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
</div>

<h6 class="text-uppercase text-muted small fw-bold mb-3">Capacités, agréments et tarifs</h6>
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <label class="form-label">Capacité d'accueil</label>
    <input type="number" min="0" name="capacite_accueil" class="form-control @error('capacite_accueil') is-invalid @enderror"
           value="{{ old('capacite_accueil', $e->capacite_accueil ?? '') }}">
    @error('capacite_accueil') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-3">
    <label class="form-label">Nombre de lits</label>
    <input type="number" min="0" name="nombre_lits" class="form-control @error('nombre_lits') is-invalid @enderror"
           value="{{ old('nombre_lits', $e->nombre_lits ?? '') }}">
    @error('nombre_lits') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-3">
    <label class="form-label">Tarif min (FCFA)</label>
    <input type="number" min="0" name="tarif_min_cfa" class="form-control @error('tarif_min_cfa') is-invalid @enderror"
           value="{{ old('tarif_min_cfa', $e->tarif_min_cfa ?? '') }}">
    @error('tarif_min_cfa') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-3">
    <label class="form-label">Tarif max (FCFA)</label>
    <input type="number" min="0" name="tarif_max_cfa" class="form-control @error('tarif_max_cfa') is-invalid @enderror"
           value="{{ old('tarif_max_cfa', $e->tarif_max_cfa ?? '') }}">
    @error('tarif_max_cfa') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Agréments <span class="text-muted small">(séparés par des virgules)</span></label>
    <input type="text" name="agrements" class="form-control @error('agrements') is-invalid @enderror"
           value="{{ old('agrements', $e ? implode(', ', $e->agrements_json ?? []) : '') }}"
           placeholder="Agrément CNAM, Convention CMU">
    @error('agrements') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-md-6">
    <label class="form-label">Certifications <span class="text-muted small">(séparées par des virgules)</span></label>
    <input type="text" name="certifications" class="form-control @error('certifications') is-invalid @enderror"
           value="{{ old('certifications', $e ? implode(', ', $e->certifications_json ?? []) : '') }}"
           placeholder="ISO 9001">
    @error('certifications') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>

  <div class="col-12">
    <label class="form-label">Description <span class="text-muted small">(présentation, mission)</span></label>
    <textarea name="description" rows="3" maxlength="2000"
              class="form-control @error('description') is-invalid @enderror">{{ old('description', $e->description ?? '') }}</textarea>
    @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
  </div>
</div>
