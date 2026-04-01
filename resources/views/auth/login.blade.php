<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Sign In — AI Chatbot</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', sans-serif;
    background: #f4f6fb;
    color: #111827;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    -webkit-font-smoothing: antialiased;
  }

  /* Subtle background blobs */
  body::before {
    content: '';
    position: fixed; inset: 0; pointer-events: none;
    background:
      radial-gradient(ellipse 70% 50% at 15% 20%, rgba(91,94,244,.08) 0%, transparent 60%),
      radial-gradient(ellipse 60% 50% at 85% 80%, rgba(124,127,247,.06) 0%, transparent 60%);
  }

  /* Card */
  .card {
    background: #ffffff;
    border: 1px solid rgba(0,0,0,.07);
    border-radius: 20px;
    padding: 40px 38px;
    width: 100%;
    max-width: 400px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.05);
    position: relative; z-index: 1;
    animation: slideUp .35s ease;
  }
  @keyframes slideUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

  /* Logo */
  .logo {
    display: flex; align-items: center; gap: 11px;
    margin-bottom: 28px;
  }
  .logo-icon {
    width: 40px; height: 40px; border-radius: 11px;
    background: #5b5ef4;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(91,94,244,.35);
  }
  .logo-icon svg { width: 20px; height: 20px; color: #fff; }
  .logo-text h1 { font-size: 1rem; font-weight: 700; color: #111827; }
  .logo-text p  { font-size: .68rem; color: #9ca3af; margin-top: 1px; }

  /* Headings */
  h2 { font-size: 1.5rem; font-weight: 800; color: #111827; margin-bottom: 5px; }
  .subtitle { font-size: .83rem; color: #6b7280; margin-bottom: 26px; }

  /* Fields */
  .field { margin-bottom: 16px; }
  label {
    display: block; font-size: .78rem; font-weight: 500;
    color: #374151; margin-bottom: 6px;
  }
  input[type=email],
  input[type=password] {
    width: 100%; padding: 11px 14px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    color: #111827; font-family: inherit; font-size: .87rem;
    outline: none; transition: border-color .18s, box-shadow .18s;
  }
  input:focus {
    border-color: #5b5ef4;
    box-shadow: 0 0 0 3px rgba(91,94,244,.12);
    background: #fff;
  }
  input::placeholder { color: #d1d5db; }
  .error { font-size: .75rem; color: #ef4444; margin-top: 5px; }

  /* Remember */
  .remember {
    display: flex; align-items: center; gap: 8px;
    font-size: .78rem; color: #6b7280;
    margin-bottom: 22px; cursor: pointer;
  }
  .remember input { width: auto; cursor: pointer; accent-color: #5b5ef4; }

  /* Sign in button */
  .btn-login {
    width: 100%; padding: 12px;
    background: #5b5ef4;
    border: none; border-radius: 10px;
    color: #fff; font-family: inherit; font-size: .9rem; font-weight: 600;
    cursor: pointer; transition: all .18s;
    letter-spacing: .2px;
  }
  .btn-login:hover {
    background: #4d50e0;
    box-shadow: 0 4px 20px rgba(91,94,244,.35);
    transform: translateY(-1px);
  }
  .btn-login:active { transform: translateY(0); }

  /* Register link */
  .register-link {
    text-align: center; margin-top: 20px;
    font-size: .78rem; color: #9ca3af;
  }
  .register-link a { color: #5b5ef4; text-decoration: none; font-weight: 500; }
  .register-link a:hover { text-decoration: underline; }

  /* Alert */
  .alert-error {
    background: rgba(239,68,68,.07);
    border: 1px solid rgba(239,68,68,.2);
    border-radius: 10px; padding: 11px 14px;
    font-size: .8rem; color: #ef4444;
    margin-bottom: 18px;
  }
  .alert-info {
    background: rgba(91,94,244,.08);
    border: 1px solid rgba(91,94,244,.2);
    border-radius: 10px; padding: 11px 14px;
    font-size: .8rem; color: #5b5ef4;
    margin-bottom: 18px;
  }
</style>
</head>
<body>

<div class="card">
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
        <a href="{{ route('password.request') }}" style="font-size: .78rem; color: #5b5ef4; text-decoration: none; font-weight: 500;">Forgot password?</a>
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
