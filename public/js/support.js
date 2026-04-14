/**
 * support.js — Support Chat Alpine component
 *
 * Handles: direct messaging between user ↔ admin,
 *          call initiation (both directions),
 *          and receiving meeting notes after a call.
 */

window.supportApp = function () {
    return {
        // ── State ─────────────────────────────────────────────────
        open:       false,          // panel visibility
        threadId:   null,
        messages:   [],
        unreadCount: 0,
        unreadPerThread: {},
        inputText:  '',
        sending:    false,
        lastTs:     null,           // ISO timestamp of last fetched message
        _pollTimer: null,

        stagedFile: null,

        _typingClearTimer: null,

        typingUsers: {},      // { userId: { name, timer } }
        seenBy: '',           // pangalan ng nakakita
        _reverbChannel: null,

        partnerOnline: false,
        _partnerOnlineTimer: null,
        messagesSeen: false, // naging blue na ba ang checks

        // ── Session Control State ─────────────────────────────────
        chatStatus:      'waiting',   // 'waiting' | 'active' | 'ended'
        assignedAdminId: null,

        // Admin thread list
        threads:        [],
        activeUserId:   null,
        activeUserName: '',

        // Call state (from context)
        userRole: document.querySelector('meta[name="user-role"]')?.content || 'user',
        userId:   parseInt(document.querySelector('meta[name="auth-user-id"]')?.content || '0'),

        // ── E2EE State ────────────────────────────────────────────
        _privateKey: null,          // RSA private key (CryptoKey, in memory)
        _publicKeyB64: null,        // own base64 public key (for comparison)
        _e2eeReady: false,          // true after key pair is loaded/generated
        _allPartiesHaveKeys: false, // true only when BOTH user and admin have key slots

        // ── Post-chat Feedback State ───────────────────────────
        postChatRating: {
            isResolved:  null,   // null = not answered, true = Yes, false = No
            rating:      null,   // 1–5 stars (optional)
            feedback:    '',     // optional comment
            submitted:   false,
        },

        // ── End Chat Modal State ────────────────────────────────
        showEndChatModal: false,

        // ── Init ──────────────────────────────────────────────────
        async init() {
            this.$watch('open', val => {
                if (val) {
                    this.unreadCount = 0;
                    window.dispatchEvent(new CustomEvent('support-unread', { detail: 0 }));
                    setTimeout(() => this._scrollToBottom(), 50);

                    // markAsSeen agad kapag nabuksan ang panel
                    if (this.threadId && this.messages.length > 0) {
                        this.markAsSeen(this.threadId);
                    }
                }
            });

            // ── Bootstrap E2EE key pair ───────────────────────────
            await this._initE2EE();

            if (this.userRole === 'admin') {
                await this.loadThreads();
                // Admin background polling to detect incoming messages on any thread
                this._threadsPollInterval = setInterval(() => this.loadThreads(), 3000);
            } else {
                await this.openMyThread();
            }

            // Pause polling when tab is hidden
            this._setupVisibilityHandler();
        },

        _setupVisibilityHandler() {
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this._pauseSupportPolls();
                } else {
                    this._resumeSupportPolls();
                }
            });
        },

        _pauseSupportPolls() {
            this._visibilityPaused = true;
            if (this._pollTimer) {
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
            if (this._threadsPollInterval) {
                clearInterval(this._threadsPollInterval);
                this._threadsPollInterval = null;
            }
        },

        _resumeSupportPolls() {
            if (!this._visibilityPaused) return;
            this._visibilityPaused = false;
            if (this.threadId) {
                this._startPoll();
            }
            if (this.userRole === 'admin') {
                this._threadsPollInterval = setInterval(() => this.loadThreads(), 3000);
            }
        },

        // ── Admin: load all user threads ─────────────────────────
        async loadThreads() {
            try {
                const r = await fetch('/api/support/threads', {
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
                const d = await r.json();

                if (d.threads) {
                    let totalUnreads = 0;
                    d.threads.forEach(t => {
                        this.unreadPerThread[t.thread_id] = t.unread_count || 0;
                        totalUnreads += (t.unread_count || 0);
                    });

                    // Update global badge only if panel is closed
                    if (!this.open) {
                        this.unreadCount = totalUnreads;
                        window.dispatchEvent(new CustomEvent('support-unread', { detail: this.unreadCount }));
                    }
                }

                this.threads = d.threads || [];
            } catch (e) {
                console.warn('[Support] Failed to load threads', e);
            }
        },

        async selectThread(userId, userName, threadId) {
            this.activeUserId   = userId;
            this.activeUserName = userName;
            if (threadId) {
                this.unreadPerThread[threadId] = 0;
            }
            await this.openThread(userId);
            this.open = true;
        },

        async markAsSeen(threadId) {
            if (!threadId) return;
            try {
                await fetch(`/api/support/thread/${threadId}/mark-seen`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
                // Admin claiming an escalating thread: update local status immediately
                // so the header switches to "🟢 Connected" without needing a refresh
                if (this.userRole === 'admin' && this.chatStatus === 'escalating') {
                    this.chatStatus = 'active';
                    this.assignedAdminId = this.userId;
                }
            } catch (e) {
                console.warn('[Support] markAsSeen failed', e);
            }
        },

        // ── User: open own thread ──────────────────────────────────
        async openMyThread() {
            await this.openThread(null);
        },

        async openThread(userId) {
            this._clearPoll();
            this.messages = [];
            this.lastTs   = null;
            this.messagesSeen  = false;
            this.partnerOnline = false;
            this.chatStatus    = 'waiting';
            this.assignedAdminId = null;

            const url = userId
                ? `/api/support/thread?user_id=${userId}`
                : '/api/support/thread';

            try {
                const r  = await fetch(url, { headers: { 'X-CSRF-TOKEN': this._csrf() } });
                const d  = await r.json();

                // No thread yet — stay lazy: thread will be created on first send.
                // The client-side welcome bubble is rendered purely in the UI so
                // empty threads never get persisted and never appear as phantom
                // rows in the admin Filament panel.
                if (! d.thread_id) {
                    this.threadId = null;
                    return;
                }

                this.threadId       = d.thread_id;
                this.chatStatus     = d.chat_status || 'waiting';
                this.assignedAdminId = d.assigned_admin_id || null;

                // E2EE: ensure thread has an AES key.
                const partnerUserId = userId ? parseInt(userId) : null;
                await this._initThreadKey(d.thread_id, partnerUserId);

                // Delayed re-run to handle the race where partner generates their
                // keypair right after our initial _initThreadKey ran without their slot.
                const _tid = d.thread_id;
                setTimeout(async () => {
                    if (this.threadId !== _tid) return; // Thread changed
                    const hadKey = !!E2EE.getCachedThreadKey(_tid);
                    await this._initThreadKey(_tid, partnerUserId);
                    // If we just acquired the key for the first time, refresh messages
                    if (!hadKey && E2EE.getCachedThreadKey(_tid)) {
                        await this.fetchMessages(false);
                    }
                }, 15000);

                // Load initial messages
                await this.fetchMessages(false);
                this._startPoll();
                // Subscribe sa Reverb channel para sa real-time events
                this._subscribeReverb(d.thread_id);
            } catch (e) {
                console.error('[Support] openThread failed', e.message);
            }
        },

        /**
         * Lazily create the thread on first send (user side only).
         * Returns true if thread is ready, false on failure.
         */
        async _ensureThread() {
            if (this.threadId) return true;
            try {
                const r = await fetch('/api/support/thread', {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
                const d = await r.json();
                if (! d.thread_id) return false;

                this.threadId        = d.thread_id;
                this.chatStatus      = d.chat_status || 'waiting';
                this.assignedAdminId = d.assigned_admin_id || null;

                await this._initThreadKey(d.thread_id, null);
                // NOTE: _startPoll() is intentionally NOT called here.
                // Callers must call fetchMessages(false) first to set lastTs,
                // then call _startPoll() — this prevents the race where the poll
                // fires with lastTs=null and over-counts messages as unread.
                this._subscribeReverb(d.thread_id);
                return true;
            } catch (e) {
                console.error('[Support] Thread creation failed', e.message);
                return false;
            }
        },

        // ── Polling ───────────────────────────────────────────────
        _startPoll() {
            this._clearPoll();
            this._pollTimer = setInterval(() => this.fetchMessages(true), 2000);
        },

        _subscribeReverb(threadId) {
            if (this._reverbChannel) {
                window.Echo?.leave(`support.thread.${this._reverbChannel}`);
            }
            this._reverbChannel = threadId;

            window.Echo?.channel(`support.thread.${threadId}`)
                .listen('.user.typing', (e) => {
                    if (e.userId === this.userId) return;

                    this._setPartnerOnline();

                    if (e.isTyping) {
                        this.typingUsers[e.userId] = e.userName;

                        if (this._typingClearTimer) clearTimeout(this._typingClearTimer);
                        this._typingClearTimer = setTimeout(() => {
                            delete this.typingUsers[e.userId];
                        }, 3000);

                    } else {
                        if (this._typingClearTimer) clearTimeout(this._typingClearTimer);
                        this._typingClearTimer = setTimeout(() => {
                            delete this.typingUsers[e.userId];
                        }, 1500);
                    }
                })
                .listen('.message.seen', (e) => {
                    if (e.seenByUserId !== this.userId) {
                        this.messagesSeen = true;
                        this.seenBy = e.seenByName;
                        setTimeout(() => { this.seenBy = ''; }, 10000);
                    }
                })
                .listen('.system.message', (e) => {
                    // Update chat status in real-time (e.g. 'active' when admin connects)
                    if (e.chatStatus) {
                        this.chatStatus = e.chatStatus;
                    }

                    // Dedup: don't push if we already have this message
                    if (this.messages.some(m => m.id === e.messageId)) return;

                    this.messages.push({
                        id:           e.messageId,
                        sender_id:    null,
                        sender:       'System',
                        role:         'system',
                        type:         'system',
                        body:         e.body,
                        metadata:     null,
                        is_encrypted: false,
                        created_at:   e.createdAt,
                    });
                    this._scrollToBottom();
                })
                .listen('.chat.ended', (e) => {
                    // Update local chat status
                    this.chatStatus = 'ended';

                    // Dedup: don't push if already present
                    if (this.messages.some(m => m.id === e.messageId)) return;

                    this.messages.push({
                        id:           e.messageId,
                        sender_id:    null,
                        sender:       'System',
                        role:         'system',
                        type:         'system',
                        body:         e.body,
                        metadata:     null,
                        is_encrypted: false,
                        created_at:   e.createdAt,
                    });
                    this._scrollToBottom();
                });
        },

        _setPartnerOnline() {
            this.partnerOnline = true;
            if (this._partnerOnlineTimer) clearTimeout(this._partnerOnlineTimer);
            this._partnerOnlineTimer = setTimeout(() => {
                this.partnerOnline = false;
            }, 30000);
        },

        _broadcastTyping(isTyping) {
            if (!this.threadId) return;
            fetch(`/api/support/thread/${this.threadId}/typing`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this._csrf(),
                },
                body: JSON.stringify({ is_typing: isTyping }),
            }).catch(() => {});
        },

        get typingText() {
            const names = Object.values(this.typingUsers);
            if (names.length === 0) return '';
            if (names.length === 1) return `${names[0]} is typing…`;
            return 'Several people are typing…';
        },

        /** Dynamic subtitle label shown under the header name (user view). */
        get chatStatusLabel() {
            if (this.chatStatus === 'active') return '🟢 Connected';
            if (this.chatStatus === 'ai_active') return '🤖 AI Agent';
            if (this.chatStatus === 'escalating') return '⏳ Connecting to agent…';
            if (this.chatStatus === 'ended')  return 'Chat ended';
            return 'We usually reply within minutes';
        },

        _clearPoll() {
            if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
            if (this._threadsPollInterval) { clearInterval(this._threadsPollInterval); this._threadsPollInterval = null; }
        },

        async fetchMessages(incremental = true) {
            if (!this.threadId) return;
            const since = incremental && this.lastTs ? `?since=${encodeURIComponent(this.lastTs)}` : '';

            try {
                const r = await fetch(`/api/support/thread/${this.threadId}/messages${since}`, {
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
                const d = await r.json();

                // Sync chat_status so the End Chat button visibility stays accurate
                if (d.chat_status && d.chat_status !== this.chatStatus) {
                    this.chatStatus = d.chat_status;
                }

                if (d.messages?.length) {
                    // Decrypt any E2EE messages before rendering
                    const decrypted = await this._decryptMessages(d.messages, this.threadId);

                    if (incremental) {
                        // Deduplicate: skip messages that were already pushed via WebSocket
                        const existingIds = new Set(this.messages.map(m => m.id));
                        const newMsgs = decrypted.filter(m => !existingIds.has(m.id));

                        if (newMsgs.length > 0) {
                            this.messages = [...this.messages, ...newMsgs];
                            this._scrollToBottom();
                        }
                    } else {
                        this.messages = decrypted;
                        this._scrollToBottom();
                    }

                    this.lastTs = d.messages[d.messages.length - 1].created_at;

                    // markAsSeen when panel is open and new messages arrived
                    if (this.open && d.messages.length > 0) {
                        await this.markAsSeen(this.threadId);
                    } else if (incremental && d.messages.length > 0) {
                        // For user badge: only count real admin messages
                        // System messages (welcome, "connecting...") and AI responses
                        // should never trigger the unread badge
                        const notifiable = this.userRole === 'admin'
                            ? d.messages.filter(m => m.role === 'user')
                            : d.messages.filter(m => m.role === 'admin');

                        if (notifiable.length > 0) {
                            this.unreadCount += notifiable.length;
                            window.dispatchEvent(new CustomEvent('support-unread', { detail: this.unreadCount }));
                        }
                    }
                }
            } catch (e) {
                console.warn('[Support] Fetch messages failed', e.message);
            }
        },

        // ── Send message ──────────────────────────────────────────
        async sendMessage() {
            const body = this.inputText.trim();
            if ((!body && !this.stagedFile) || this.sending) return;

            // Create thread on first send if it doesn't exist yet
            const isNewThread = !this.threadId;
            if (isNewThread && !await this._ensureThread()) return;

            this._broadcastTyping(false);

            this.sending   = true;
            this.inputText = '';
            const fileToSend = this.stagedFile;
            this.stagedFile  = null;

            try {
                const formData = new FormData();

                // Only encrypt when BOTH parties have key slots.
                // If the admin hasn't been added to encrypted_keys yet,
                // send plaintext so the admin can always read messages.
                const threadKey = (this._e2eeReady && this._allPartiesHaveKeys && typeof E2EE !== 'undefined')
                    ? E2EE.getCachedThreadKey(this.threadId)
                    : null;

                if (body && threadKey) {
                    // E2EE: encrypt before sending
                    const { ciphertext, iv } = await E2EE.encryptMessage(body, threadKey);
                    formData.append('body', ciphertext);
                    formData.append('iv', iv);
                    formData.append('is_encrypted', '1');
                } else if (body) {
                    formData.append('body', body);
                }

                if (fileToSend) formData.append('file', fileToSend);

                await fetch(`/api/support/thread/${this.threadId}/message`, {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                    body:    formData,
                });

                await this.fetchMessages(true);
                // Start poll after first fetch so lastTs is set before timer fires
                if (isNewThread) this._startPoll();
            } catch (e) {
                console.error('[Support] Send failed', e.message);
            } finally {
                this.sending = false;
            }
        },

        // ── End Chat (admin only) — opens the confirmation modal ──
        endChat() {
            if (!this.threadId || this.userRole !== 'admin') return;
            this.showEndChatModal = true;
        },

        // ── Confirm End Chat — called from modal buttons ───────────
        async confirmEndChat(resolutionStatus = 'resolved') {
            this.showEndChatModal = false;
            if (!this.threadId) return;

            try {
                await fetch(`/api/support/thread/${this.threadId}/end-chat`, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({ resolution_status: resolutionStatus }),
                });
                this.chatStatus = 'ended';

                // After 2s: reset admin view back to thread list
                setTimeout(() => {
                    this.threadId        = null;
                    this.messages        = [];
                    this.lastTs          = null;
                    this.chatStatus      = 'waiting';
                    this.activeUserId    = null;
                    this.activeUserName  = '';
                    this.assignedAdminId = null;
                    this._clearPoll();
                    this.open = false;
                }, 2000);
            } catch (e) {
                console.error('[Support] confirmEndChat failed', e);
            }
        },

        handleSupportFile(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.stagedFile = file;
            e.target.value = '';
        },

        removeSupportFile() {
            this.stagedFile = null;
        },

        formatBytes(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },

        isImage(mime) {
            return mime && mime.startsWith('image/');
        },

        // ── Trigger call (from within support chat) ───────────────
        triggerCall() {
            if (this.userRole === 'user') {
                window.dispatchEvent(new CustomEvent('open-rtc'));
                setTimeout(() => {
                    const webrtcEl = document.querySelector('[x-data*="webrtcApp"]')?._x_dataStack?.[0];
                    if (webrtcEl && typeof webrtcEl.callAdmin === 'function') {
                        webrtcEl.callAdmin();
                    }
                }, 100);
            } else if (this.userRole === 'admin' && this.activeUserId) {
                window.dispatchEvent(new CustomEvent('open-rtc'));
                setTimeout(() => {
                    const webrtcEl = document.querySelector('[x-data*="webrtcApp"]')?._x_dataStack?.[0];
                    if (webrtcEl && typeof webrtcEl.callUser === 'function') {
                        webrtcEl.callUser(this.activeUserId, this.activeUserName);
                    }
                }, 100);
            }
        },

        // ── Post-Chat Rating ──────────────────────────────────────
        async submitRating() {
            if (!this.threadId || this.postChatRating.isResolved === null) return;

            try {
                const response = await fetch(`/api/support/thread/${this.threadId}/rate-chat`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({
                        is_resolved_by_user: this.postChatRating.isResolved,
                        feedback_rating:     this.postChatRating.rating,
                        feedback_comment:    this.postChatRating.feedback || null,
                    }),
                });

                if (response.ok) {
                    this.postChatRating.submitted = true;
                    // After 2s: restart the thread and reset the UI to fresh state
                    setTimeout(async () => {
                        await this._restartChat();
                    }, 2000);
                }
            } catch (e) {
                console.error('[Support] Failed to submit feedback', e);
            }
        },

        async _restartChat() {
            if (!this.threadId) return;
            try {
                // Backend: reset thread status to 'waiting' + update session boundary.
                // No welcome message is persisted — it's shown client-side only.
                await fetch(`/api/support/thread/${this.threadId}/restart`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
            } catch (e) {
                console.warn('[Support] restartThread failed', e);
            }
            // Reset local state to fresh
            this.postChatRating = { isResolved: null, rating: null, feedback: '', submitted: false };
            this.messages       = [];
            // Set lastTs to RIGHT NOW so the running poll's next tick only fetches
            // messages created AFTER this restart point — old session messages can't
            // creep back in via the incremental poll.
            this.lastTs         = new Date().toISOString();
            this.chatStatus     = 'waiting';
            this._scrollToBottom();
        },

        // ── E2EE Helpers ──────────────────────────────────────────

        async _initE2EE() {
            if (typeof E2EE === 'undefined') return;
            try {
                this._privateKey = await E2EE.loadPrivateKey();

                if (!this._privateKey) {
                    // First time — generate and persist both keys
                    const { publicKey, privateKey } = await E2EE.generateKeyPair();
                    await E2EE.savePrivateKey(privateKey);
                    this._privateKey = privateKey;

                    const pubB64 = await E2EE.exportPublicKey(publicKey);
                    this._publicKeyB64 = pubB64;
                    await E2EE.savePublicKeyB64(pubB64);
                    await this._uploadPublicKey(pubB64);
                } else {
                    // Existing private key — load the stored public key and re-upload
                    // so the server always has it (handles DB resets / first-time admin login)
                    const storedPubB64 = await E2EE.loadPublicKeyB64();

                    if (storedPubB64) {
                        this._publicKeyB64 = storedPubB64;
                        await this._uploadPublicKey(storedPubB64); // idempotent PUT
                    } else {
                        // No stored public key — generate a fresh keypair as last resort
                        // (old thread keys wrapped with old public key will be unreadable,
                        //  but future key exchanges will work correctly)
                        const { publicKey, privateKey } = await E2EE.generateKeyPair();
                        await E2EE.savePrivateKey(privateKey);
                        this._privateKey = privateKey;

                        const pubB64 = await E2EE.exportPublicKey(publicKey);
                        this._publicKeyB64 = pubB64;
                        await E2EE.savePublicKeyB64(pubB64);
                        await this._uploadPublicKey(pubB64);
                    }
                }

                this._e2eeReady = true;
            } catch (err) {
                console.warn('[E2EE] Key init failed — falling back to plaintext', err);
            }
        },

        async _uploadPublicKey(pubB64) {
            await fetch('/api/user/public-key', {
                method:  'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this._csrf(),
                },
                body: JSON.stringify({ public_key: pubB64 }),
            });
        },

        async _initThreadKey(threadId, partnerUserId, _retryCount = 0) {
            if (!this._e2eeReady || typeof E2EE === 'undefined') return;

            // Determine who the "other party" is for key-slot verification
            const expectedPartnerId = partnerUserId
                ? partnerUserId
                : parseInt(document.querySelector('meta[name="support-admin-id"]')?.content || '0');

            try {
                const r = await fetch(`/api/support/thread/${threadId}/keys`, {
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
                const d = await r.json();
                const keys = d.encrypted_keys || {};
                const myWrappedKey = keys[String(this.userId)];

                if (myWrappedKey) {
                    // ✅ We have our wrapped copy — decrypt and cache it
                    const threadKey = await E2EE.decryptThreadKey(myWrappedKey, this._privateKey);
                    E2EE.cacheThreadKey(threadId, threadKey);

                    // 🔄 If known partner has no slot, add them now using our thread key.
                    // This handles the case where admin opens the thread before their
                    // public key was available when the user first set up the thread key.
                    let partnerHasSlot = !!keys[String(expectedPartnerId)];

                    if (expectedPartnerId && expectedPartnerId !== this.userId && !partnerHasSlot) {
                        partnerHasSlot = await this._addPartySlot(threadId, expectedPartnerId, threadKey, keys) === true;
                    }

                    // ✅ Mark encryption as safe only when both parties have key slots.
                    // Until then, messages are sent as plaintext so both sides can read them.
                    this._allPartiesHaveKeys = partnerHasSlot;

                } else if (Object.keys(keys).length === 0) {
                    // ✅ No keys exist at all — we're the first party, generate a fresh thread key
                    const threadKey    = await E2EE.generateThreadKey();
                    const encryptedMap = {};

                    // Encrypt for ourselves
                    let ownPubB64 = this._publicKeyB64;
                    if (!ownPubB64) {
                        const ownR = await fetch(`/api/user/${this.userId}/public-key`, {
                            headers: { 'X-CSRF-TOKEN': this._csrf() },
                        });
                        const ownD = await ownR.json();
                        ownPubB64  = ownD.public_key;
                    }
                    if (ownPubB64) {
                        const ownPubKey = await E2EE.importPublicKey(ownPubB64);
                        encryptedMap[String(this.userId)] = await E2EE.encryptThreadKeyForUser(threadKey, ownPubKey);
                    }

                    // Encrypt for explicitly known partner (admin side)
                    if (partnerUserId) {
                        const partR = await fetch(`/api/user/${partnerUserId}/public-key`, {
                            headers: { 'X-CSRF-TOKEN': this._csrf() },
                        });
                        const partD = await partR.json();
                        if (partD.public_key) {
                            const partPubKey = await E2EE.importPublicKey(partD.public_key);
                            encryptedMap[String(partnerUserId)] = await E2EE.encryptThreadKeyForUser(threadKey, partPubKey);
                        }
                    }

                    // User side (no explicit partner yet): pre-encrypt for the default admin
                    if (!partnerUserId) {
                        const adminId = parseInt(
                            document.querySelector('meta[name="support-admin-id"]')?.content || '0'
                        );
                        if (adminId && adminId !== this.userId) {
                            try {
                                const adminR = await fetch(`/api/user/${adminId}/public-key`, {
                                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                                });
                                const adminD = await adminR.json();
                                if (adminD.public_key) {
                                    const adminPubKey = await E2EE.importPublicKey(adminD.public_key);
                                    encryptedMap[String(adminId)] = await E2EE.encryptThreadKeyForUser(threadKey, adminPubKey);
                                }
                            } catch (_) { /* admin may not have generated their key pair yet — skip */ }
                        }
                    }

                    await fetch(`/api/support/thread/${threadId}/keys`, {
                        method:  'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this._csrf(),
                        },
                        body: JSON.stringify({ keys: encryptedMap }),
                    });

                    E2EE.cacheThreadKey(threadId, threadKey);

                    // Check if partner was included
                    this._allPartiesHaveKeys = expectedPartnerId
                        ? !!encryptedMap[String(expectedPartnerId)]
                        : false;

                } else {
                    // ⚠️ Keys exist but no slot for us yet.
                    // The user who holds the key will add our slot once they open their panel.
                    // Retry a few times so we can decrypt as soon as it becomes available.
                    if (_retryCount < 6) {
                        setTimeout(async () => {
                            await this._initThreadKey(threadId, partnerUserId, _retryCount + 1);

                            // If we now have the key, do a full message refresh to decrypt
                            if (E2EE.getCachedThreadKey(threadId)) {
                                await this.fetchMessages(false);
                            }
                        }, 5000);
                    }
                }

            } catch (err) {
                console.warn('[E2EE] Thread key init failed', err);
            }
        },

        /**
         * Add a key slot for a party that was missing from encrypted_keys.
         * The caller must already hold the plaintext threadKey.
         */
        async _addPartySlot(threadId, partyUserId, threadKey, existingKeys) {
            try {
                const r = await fetch(`/api/user/${partyUserId}/public-key`, {
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
                const d = await r.json();
                if (!d.public_key) return false; // Party hasn't generated their keypair yet

                const pubKey     = await E2EE.importPublicKey(d.public_key);
                const wrappedKey = await E2EE.encryptThreadKeyForUser(threadKey, pubKey);

                const updatedKeys = { ...existingKeys, [String(partyUserId)]: wrappedKey };
                await fetch(`/api/support/thread/${threadId}/keys`, {
                    method:  'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({ keys: updatedKeys }),
                });
                return true;
            } catch (err) {
                console.warn('[E2EE] Failed to add key slot for user', partyUserId, err);
                return false;
            }
        },

        async _decryptMessages(messages, threadId) {
            if (!this._e2eeReady || typeof E2EE === 'undefined') return messages;

            const threadKey = E2EE.getCachedThreadKey(threadId);

            return Promise.all(messages.map(async (msg) => {
                if (!msg.is_encrypted) return msg;

                // No thread key cached — show a clean placeholder instead of raw ciphertext
                if (!threadKey) return { ...msg, body: '🔒 Encrypted' };

                const iv = msg.metadata?.encryption?.iv;
                if (!iv) return { ...msg, body: '🔒 Encrypted' };

                try {
                    const plaintext = await E2EE.decryptMessage(msg.body, iv, threadKey);
                    return { ...msg, body: plaintext };
                } catch (_) {
                    return { ...msg, body: '🔒 Encrypted' };
                }
            }));
        },

        // ── Helpers ───────────────────────────────────────────────
        _csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        _scrollToBottom() {
            const el = document.getElementById('support-messages');
            if (el) el.scrollTop = el.scrollHeight;
            this.$nextTick(() => {
                if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
            });
        },

        formatTime(iso) {
            const d = new Date(iso);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },

        isOwnMessage(msg) {
            // System messages are never "own"
            if (msg.type === 'system' || msg.sender_id === null) return false;
            return msg.sender_id === this.userId;
        },

        /**
         * Convert Markdown meeting-notes to sanitised HTML for x-html rendering.
         */
        renderNotes(md) {
            if (!md) return '';

            const lines = md.split('\n');
            let html    = '';
            let inList  = false;

            lines.forEach(raw => {
                const line = raw.trimEnd();

                if (/^##\s+/.test(line)) {
                    if (inList) { html += '</ul>'; inList = false; }
                    const text = this._mdInline(line.replace(/^##\s+/, ''));
                    html += `<h3 class="mn-heading">${text}</h3>`;
                    return;
                }

                if (/^###\s+/.test(line)) {
                    if (inList) { html += '</ul>'; inList = false; }
                    const text = this._mdInline(line.replace(/^###\s+/, ''));
                    html += `<h4 class="mn-subheading">${text}</h4>`;
                    return;
                }

                if (/^[\*\-]\s+/.test(line)) {
                    if (!inList) { html += '<ul class="mn-list">'; inList = true; }
                    const text = this._mdInline(line.replace(/^[\*\-]\s+/, ''));
                    html += `<li>${text}</li>`;
                    return;
                }

                if (inList) { html += '</ul>'; inList = false; }

                if (line === '') {
                    html += '<div class="mn-gap"></div>';
                    return;
                }

                html += `<p class="mn-para">${this._mdInline(line)}</p>`;
            });

            if (inList) html += '</ul>';
            return html;
        },

        _mdInline(text) {
            return text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g,     '<em>$1</em>')
                .replace(/`(.+?)`/g,       '<code class="mn-code">$1</code>');
        },
    };
};
