<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<link rel="manifest" href="/manifest.json">
<meta name="csrf-token" content="{{ csrf_token() }}"/>
<meta name="agora-app-id" content="{{ config('services.agora.app_id') }}"/>
<meta name="stun-server" content="{{ config('services.webrtc.stun', 'stun:stun.l.google.com:19302') }}"/>
<meta name="auth-user" content="{{ auth()->check() ? auth()->user()->name : '' }}"/>
<meta name="auth-user-id" content="{{ auth()->check() ? auth()->user()->id : '' }}"/>
<meta name="is-authenticated" content="{{ auth()->check() ? 'true' : 'false' }}"/>
<meta name="login-url" content="{{ route('login') }}"/>
<meta name="user-role" content="{{ auth()->check() ? auth()->user()->role : 'user' }}"/>
<meta name="support-admin-id" content="{{ \App\Models\User::where('role', 'admin')->first()?->id ?? '' }}"/>
<title>AI Chatbot — Powered by Maxx</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<script>
  // Initialize Alpine sidebar store before Alpine processes the DOM.
  // On desktop (>= 768 px) the sidebar is open by default.
  // On mobile it starts closed so the user lands on the chat view first.
  document.addEventListener('alpine:init', () => {
    Alpine.store('sidebar', { open: window.innerWidth >= 768 });
  });
</script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://download.agora.io/sdk/release/AgoraRTC_N-4.20.2.js"></script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<link rel="stylesheet" href="{{ asset('css/chatbot.css') }}?v=6"/>
<style>
  /* Inline extras that depend on server-side theme */
  [x-cloak] { display: none !important; }

  /* Inline extras that depend on server-side theme */
  body.loaded { transition: background .3s, color .3s; }

  /* ── MESSAGE BUBBLE & ATTACHMENT STYLES ── */
  .msg-attachment-list {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 10px;
      padding-bottom: 8px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.15);
  }
  .att-card {
      background: rgba(0, 0, 0, 0.12);
      padding: 6px 10px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.75rem;
      border: 1px solid rgba(255, 255, 255, 0.08);
  }
  .att-format {
      font-weight: 800;
      font-size: 0.65rem;
      background: rgba(0, 0, 0, 0.3);
      padding: 2px 5px;
      border-radius: 4px;
      color: #fff;
      white-space: nowrap;
  }
  .att-name {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      flex: 1;
      opacity: 0.9;
  }

  /* Toast notification */
  #toast {
    position: fixed; bottom: 88px; left: 50%; transform: translateX(-50%) translateY(20px);
    background: rgba(20,30,55,.95); border: 1px solid rgba(0,212,255,.35);
    border-radius: 12px; padding: 12px 20px; font-size: .83rem; color: #ddeeff;
    display: flex; align-items: center; gap: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,.4), 0 0 20px rgba(0,212,255,.1);
    opacity: 0; pointer-events: none;
    transition: opacity .25s, transform .25s;
    z-index: 9500; white-space: nowrap;
  }
  #toast.show { opacity: 1; transform: translateX(-50%) translateY(0); pointer-events: auto; }
  #toast a { color: #00d4ff; font-weight: 600; text-decoration: none; }
  #toast a:hover { text-decoration: underline; }

  /* Incoming call buttons */
  .rtc-incoming-btns { display: flex; gap: 14px; margin-top: 18px; justify-content: center; }
  .rtc-accept-btn {
    padding: 10px 24px; background: #16a34a; color: #fff;
    border: none; border-radius: 30px; font-size: .9rem; font-weight: 600;
    cursor: pointer; transition: background .18s, transform .18s;
  }
  .rtc-accept-btn:hover { background: #15803d; transform: scale(1.04); }
  .rtc-decline-btn {
    padding: 10px 24px; background: #dc2626; color: #fff;
    border: none; border-radius: 30px; font-size: .9rem; font-weight: 600;
    cursor: pointer; transition: background .18s, transform .18s;
  }
  .rtc-decline-btn:hover { background: #b91c1c; transform: scale(1.04); }
  .rtc-admin-waiting { text-align: center; color: rgba(255,255,255,.5); font-size: .82rem; padding: 8px 0; }

  /* ═══════════════════════════════════════════════
     SUPPORT CHAT PANEL — theme-aware (light/dark)
     ═══════════════════════════════════════════════ */
  #support-panel {
    position: fixed; right: 0; bottom: 0; top: 0;
    width: 400px; max-width: 100vw;
    background: var(--bg-base);
    border-left: 1px solid var(--border);
    display: flex; flex-direction: column;
    z-index: 9000;
    transform: translateX(100%);
    transition: transform .3s cubic-bezier(.4,0,.2,1), background .22s ease, border-color .22s ease;
    box-shadow: -12px 0 48px rgba(0,0,0,.12);
    font-family: 'Inter', sans-serif;
  }
  #support-panel.open { transform: translateX(0); }

  /* Header */
  .sp-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px;
    background: var(--bg-panel);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    backdrop-filter: blur(12px);
    transition: background .22s ease, border-color .22s ease;
  }
  .sp-header-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; font-weight: 700; color: #fff;
    flex-shrink: 0;
  }
  .sp-header-info { flex: 1; min-width: 0; }
  .sp-header-name {
    font-weight: 600; font-size: .9rem;
    color: var(--txt); white-space: nowrap;
    overflow: hidden; text-overflow: ellipsis;
  }
  .sp-header-sub { font-size: .72rem; color: var(--txt-3); margin-top: 1px; }
  .sp-back {
    background: none; border: none; cursor: pointer;
    color: var(--txt-2); padding: 6px;
    border-radius: 8px; transition: background .15s, color .15s;
    display: flex; align-items: center;
  }
  .sp-back:hover { background: var(--bg-surface); color: var(--txt); }
  .sp-call-btn {
    background: var(--accent); border: none; border-radius: 20px;
    padding: 7px 14px; cursor: pointer; color: #fff;
    display: flex; align-items: center; gap: 6px;
    font-size: .78rem; font-weight: 600;
    transition: opacity .15s, transform .12s;
    white-space: nowrap; flex-shrink: 0;
  }
  .sp-call-btn:hover { opacity: .88; transform: scale(1.03); }
  .sp-close {
    background: none; border: none; cursor: pointer;
    color: var(--txt-3); padding: 6px;
    border-radius: 8px; transition: background .15s, color .15s;
    display: flex; align-items: center; flex-shrink: 0;
  }
  .sp-close:hover { background: var(--bg-surface); color: var(--txt); }

  /* Thread list (admin sidebar) */
  .sp-threads {
    overflow-y: auto; flex: 1; padding: 8px 6px;
    scrollbar-width: thin; scrollbar-color: var(--border) transparent;
  }
  .sp-threads-label {
    font-size: .68rem; font-weight: 600; letter-spacing: .06em;
    color: var(--txt-3); text-transform: uppercase;
    padding: 10px 10px 6px;
  }
  .sp-thread-item {
    display: flex; align-items: center; gap: 11px;
    padding: 10px 10px; border-radius: 12px; cursor: pointer;
    transition: background .15s; margin-bottom: 2px;
  }
  .sp-thread-item:hover { background: var(--bg-surface); }
  .sp-thread-item.active { background: var(--accent-dim); }
  .sp-thread-av {
    width: 42px; height: 42px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem; font-weight: 700; color: #fff;
    flex-shrink: 0;
  }
  .sp-thread-meta { flex: 1; min-width: 0; }
  .sp-thread-name {
    font-size: .875rem; font-weight: 600;
    color: var(--txt); margin-bottom: 2px;
  }
  .sp-thread-preview {
    font-size: .76rem; color: var(--txt-3);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }

  /* Messages area */
  .sp-messages-wrap {
    flex: 1; overflow: hidden;
    display: flex; flex-direction: column;
  }
  #support-messages {
    flex: 1; overflow-y: auto;
    padding: 16px 14px 8px;
    display: flex; flex-direction: column;
    gap: 4px;
    scrollbar-width: thin; scrollbar-color: var(--border) transparent;
  }
  .sp-msg-spacer { flex: 1; }

  /* Message groups */
  .sp-msg-group { display: flex; flex-direction: column; gap: 2px; margin-bottom: 8px; }
  .sp-msg-group.own { align-items: flex-end; }
  .sp-msg-group.other { align-items: flex-start; }
  .sp-sender-name {
    font-size: .7rem; font-weight: 600;
    color: var(--txt-3);
    margin-bottom: 4px; padding-left: 4px;
  }

  /* Bubbles */
  .sp-bubble {
    max-width: 82%;
    padding: 9px 14px;
    font-size: .875rem; line-height: 1.5;
    word-break: break-word; overflow-wrap: anywhere;
    border-radius: 18px;
    transition: background .22s ease, color .22s ease;
  }
  .sp-bubble.other {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    color: var(--txt);
    border-bottom-left-radius: 5px;
  }
  .sp-bubble.own {
    background: var(--user-grad);
    color: #fff;
    border-bottom-right-radius: 5px;
    box-shadow: 0 4px 18px rgba(91,94,244,.22);
    position: relative;
  }
  .sp-bubble.system {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    color: var(--txt-2);
    font-size: .75rem;
    text-align: center;
    border-radius: 99px;
    padding: 5px 14px;
    align-self: center;
    max-width: 90%;
  }
  .sp-bubble.notes {
    background: var(--accent-dim);
    border: 1px solid var(--border-hi);
    color: var(--txt);
    max-width: 100%; width: 100%;
    border-radius: 14px;
    font-size: .815rem;
    line-height: 1.65;
  }
  /* Rendered Markdown inside meeting notes */
  .sp-notes-body .mn-heading {
    font-size: .88rem;
    font-weight: 700;
    color: var(--accent);
    margin: 12px 0 4px;
    padding-bottom: 4px;
    border-bottom: 1px solid var(--border);
    letter-spacing: .02em;
  }
  .sp-notes-body .mn-heading:first-child { margin-top: 0; }
  .sp-notes-body .mn-subheading {
    font-size: .82rem; font-weight: 600;
    color: var(--accent-2); margin: 8px 0 3px;
  }
  .sp-notes-body .mn-list {
    margin: 4px 0 6px 0;
    padding-left: 16px;
    list-style: disc;
  }
  .sp-notes-body .mn-list li { margin-bottom: 4px; }
  .sp-notes-body .mn-para {
    margin: 2px 0 4px;
    color: var(--txt-2);
  }
  .sp-notes-body .mn-gap { height: 6px; }
  .sp-notes-body .mn-code {
    background: var(--bg-surface);
    border-radius: 4px;
    padding: 1px 5px;
    font-size: .78rem;
    font-family: monospace;
  }
  .sp-notes-body strong { color: var(--txt); }
  .sp-notes-header {
    font-size: .72rem; color: var(--txt-3);
    text-align: center; margin-bottom: 6px;
    display: flex; align-items: center; justify-content: center; gap: 5px;
  }

  /* ── Meeting notes: audio player ─────────────────────────────── */
  .sp-notes-audio-wrap {
    margin: 6px 0 10px;
    width: 100%;
  }
  .sp-notes-audio-label {
    font-size: 11px;
    opacity: 0.6;
    margin-bottom: 5px;
    letter-spacing: .02em;
    color: var(--accent);
  }
  .sp-notes-audio-wrap audio {
    width: 100%;
    display: block;
    border-radius: 999px;
    outline: none;
    min-width: 0;
    box-sizing: border-box;
    /* inherit page color-scheme so it auto-matches light/dark */
    color-scheme: inherit;
  }
  /* In dark mode, force dark audio controls too */
  .dark .sp-notes-audio-wrap audio { color-scheme: dark; }

  /* Timestamp */
  .sp-time {
    font-size: .67rem; color: var(--txt-3);
    margin-top: 3px; padding: 0 4px;
  }
  .sp-msg-group.own .sp-time { text-align: right; }

  /* Input bar */
  .sp-input-row {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 12px 14px;
    border-top: 1px solid var(--border);
    flex-shrink: 0;
    background: var(--bg-panel);
    transition: background .22s ease, border-color .22s ease;
  }
  .sp-input {
    flex: 1;
    background: var(--bg-surface);
    border: 1.5px solid var(--border);
    border-radius: 22px;
    padding: 10px 16px;
    color: var(--txt);
    font-size: .875rem;
    font-family: inherit;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    min-width: 0;
  }
  .sp-input::placeholder { color: var(--txt-3); }
  .sp-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-dim);
  }
  .sp-send {
    background: var(--user-grad);
    border: none; border-radius: 50%;
    width: 40px; height: 40px; min-width: 40px;
    color: #fff; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: opacity .15s, transform .12s;
    box-shadow: 0 4px 12px rgba(91,94,244,.35);
  }
  .sp-send:hover { opacity: .88; transform: scale(1.06); }
  .sp-send:disabled { opacity: .3; cursor: not-allowed; transform: none; box-shadow: none; }

  .session-title-shimmer {
    height: 13px;
    width: 75%;
    border-radius: 6px;
    background: linear-gradient(
        90deg,
        var(--bg-surface) 25%,
        var(--border-hi, rgba(99,102,241,0.25)) 50%,
        var(--bg-surface) 75%
    );
    background-size: 200% 100%;
    animation: shimmer-slide 1.5s infinite ease-in-out;
  }

  @keyframes shimmer-slide {
      0%   { background-position: 200% center; }
      100% { background-position: -200% center; }
  }

    .sp-attach-btn {
      background: none; border: none; cursor: pointer;
      color: var(--txt-3); padding: 6px;
      border-radius: 8px; transition: color .15s, background .15s;
      display: flex; align-items: center; flex-shrink: 0;
  }
  .sp-attach-btn:hover { color: var(--accent); background: var(--accent-dim); }

  .sp-file-preview {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 12px;
      background: var(--bg-surface);
      border-top: 1px solid var(--border);
      font-size: .78rem; color: var(--txt);
  }
  .sp-file-preview-name {
      flex: 1; overflow: hidden;
      text-overflow: ellipsis; white-space: nowrap;
  }
  .sp-file-preview-remove {
      background: none; border: none; cursor: pointer;
      color: var(--txt-3); font-size: 1rem; line-height: 1;
      padding: 0 4px; transition: color .15s;
  }
  .sp-file-preview-remove:hover { color: #ef4444; }

  /* File bubble */
  .sp-file-bubble {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 14px;
      border-radius: 14px;
      max-width: 82%;
      font-size: .82rem;
      cursor: pointer;
      text-decoration: none;
      transition: opacity .15s;
  }
  .sp-file-bubble:hover { opacity: .85; }
  .sp-file-bubble.own {
      background: var(--user-grad);
      color: #fff;
      border-bottom-right-radius: 5px;
  }
  .sp-file-bubble.other {
      background: var(--bg-surface);
      border: 1px solid var(--border);
      color: var(--txt);
      border-bottom-left-radius: 5px;
  }
  .sp-file-icon { font-size: 1.4rem; flex-shrink: 0; }
  .sp-file-info { min-width: 0; }
  .sp-file-info-name {
      font-weight: 600;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      max-width: 180px;
  }
  .sp-file-info-size { font-size: .7rem; opacity: .7; margin-top: 2px; }

  /* Image preview in chat */
  .sp-img-bubble {
      max-width: 220px; border-radius: 12px;
      overflow: hidden; cursor: pointer;
      border: 2px solid var(--border);
      transition: opacity .15s;
  }
  .sp-img-bubble:hover { opacity: .9; }
  .sp-img-bubble img { width: 100%; display: block; }

  @keyframes dot-bounce {
    0%, 80%, 100% { transform: translateY(0); }
    40% { transform: translateY(-4px); }
  }

  .sp-msg-status {
    font-size: .65rem;
    margin-left: 6px;
    display: inline-flex;
    align-items: center;
    position: absolute;  /* ← DAGDAG */
    left: -26px;        /* ← labas ng bubble */
    bottom: 2px;
  }
  .sp-check { color: rgba(255,255,255,0.5); }
  .sp-check.delivered { color: rgba(255,255,255,0.7); }
  .sp-check.seen { color: #60d4f7; }

</style>

</head>
<body>

<div id="app"
     x-data="chatApp()"
     x-init="init()">

  <!-- ══ SIDEBAR ═══════════════════════════════════════════════════════════ -->
  <aside id="sidebar" :class="{ open: $store.sidebar.open }">

    <div id="sidebar-header">
      <div class="logo-icon">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1 1 .03 2.7-1.41 2.38l-1.98-.495a9.038 9.038 0 01-4.61 0l-1.98.495c-1.44.32-2.41-1.38-1.41-2.38L5 14.5"/>
        </svg>
      </div>
      <div>
        <div class="logo-title">AI Chatbot</div>
        <div class="logo-sub">Powered by Maxx</div>
      </div>
    </div>

    <!-- Search -->
    <div class="sb-search">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search" x-model="searchQuery" class="sb-search-input"/>
    </div>

    <!-- Nav -->
    <nav class="sb-nav">
      <button class="sb-nav-item sb-nav-active" @click="newChat()">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Home
      </button>
      <button class="sb-nav-item">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        History
      </button>
      <!-- Support Chat button -->
      <button class="sb-nav-item" id="support-chat-btn" 
              x-data="{ unread: 0 }" 
              @support-unread.window="unread = $event.detail"
              @click="$dispatch('open-support'); unread = 0"
              style="position: relative;">
        <div style="display: flex; align-items: center; gap: 8px; flex: 1;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
          <span>Support</span>
        </div>
        <span x-show="unread > 0" x-text="unread" x-transition.opacity 
              style="display: none; background: #ef4444; color: #fff; font-size: 0.7rem; font-weight: 700; border-radius: 99px; padding: 2px 7px; min-width: 18px; text-align: center; box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);">
        </span>
      </button>
    </nav>

    <!-- History -->
    <div class="sessions-list">
      <div class="sb-group-label" x-show="sessions.length > 0">Recent Chats</div>
      <template x-for="s in sessions" :key="s.token">
      <div class="session-item" :class="{ active: s.token === sessionToken }" @click="switchSession(s.token)">
        <div class="session-title" 
             x-show="generatingTitleFor !== s.token"
             x-text="s.title">
        </div>
        <div class="session-title-shimmer" 
             x-show="generatingTitleFor === s.token">
        </div>
          <button class="session-delete" @click="deleteSession(s.token, $event)" title="Delete">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
          </button>
        </div>
      </template>
      <div x-show="sessions.length === 0" class="sb-empty">No chats yet. Start one!</div>
    </div>

    <!-- Footer -->
    <div class="sidebar-footer">
      @if(auth()->user()->isAdmin())
      <a href="/admin" target="_blank" class="icon-btn" title="Admin Panel" style="text-decoration:none;flex-shrink:0">
        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
      </a>
      @endif
      <!-- Clickable profile avatar -->
      <button class="sb-avatar-btn" @click="openProfile()" title="Edit Profile" style="flex-shrink:0">
        <img x-show="profile.avatar_url" :src="profile.avatar_url" x-cloak
             style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);" alt="Avatar">
        <div x-show="!profile.avatar_url"
             style="width:32px;height:32px;border-radius:50%;background:var(--user-grad);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;border:2px solid var(--accent);"
             x-text="profile.name ? profile.name.charAt(0).toUpperCase() : '?'"></div>
      </button>

      <div class="sb-user-info" style="cursor:pointer" @click="openProfile()">
        <div class="sb-user-name" x-text="profile.name || '{{ auth()->user()->name }}'"></div>
        <div class="sb-user-email">{{ auth()->user()->email }}</div>
      </div>
      <!-- Dark / Light toggle -->
      <button class="icon-btn" @click="toggleTheme()" :title="isDark ? 'Switch to Light' : 'Switch to Dark'" style="flex-shrink:0">
        <svg x-show="!isDark" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
        <svg x-show="isDark"  width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
      </button>
      <form id="logout-form" method="POST" action="{{ route('logout') }}" style="flex-shrink:0">
        @csrf
        <button type="submit" class="icon-btn" title="Sign out" style="color:#e53e3e"
          onclick="localStorage.setItem('app_logout', Date.now())">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1"/></svg>
        </button>
      </form>
    </div>
  </aside>


  <!-- ══ MAIN ══════════════════════════════════════════════════════════════ -->
  <main id="chat-main">

    <header id="chat-header">
      <div class="model-pill">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5m4.75-11.396c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3"/></svg>
        Qwen AI
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
      </div>
      <div class="header-actions">
        <button class="icon-btn" title="Toggle sidebar" @click="$store.sidebar.open = !$store.sidebar.open">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
      </div>
    </header>

    <div id="messages-container" x-ref="msgContainer">

      <div class="greeting-state" x-show="messages.length === 0 && !isLoading">
        <div class="greeting-orb">
          <div class="orb-glow"></div>
          <div class="orb-core"></div>
        </div>
        <h1 class="greeting-title">
          <span x-text="greetingText()"></span>,&nbsp;<span class="greeting-name">{{ auth()->user()->name }}</span>
        </h1>
        <p class="greeting-sub">How Can I <span class="greeting-accent">Assist You Today?</span></p>
        <div class="quick-prompts">
          <button class="quick-prompt" @click="setQuickPrompt('Explain quantum computing in simple terms')">⚛️ Explain quantum computing</button>
          <button class="quick-prompt" @click="setQuickPrompt('Write a Python function to sort a list of dictionaries by key')">🐍 Sort dictionaries in Python</button>
          <button class="quick-prompt" @click="setQuickPrompt('What are the best practices for REST API design?')">🔗 REST API best practices</button>
          <button class="quick-prompt" @click="setQuickPrompt('Tell me a fun fact about space')">🚀 Fun space fact</button>
        </div>
      </div>

      <template x-for="msg in messages" :key="msg.id">
        <div class="message" :class="msg.role">
          <div class="msg-avatar" x-text="msg.role === 'user' ? 'U' : 'AI'"></div>
          <div class="msg-body">
            <div class="msg-bubble" :class="msg.role">
                <template x-if="msg.attachments && msg.attachments.length > 0">
                    <div class="msg-attachment-list" style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.15);">
                        <template x-for="att in msg.attachments">
                            <div class="att-card" style="background: rgba(0,0,0,0.12); padding: 6px 10px; border-radius: 8px; display: flex; align-items: center; gap: 8px; font-size: 0.75rem; border: 1px solid rgba(255,255,255,0.08);">
                                <span class="att-format" x-text="att.format" style="font-weight: 800; font-size: 0.65rem; background: rgba(0,0,0,0.3); padding: 2px 5px; border-radius: 4px; color: #fff; text-transform: uppercase;"></span>
                                <span class="att-name" x-text="att.name" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; opacity: 0.9;"></span>
                            </div>
                        </template>
                    </div>
                </template>

                <div x-show="msg.role === 'user'" x-text="msg.content" style="white-space: pre-wrap; font-family: inherit;"></div>
                <div x-show="msg.role === 'assistant'" x-html="msg.renderedHtml || renderContent(msg.content)"></div>
            </div>
            
            <div class="msg-meta">
              <span x-text="msg.time"></span>
              <button class="msg-speak-btn" x-show="msg.role === 'assistant'" @click="speakMessage(msg.content)" title="Read aloud">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 010 12.728M16.463 8.288a5.25 5.25 0 010 7.424M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 012.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75z"/></svg>
              </button>
            </div>
          </div>
        </div>
      </template>

      <div class="message assistant" x-show="isLoading" x-transition>
        <div class="msg-avatar">AI</div>
        <div class="msg-body">
          <div class="typing-bubble">
            <div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>
          </div>
        </div>
      </div>
    </div>

    <div id="input-area">
      <div class="input-wrapper" style="display: flex; flex-direction: column; gap: 8px;">

        <style>
          .input-plus-menu { position: relative; display: flex; align-items: center; }
          .plus-dropdown { position: absolute; bottom: calc(100% + 12px); left: 0; background: var(--bg-surface); border: 1px solid var(--border); border-radius: 12px; padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); display: flex; flex-direction: column; min-width: 180px; z-index: 50; }
          .plus-dropdown-item { padding: 10px 14px; display: flex; align-items: center; gap: 10px; color: var(--txt); font-size: 0.85rem; font-weight: 500; background: transparent; border: none; border-radius: 8px; cursor: pointer; text-align: left; width: 100%; transition: background 0.15s; }
          .plus-dropdown-item:hover { background: rgba(100, 116, 139, 0.1); }
          /* Layout fix para hindi pumatong */
          .file-previews { display: flex; gap: 8px; padding: 5px 12px; flex-wrap: wrap; }
          .file-preview-item { display: flex; align-items: center; gap: 8px; background: rgba(100, 116, 139, 0.08); border: 1px solid rgba(100, 116, 139, 0.2); border-radius: 8px; padding: 6px 12px; font-size: 0.75rem; font-weight: 500; color: var(--txt); }
        </style>
        
        <div class="file-previews" x-show="stagedFiles.length > 0" style="display: flex; gap: 8px; flex-wrap: wrap; padding: 5px 10px;">
           <template x-for="(f, idx) in stagedFiles" :key="idx">
             <div class="file-preview-item">
               <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
               <span x-text="f.name" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></span>
               <button @click="removeFile(idx)">&times;</button>
             </div>
           </template>
        </div>

        <div class="input-top-row">
          <div class="input-plus-menu" x-data="{ plusOpen: false }" @click.away="plusOpen = false">
            <button class="input-attach-btn" @click="plusOpen = !plusOpen" title="Extra Features" style="color: var(--accent);">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
            </button>
            <div class="plus-dropdown" x-show="plusOpen" x-transition.opacity.duration.200ms style="display: none;">
              <button class="plus-dropdown-item" @click="setQuickPrompt('Reason step by step: '); plusOpen = false">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" d="M12 8v4l3 3"/></svg>
                Reasoning
              </button>
              <button class="plus-dropdown-item" @click="setQuickPrompt('Create an image of: '); plusOpen = false">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                Create Image
              </button>
              <button class="plus-dropdown-item" @click="setQuickPrompt('Do deep research on: '); plusOpen = false">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
                Deep Research
              </button>
            </div>
          </div>

          <button class="input-attach-btn" @click="$refs.fileInput.click()" title="Attach file">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
          </button>

          <input type="file" x-ref="fileInput" @change="handleFileUpload" style="display: none;" accept=".pdf,.txt,.md,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.csv,.json" multiple>

          <textarea id="message-input" x-ref="input" x-model="inputText" @keydown.enter.prevent="sendMessage()" @input="autoResize()" placeholder="Initiate a query…" rows="1" style="flex:1;"></textarea>

          <button class="input-attach-btn" @click="toggleVoiceInput()" :class="{ active: isListening }" title="Voice Input">
             <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
          </button>

          <button class="input-btn btn-send" @click="sendMessage()" :disabled="(!inputText.trim() && stagedFiles.length === 0) || isLoading">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
          </button>
        </div>

      </div>
      <div id="status-bar">
        <div class="status-dot" :class="statusMode"></div>
        <span x-text="statusText"></span>
      </div>
    </div>
  </main>

  <!-- ══ PROFILE MODAL — backdrop + card as separate fixed layers ══════════════ -->
<!-- Layer 1: Backdrop -->
<div x-show="profileOpen" x-cloak
         class="profile-modal-backdrop"
         @click="profileOpen = false"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"></div>

    <div x-show="profileOpen" x-cloak
         class="profile-modal-overlay"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">

      <div class="profile-modal" style="pointer-events:all;" @click.stop>
        <div class="profile-modal-header">
          <h2 class="profile-modal-title">Edit Profile</h2>
          <button class="profile-modal-close" @click="profileOpen = false">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <div class="profile-avatar-section">
          <div class="profile-avatar-wrap">
            <img x-show="profile.avatar_url" :src="profile.avatar_url" class="profile-avatar-img" alt="Avatar" x-cloak>
            <div x-show="!profile.avatar_url" class="profile-avatar-placeholder"
                 x-text="profile.name ? profile.name.charAt(0).toUpperCase() : '?'"></div>
            <label class="profile-avatar-edit" title="Change photo">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536M9 13l6-6M3 21h18"/></svg>
              <input type="file" @change="uploadAvatar($event)" accept="image/*" style="display:none;">
            </label>
          </div>
          <div x-show="avatarUploading" class="profile-uploading-badge">Uploading…</div>
          <p class="profile-avatar-hint">Click the pencil to change your photo</p>
        </div>

        <div class="profile-fields">
          <div class="profile-field">
            <label class="profile-label">Full Name</label>
            <input class="profile-input" type="text" x-model="profile.name" placeholder="Your full name">
          </div>
          <div class="profile-field">
            <label class="profile-label">Email Address</label>
            <input class="profile-input" type="email" :value="profile.email" disabled style="opacity:.6;cursor:not-allowed;">
          </div>
          <div class="profile-field">
            <label class="profile-label">Phone Number</label>
            <input class="profile-input" type="tel" x-model="profile.phone" placeholder="+63 917 123 4567">
          </div>
          <div class="profile-field">
                <label class="profile-label">Date of Birth</label>
                <input class="profile-input" type="date" x-model="profile.dob">
          </div>
        </div>

        <div class="profile-actions">
          <button class="profile-btn-save" @click="saveProfile()" :disabled="profileSaving">
            <span x-text="profileSaving ? 'Saving…' : 'Save Changes'">Save Changes</span>
          </button>
          <button class="profile-btn-cancel" @click="profileOpen = false">Cancel</button>
        </div>

        <div x-show="profileSaved" x-transition class="profile-saved-badge">✓ Profile updated!</div>
      </div>
    </div>

</div>

<!-- ══ WEBRTC MODAL ══════════════════════════════════════════════════════════ -->
<div x-data="webrtcApp()" x-init="init()" @open-rtc.window="showRtcModal = true">
  <div class="modal-overlay" x-show="showRtcModal" x-cloak @click.self="!['waiting','connecting','active'].includes(status) && (showRtcModal = false)">
    <div class="rtc-modal" @click.stop :class="status !== 'idle' ? 'rtc-modal--active' : ''">

      <!-- ── Idle State: role-based layout ── -->
      <template x-if="status === 'idle'">
        <div>
          <div class="modal-header">
            <span class="modal-title">📹 Video Call</span>
            <button class="modal-close" @click="showRtcModal = false">✕</button>
          </div>
          <div class="rtc-idle-art">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" opacity=".4"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72v8.44l-4.72-4.72z"/><rect x="1.5" y="7.5" width="13.5" height="9" rx="2"/></svg>
            <!-- User: show who they're calling -->
            <template x-if="userRole === 'user'"><p>Call the admin for live support</p></template>
            <!-- Admin: tell them they're listening -->
            <template x-if="userRole === 'admin'"><p>You'll be notified when a user calls</p></template>
          </div>
          <div class="rtc-actions">
            <!-- User role: single Call Admin button -->
            <template x-if="userRole === 'user'">
              <button class="rtc-btn rtc-btn-create" @click="callAdmin()">📞 Call Admin</button>
            </template>
            <!-- Admin role: passive waiting message -->
            <template x-if="userRole === 'admin'">
              <p class="rtc-admin-waiting">🔔 Incoming calls will appear automatically</p>
            </template>
          </div>
        </div>
      </template>

      <!-- ── Active/Connecting/Waiting State: phone-style portrait call ── -->
      <template x-if="status !== 'idle'">
        <div class="rtc-stage-wrap">

          <!-- Remote video: fills the full portrait stage -->
          <div class="rtc-remote-stage">
            <!-- Always keep remote-video in DOM (x-show not x-if) so Agora can attach stream any time -->
            <div id="remote-video" x-show="status === 'active'" style="width: 100%; height: 100%;"></div>
            <div class="rtc-stage-placeholder" x-show="status !== 'active'">
              <!-- Animated pulse avatar -->
              <div class="rtc-avatar-pulse">
                <div class="rtc-avatar-ring"></div>
                <svg width="52" height="52" viewBox="0 0 24 24" fill="rgba(255,255,255,.3)"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
              </div>
              <p class="rtc-waiting-label" x-text="statusLabel"></p>

              <!-- Incoming call Accept / Decline buttons -->
              <!-- onclick (not @click) so the handler resolves in window scope,
                   bypassing Alpine's reactive scope resolver entirely. -->
              <template x-if="status === 'incoming'">
                <div class="rtc-incoming-btns">
                  <button class="rtc-accept-btn" onclick="__rtcAccept()">
                    ✓ <span x-text="userRole === 'user' ? 'Join' : 'Accept'"></span>
                  </button>
                  <button class="rtc-decline-btn" onclick="__rtcDecline()">
                    ✕ Decline
                  </button>
                </div>
              </template>

              <!-- User: ringing — Cancel button -->
              <template x-if="status === 'ringing'">
                <button class="rtc-decline-btn" @click="endCall(); showRtcModal = false" style="margin-top:14px">Cancel</button>
              </template>
            </div>
          </div>

          <!-- Back button (top-left) -->
          <button class="rtc-back-btn" @click="endCall(); showRtcModal = false">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
          </button>

          <!-- Local camera PiP: top-right corner + mute badge overlay -->
          <div class="rtc-pip rtc-pip--topright">
            <!-- Always keep local-video in DOM so Agora can attach stream reliably -->
            <div id="local-video" x-show="status !== 'idle' && !camOff" style="width: 100%; height: 100%; border-radius: 12px; overflow: hidden; background: #000;"></div>
            <div class="rtc-pip-off" x-show="status === 'idle' || camOff">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72v8.44l-4.72-4.72z"/><rect x="1.5" y="7.5" width="13.5" height="9" rx="2"/></svg>
            </div>
            <!-- Mute indicator badge on PiP — so you can see you're muted -->
            <div class="rtc-pip-mute-badge" x-show="micMuted">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M19 11h-1.7c0 .74-.16 1.43-.43 2.05l1.23 1.23c.56-.98.9-2.09.9-3.28zm-4.02.17c0-.06.02-.11.02-.17V5c0-1.66-1.34-3-3-3S9 3.34 9 5v.18l5.98 5.99zM4.27 3L3 4.27l6.01 6.01V11c0 1.66 1.33 3 2.99 3 .22 0 .44-.03.65-.08l1.66 1.66c-.71.33-1.5.52-2.31.52-2.76 0-5.3-2.1-5.3-5.1H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c.91-.13 1.77-.45 2.54-.9L19.73 21 21 19.73 4.27 3z"/></svg>
            </div>
          </div>

          <!-- Overlays over remote video: remote mic muted OR local speaker muted -->
          <div class="rtc-remote-mute" x-show="remoteMuted || speakerMuted">
            <template x-if="speakerMuted">
              <!-- Speaker off icon -->
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5L6 9H2v6h4l5 4V5zM23 9l-6 6M17 9l6 6"/></svg>
            </template>
            <template x-if="!speakerMuted && remoteMuted">
              <!-- Remote mic-off icon -->
              <svg width="16" height="16" viewBox="0 0 24 24" fill="white"><path d="M19 11h-1.7c0 .74-.16 1.43-.43 2.05l1.23 1.23c.56-.98.9-2.09.9-3.28zm-4.02.17c0-.06.02-.11.02-.17V5c0-1.66-1.34-3-3-3S9 3.34 9 5v.18l5.98 5.99zM4.27 3L3 4.27l6.01 6.01V11c0 1.66 1.33 3 2.99 3 .22 0 .44-.03.65-.08l1.66 1.66c-.71.33-1.5.52-2.31.52-2.76 0-5.3-2.1-5.3-5.1H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c.91-.13 1.77-.45 2.54-.9L19.73 21 21 19.73 4.27 3z"/></svg>
            </template>
            <span x-text="speakerMuted ? 'Speaker Off' : 'Muted'"></span>
          </div>

          <!-- Bottom info bar: room ID + live dot -->
          <div class="rtc-info-bar" x-show="roomId">
            <div class="rtc-info-left">
              <div class="rtc-info-name" x-text="roomId" @click="copyRoomId()" title="Click to copy"></div>
              <div class="rtc-info-status">
                <template x-if="status === 'active'">
                  <span class="rtc-live-dot"></span>
                </template>
                <span x-text="statusLabel" style="font-size:.75rem;color:rgba(255,255,255,.6);"></span>
              </div>
            </div>
          </div>

          <!-- Bottom controls: 5-button rounded icon row -->
          <div class="rtc-controls">
            <button class="rtc-ctrl-btn" @click="toggleMic()" :class="micMuted ? 'rtc-ctrl-btn--off' : ''" :title="micMuted ? 'Unmute' : 'Mute'">
              <!-- Mic icon — shows crossed-out version when muted -->
              <template x-if="!micMuted">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 1a4 4 0 014 4v7a4 4 0 01-8 0V5a4 4 0 014-4z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19 10v1a7 7 0 01-14 0v-1M12 19v4M8 23h8"/></svg>
              </template>
              <template x-if="micMuted">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19 11h-1.7c0 .74-.16 1.43-.43 2.05l1.23 1.23c.56-.98.9-2.09.9-3.28zm-4.02.17c0-.06.02-.11.02-.17V5c0-1.66-1.34-3-3-3S9 3.34 9 5v.18l5.98 5.99zM4.27 3L3 4.27l6.01 6.01V11c0 1.66 1.33 3 2.99 3 .22 0 .44-.03.65-.08l1.66 1.66c-.71.33-1.5.52-2.31.52-2.76 0-5.3-2.1-5.3-5.1H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c.91-.13 1.77-.45 2.54-.9L19.73 21 21 19.73 4.27 3z"/></svg>
              </template>
            </button>
            <button class="rtc-ctrl-btn" @click="toggleCam()" :class="camOff ? 'rtc-ctrl-btn--off' : ''" :title="camOff ? 'Camera On' : 'Camera Off'">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72v8.44l-4.72-4.72z"/><rect x="1.5" y="7.5" width="13.5" height="9" rx="2"/></svg>
            </button>
            <button class="rtc-ctrl-btn rtc-ctrl-btn--end" @click="endCall(); showRtcModal = false" title="End Call">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M6.6 10.8C8 13.6 10.4 16 13.2 17.4l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1L6.6 10.8z"/></svg>
            </button>
            <button class="rtc-ctrl-btn" @click="toggleSpeaker()" :class="speakerMuted ? 'rtc-ctrl-btn--off' : ''" :title="speakerMuted ? 'Unmute Speaker' : 'Mute Speaker'">
              <template x-if="!speakerMuted">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5L6 9H2v6h4l5 4V5zM19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg>
              </template>
              <template x-if="speakerMuted">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5L6 9H2v6h4l5 4V5zM23 9l-6 6M17 9l6 6"/></svg>
              </template>
            </button>
            <button class="rtc-ctrl-btn" @click="flipCam()" title="Flip Camera (front/back)">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </button>
          </div>
        </div>
      </template>
    </div>
  </div>
</div>

<!-- ══ SUPPORT CHAT PANEL ═══════════════════════════════════════════════════ -->
<div id="support-panel"
     x-data="supportApp()"
     x-init="init()"
     @open-support.window="open = !open"
     :class="{ open: open }">

  <!-- ── Header ── -->
  <div class="sp-header">
    <!-- Back arrow (admin, when thread open) -->
    <template x-if="userRole === 'admin' && threadId">
      <button class="sp-back"
              @click="threadId = null; activeUserId = null; messages = []; _clearPoll(); loadThreads()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
      </button>
    </template>

    <!-- Avatar — single element, no nested x-if (bare text inside x-if crashes Alpine) -->
    <template x-if="threadId || userRole === 'user'">
      <div class="sp-header-avatar"
           x-text="userRole === 'user' ? 'S' : (activeUserName ? activeUserName.charAt(0).toUpperCase() : 'U')">
      </div>
    </template>

    <!-- Title / name -->
    <div class="sp-header-info">
      <template x-if="userRole === 'user'">
        <div>
          <div class="sp-header-name">Support Chat</div>
          <div class="sp-header-sub" x-text="chatStatusLabel"></div>
        </div>
      </template>
      <template x-if="userRole === 'admin' && !threadId">
        <div><div class="sp-header-name">Support Inbox</div></div>
      </template>
      <template x-if="userRole === 'admin' && threadId">
        <div>
          <div class="sp-header-name" x-text="activeUserName || 'User'"></div>
          <div class="sp-header-sub"
               :style="chatStatus === 'active' ? 'color:#22c55e' : (chatStatus === 'ended' ? 'color:#ef4444' : '')"
               x-text="chatStatus === 'active' ? '🟢 Connected' : (chatStatus === 'ended' ? 'Session ended' : '⏳ Waiting')"></div>
        </div>
      </template>
    </div>

    <!-- 📞 Call button (only when thread open) -->
    <template x-if="threadId">
      <button class="sp-call-btn" @click="triggerCall()" title="Start call">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M6.6 10.8C8 13.6 10.4 16 13.2 17.4l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1L6.6 10.8z"/></svg>
        Call
      </button>
    </template>

    <!-- 🚫 End Chat button (admin only, when thread is active/waiting) -->
    <template x-if="userRole === 'admin' && threadId && chatStatus !== 'ended'">
      <button @click="endChat()" title="End this chat session"
              style="background:#ef4444;border:none;border-radius:20px;padding:7px 12px;cursor:pointer;color:#fff;display:flex;align-items:center;gap:5px;font-size:.78rem;font-weight:600;transition:opacity .15s;flex-shrink:0;opacity:.9"
              onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.9">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        End
      </button>
    </template>

    <button class="sp-close" @click="open = false">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
  </div>

  <!-- ── Admin Thread List ── -->
  <template x-if="userRole === 'admin' && !threadId">
    <div class="sp-threads">
      <div class="sp-threads-label">Conversations</div>
      <template x-if="threads.length === 0">
        <div style="text-align:center;padding:48px 16px">
          <div style="font-size:2rem;margin-bottom:8px">💬</div>
          <p style="color:var(--txt-3);font-size:.82rem;margin:0">No support conversations yet</p>
        </div>
      </template>
      <template x-for="t in threads" :key="t.thread_id">
        <div class="sp-thread-item" :class="{ active: t.user_id === activeUserId }"
             @click="selectThread(t.user_id, t.user_name, t.thread_id)">
          <div class="sp-thread-av" x-text="t.user_name ? t.user_name.charAt(0).toUpperCase() : 'U'"></div>
          <div class="sp-thread-meta">
            <div class="sp-thread-name" 
                 x-text="t.user_name"
                 :style="unreadPerThread[t.thread_id] ? 'color: var(--accent); font-weight: 800; text-shadow: 0 0px 8px rgba(91,94,244,0.35);' : ''"></div>
            <div class="sp-thread-preview" 
                 x-text="t.last_message || 'No messages yet'"
                 :style="unreadPerThread[t.thread_id] ? 'color: var(--txt); font-weight: 700;' : ''"></div>
          </div>
          <div x-show="unreadPerThread[t.thread_id]" 
               x-text="unreadPerThread[t.thread_id]"
               x-transition.opacity
               style="display: none; background: #ef4444; color: #fff; font-size: 0.65rem; font-weight: 700; border-radius: 99px; padding: 2px 6px; min-width: 18px; text-align: center; margin-left: auto; box-shadow: 0 2px 5px rgba(239, 68, 68, 0.3);">
          </div>
        </div>
      </template>
    </div>
  </template>

  <!-- ── Messages View ── -->
  <template x-if="threadId">
    <div class="sp-messages-wrap">
      <div id="support-messages">
        <!-- Spacer: pushes messages to the bottom when there are fewer messages -->
        <div class="sp-msg-spacer"></div>

        <!-- Empty state -->
        <template x-if="messages.length === 0">
          <div style="text-align:center;padding:32px 16px">
            <div style="font-size:2.5rem;margin-bottom:8px">👋</div>
            <p style="color:var(--txt-3);font-size:.82rem;margin:0">No messages yet. Say hello!</p>
          </div>
        </template>

        <template x-for="msg in messages" :key="msg.id">
          <div>
            <!-- System chip (call_started / call_ended / system) -->
            <template x-if="msg.type === 'call_started' || msg.type === 'call_ended' || msg.type === 'system'">
              <div style="display:flex;justify-content:center;margin:8px 0">
                <div class="sp-bubble system" x-text="msg.body"></div>
              </div>
            </template>

            <!-- Meeting notes card -->
            <template x-if="msg.type === 'meeting_notes'">
              <div style="margin:10px 0">
                <div class="sp-notes-header">
                  <span>📋</span> AI Meeting Notes · <span x-text="formatTime(msg.created_at)"></span>
                </div>
                <!-- Playback player for the raw call recording -->
                <template x-if="msg.metadata && msg.metadata.recording_url">
                  <div class="sp-notes-audio-wrap">
                    <audio controls preload="auto"
                           :src="msg.metadata.recording_url"
                           x-effect="msg.metadata.recording_url && $nextTick(() => $el.load())"
                           @loadedmetadata="if($el.duration === Infinity || isNaN($el.duration)){ $el.currentTime = 1e101; $el.addEventListener('timeupdate', function fix(){ $el.removeEventListener('timeupdate', fix); $el.currentTime = 0; }); }"
                           onerror="this.closest('.sp-notes-audio-wrap').style.display='none';">
                      Your browser does not support audio playback.
                    </audio>
                  </div>
                </template>
                <div class="sp-bubble notes sp-notes-body" x-html="renderNotes(msg.body)"></div>
              </div>
            </template>

            <!-- Regular text message -->
            <template x-if="msg.type === 'text'">
              <div :class="['sp-msg-group', isOwnMessage(msg) ? 'own' : 'other']">
                <template x-if="!isOwnMessage(msg)">
                  <div class="sp-sender-name" x-text="msg.sender"></div>
                </template>
                <div :class="['sp-bubble', isOwnMessage(msg) ? 'own' : 'other']">
                  <span x-text="msg.body"></span>
                  <!-- ✅ Check marks para sa sariling messages -->
                  <template x-if="isOwnMessage(msg)">
                    <span class="sp-msg-status">
                      <!-- Blue double check = seen -->
                      <template x-if="messagesSeen">
                        <span>
                          <svg width="16" height="10" viewBox="0 0 16 10" fill="none">
                            <path d="M1 5l3 3L11 1" stroke="#60d4f7" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M5 5l3 3L15 1" stroke="#60d4f7" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                          </svg>
                        </span>
                      </template>
                      <!-- Gray double check = partner online -->
                      <template x-if="!messagesSeen && partnerOnline">
                        <span>
                          <svg width="16" height="10" viewBox="0 0 16 10" fill="none">
                            <path d="M1 5l3 3L11 1" stroke="rgba(255,255,255,0.6)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M5 5l3 3L15 1" stroke="rgba(255,255,255,0.6)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                          </svg>
                        </span>
                      </template>
                      <!-- Single gray check = sent lang -->
                      <template x-if="!messagesSeen && !partnerOnline">
                        <span>
                          <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                            <path d="M1 5l3 3L9 1" stroke="rgba(255,255,255,0.5)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                          </svg>
                        </span>
                      </template>
                    </span>
                  </template>
                </div>
                <div class="sp-time" x-text="formatTime(msg.created_at)"></div>
              </div>
            </template>
            <!-- File / Image attachment message -->
          <template x-if="msg.type === 'file'">
              <div :class="['sp-msg-group', isOwnMessage(msg) ? 'own' : 'other']">
                  <template x-if="!isOwnMessage(msg)">
                      <div class="sp-sender-name" x-text="msg.sender"></div>
                  </template>

                  <!-- Image preview -->
                  <template x-if="msg.metadata && isImage(msg.metadata.attachment_mime)">
                      <a :href="msg.metadata.attachment_url" target="_blank" class="sp-img-bubble">
                          <img :src="msg.metadata.attachment_url" :alt="msg.metadata.attachment_name">
                      </a>
                  </template>
                  <!-- Non-image file -->
                  <template x-if="msg.metadata && !isImage(msg.metadata.attachment_mime)">
                      <a :href="msg.metadata.attachment_url" target="_blank"
                        :class="['sp-file-bubble', isOwnMessage(msg) ? 'own' : 'other']">
                          <span class="sp-file-icon">📄</span>
                          <div class="sp-file-info">
                              <div class="sp-file-info-name" x-text="msg.metadata.attachment_name"></div>
                              <div class="sp-file-info-size" x-text="formatBytes(msg.metadata.attachment_size)"></div>
                          </div>
                      </a>
                  </template>

                  <!-- Optional caption -->
                  <template x-if="msg.body">
                      <div :class="['sp-bubble', isOwnMessage(msg) ? 'own' : 'other']"
                          x-text="msg.body" style="margin-top: 4px;"></div>
                  </template>

                  <div class="sp-time" x-text="formatTime(msg.created_at)"></div>
              </div>
          </template>
        </template>
      </div>

      <div x-show="seenBy" x-transition.opacity
             style="text-align: center; font-size: .65rem;
                    color: var(--txt-3); opacity: 0.5;
                    padding: 0 4px 8px;">
            ✓✓ <span x-text="seenBy"></span>
        </div>

      <div x-show="typingText" 
            x-transition.opacity
            style="padding: 4px 14px 6px; font-size: .72rem; color: var(--txt-3); display: flex; align-items: center; gap: 6px;">
            <div style="display: flex; gap: 3px; align-items: center;">
                <span style="width:5px;height:5px;border-radius:50%;background:var(--txt-3);animation:dot-bounce .8s infinite 0s"></span>
                <span style="width:5px;height:5px;border-radius:50%;background:var(--txt-3);animation:dot-bounce .8s infinite .15s"></span>
                <span style="width:5px;height:5px;border-radius:50%;background:var(--txt-3);animation:dot-bounce .8s infinite .3s"></span>
            </div>
            <span x-text="typingText"></span>
        </div>

      <!-- Input bar -->
      <div class="sp-input-row" style="flex-direction: column; padding: 0; gap: 0;">
        <!-- File preview strip -->
        <div class="sp-file-preview" x-show="stagedFile" style="display:none;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
            <span class="sp-file-preview-name" x-text="stagedFile?.name"></span>
            <span style="opacity:.5; font-size:.7rem;" x-text="stagedFile ? formatBytes(stagedFile.size) : ''"></span>
            <button class="sp-file-preview-remove" @click="removeSupportFile()">×</button>
        </div>

        <!-- Input row -->
        <div style="display:flex; align-items:center; gap:8px; padding: 10px 12px 14px;">
            <button class="sp-attach-btn" @click="$refs.spFileInput.click()" title="Attach file">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
            </button>
            <input type="file" x-ref="spFileInput" @change="handleSupportFile($event)" style="display:none;"
                  accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.gif,.webp">
            <input class="sp-input"
                  type="text"
                  placeholder="Type a message…"
                  x-model="inputText"
                  @input="inputText ? _broadcastTyping(true) : _broadcastTyping(false)"
                  @keydown.enter.prevent="sendMessage()"
                  @blur="_broadcastTyping(false)"/>
            <button class="sp-send"
                    :disabled="(!inputText.trim() && !stagedFile) || sending"
                    @click="sendMessage()">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
            </button>
        </div>
      </div> <!-- ✅ closes sp-input-row -->
    </div> <!-- ✅ closes sp-messages-wrap -->
  </template> <!-- ✅ closes x-if="threadId" -->
</div> <!-- ✅ closes support panel -->

<!-- Toast notification (login required etc.) -->
<div id="toast">
  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/></svg>
  <span id="toast-msg"></span>
</div>

<script src="{{ asset('js/chatbot.js') }}?v=29"></script>
<script src="{{ asset('js/webrtc.js') }}?v=41"></script>
<script src="{{ asset('js/crypto.js') }}?v=1"></script>
<script src="{{ asset('js/support.js') }}?v=22"></script>
<script>
  // Cross-tab auto-logout
  window.addEventListener('storage', function(e) {
    if (e.key === 'app_logout') {
      window.location.href = '{{ route("login") }}';
    }
  });
</script>
</body>
</html>
