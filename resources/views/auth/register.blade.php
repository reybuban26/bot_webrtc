<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Create Account — AI Chatbot</title>
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
    max-width: 460px;
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
  input[type=text],
  input[type=email],
  input[type=password],
  input[type=tel],
  input[type=date] {
    width: 100%; padding: 11px 14px;
    background: var(--auth-input-bg);
    border: 1px solid var(--auth-input-border);
    border-radius: 10px;
    color: var(--auth-text); font-family: inherit; font-size: .87rem;
    outline: none; transition: border-color .18s, box-shadow .18s, background .18s;
    -webkit-appearance: none; appearance: none;
  }
  input[type=date]::-webkit-calendar-picker-indicator {
    opacity: .5; cursor: pointer;
  }
  .dark input[type=date]::-webkit-calendar-picker-indicator {
    filter: invert(1);
  }
  input:focus {
    border-color: var(--auth-accent);
    box-shadow: 0 0 0 3px var(--auth-accent-ring);
    background: var(--auth-input-focus-bg);
  }
  input::placeholder { color: var(--auth-placeholder); }
  .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
  .field-row .field { margin-bottom: 0; }
  .error { font-size: .75rem; color: var(--auth-error); margin-top: 5px; }

  .btn-register {
    width: 100%; padding: 12px; margin-top: 2px;
    background: var(--auth-accent);
    border: none; border-radius: 10px;
    color: #fff; font-family: inherit; font-size: .9rem; font-weight: 600;
    cursor: pointer; transition: all .18s;
    letter-spacing: .2px;
  }
  .btn-register:hover {
    background: var(--auth-accent-hover);
    box-shadow: 0 4px 20px var(--auth-accent-glow);
    transform: translateY(-1px);
  }
  .btn-register:active { transform: translateY(0); }

  .login-link {
    text-align: center; margin-top: 20px;
    font-size: .78rem; color: var(--auth-text-3);
  }
  .login-link a { color: var(--auth-accent); text-decoration: none; font-weight: 500; }
  .login-link a:hover { text-decoration: underline; }

  .alert-error {
    background: var(--auth-error-bg);
    border: 1px solid var(--auth-error-border);
    border-radius: 10px; padding: 11px 14px;
    font-size: .8rem; color: var(--auth-error);
    margin-bottom: 18px;
  }

  /* Password Strength */
  .password-input-wrapper { display: flex; align-items: center; position: relative; width: 100%; }
  .password-input-wrapper input { padding-right: 40px; width: 100%; }
  .password-toggle { position: absolute; right: 12px; cursor: pointer; color: var(--auth-text-3); display: flex; }

  .pw-strength {
    background: var(--auth-pw-bg); border-radius: 8px;
    margin-top: 0; padding: 0 14px;
    opacity: 0; max-height: 0; overflow: hidden;
    transition: all 0.6s ease-in-out;
  }
  .pw-strength.show {
    opacity: 1; max-height: 250px; margin-top: 8px; padding: 14px;
  }
  .pw-header { display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: .88rem; margin-bottom: 10px; }
  .pw-header.weak   { color: #ef4444; }
  .pw-header.fair   { color: #eab308; }
  .pw-header.strong { color: #22c55e; }

  .pw-bars { display: flex; gap: 4px; margin-bottom: 14px; }
  .pw-bar { height: 4px; flex: 1; border-radius: 2px; background: var(--auth-pw-bar); transition: background .3s; }

  .pw-list { list-style: none; font-size: .78rem; color: var(--auth-text-2); display: flex; flex-direction: column; gap: 8px; }
  .pw-list li { display: flex; align-items: center; gap: 8px; }
  .pw-list li.valid { color: var(--auth-pw-valid); }
  .icon-wrap { width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
  .icon-wrap.icon-x { background: var(--auth-icon-x-bg); color: var(--auth-icon-x-color); }
  .icon-wrap.icon-x svg { width: 10px; height: 10px; stroke-width: 3; }
  .icon-wrap.icon-check { background: var(--auth-check-bg); color: var(--auth-check-color); }
  .icon-wrap.icon-check svg { width: 10px; height: 10px; stroke-width: 3; }
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

  <h2>Create account</h2>
  <p class="subtitle">Start chatting with your personal AI assistant</p>

  @if ($errors->any())
    <div class="alert-error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('register.post') }}">
    @csrf
    <div class="field">
      <label for="name">Full Name</label>
      <input type="text" id="name" name="name" value="{{ old('name') }}"
             autocomplete="name" required placeholder="John Doe"/>
      @error('name')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="email">Email Address</label>
      <input type="email" id="email" name="email" value="{{ old('email') }}"
             autocomplete="email" required placeholder="you@example.com"/>
      @error('email')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div class="field-row">
      <div class="field">
        <label for="phone">Phone Number</label>
        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}"
               autocomplete="tel" required placeholder="+63 917 123 4567"/>
        @error('phone')<div class="error">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="dob">Date of Birth</label>
        <input type="date" id="dob" name="dob" value="{{ old('dob') }}" required/>
        @error('dob')<div class="error">{{ $message }}</div>@enderror
      </div>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <div class="password-input-wrapper">
        <input type="password" id="password" name="password" required placeholder="••••••••"/>
        <div class="password-toggle" onclick="togglePassword('password', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
        </div>
      </div>
      @error('password')<div class="error">{{ $message }}</div>@enderror

      <div class="pw-strength" id="pwStrengthContainer">
        <div class="pw-header weak">
          <span class="pw-icon"></span>
          <span class="pw-text">Weak</span>
        </div>
        <div class="pw-bars">
          <div class="pw-bar" style="background:#ef4444"></div>
          <div class="pw-bar"></div>
          <div class="pw-bar"></div>
        </div>
        <ul class="pw-list">
          <li class="req-len"><div class="icon-wrap icon-x"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg></div><span>Be at least 8 characters long</span></li>
          <li class="req-up"><div class="icon-wrap icon-x"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg></div><span>At least one uppercase letter (A-Z)</span></li>
          <li class="req-num"><div class="icon-wrap icon-x"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg></div><span>At least one number (0-9)</span></li>
          <li class="req-sp"><div class="icon-wrap icon-x"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg></div><span>At least one special character (!@#$%^&*)</span></li>
        </ul>
      </div>
    </div>

    <div class="field">
      <label for="password_confirmation">Confirm Password</label>
      <div class="password-input-wrapper">
        <input type="password" id="password_confirmation" name="password_confirmation" required placeholder="••••••••"/>
        <div class="password-toggle" onclick="togglePassword('password_confirmation', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
        </div>
      </div>
    </div>
    <button type="submit" class="btn-register">Create Account →</button>
  </form>

  <div class="login-link">
    Already have an account? <a href="{{ route('login') }}">Sign in</a>
  </div>
</div>

<script>
function togglePassword(inputId, iconEl) {
  const inp = document.getElementById(inputId);
  if (inp.type === 'password') {
    inp.type = 'text';
    iconEl.innerHTML = `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path></svg>`;
  } else {
    inp.type = 'password';
    iconEl.innerHTML = `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>`;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const input = document.getElementById('password');
  const container = document.getElementById('pwStrengthContainer');
  if(!input || !container) return;

  const header = container.querySelector('.pw-header');
  const text = container.querySelector('.pw-text');
  const icon = container.querySelector('.pw-icon');
  const bars = container.querySelectorAll('.pw-bar');
  const reqLen = container.querySelector('.req-len');
  const reqUp = container.querySelector('.req-up');
  const reqNum = container.querySelector('.req-num');
  const reqSp = container.querySelector('.req-sp');

  const svgX = `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>`;
  const svgCheck = `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>`;
  const iconWeak = `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>`;
  const iconFair = `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>`;
  const iconStrong = `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>`;

  function updateReq(el, isValid) {
    const iconWrapper = el.querySelector('.icon-wrap');
    if(isValid) {
      el.classList.add('valid');
      if (!iconWrapper.classList.contains('icon-check')) {
          iconWrapper.className = 'icon-wrap icon-check';
          iconWrapper.innerHTML = svgCheck;
      }
    } else {
      el.classList.remove('valid');
      if (!iconWrapper.classList.contains('icon-x')) {
          iconWrapper.className = 'icon-wrap icon-x';
          iconWrapper.innerHTML = svgX;
      }
    }
  }

  input.addEventListener('input', () => {
    const val = input.value;
    if(val.length > 0) container.classList.add('show');
    else container.classList.remove('show');

    const hasLen = val.length >= 8;
    const hasUp = /[A-Z]/.test(val);
    const hasNum = /[0-9]/.test(val);
    const hasSp = /[!@#$%^&*(),.?":{}|<>]/.test(val);

    updateReq(reqLen, hasLen);
    updateReq(reqUp, hasUp);
    updateReq(reqNum, hasNum);
    updateReq(reqSp, hasSp);

    let score = 0;
    if(hasLen) score++;
    if(hasUp) score++;
    if(hasNum) score++;
    if(hasSp) score++;

    bars.forEach(b => b.style.background = 'var(--auth-pw-bar)');
    header.className = 'pw-header';

    if(score <= 2) {
      text.textContent = 'Weak';
      bars[0].style.background = '#ef4444';
      header.classList.add('weak');
      icon.innerHTML = iconWeak;
    } else if (score === 3) {
      text.textContent = 'Fair';
      bars[0].style.background = '#eab308';
      bars[1].style.background = '#eab308';
      header.classList.add('fair');
      icon.innerHTML = iconFair;
    } else if (score === 4) {
      text.textContent = 'Strong';
      bars[0].style.background = '#22c55e';
      bars[1].style.background = '#22c55e';
      bars[2].style.background = '#22c55e';
      header.classList.add('strong');
      icon.innerHTML = iconStrong;
    }
  });
});
</script>
</body>
</html>
