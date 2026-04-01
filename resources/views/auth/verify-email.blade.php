<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Verify Email — AI Chatbot</title>
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
    max-width: 440px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.05);
    position: relative; z-index: 1;
    animation: slideUp .35s ease;
    text-align: center;
  }
  @keyframes slideUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

  /* Icon */
  .mail-icon-wrap {
    width: 64px; height: 64px; border-radius: 18px;
    background: rgba(91,94,244,0.1);
    color: #5b5ef4;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 24px auto;
  }
  .mail-icon-wrap svg { width: 32px; height: 32px; }

  /* Headings */
  h2 { font-size: 1.5rem; font-weight: 800; color: #111827; margin-bottom: 12px; }
  .subtitle { font-size: .9rem; color: #4b5563; margin-bottom: 28px; line-height: 1.5; }

  /* Primary Button */
  .btn-primary {
    width: 100%; padding: 13px;
    background: #5b5ef4;
    border: none; border-radius: 10px;
    color: #fff; font-size: .88rem; font-weight: 600;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(91,94,244,.25);
    transition: all .2s;
    font-family: inherit;
  }
  .btn-primary:active { transform: scale(0.98); box-shadow: none; }
  .btn-primary:hover { background: #4f51e0; box-shadow: 0 6px 16px rgba(91,94,244,.35); }

  /* Secondary Button */
  .btn-secondary {
    display: inline-block;
    background: none; border: none;
    color: #6b7280; font-size: .85rem; font-weight: 500;
    cursor: pointer; font-family: inherit; margin-top: 20px;
    text-decoration: underline; text-underline-offset: 3px;
    transition: color 0.2s;
  }
  .btn-secondary:hover { color: #111827; }

  /* Alerts */
  .alert-success {
    background: #ecfdf5; color: #065f46;
    padding: 12px; border-radius: 10px;
    font-size: 0.85rem; font-weight: 500;
    margin-bottom: 24px; text-align: left;
    display: flex; align-items: center; gap: 8px;
    border: 1px solid #d1fae5;
  }
</style>
</head>
<body>

  <div class="card">
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
      <strong style="color: #111827;">{{ Auth::user()->email ?? 'your email' }}</strong>. <br/><br/>
      Please check your inbox (and spam folder) and click the link to activate your account so you can access the Chat dashboard.
    </p>

    <!-- Resend Verification Form -->
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
    
    <!-- Hidden data anchor to pass Blade session securely to Javascript without IDE lint errors -->
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
          // 120 seconds = 120,000 milliseconds
          const endTime = new Date().getTime() + 120000;
          localStorage.setItem('verifyCooldown', endTime);
        }
      });
    });
  </script>

</body>
</html>
