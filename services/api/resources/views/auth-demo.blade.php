<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaSante — Test Auth (navigateur)</title>
  <style>
    :root{
      --blue600:#1E6BB8; --blue700:#17569A; --blue900:#0C3463; --blue50:#F2F8FD;
      --ink700:#33475A; --ink500:#62768A; --line:#D9E2EA; --surface:#FFFFFF;
      --green-bg:#E4F5EA; --green-tx:#146C3A; --red-bg:#FCE8E8; --red-tx:#A11616;
    }
    *{box-sizing:border-box} body{margin:0;font-family:system-ui,Roboto,Arial,sans-serif;
      background:linear-gradient(180deg,#F2F8FD,#DCEAF8,#93BBE6,#1E5BAA);min-height:100vh;color:var(--ink700)}
    .wrap{max-width:880px;margin:0 auto;padding:24px}
    h1{color:var(--blue900);font-size:24px;margin:4px 0}
    .sub{color:var(--ink700);margin:0 0 16px}
    .card{background:var(--surface);border-radius:24px;padding:20px;margin-bottom:16px;
      box-shadow:0 2px 8px rgba(12,52,99,.08)}
    .grid{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
    button{border:0;border-radius:999px;padding:12px 18px;font-size:15px;font-weight:700;
      color:#fff;background:var(--blue600);cursor:pointer;min-height:48px}
    button:hover{background:var(--blue700)} button.alt{background:#fff;color:var(--blue600);border:1px solid var(--blue600)}
    button.danger{background:#DC2626} button.danger:hover{background:#B91C1C}
    pre{background:#0C3463;color:#DCEAF8;border-radius:12px;padding:16px;overflow:auto;font-size:13px;max-height:420px}
    .muted{color:var(--ink500);font-size:13px}
    label{font-weight:600;display:block;margin:8px 0 4px}
    input{width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;font-size:15px;background:#F3F7FB}
    .row{display:flex;gap:12px;flex-wrap:wrap} .row > div{flex:1;min-width:180px}
    .pill{display:inline-block;padding:4px 12px;border-radius:999px;font-weight:700;font-size:13px}
    .on{background:var(--green-bg);color:var(--green-tx)} .off{background:var(--red-bg);color:var(--red-tx)}
    .step{font-weight:800;color:var(--blue900)}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>🔐 MaSante — Test du Module 2A.1 (Authentification)</h1>
    <p class="sub">Page de test servie par le backend (même origine, aucun CORS). En dev, le code OTP est
      renvoyé par l'API et reporté automatiquement dans le champ « code ». État du token :
      <span id="tokState" class="pill off">aucun token</span></p>

    <div class="card">
      <div class="row">
        <div><label>Téléphone</label><input id="telephone" value="+2250709111222"></div>
        <div><label>Prénom</label><input id="prenom" value="Awa"></div>
        <div><label>Nom</label><input id="nom" value="Kouadio"></div>
      </div>
      <div class="row">
        <div><label>Mot de passe</label><input id="password" value="Test1234pass"></div>
        <div><label>Code OTP (auto en dev)</label><input id="code" placeholder="6 chiffres"></div>
      </div>
    </div>

    <div class="card">
      <p class="step">1 · Inscription → 2 · Vérification OTP → (token) → 3 · Profil → 4 · Déconnexion</p>
      <div class="grid">
        <button onclick="register()">1. S'inscrire (register)</button>
        <button onclick="verify()">2. Vérifier le code (verify-otp)</button>
        <button class="alt" onclick="resend()">Renvoyer un code (resend-otp)</button>
        <button onclick="me()">3. Mon profil (me)</button>
        <button class="danger" onclick="logout()">4. Déconnexion (logout)</button>
      </div>
      <p class="muted" style="margin-top:10px">Connexion d'un compte déjà vérifié (ex. seedé
        <code>+2250700000000</code> / <code>password</code>) :</p>
      <div class="grid">
        <button class="alt" onclick="login()">Se connecter (login)</button>
        <button class="alt" onclick="meNoToken()">Tester /me SANS token (→ 401)</button>
      </div>
    </div>

    <div class="card">
      <pre id="out">Résultat ici…</pre>
    </div>
  </div>

<script>
  let authToken = null;

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
    }catch(e){ show({ erreur:String(e) }, 'ERR'); return { data:null }; }
  }

  async function register(){
    const { data } = await call('POST','/api/v1/auth/register', {
      telephone: val('telephone'), nom: val('nom'), prenom: val('prenom'),
      password: val('password'), password_confirmation: val('password'),
    }, false);
    if (data && data.dev_code_otp) document.getElementById('code').value = data.dev_code_otp;
  }

  async function resend(){
    const { data } = await call('POST','/api/v1/auth/resend-otp', { telephone: val('telephone'), but:'inscription' }, false);
    if (data && data.dev_code_otp) document.getElementById('code').value = data.dev_code_otp;
  }

  async function verify(){
    const { data } = await call('POST','/api/v1/auth/verify-otp', {
      telephone: val('telephone'), code: val('code'), but:'inscription',
    }, false);
    if (data && data.token) setToken(data.token);
  }

  async function login(){
    const { data } = await call('POST','/api/v1/auth/login', {
      telephone: val('telephone'), password: val('password'),
    }, false);
    if (data && data.token) setToken(data.token);
  }

  const me = () => call('GET','/api/v1/auth/me');
  const meNoToken = () => call('GET','/api/v1/auth/me', undefined, false);

  async function logout(){
    await call('POST','/api/v1/auth/logout');
    setToken(null);
  }
</script>
</body>
</html>
