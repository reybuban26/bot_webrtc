<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Verify Email — AI Chatbot</title>
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
    max-width: 440px;
    box-shadow: var(--auth-card-shadow);
    position: relative; z-index: 1;
    animation: slideUp .35s ease;
    text-align: center;
    transition: background .3s, border-color .3s, box-shadow .3s;
  }
  @keyframes slideUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

  .mail-icon-wrap {
    width: 64px; height: 64px; border-radius: 18px;
    background: var(--auth-accent-soft);
    color: var(--auth-accent);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 24px auto;
  }
  .mail-icon-wrap svg { width: 32px; height: 32px; }

  h2 { font-size: 1.5rem; font-weight: 800; color: var(--auth-text); margin-bottom: 12px; }
  .subtitle { font-size: .9rem; color: var(--auth-text-2); margin-bottom: 28px; line-height: 1.5; }
  .subtitle strong { color: var(--auth-text); }

  .btn-primary {
    width: 100%; padding: 13px;
    background: var(--auth-accent);
    border: none; border-radius: 10px;
    color: #fff; font-size: .88rem; font-weight: 600;
    cursor: pointer;
    box-shadow: 0 4px 12px var(--auth-accent-glow);
    transition: all .2s;
    font-family: inherit;
  }
  .btn-primary:active { transform: scale(0.98); box-shadow: none; }
  .btn-primary:hover { background: var(--auth-accent-hover); box-shadow: 0 6px 16px var(--auth-accent-glow); }

  .btn-secondary {
    display: inline-block;
    background: none; border: none;
    color: var(--auth-text-2); font-size: .85rem; font-weight: 500;
    cursor: pointer; font-family: inherit; margin-top: 20px;
    text-decoration: underline; text-underline-offset: 3px;
    transition: color 0.2s;
  }
  .btn-secondary:hover { color: var(--auth-text); }

  .alert-success {
    background: var(--auth-alert-success-bg); color: var(--auth-alert-success-color);
    padding: 12px; border-radius: 10px;
    font-size: 0.85rem; font-weight: 500;
    margin-bottom: 24px; text-align: left;
    display: flex; align-items: center; gap: 8px;
    border: 1px solid var(--auth-alert-success-border);
  }
</style>
</head>
<body>

  <div class="card">
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle dark mode">
      <svg class="icon-sun" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path stroke-linecap="round" d="M12 1v2m0 18v2M4.22 4.22l1.42 1.42m12.72 12.72l1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
      <svg class="icon-moon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
    </button>

    <div class="mail-icon-wrap">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
      </svg>
    </div>

    <h2>Verify Your Email</h2>

    @if (session('message'))
        <div class="alert-success">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:20px;height:20px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            {{ session('message') }}
        </div>
    @endif

    <p class="subtitle">
      Welcome aboard! We've sent a verification link to your email address: <br/>
      <strong>{{ Auth::user()->email ?? 'your email' }}</strong>. <br/><br/>
      Please check your inbox (and spam folder) and click the link to activate your account so you can access the Chat dashboard.
    </p>

    <form id="resendForm" method="POST" action="{{ route('verification.send') }}">
      @csrf
      <button id="btnResend" type="submit" class="btn-primary">
        Resend Verification Link
      </button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn-secondary">Log out</button>
    </form>

    <div id="pageMetaData" data-registered="{{ session()->pull('just_registered') ? '1' : '0' }}" style="display: none;"></div>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const btn = document.getElementById('btnResend');
      const form = document.getElementById('resendForm');

      let cooldownEnd = localStorage.getItem('verifyCooldown');

      const isNewlyRegistered = document.getElementById('pageMetaData').getAttribute('data-registered') === '1';
      if (isNewlyRegistered) {
          cooldownEnd = new Date().getTime() + 120000;
          localStorage.setItem('verifyCooldown', cooldownEnd);
      }

      function startTimer(endTime) {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';

        const interval = setInterval(() => {
          const now = new Date().getTime();
          const distance = endTime - now;

          if (distance <= 0) {
            clearInterval(interval);
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
            btn.textContent = 'Resend Verification Link';
            localStorage.removeItem('verifyCooldown');
          } else {
            const seconds = Math.floor(distance / 1000);
            btn.textContent = `Wait ${seconds}s to resend`;
          }
        }, 1000);
      }

      if (cooldownEnd) {
        cooldownEnd = parseInt(cooldownEnd);
        if (new Date().getTime() < cooldownEnd) {
          startTimer(cooldownEnd);
        } else {
          localStorage.removeItem('verifyCooldown');
        }
      }

      form.addEventListener('submit', () => {
        if (!btn.disabled) {
          const endTime = new Date().getTime() + 120000;
          localStorage.setItem('verifyCooldown', endTime);
        }
      });
    });
  </script>

</body>
</html>
