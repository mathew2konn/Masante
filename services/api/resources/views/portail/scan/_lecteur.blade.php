{{--
  Lecteur de QR Code partagé par les deux flux de scan (4.5).
  Paramètres : $action (URL POST), $champ (nom du champ), $aide (texte sous le cadre).

  La caméra du navigateur (getUserMedia) n'est accessible que sur une ORIGINE SÉCURISÉE :
  https:// (Ngrok) ou http://localhost. Sur un poste sans caméra — ou si l'agent refuse
  l'autorisation — la saisie manuelle du contenu du QR reste toujours disponible.
--}}
<form method="POST" action="{{ $action }}" id="form-scan">
  @csrf

  <div id="lecteur" class="border rounded bg-body-tertiary mx-auto" style="max-width:420px; min-height:120px"></div>

  <div class="text-center my-3">
    <button type="button" class="btn btn-ms" id="btn-camera">
      <i class="bi bi-camera-video"></i> Activer la caméra
    </button>
    <button type="button" class="btn btn-outline-secondary d-none" id="btn-stop">
      <i class="bi bi-stop-circle"></i> Arrêter
    </button>
  </div>

  <p class="text-muted small text-center">{{ $aide }}</p>

  <hr class="my-4">

  <label for="{{ $champ }}" class="form-label small text-muted">
    <i class="bi bi-keyboard"></i> Saisie manuelle (poste sans caméra)
  </label>
  <div class="input-group">
    <textarea class="form-control font-monospace @error($champ) is-invalid @enderror"
              id="{{ $champ }}" name="{{ $champ }}" rows="2"
              placeholder="Collez ici le contenu du QR Code">{{ old($champ) }}</textarea>
    <button class="btn btn-ms" type="submit"><i class="bi bi-arrow-right-circle"></i> Valider</button>
    @error($champ)
      <div class="invalid-feedback">{{ $message }}</div>
    @enderror
  </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
  (function () {
    const form   = document.getElementById('form-scan');
    const champ  = document.getElementById(@json($champ));
    const zone   = document.getElementById('lecteur');
    const start  = document.getElementById('btn-camera');
    const stop   = document.getElementById('btn-stop');
    let lecteur  = null;
    let envoye   = false;   // un QR lu ne doit déclencher qu'UNE soumission.

    function erreur(texte) {
      zone.innerHTML = '<p class="text-danger small p-3 mb-0">' + texte + '</p>';
    }

    start.addEventListener('click', async function () {
      if (!window.isSecureContext) {
        erreur("La caméra exige une connexion sécurisée (https ou localhost). Utilisez la saisie manuelle.");
        return;
      }

      lecteur = new Html5Qrcode('lecteur');
      start.classList.add('d-none');
      stop.classList.remove('d-none');

      try {
        await lecteur.start(
          { facingMode: 'environment' },
          { fps: 10, qrbox: { width: 240, height: 240 } },
          function (texte) {
            if (envoye) return;
            envoye = true;
            champ.value = texte;
            lecteur.stop().finally(function () { form.submit(); });
          },
          function () { /* image sans QR : silencieux, appelé à chaque frame */ },
        );
      } catch (e) {
        start.classList.remove('d-none');
        stop.classList.add('d-none');
        erreur("Caméra indisponible ou refusée. Utilisez la saisie manuelle ci-dessous.");
      }
    });

    stop.addEventListener('click', function () {
      if (!lecteur) return;
      lecteur.stop().finally(function () {
        zone.innerHTML = '';
        stop.classList.add('d-none');
        start.classList.remove('d-none');
      });
    });
  })();
</script>
