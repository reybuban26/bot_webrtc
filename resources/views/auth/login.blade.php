<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Sign In — AI Chatbot</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="/css/theme.css"/>
<script src="/js/theme.js"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', sans-serif;
    background: var(--auth-bg);
    color: var(--auth-text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    -webkit-font-smoothing: antialiased;
    transition: background .3s, color .3s;
  }

  body::before {
    content: '';
    position: fixed; inset: 0; pointer-events: none;
    background:
      radial-gradient(ellipse 70% 50% at 15% 20%, var(--auth-blob1) 0%, transparent 60%),
      radial-gradient(ellipse 60% 50% at 85% 80%, var(--auth-blob2) 0%, transparent 60%);
  }

  .card {
    background: var(--auth-card-bg);
    border: 1px solid var(--auth-card-border);
    border-radius: 20px;
    padding: 40px 38px;
    width: 100%;
    max-width: 400px;
    box-shadow: var(--auth-card-shadow);
    position: relative; z-index: 1;
    animation: slideUp .35s ease;
    transition: background .3s, border-color .3s, box-shadow .3s;
  }
  @keyframes slideUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

  .logo {
    display: flex; align-items: center; gap: 11px;
    margin-bottom: 28px;
  }
  .logo-icon {
    width: 40px; height: 40px; border-radius: 11px;
    background: var(--auth-accent);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px var(--auth-accent-glow);
  }
  .logo-icon svg { width: 20px; height: 20px; color: #fff; }
  .logo-text h1 { font-size: 1rem; font-weight: 700; color: var(--auth-text); }
  .logo-text p  { font-size: .68rem; color: var(--auth-text-3); margin-top: 1px; }

  h2 { font-size: 1.5rem; font-weight: 800; color: var(--auth-text); margin-bottom: 5px; }
  .subtitle { font-size: .83rem; color: var(--auth-text-2); margin-bottom: 26px; }

  .field { margin-bottom: 16px; }
  label {
    display: block; font-size: .78rem; font-weight: 500;
    color: var(--auth-text-label); margin-bottom: 6px;
  }
  input[type=email],
  input[type=password] {
    width: 100%; padding: 11px 14px;
    background: var(--auth-input-bg);
    border: 1px solid var(--auth-input-border);
    border-radius: 10px;
    color: var(--auth-text); font-family: inherit; font-size: .87rem;
    outline: none; transition: border-color .18s, box-shadow .18s, background .18s;
  }
  input:focus {
    border-color: var(--auth-accent);
    box-shadow: 0 0 0 3px var(--auth-accent-ring);
    background: var(--auth-input-focus-bg);
  }
  input::placeholder { color: var(--auth-placeholder); }
  .error { font-size: .75rem; color: var(--auth-error); margin-top: 5px; }

  .remember {
    display: flex; align-items: center; gap: 8px;
    font-size: .78rem; color: var(--auth-text-2);
    margin-bottom: 22px; cursor: pointer;
  }
  .remember input { width: auto; cursor: pointer; accent-color: var(--auth-accent); }

  .btn-login {
    width: 100%; padding: 12px;
    background: var(--auth-accent);
    border: none; border-radius: 10px;
    color: #fff; font-family: inherit; font-size: .9rem; font-weight: 600;
    cursor: pointer; transition: all .18s;
    letter-spacing: .2px;
  }
  .btn-login:hover {
    background: var(--auth-accent-hover);
    box-shadow: 0 4px 20px var(--auth-accent-glow);
    transform: translateY(-1px);
  }
  .btn-login:active { transform: translateY(0); }

  .register-link {
    text-align: center; margin-top: 20px;
    font-size: .78rem; color: var(--auth-text-3);
  }
  .register-link a { color: var(--auth-accent); text-decoration: none; font-weight: 500; }
  .register-link a:hover { text-decoration: underline; }

  .alert-error {
    background: var(--auth-error-bg);
    border: 1px solid var(--auth-error-border);
    border-radius: 10px; padding: 11px 14px;
    font-size: .8rem; color: var(--auth-error);
    margin-bottom: 18px;
  }
  .alert-info {
    background: var(--auth-alert-info-bg);
    border: 1px solid var(--auth-alert-info-border);
    border-radius: 10px; padding: 11px 14px;
    font-size: .8rem; color: var(--auth-alert-info-color);
    margin-bottom: 18px;
  }
</style>
</head>
<body>

<div class="card">
  <button class="theme-toggle" onclick="toggleTheme()" title="Toggle dark mode">
    <svg class="icon-sun" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path stroke-linecap="round" d="M12 1v2m0 18v2M4.22 4.22l1.42 1.42m12.72 12.72l1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
    <svg class="icon-moon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
  </button>

  <div class="logo">
    <div class="logo-icon">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1 1 .03 2.7-1.41 2.38l-1.98-.495a9.038 9.038 0 01-4.61 0l-1.98.495c-1.44.32-2.41-1.38-1.41-2.38L5 14.5"/>
      </svg>
    </div>
    <div class="logo-text">
      <h1>AI Chatbot</h1>
      <p>Powered by Maxx</p>
    </div>
  </div>

  <h2>Welcome back</h2>
  <p class="subtitle">Sign in to continue your conversations</p>

  @if ($errors->any())
    <div class="alert-error">{{ $errors->first() }}</div>
  @endif
  @if (session('status'))
    <div class="alert-info">{{ session('status') }}</div>
  @endif

  <form method="POST" action="{{ route('login') }}">
    @csrf
    <div class="field">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email"
             value="{{ old('email') }}" autocomplete="email" required
             placeholder="you@example.com"/>
      @error('email')<div class="error">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px;">
        <label for="password" style="margin-bottom: 0;">Password</label>
        <a href="{{ route('password.request') }}" style="font-size: .78rem; color: var(--auth-accent); text-decoration: none; font-weight: 500;">Forgot password?</a>
      </div>
      <input type="password" id="password" name="password"
             autocomplete="current-password" required
             placeholder="••••••••"/>
      @error('password')<div class="error">{{ $message }}</div>@enderror
    </div>

    <label class="remember">
      <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : ''}}/>
      Remember me for 30 days
    </label>

    <button type="submit" class="btn-login">Sign in →</button>
  </form>

  <div class="register-link">
    Don't have an account? <a href="{{ route('register') }}">Create one</a>
  </div>
</div>

</body>
</html>
