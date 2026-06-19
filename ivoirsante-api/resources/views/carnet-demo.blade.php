<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaSante — Test Carnet & QR (navigateur)</title>
  <!-- Génération du QR 100% côté navigateur (le token ne sort jamais de la page). -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <style>
    :root{
      --blue600:#1E6BB8; --blue700:#17569A; --blue900:#0C3463; --blue50:#F2F8FD;
      --ink700:#33475A; --ink500:#62768A; --line:#D9E2EA; --surface:#FFFFFF;
      --green-bg:#E4F5EA; --green-tx:#146C3A; --red-bg:#FCE8E8; --red-tx:#A11616;
    }
    *{box-sizing:border-box} body{margin:0;font-family:system-ui,Roboto,Arial,sans-serif;
      background:linear-gradient(180deg,#F2F8FD,#DCEAF8,#93BBE6,#1E5BAA);min-height:100vh;color:var(--ink700)}
    .wrap{max-width:980px;margin:0 auto;padding:24px}
    h1{color:var(--blue900);font-size:24px;margin:4px 0}
    .sub{color:var(--ink700);margin:0 0 16px}
    .card{background:var(--surface);border-radius:24px;padding:20px;margin-bottom:16px;
      box-shadow:0 2px 8px rgba(12,52,99,.08)}
    .grid{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
    button{border:0;border-radius:999px;padding:12px 18px;font-size:15px;font-weight:700;
      color:#fff;background:var(--blue600);cursor:pointer;min-height:48px}
    button:hover{background:var(--blue700)} button.alt{background:#fff;color:var(--blue600);border:1px solid var(--blue600)}
    button.danger{background:#DC2626} button.danger:hover{background:#B91C1C}
    button.small{padding:8px 12px;font-size:13px;min-height:auto}
    pre{background:#0C3463;color:#DCEAF8;border-radius:12px;padding:16px;overflow:auto;font-size:13px;max-height:360px}
    .muted{color:var(--ink500);font-size:13px}
    label{font-weight:600;display:block;margin:8px 0 4px}
    input,select{width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;font-size:15px;background:#F3F7FB}
    .row{display:flex;gap:12px;flex-wrap:wrap} .row > div{flex:1;min-width:150px}
    .pill{display:inline-block;padding:4px 12px;border-radius:999px;font-weight:700;font-size:13px}
    .on{background:var(--green-bg);color:var(--green-tx)} .off{background:var(--red-bg);color:var(--red-tx)}
    .step{font-weight:800;color:var(--blue900)}
    .membre{border:1px solid var(--line);border-radius:14px;padding:12px;margin-bottom:10px}
    .membre b{color:var(--blue900)}
    #qrzone{display:none;text-align:center}
    #qrcode{display:inline-block;margin:10px auto;padding:12px;background:#fff;border-radius:12px}
    .count{font-size:28px;font-weight:800;color:var(--blue700)}
    .tok{word-break:break-all;background:var(--blue50);border-radius:8px;padding:8px;font-size:12px;color:var(--ink700)}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>🩺 MaSante — Test du Module 2 (Carnet de santé + QR)</h1>
    <p class="sub">Page de test servie par le backend (même origine, aucun CORS). Étapes
      <b>2A.2 (membres)</b> et <b>2A.3 (QR dynamique + journal d'accès)</b>. État du token :
      <span id="tokState" class="pill off">aucun token</span></p>

    <!-- 0 · Obtenir un token (réutilise l'auth 2A.1) -->
    <div class="card">
      <p class="step">0 · Obtenir un token (auth 2A.1)</p>
      <div class="row">
        <div><label>Téléphone</label><input id="telephone" value="+2250709333444"></div>
        <div><label>Prénom (titulaire)</label><input id="prenom" value="Awa"></div>
        <div><label>Nom (titulaire)</label><input id="nom" value="Kouadio"></div>
        <div><label>Mot de passe</label><input id="password" value="Test1234pass"></div>
      </div>
      <div class="grid" style="margin-top:10px">
        <button onclick="autoToken()">S'inscrire + vérifier (token auto)</button>
        <button class="alt" onclick="randTel()">Nouveau téléphone aléatoire</button>
        <button class="alt" onclick="login()">Se connecter (compte existant)</button>
      </div>
      <p class="muted" style="margin-top:8px">En dev, l'OTP est renvoyé par l'API : la vérification est enchaînée automatiquement.</p>
    </div>

    <!-- 1 · Membres de la famille (2A.2) -->
    <div class="card">
      <p class="step">1 · Membres de la famille (max 5 par compte)</p>
      <div class="row">
        <div><label>Prénom</label><input id="m_prenom" value="Yann"></div>
        <div><label>Nom</label><input id="m_nom" value="Kouadio"></div>
        <div><label>Date de naissance</label><input id="m_naissance" type="date" value="2015-04-12"></div>
      </div>
      <div class="row">
        <div><label>Sexe</label>
          <select id="m_sexe"><option value="M">M</option><option value="F">F</option></select></div>
        <div><label>Groupe sanguin</label>
          <select id="m_groupe">
            <option value="">—</option><option>O+</option><option>O-</option><option>A+</option>
            <option>A-</option><option>B+</option><option>B-</option><option>AB+</option><option>AB-</option>
          </select></div>
        <div><label>N° CMU (chiffré en base)</label><input id="m_cmu" value="CMU00112233"></div>
      </div>
      <div class="grid" style="margin-top:10px">
        <button onclick="creerMembre()">Créer le membre</button>
        <button class="alt" onclick="listerMembres()">Rafraîchir la liste</button>
      </div>
      <div id="membres" style="margin-top:14px"></div>
    </div>

    <!-- 2 · Antécédents & impact sur le triage (2A.4 + F1.3) -->
    <div class="card">
      <p class="step">2 · Antécédents & impact sur le triage</p>
      <div class="row">
        <div><label>Membre concerné</label><select id="ant_membre"></select></div>
        <div><label>Type</label>
          <select id="ant_type">
            <option value="maladie_chronique">Maladie chronique</option>
            <option value="allergie">Allergie</option>
            <option value="chirurgie">Chirurgie</option>
            <option value="hospitalisation">Hospitalisation</option>
            <option value="autre">Autre</option>
          </select></div>
        <div><label>Impact triage (0-20)</label><input id="ant_impact" type="number" min="0" max="20" value="12"></div>
      </div>
      <div class="row">
        <div style="flex:2"><label>Description (chiffrée en base)</label><input id="ant_desc" value="Asthme persistant modéré"></div>
      </div>
      <div class="grid" style="margin-top:10px">
        <button onclick="ajouterAntecedent()">Ajouter l'antécédent</button>
        <button class="alt" onclick="listerAntecedents()">Lister les antécédents</button>
      </div>

      <hr style="border:none;border-top:1px solid var(--line);margin:16px 0">
      <p class="muted">Lancer un triage <b>rattaché à ce membre</b> : ses antécédents s'ajoutent au score
        (plafond 20). Sans membre, le triage est anonyme (antécédents = 0).</p>
      <div class="row">
        <div><label>Symptôme</label><select id="ant_symptome"></select></div>
        <div style="display:flex;align-items:flex-end">
          <button onclick="lancerTriage()">Lancer un triage pour ce membre</button>
        </div>
      </div>
      <div id="triageOut" style="margin-top:12px"></div>
    </div>

    <!-- 3 · QR dynamique (2A.3) -->
    <div class="card" id="qrcard">
      <p class="step">3 · QR dynamique (généré pour un membre ci-dessus)</p>
      <div id="qrzone">
        <p class="muted">QR pour <b id="qrMembre"></b> — valable <span id="qrTtl">10</span> min, usage unique.</p>
        <div id="qrcode"></div>
        <p>Expire dans <span id="qrCount" class="count">10:00</span></p>
        <p class="muted">Contenu opaque encodé (jamais le matricule) :</p>
        <div class="tok" id="qrToken"></div>
      </div>
      <p class="muted" id="qrHint">Clique sur « Générer QR » sur un membre pour afficher le code.</p>
    </div>

    <div class="card">
      <pre id="out">Résultat ici…</pre>
    </div>
  </div>

<script>
  let authToken = null;
  let countTimer = null;

  const H = () => {
    const h = { 'Accept':'application/json', 'Content-Type':'application/json', 'ngrok-skip-browser-warning':'true' };
    if (authToken) h['Authorization'] = 'Bearer ' + authToken;
    return h;
  };
  const val = (id) => document.getElementById(id).value.trim();

  function setToken(t){
    authToken = t || null;
    const s = document.getElementById('tokState');
    if (authToken){ s.textContent = 'token actif : ' + authToken.slice(0,12) + '…'; s.className = 'pill on'; }
    else { s.textContent = 'aucun token'; s.className = 'pill off'; }
  }
  function show(data, status){
    document.getElementById('out').textContent = 'HTTP ' + status + '\n' + JSON.stringify(data, null, 2);
  }
  async function call(method, url, body, withAuth = true){
    try{
      const headers = H();
      if (!withAuth) delete headers['Authorization'];
      const opt = { method, headers };
      if (body !== undefined) opt.body = JSON.stringify(body);
      const r = await fetch(url, opt);
      const data = await r.json().catch(() => ({}));
      show(data, r.status);
      return { data, status:r.status };
    }catch(e){ show({ erreur:String(e) }, 'ERR'); return { data:null, status:'ERR' }; }
  }

  // ---- 0 · Auth ----
  function randTel(){
    let n = '';
    for (let i=0;i<8;i++) n += Math.floor(Math.random()*10);
    document.getElementById('telephone').value = '+2250709' + n;
  }
  async function autoToken(){
    const tel = val('telephone');
    const reg = await call('POST','/api/v1/auth/register', {
      telephone: tel, nom: val('nom'), prenom: val('prenom'),
      password: val('password'), password_confirmation: val('password'),
    }, false);
    const code = reg.data && reg.data.dev_code_otp;
    if (!code){ return; } // erreur (ex. numéro déjà pris) : voir le résultat brut.
    const ver = await call('POST','/api/v1/auth/verify-otp', { telephone: tel, code, but:'inscription' }, false);
    if (ver.data && ver.data.token){ setToken(ver.data.token); listerMembres(); chargerSymptomes(); }
  }
  async function login(){
    const { data } = await call('POST','/api/v1/auth/login', { telephone: val('telephone'), password: val('password') }, false);
    if (data && data.token){ setToken(data.token); listerMembres(); chargerSymptomes(); }
  }

  // ---- 1 · Membres ----
  async function creerMembre(){
    const { data } = await call('POST','/api/v1/membres', {
      nom: val('m_nom'), prenom: val('m_prenom'), date_naissance: val('m_naissance'),
      sexe: val('m_sexe'), groupe_sanguin: val('m_groupe') || null, cmu_numero: val('m_cmu') || null,
      cmu_statut: 'actif',
    });
    if (data && data.membre) listerMembres();
  }
  async function listerMembres(){
    const { data } = await call('GET','/api/v1/membres');
    const box = document.getElementById('membres');
    box.innerHTML = '';
    if (!data || !data.membres){ return; }
    remplirSelectMembres(data.membres);
    if (data.membres.length === 0){ box.innerHTML = '<p class="muted">Aucun membre pour ce compte.</p>'; return; }
    for (const m of data.membres){
      const div = document.createElement('div');
      div.className = 'membre';
      div.innerHTML =
        '<b>' + esc(m.prenom) + ' ' + esc(m.nom) + '</b> · ' + esc(m.sexe) +
        ' · né(e) le ' + esc((m.date_naissance||'').slice(0,10)) +
        (m.groupe_sanguin ? ' · ' + esc(m.groupe_sanguin) : '') +
        '<div class="grid" style="margin-top:8px">' +
          '<button class="small" onclick="genererQr(' + m.id + ",'" + esc(m.prenom) + ' ' + esc(m.nom) + "')\">Générer QR</button>" +
          '<button class="small alt" onclick="voirAcces(' + m.id + ')">Voir le journal d\'accès</button>' +
          '<button class="small danger" onclick="supprMembre(' + m.id + ')">Supprimer</button>' +
        '</div>';
      box.appendChild(div);
    }
  }
  async function supprMembre(id){
    await call('DELETE','/api/v1/membres/' + id);
    listerMembres();
  }

  // ---- 2 · QR ----
  async function genererQr(id, nom){
    const { data } = await call('POST','/api/v1/membres/' + id + '/qr');
    if (!data || !data.qr){ return; }
    document.getElementById('qrHint').style.display = 'none';
    document.getElementById('qrzone').style.display = 'block';
    document.getElementById('qrMembre').textContent = nom;
    document.getElementById('qrToken').textContent = data.qr;

    const holder = document.getElementById('qrcode');
    holder.innerHTML = '';
    if (window.QRCode){ new QRCode(holder, { text: data.qr, width: 200, height: 200 }); }
    else { holder.innerHTML = '<span class="muted">(librairie QR non chargée — token ci-dessous)</span>'; }

    demarrerCompte(data.expires_in || 600);
  }
  function demarrerCompte(sec){
    if (countTimer) clearInterval(countTimer);
    const el = document.getElementById('qrCount');
    const tick = () => {
      if (sec <= 0){ el.textContent = 'expiré'; clearInterval(countTimer); return; }
      const m = String(Math.floor(sec/60)).padStart(2,'0');
      const s = String(sec%60).padStart(2,'0');
      el.textContent = m + ':' + s; sec--;
    };
    tick(); countTimer = setInterval(tick, 1000);
  }
  const voirAcces = (id) => call('GET','/api/v1/membres/' + id + '/acces');

  // ---- Antécédents & triage (2A.4 + F1.3) ----
  function remplirSelectMembres(membres){
    const sel = document.getElementById('ant_membre');
    const prev = sel.value;
    sel.innerHTML = '';
    for (const m of membres){
      const o = document.createElement('option');
      o.value = m.id; o.textContent = m.prenom + ' ' + m.nom;
      sel.appendChild(o);
    }
    if (prev) sel.value = prev;
  }
  async function chargerSymptomes(){
    const { data } = await call('GET','/api/v1/symptomes');
    const sel = document.getElementById('ant_symptome');
    sel.innerHTML = '';
    if (!data || !data.symptomes) return;
    for (const s of data.symptomes){
      const o = document.createElement('option');
      o.value = s.id; o.textContent = s.nom_fr;
      sel.appendChild(o);
    }
  }
  async function ajouterAntecedent(){
    const id = document.getElementById('ant_membre').value;
    if (!id){ show({ info:'Crée d\'abord un membre.' }, '—'); return; }
    await call('POST','/api/v1/membres/' + id + '/antecedents', {
      type: val('ant_type'), description: val('ant_desc'),
      impact_triage: parseInt(document.getElementById('ant_impact').value || '0', 10),
    });
  }
  const listerAntecedents = () => {
    const id = document.getElementById('ant_membre').value;
    if (id) call('GET','/api/v1/membres/' + id + '/antecedents');
  };
  async function lancerTriage(){
    const id = document.getElementById('ant_membre').value;
    const sympt = document.getElementById('ant_symptome').value;
    if (!sympt){ show({ info:'Aucun symptôme disponible (lance le seed SymptomeSeeder).' }, '—'); return; }
    const { data } = await call('POST','/api/v1/triage/analyser', {
      symptomes: [parseInt(sympt, 10)], membre_id: id ? parseInt(id, 10) : null,
    });
    const box = document.getElementById('triageOut');
    if (data && data.details_score){
      const d = data.details_score;
      box.innerHTML =
        '<p>Niveau : <span class="pill ' + (data.niveau === 'urgent' ? 'off' : 'on') + '">' + esc(data.niveau) + '</span> · '
        + 'Score total : <b>' + data.score_severite + '/100</b></p>'
        + '<p class="muted">Détail — symptômes : ' + d.symptomes + ' · réponses : ' + d.reponses
        + ' · <b>antécédents : ' + d.antecedents + '</b> (plafond 20)'
        + (data.drapeau_rouge ? ' · 🚩 drapeau rouge' : '') + '</p>';
    } else { box.innerHTML = ''; }
  }

  function esc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>
</body>
</html>
