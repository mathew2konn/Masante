<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaSante — Test Triage (navigateur)</title>
  <style>
    :root{
      --blue600:#1E6BB8; --blue700:#17569A; --blue900:#0C3463; --blue50:#F2F8FD;
      --ink700:#33475A; --ink500:#62768A; --line:#D9E2EA; --surface:#FFFFFF;
      --green-bg:#E4F5EA; --green-tx:#146C3A; --orange-bg:#FDF0DD; --orange-tx:#9A5A07;
      --red-bg:#FCE8E8; --red-tx:#A11616;
    }
    *{box-sizing:border-box} body{margin:0;font-family:system-ui,Roboto,Arial,sans-serif;
      background:linear-gradient(180deg,#F2F8FD,#DCEAF8,#93BBE6,#1E5BAA);min-height:100vh;color:var(--ink700)}
    .wrap{max-width:880px;margin:0 auto;padding:24px}
    h1{color:var(--blue900);font-size:24px;margin:4px 0}
    .sub{color:var(--ink700);margin:0 0 16px}
    .card{background:var(--surface);border-radius:24px;padding:20px;margin-bottom:16px;
      box-shadow:0 2px 8px rgba(12,52,99,.08)}
    .grid{display:flex;flex-wrap:wrap;gap:10px}
    button{border:0;border-radius:999px;padding:12px 18px;font-size:15px;font-weight:700;
      color:#fff;background:var(--blue600);cursor:pointer;min-height:48px}
    button:hover{background:var(--blue700)} button.alt{background:#fff;color:var(--blue600);border:1px solid var(--blue600)}
    pre{background:#0C3463;color:#DCEAF8;border-radius:12px;padding:16px;overflow:auto;font-size:13px;max-height:420px}
    .badge{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:999px;font-weight:800;font-size:16px}
    .dot{width:10px;height:10px;border-radius:50%}
    .leger{background:var(--green-bg);color:var(--green-tx)} .leger .dot{background:#1F9D57}
    .modere{background:var(--orange-bg);color:var(--orange-tx)} .modere .dot{background:#E8911F}
    .urgent{background:var(--red-bg);color:var(--red-tx)} .urgent .dot{background:#DC2626}
    .muted{color:var(--ink500);font-size:13px}
    label{font-weight:600}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>🏥 MaSante — Test du Module 1 (Triage)</h1>
    <p class="sub">Page de test servie par le backend. Cliquez sur les boutons : les requêtes (GET et POST) partent vers l'API et le résultat s'affiche en bas.</p>

    <div class="card">
      <div class="grid">
        <button onclick="health()">0. Health-check</button>
        <button onclick="symptomes()">F1.1 — Symptômes</button>
        <button onclick="historique()">F1.6 — Historique</button>
      </div>
    </div>

    <div class="card">
      <strong>F1.3 — Analyser un triage</strong>
      <div class="grid" style="margin-top:10px">
        <button onclick="analyser('leger')">Cas LÉGER</button>
        <button onclick="analyser('modere')">Cas MODÉRÉ</button>
        <button onclick="analyser('urgent')">Cas URGENT (drapeau rouge)</button>
        <button onclick="analyser('pediatrie')">Cas PÉDIATRIE</button>
        <button class="alt" onclick="analyser('invalide')">Validation (0 symptôme → 422)</button>
      </div>
      <p class="muted" style="margin-top:10px">Dernier triage : <span id="lastId">aucun</span>
        <button class="alt" style="padding:6px 12px;min-height:0" onclick="fiche()">F1.8 — Voir la fiche</button>
      </p>
    </div>

    <div class="card">
      <div id="resultBadge"></div>
      <pre id="out">Résultat ici…</pre>
    </div>
  </div>

<script>
  let lastTriageId = null;

  // En-têtes communs : JSON + saut de l'avertissement Ngrok.
  const H = { 'Accept':'application/json', 'Content-Type':'application/json', 'ngrok-skip-browser-warning':'true' };

  const cas = {
    leger:     { symptomes:[3] },
    modere:    { symptomes:[1,4], reponses:[{symptome_id:1,cle:'duree_jours',valeur:5},{symptome_id:4,cle:'intensite',valeur:6}], patient_age:30, patient_sexe:'F' },
    urgent:    { symptomes:[7], patient_nom:'Kouassi', patient_age:40, patient_sexe:'M' },
    pediatrie: { symptomes:[1], patient_nom:'Bébé Aya', patient_age:5 },
    invalide:  { symptomes:[] },
  };

  function show(data, status){
    document.getElementById('out').textContent = JSON.stringify(data, null, 2);
    const b = document.getElementById('resultBadge'); b.innerHTML='';
    if (data && data.niveau){
      const lib = {leger:'LÉGER',modere:'MODÉRÉ',urgent:'URGENT'}[data.niveau];
      b.innerHTML = '<span class="badge '+data.niveau+'"><span class="dot"></span>'+lib+' — score '+data.score_severite+'/100</span>';
    } else if (status){
      b.innerHTML = '<span class="muted">HTTP '+status+'</span>';
    }
  }

  async function call(method, url, body){
    try{
      const opt = { method, headers:H };
      if (body !== undefined) opt.body = JSON.stringify(body);
      const r = await fetch(url, opt);
      const data = await r.json();
      show(data, r.status);
      return data;
    }catch(e){ show({erreur:String(e)}); }
  }

  const health     = () => call('GET', '/api/health');
  const symptomes  = () => call('GET', '/api/v1/symptomes');
  const historique = () => call('GET', '/api/v1/triage/historique');

  async function analyser(type){
    const data = await call('POST', '/api/v1/triage/analyser', cas[type]);
    if (data && data.triage_id){ lastTriageId = data.triage_id; document.getElementById('lastId').textContent = '#'+lastTriageId; }
  }
  function fiche(){
    if (!lastTriageId){ show({info:'Lancez d\'abord une analyse pour obtenir un triage_id.'}); return; }
    call('GET', '/api/v1/triage/'+lastTriageId+'/fiche');
  }
</script>
</body>
</html>
