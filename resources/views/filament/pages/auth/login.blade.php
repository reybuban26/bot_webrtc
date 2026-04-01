{{-- Custom Filament Admin Login Page --}}
{{-- Matches the /login and /chat design --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Admin Sign In — AI Chatbot</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:      #f4f6fb;
    --panel:   #ffffff;
    --txt:     #111827;
    --txt-2:   #6b7280;
    --txt-3:   #9ca3af;
    --border:  #e5e7eb;
    --input-bg:#f9fafb;
    --accent:  #5b5ef4;
    --accent-h:#4d50e0;
    --accent-d:rgba(91,94,244,.12);
    --err-bg:  rgba(239,68,68,.07);
    --err-b:   rgba(239,68,68,.2);
    --err-c:   #ef4444;
  }
  .dark {
    --bg:      #0f1117;
    --panel:   #1a1f2e;
    --txt:     #f1f5f9;
    --txt-2:   #94a3b8;
    --txt-3:   #64748b;
    --border:  rgba(255,255,255,.08);
    --input-bg:rgba(255,255,255,.04);
    --accent:  #6366f1;
    --accent-h:#818cf8;
    --accent-d:rgba(99,102,241,.15);
    --err-bg:  rgba(239,68,68,.12);
    --err-b:   rgba(239,68,68,.3);
    --err-c:   #f87171;
  }

  body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    color: var(--txt);
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    -webkit-font-smoothing: antialiased;
    transition: background .25s, color .25s;
  }
  body::before {
    content: ''; position: fixed; inset: 0; pointer-events: none;
    background:
      radial-gradient(ellipse 70% 50% at 15% 20%, rgba(91,94,244,.08) 0%, transparent 60%),
      radial-gradient(ellipse 60% 50% at 85% 80%, rgba(124,127,247,.06) 0%, transparent 60%);
  }

  /* Card */
  .card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 40px 38px;
    width: 100%; max-width: 400px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
    position: relative; z-index: 1;
    animation: slideUp .35s ease;
    transition: background .25s, border-color .25s;
  }
  @keyframes slideUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

  /* Logo */
  .logo { display: flex; align-items: center; gap: 11px; margin-bottom: 28px; }
  .logo-icon {
    width: 40px; height: 40px; border-radius: 11px; background: var(--accent);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(91,94,244,.35);
  }
  .logo-icon svg { width: 20px; height: 20px; color: #fff; }
  .logo-text h1 { font-size: 1rem; font-weight: 700; color: var(--txt); }
  .logo-text p  { font-size: .68rem; color: var(--txt-3); margin-top: 1px; }

  /* Theme toggle */
  .theme-toggle {
    position: absolute; top: 16px; right: 16px;
    background: var(--input-bg); border: 1px solid var(--border);
    border-radius: 8px; padding: 6px 8px;
    cursor: pointer; display: flex; align-items: center; gap: 5px;
    font-size: .72rem; color: var(--txt-2); transition: all .18s;
  }
  .theme-toggle:hover { border-color: var(--accent); color: var(--accent); }

  h2 { font-size: 1.5rem; font-weight: 800; color: var(--txt); margin-bottom: 5px; }
  .subtitle { font-size: .83rem; color: var(--txt-2); margin-bottom: 26px; }

  .field { margin-bottom: 16px; }
  label { display: block; font-size: .78rem; font-weight: 500; color: var(--txt); margin-bottom: 6px; }
  input[type=email],
  input[type=password],
  input[type=text] {
    width: 100%; padding: 11px 14px;
    background: var(--input-bg); border: 1px solid var(--border);
    border-radius: 10px; color: var(--txt);
    font-family: inherit; font-size: .87rem;
    outline: none; transition: border-color .18s, box-shadow .18s, background .25s;
  }
  input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-d);
    background: var(--panel);
  }
  input::placeholder { color: var(--txt-3); }

  .error { font-size: .75rem; color: var(--err-c); margin-top: 5px; }
  .alert-error {
    background: var(--err-bg); border: 1px solid var(--err-b);
    border-radius: 10px; padding: 11px 14px;
    font-size: .8rem; color: var(--err-c); margin-bottom: 18px;
  }

  /* Password wrapper (for show/hide eye) */
  .pass-wrap { position: relative; }
  .pass-wrap input { padding-right: 40px; }
  .pass-eye {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--txt-3); padding: 0; display: flex; align-items: center;
    transition: color .18s;
  }
  .pass-eye:hover { color: var(--accent); }

  /* Remember */
  .remember {
    display: flex; align-items: center; gap: 8px;
    font-size: .78rem; color: var(--txt-2);
    margin-bottom: 22px; cursor: pointer;
  }
  .remember input { width: auto; cursor: pointer; accent-color: var(--accent); }

  /* Button */
  .btn-login {
    width: 100%; padding: 12px;
    background: var(--accent); border: none; border-radius: 10px;
    color: #fff; font-family: inherit; font-size: .9rem; font-weight: 600;
    cursor: pointer; transition: all .18s; letter-spacing: .2px;
  }
  .btn-login:hover {
    background: var(--accent-h);
    box-shadow: 0 4px 20px rgba(91,94,244,.35);
    transform: translateY(-1px);
  }
  .btn-login:active { transform: translateY(0); }

  /* Badge */
  .admin-badge {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--accent-d); color: var(--accent);
    font-size: .65rem; font-weight: 600; padding: 3px 8px;
    border-radius: 20px; margin-bottom: 14px; letter-spacing: .5px;
    text-transform: uppercase;
  }
</style>
</head>
<body id="page-body">

<div class="card">

  <!-- Theme toggle -->
  <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
    <svg id="theme-icon-dark" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
    <svg id="theme-icon-light" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
  </button>

  <div class="logo">
    <div class="logo-icon">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1 1 .03 2.7-1.41 2.38l-1.98-.495a9.038 9.038 0 01-4.61 0l-1.98.495c-1.44.32-2.41-1.38-1.41-2.38L5 14.5"/>
      </svg>
    </div>
    <div class="logo-text">
      <h1>AI Chatbot</h1>
      <p>Admin Panel</p>
    </div>
  </div>

  <div class="admin-badge">
    <svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
    Administrator
  </div>

  <h2>Welcome back</h2>
  <p class="subtitle">Sign in to your admin account</p>

  @if ($errors->any())
    <div class="alert-error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('filament.admin.auth.login') }}" wire:submit.prevent="authenticate">
    @csrf
    <div class="field">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email"
             value="{{ old('email') }}" autocomplete="email" required
             placeholder="admin@example.com"/>
      @error('email')<div class="error">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="password">Password</label>
      <div class="pass-wrap">
        <input type="password" id="password" name="password"
               autocomplete="current-password" required
               placeholder="••••••••"/>
        <button type="button" class="pass-eye" onclick="togglePass()" title="Show/hide password">
          <svg id="eye-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      @error('password')<div class="error">{{ $message }}</div>@enderror
    </div>

    <label class="remember">
      <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}/>
      Remember me
    </label>

    <button type="submit" class="btn-login">Sign in →</button>
  </form>
</div>

<script>
  // ── Theme ────────────────────────────────────────────
  const body = document.getElementById('page-body');
  const dark = localStorage.getItem('theme') === 'dark';
  if (dark) applyDark();

  function applyDark() {
    body.classList.add('dark');
    document.getElementById('theme-icon-dark').style.display  = 'none';
    document.getElementById('theme-icon-light').style.display = '';
  }
  function applyLight() {
    body.classList.remove('dark');
    document.getElementById('theme-icon-dark').style.display  = '';
    document.getElementById('theme-icon-light').style.display = 'none';
  }
  function toggleTheme() {
    if (body.classList.contains('dark')) {
      localStorage.setItem('theme', 'light'); applyLight();
    } else {
      localStorage.setItem('theme', 'dark'); applyDark();
    }
  }

  // ── Password show/hide ───────────────────────────────
  function togglePass() {
    const inp = document.getElementById('password');
    inp.type = inp.type === 'password' ? 'text' : 'password';
  }
</script>
</body>
</html>
