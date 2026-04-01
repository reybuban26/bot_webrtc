<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Forgot Password — AI Chatbot</title>
<meta name="csrf-token" content="{{ csrf_token() }}">
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
  body::before {
    content: '';
    position: fixed; inset: 0; pointer-events: none;
    background:
      radial-gradient(ellipse 70% 50% at 15% 20%, rgba(91,94,244,.08) 0%, transparent 60%),
      radial-gradient(ellipse 60% 50% at 85% 80%, rgba(124,127,247,.06) 0%, transparent 60%);
  }
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
  .logo { display: flex; align-items: center; gap: 11px; margin-bottom: 28px; }
  .logo-icon {
    width: 40px; height: 40px; border-radius: 11px;
    background: #5b5ef4;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(91,94,244,.35);
  }
  .logo-icon svg { width: 20px; height: 20px; color: #fff; }
  .logo-text h1 { font-size: 1rem; font-weight: 700; color: #111827; }
  .logo-text p  { font-size: .68rem; color: #9ca3af; margin-top: 1px; }

  h2 { font-size: 1.5rem; font-weight: 800; color: #111827; margin-bottom: 5px; }
  .subtitle { font-size: .83rem; color: #6b7280; margin-bottom: 26px; line-height: 1.4; }

  .field { margin-bottom: 16px; }
  label {
    display: block; font-size: .78rem; font-weight: 500;
    color: #374151; margin-bottom: 6px;
  }
  input[type=email], input[type=text], input[type=password] {
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
  
  .btn-primary {
    width: 100%; padding: 12px; margin-top: 10px;
    background: #5b5ef4;
    border: none; border-radius: 10px;
    color: #fff; font-family: inherit; font-size: .9rem; font-weight: 600;
    cursor: pointer; transition: all .18s;
  }
  .btn-primary:hover { background: #4d50e0; box-shadow: 0 4px 20px rgba(91,94,244,.35); transform: translateY(-1px); }
  .btn-primary:disabled { background: #a5a7f4; cursor: not-allowed; transform: none; box-shadow: none; }

  .back-link {
    text-align: center; margin-top: 20px;
    font-size: .78rem; color: #9ca3af;
  }
  .back-link a { color: #5b5ef4; text-decoration: none; font-weight: 500; }
  .back-link a:hover { text-decoration: underline; }

  .alert {
    border-radius: 10px; padding: 11px 14px;
    font-size: .8rem; margin-bottom: 18px; display: none;
  }
  .alert.error { background: rgba(239,68,68,.07); border: 1px solid rgba(239,68,68,.2); color: #ef4444; }
  .alert.success { background: rgba(34,197,94,.07); border: 1px solid rgba(34,197,94,.2); color: #16a34a; }

  /* Password Strength */
  .password-input-wrapper { display: flex; align-items: center; position: relative; width: 100%; }
  .password-input-wrapper input { padding-right: 40px; width: 100%; }
  .password-toggle { position: absolute; right: 12px; cursor: pointer; color: #9ca3af; display: flex; }
  
  .pw-strength {
    background: #f3f4f6; border-radius: 8px; 
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
  .pw-bar { height: 4px; flex: 1; border-radius: 2px; background: #d1d5db; transition: background .3s; }
  
  .pw-list { list-style: none; font-size: .78rem; color: #6b7280; display: flex; flex-direction: column; gap: 8px; }
  .pw-list li { display: flex; align-items: center; gap: 8px; }
  .pw-list li.valid { color: #111827; }
  .icon-wrap { width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
  .icon-wrap.icon-x { background: #e5e7eb; color: #9ca3af; }
  .icon-wrap.icon-x svg { width: 10px; height: 10px; stroke-width: 3; }
  .icon-wrap.icon-check { background: #dcfce7; color: #22c55e; }
  .icon-wrap.icon-check svg { width: 10px; height: 10px; stroke-width: 3; }

  /* Steps visibility */
  #step-email { display: block; }
  #step-otp { display: none; }
  #step-reset { display: none; }
  #step-success { display: none; }

  /* OTP Split Input Boxes */
  .otp-container { display: flex; gap: 10px; justify-content: center; margin-top: 10px; margin-bottom: 20px; }
  .otp-box {
    width: 48px; height: 56px;
    text-align: center; font-size: 1.5rem; font-weight: 700;
    border: 1px solid #e5e7eb; border-radius: 12px;
    background: #f9fafb; outline: none; transition: all 0.2s;
    color: #111827; box-shadow: inset 0 2px 4px rgba(0,0,0,.02);
  }
  .otp-box:focus {
    border-color: #5b5ef4; box-shadow: 0 0 0 3px rgba(91,94,244,.12), inset 0 2px 4px rgba(0,0,0,.02); background: #fff;
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

  <div id="alertBox" class="alert"></div>

  <!-- STEP 1: Request OTP -->
  <div id="step-email">
    <h2>Forgot Password</h2>
    <p class="subtitle">Enter your registered email address and we will send you a 6-digit OTP code to reset your password.</p>
    <div class="field">
      <label for="email">Email address</label>
      <input type="email" id="email" placeholder="you@example.com" required/>
    </div>
    <button type="button" class="btn-primary" id="btnSendOtp">Send OTP</button>
  </div>

  <!-- STEP 2: Verify OTP -->
  <div id="step-otp">
    <h2>Verify Email</h2>
    <p class="subtitle">We've sent a 6-digit code to your email. It will expire in 15 minutes.</p>
    <label style="text-align: center;">Enter 6-Digit OTP</label>
    <div class="otp-container">
      <input type="text" class="otp-box" maxlength="1" autocomplete="off" />
      <input type="text" class="otp-box" maxlength="1" autocomplete="off" />
      <input type="text" class="otp-box" maxlength="1" autocomplete="off" />
      <input type="text" class="otp-box" maxlength="1" autocomplete="off" />
      <input type="text" class="otp-box" maxlength="1" autocomplete="off" />
      <input type="text" class="otp-box" maxlength="1" autocomplete="off" />
    </div>
    <input type="hidden" id="otp" value="" />
    <button type="button" class="btn-primary" id="btnVerifyOtp">Verify OTP</button>

    <div style="text-align: center; margin-top: 15px; font-size: 0.8rem; color: #6b7280;">
      Didn't receive the code? 
      <button type="button" id="btnResendOtp" style="background: none; border: none; color: #a5a7f4; font-weight: 600; cursor: not-allowed; padding: 0; outline: none; font-family: inherit;" disabled>Resend OTP</button>
    </div>
  </div>

  <!-- STEP 3: Reset Password -->
  <div id="step-reset">
    <h2>Create New Password</h2>
    <p class="subtitle">Your identity has been verified. Please enter your new password below.</p>
    <div class="field">
      <label for="password">New Password</label>
      <div class="password-input-wrapper">
        <input type="password" id="password" required placeholder="••••••••"/>
        <div class="password-toggle" onclick="togglePassword('password', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
        </div>
      </div>
      
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
        <input type="password" id="password_confirmation" required placeholder="••••••••"/>
        <div class="password-toggle" onclick="togglePassword('password_confirmation', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
        </div>
      </div>
    </div>
    <button type="button" class="btn-primary" id="btnResetPass">Save New Password</button>
  </div>

  <!-- STEP 4: Success -->
  <div id="step-success" style="text-align: center;">
    <h2>Password Updated!</h2>
    <p class="subtitle" style="margin-bottom: 30px;">Your password has been changed successfully. You can now sign in with your new credentials.</p>
    <a href="/login" style="display:inline-block; padding: 12px 24px; background: #5b5ef4; color:#fff; border-radius:10px; text-decoration:none; font-weight:600;">Go to Login</a>
  </div>

  <div class="back-link" id="backLink" style="display: block;">
    Remembered your password? <a href="/login">Sign in</a>
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

  const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const alertBox = document.getElementById('alertBox');
  let currentEmail = '';
  let currentOtp = '';
  let countdownTimer = null;

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
      
      bars.forEach(b => b.style.background = '#d1d5db');
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

  function showAlert(message, isError = true) {
    alertBox.textContent = message;
    alertBox.className = 'alert ' + (isError ? 'error' : 'success');
    alertBox.style.display = 'block';
  }
  function hideAlert() { alertBox.style.display = 'none'; }

  // Resend Timer Logic
  const resendBtn = document.getElementById('btnResendOtp');
  function startResendCooldown() {
    let timeLeft = 120;
    resendBtn.disabled = true;
    resendBtn.style.color = '#a5a7f4';
    resendBtn.style.cursor = 'not-allowed';
    resendBtn.style.textDecoration = 'none';
    resendBtn.textContent = `Resend OTP (${timeLeft}s)`;
    
    if(countdownTimer) clearInterval(countdownTimer);
    countdownTimer = setInterval(() => {
      timeLeft--;
      resendBtn.textContent = `Resend OTP (${timeLeft}s)`;
      if(timeLeft <= 0) {
        clearInterval(countdownTimer);
        resendBtn.disabled = false;
        resendBtn.style.color = '#5b5ef4';
        resendBtn.style.cursor = 'pointer';
        resendBtn.textContent = 'Resend OTP';
      }
    }, 1000);
  }

  resendBtn.addEventListener('mouseover', () => { if(!resendBtn.disabled) resendBtn.style.textDecoration = 'underline'; });
  resendBtn.addEventListener('mouseout', () => { resendBtn.style.textDecoration = 'none'; });

  async function requestOtp(email) {
    return await fetch('/api/auth/forgot-password', {
      method: 'POST', 
      headers: { 
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
      },
      body: JSON.stringify({ email })
    });
  }

  // 1. Send OTP
  document.getElementById('btnSendOtp').addEventListener('click', async (e) => {
    const btn = e.target;
    const email = document.getElementById('email').value.trim();
    if(!email) return showAlert('Please enter your email.');
    
    btn.disabled = true; btn.textContent = 'Sending...'; hideAlert();
    try {
      const res = await requestOtp(email);
      const data = await res.json();
      if(res.ok) {
        currentEmail = email;
        document.getElementById('step-email').style.display = 'none';
        document.getElementById('step-otp').style.display = 'block';
        showAlert('OTP has been sent to your email!', false);
        startResendCooldown();
      } else {
        showAlert(data.error || data.message || 'Error sending email.');
      }
    } catch (err) { showAlert('Network error. Try again.'); }
    btn.disabled = false; btn.textContent = 'Send OTP';
  });

  // Resend OTP Action
  resendBtn.addEventListener('click', async (e) => {
    if(e.target.disabled) return;
    e.target.textContent = 'Sending...';
    e.target.disabled = true;
    e.target.style.cursor = 'not-allowed';
    e.target.style.color = '#a5a7f4';
    e.target.style.textDecoration = 'none';
    hideAlert();
    try {
      const res = await requestOtp(currentEmail);
      const data = await res.json();
      if(res.ok) {
        showAlert('A new OTP has been sent!', false);
        startResendCooldown();
      } else {
        showAlert(data.error || data.message || 'Error resending email.');
        // Re-enable if error
        e.target.disabled = false;
        e.target.textContent = 'Resend OTP';
        e.target.style.color = '#5b5ef4';
        e.target.style.cursor = 'pointer';
      }
    } catch (err) { 
        showAlert('Network error. Try again.'); 
        e.target.disabled = false; 
        e.target.textContent = 'Resend OTP';
        e.target.style.color = '#5b5ef4';
        e.target.style.cursor = 'pointer';
    }
  });

  // OTP Input Logic
  const otpBoxes = document.querySelectorAll('.otp-box');
  const otpHidden = document.getElementById('otp');

  otpBoxes.forEach((box, index) => {
    box.addEventListener('input', (e) => {
      // Allow only numbers
      box.value = box.value.replace(/[^0-9]/g, '');
      if (box.value && index < otpBoxes.length - 1) {
        otpBoxes[index + 1].focus();
      }
      updateHiddenOtp();
    });

    box.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !box.value && index > 0) {
        otpBoxes[index - 1].focus();
      }
    });

    // Handle paste event
    box.addEventListener('paste', (e) => {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
      if (pasted) {
        for (let i = 0; i < pasted.length && index + i < otpBoxes.length; i++) {
          otpBoxes[index + i].value = pasted[i];
        }
        updateHiddenOtp();
        const nextIndex = Math.min(index + pasted.length, otpBoxes.length - 1);
        otpBoxes[nextIndex].focus();
      }
    });
  });

  function updateHiddenOtp() {
    otpHidden.value = Array.from(otpBoxes).map(b => b.value).join('');
  }

  // 2. Verify OTP
  document.getElementById('btnVerifyOtp').addEventListener('click', async (e) => {
    const btn = e.target;
    const otp = document.getElementById('otp').value.trim();
    if(!otp || otp.length < 6) return showAlert('Please enter the 6-digit OTP.');

    btn.disabled = true; btn.textContent = 'Verifying...'; hideAlert();
    try {
      const res = await fetch('/api/auth/verify-otp', {
        method: 'POST', 
        headers: { 
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({ email: currentEmail, otp })
      });
      const data = await res.json();
      if(res.ok) {
        currentOtp = otp;
        document.getElementById('step-otp').style.display = 'none';
        document.getElementById('step-reset').style.display = 'block';
        hideAlert();
      } else {
        showAlert(data.error || data.message || 'Invalid OTP.');
      }
    } catch (err) { showAlert('Network error. Try again.'); }
    btn.disabled = false; btn.textContent = 'Verify OTP';
  });

  // 3. Reset Password
  document.getElementById('btnResetPass').addEventListener('click', async (e) => {
    const btn = e.target;
    const password = document.getElementById('password').value;
    const password_confirmation = document.getElementById('password_confirmation').value;
    
    if(password.length < 8) return showAlert('Password must be at least 8 characters.');
    if(password !== password_confirmation) return showAlert('Passwords do not match.');

    btn.disabled = true; btn.textContent = 'Saving...'; hideAlert();
    try {
      const res = await fetch('/api/auth/reset-password', {
        method: 'POST', 
        headers: { 
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({ email: currentEmail, otp: currentOtp, password, password_confirmation })
      });
      const data = await res.json();
      if(res.ok) {
        document.getElementById('step-reset').style.display = 'none';
        document.getElementById('step-success').style.display = 'block';
        document.getElementById('backLink').style.display = 'none';
        hideAlert();
      } else {
        showAlert(data.error || data.message || 'Failed to reset password.');
      }
    } catch (err) { showAlert('Network error. Try again.'); }
    btn.disabled = false; btn.textContent = 'Save New Password';
  });
</script>
</body>
</html>
