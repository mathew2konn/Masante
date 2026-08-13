@extends('portail.layout')

@section('titre', 'Modifier · ' . $etablissement->nom)

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="{{ route('portail.etablissements.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h1 class="h3 mb-0" style="color:var(--ms-blue-dark)">Modifier l'établissement</h1>
</div>

<form method="POST" action="{{ route('portail.etablissements.update', $etablissement) }}">
  @csrf @method('PUT')

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-medium"><i class="bi bi-hospital text-ms"></i> Établissement</div>
    <div class="card-body">
      @include('portail.etablissements._form', ['etablissement' => $etablissement])
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-ms" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
    <a href="{{ route('portail.etablissements.index') }}" class="btn btn-outline-secondary">Annuler</a>
  </div>
</form>

{{--
  P6.4c/P6.4d — Images (CDC_11 §3.1 « formulaire dédié »). Lève la limite O1 d'ADR-028 : jusqu'ici
  les images n'étaient déposables que par l'API.

  Formulaire SÉPARÉ de celui de l'établissement, et c'est délibéré : un envoi de fichier échoue plus
  souvent qu'une saisie de texte (taille, format, réseau). Fondus dans le même formulaire, un refus
  d'image ferait perdre trente champs déjà remplis.

  Les catégories et leur maximum viennent de la TABLE de référence — « un établissement n'a qu'un
  logo » est une donnée, pas une règle écrite ici.
--}}
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header bg-white fw-medium"><i class="bi bi-images text-ms"></i> Images</div>
  <div class="card-body">
    @if (session('erreur'))
      <div class="alert alert-warning py-2 small">{{ session('erreur') }}</div>
    @endif

    @if ($images->isEmpty())
      <p class="text-muted small">Aucune image publiée. Le logo remplacera l'icône générique dans l'application.</p>
    @else
      <div class="d-flex flex-wrap gap-3 mb-3">
        @foreach ($images as $image)
          <div class="text-center">
            <img src="{{ $image->url }}" alt="{{ $image->categorie_code }}"
                 style="width:120px;height:90px;object-fit:cover;border-radius:.5rem;border:1px solid #dee2e6">
            <div class="small text-muted mt-1">{{ $image->categorie?->libelle ?? $image->categorie_code }}</div>
            <form method="POST" action="{{ route('portail.etablissements.images.destroy', [$etablissement, $image]) }}"
                  onsubmit="return confirm('Supprimer cette image ?')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-link text-danger p-0" type="submit">Supprimer</button>
            </form>
          </div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('portail.etablissements.images.store', $etablissement) }}"
          enctype="multipart/form-data" class="row g-2 align-items-end">
      @csrf
      <div class="col-md-4">
        <label class="form-label small">Catégorie</label>
        <select name="categorie" class="form-select form-select-sm" required>
          @foreach ($categories as $categorie)
            <option value="{{ $categorie->code }}">{{ $categorie->libelle }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label small">Fichier <span class="text-muted">(JPEG, PNG ou WebP)</span></label>
        <input type="file" name="image" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required>
      </div>
      <div class="col-md-3">
        <button class="btn btn-sm btn-ms w-100" type="submit"><i class="bi bi-upload"></i> Ajouter</button>
      </div>
      @error('image') <div class="col-12 text-danger small">{{ $message }}</div> @enderror
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm mt-4">
  <div class="card-header bg-white fw-medium"><i class="bi bi-person-badge text-ms"></i> Compte gestionnaire</div>
  <div class="card-body">
    @if ($gestionnaire)
      <p class="mb-2">
        <strong>{{ $gestionnaire->prenom }} {{ $gestionnaire->nom }}</strong>
        — {{ $gestionnaire->email }}
        @if ($gestionnaire->password === null)
          <span class="badge bg-warning text-dark ms-1">Activation en attente</span>
        @else
          <span class="badge bg-success ms-1">Activé</span>
        @endif
      </p>
      @if ($gestionnaire->password === null)
        <form method="POST" action="{{ route('portail.etablissements.lien', $etablissement) }}">
          @csrf
          <button class="btn btn-sm btn-outline-primary" type="submit">
            <i class="bi bi-arrow-repeat"></i> Régénérer le lien d'activation
          </button>
        </form>
      @endif
    @else
      <p class="text-muted mb-0">Aucun gestionnaire rattaché.</p>
    @endif
    @error('gestionnaire') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
  </div>
</div>
@endsection
