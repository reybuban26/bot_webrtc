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

        typingUsers: {},      // { userId: { name, timer } }
        seenBy: '',           // pangalan ng nakakita
        _reverbChannel: null,

        partnerOnline: false,
        _partnerOnlineTimer: null,
        messagesSeen: false, // naging blue na ba ang checks

        // Admin thread list
        threads:        [],
        activeUserId:   null,
        activeUserName: '',

        // Call state (from context)
        userRole: document.querySelector('meta[name="user-role"]')?.content || 'user',
        userId:   parseInt(document.querySelector('meta[name="auth-user-id"]')?.content || '0'),

        // ── Init ──────────────────────────────────────────────────
        async init() {
            this.$watch('open', val => {
                if (val) {
                    this.unreadCount = 0;
                    window.dispatchEvent(new CustomEvent('support-unread', { detail: 0 }));
                    setTimeout(() => this._scrollToBottom(), 50);

                    // ✅ DAGDAG — markAsSeen agad kapag nabuksan ang panel
                    if (this.threadId) {
                        this.markAsSeen(this.threadId);
                    }
                }
            });

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
                await this.markAsSeen(threadId);
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
            } catch (e) {
                console.warn('[Support] markAsSeen failed', e);
            }
        },

        // ── User: open own thread ──────────────────────────────────
        // Load thread data silently on init so the panel is ready instantly
        // when the user clicks the Support button. Do NOT set open=true here
        // — the panel must only appear on explicit user action.
        async openMyThread() {
            await this.openThread(null);
            // Mark as seen when user opens their own thread if messages exist
            if (this.threadId && this.open) {
                await this.markAsSeen(this.threadId);
            }
        },

        async openThread(userId) {
            this._clearPoll();
            this.messages = [];
            this.lastTs   = null;
            this.messagesSeen = false; // ← DAGDAG
            this.partnerOnline = false; // ← DAGDAG

            const url = userId
                ? `/api/support/thread?user_id=${userId}`
                : '/api/support/thread';

            try {
                const r  = await fetch(url, { headers: { 'X-CSRF-TOKEN': this._csrf() } });
                const d  = await r.json();
                this.threadId = d.thread_id;

                // Load initial messages
                await this.fetchMessages(false);
                this._startPoll();
                // Subscribe sa Reverb channel para sa real-time events
                this._subscribeReverb(d.thread_id);
            } catch (e) {
                console.error('[Support] openThread failed', e.message);
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

                // ← DAGDAG: Kung may typing event = online ang partner
                this._setPartnerOnline();

                if (e.isTyping) {
                    this.typingUsers[e.userId] = e.userName;
                } else {
                    delete this.typingUsers[e.userId];
                }
            })
            .listen('.message.seen', (e) => {
                if (e.seenByUserId !== this.userId) {
                    this.messagesSeen = true; // ← blue agad lahat ng checks
                    this.seenBy = e.seenByName;
                    setTimeout(() => { this.seenBy = ''; }, 10000);
                }
            });
        },

        _setPartnerOnline() {
            this.partnerOnline = true;
            // I-reset ang online status after 30 seconds ng walang activity
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

                if (d.messages?.length) {
                    this.messages = incremental
                        ? [...this.messages, ...d.messages]
                        : d.messages;

                    this.lastTs = d.messages[d.messages.length - 1].created_at;
                    this._scrollToBottom();

                    // ✅ PALITAN — markAsSeen AGAD kapag bukas ang panel, hindi lang count
                    if (this.open) {
                        await this.markAsSeen(this.threadId);
                    } else {
                        this.unreadCount += d.messages.length;
                        window.dispatchEvent(new CustomEvent('support-unread', { detail: this.unreadCount }));
                    }
                }
            } catch (e) {
                console.warn('[Support] Fetch messages failed', e.message);
            }
        },

        // ── Send message ──────────────────────────────────────────
        async sendMessage() {
            const body = this.inputText.trim();
            if ((!body && !this.stagedFile) || this.sending || !this.threadId) return;

            this._broadcastTyping(false);

            this.sending   = true;
            this.inputText = '';
            const fileToSend = this.stagedFile;
            this.stagedFile  = null;

            try {
                const formData = new FormData();
                if (body)       formData.append('body', body);
                if (fileToSend) formData.append('file', fileToSend);

                await fetch(`/api/support/thread/${this.threadId}/message`, {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                    body:    formData,
                });

                await this.fetchMessages(true);
            } catch (e) {
                console.error('[Support] Send failed', e.message);
            } finally {
                this.sending = false;
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
                // User → Admin call: open the WebRTC modal and call admin
                window.dispatchEvent(new CustomEvent('open-rtc'));
                // Slight delay to let Alpine render the modal, then auto-call
                setTimeout(() => {
                    const webrtcEl = document.querySelector('[x-data*="webrtcApp"]')?._x_dataStack?.[0];
                    if (webrtcEl && typeof webrtcEl.callAdmin === 'function') {
                        webrtcEl.callAdmin();
                    }
                }, 100);
            } else if (this.userRole === 'admin' && this.activeUserId) {
                // Admin → User call
                window.dispatchEvent(new CustomEvent('open-rtc'));
                setTimeout(() => {
                    const webrtcEl = document.querySelector('[x-data*="webrtcApp"]')?._x_dataStack?.[0];
                    if (webrtcEl && typeof webrtcEl.callUser === 'function') {
                        webrtcEl.callUser(this.activeUserId, this.activeUserName);
                    }
                }, 100);
            }
        },

        // ── Helpers ───────────────────────────────────────────────
        _csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        _scrollToBottom() {
            const el = document.getElementById('support-messages');
            if (el) el.scrollTop = el.scrollHeight; // immediate
            this.$nextTick(() => {
                if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
            });
        },

        formatTime(iso) {
            const d = new Date(iso);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },

        isOwnMessage(msg) {
            return msg.sender_id === this.userId;
        },

        /**
         * Convert Markdown meeting-notes to sanitised HTML for x-html rendering.
         * Handles: ## headings, **bold**, * bullets, blank-line paragraphs.
         */
        renderNotes(md) {
            if (!md) return '';

            const lines = md.split('\n');
            let html    = '';
            let inList  = false;

            lines.forEach(raw => {
                const line = raw.trimEnd();

                // --- H2 heading:  ## Title
                if (/^##\s+/.test(line)) {
                    if (inList) { html += '</ul>'; inList = false; }
                    const text = this._mdInline(line.replace(/^##\s+/, ''));
                    html += `<h3 class="mn-heading">${text}</h3>`;
                    return;
                }

                // --- H3 heading:  ### Title
                if (/^###\s+/.test(line)) {
                    if (inList) { html += '</ul>'; inList = false; }
                    const text = this._mdInline(line.replace(/^###\s+/, ''));
                    html += `<h4 class="mn-subheading">${text}</h4>`;
                    return;
                }

                // --- Bullet: * item or - item
                if (/^[\*\-]\s+/.test(line)) {
                    if (!inList) { html += '<ul class="mn-list">'; inList = true; }
                    const text = this._mdInline(line.replace(/^[\*\-]\s+/, ''));
                    html += `<li>${text}</li>`;
                    return;
                }

                // --- Close list before blank or normal line
                if (inList) { html += '</ul>'; inList = false; }

                // --- Blank line → paragraph break
                if (line === '') {
                    html += '<div class="mn-gap"></div>';
                    return;
                }

                // --- Normal paragraph line
                html += `<p class="mn-para">${this._mdInline(line)}</p>`;
            });

            if (inList) html += '</ul>';
            return html;
        },

        /** Inline Markdown: **bold**, *italic*, backtick `code` */
        _mdInline(text) {
            return text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g,     '<em>$1</em>')
                .replace(/`(.+?)`/g,       '<code class="mn-code">$1</code>');
        },
    };
};
