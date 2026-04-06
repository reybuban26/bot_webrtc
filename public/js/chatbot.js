/**
 * chatbot.js — Main chat logic + voice synthesis
 */

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

window.chatApp = function () {
    return {
        // ── State ────────────────────────────────────────────────────
        messages: [],
        inputText: '',
        isLoading: false,
        sessionToken: '',
        sessionTitle: 'New Chat',
        sessions: [],
        activeSessionId: null,
        isDark: false,          // default to light theme (BeeBot style)
        searchQuery: '',        // sidebar session search
        statusText: 'Ready',
        statusMode: 'online',

        // Voice
        isListening: false,
        isSpeaking: false,
        recognition: null,
        currentAudio: null,

        stagedFiles: [],

        // Profile
        profileOpen: false,
        profileSaving: false,
        profileSaved: false,
        avatarUploading: false,
        profile: { name: '', email: '', phone: '', avatar_url: null },

        // ── Greeting helper ──────────────────────────────────────────
        greetingText() {
            const h = new Date().getHours();
            if (h < 12) return 'Good Morning';
            if (h < 17) return 'Good Afternoon';
            return 'Good Evening';
        },

        // ── Filtered sessions for search ─────────────────────────────
        get filteredSessions() {
            if (!this.searchQuery.trim()) return this.sessions;
            const q = this.searchQuery.toLowerCase();
            return this.sessions.filter(s => s.title.toLowerCase().includes(q));
        },

        // ── Init ─────────────────────────────────────────────────────
        async init() {
            const saved = localStorage.getItem('theme');
            this.isDark = (saved === 'dark');
            this.applyTheme();

            this.sessionToken = this.getCookie('chat_session') || '';
            await this.loadSessions();

            if (this.sessionToken) {
                await this.loadHistory(this.sessionToken);
            } else if (this.sessions.length > 0) {
                this.sessionToken = this.sessions[0].token;
                await this.loadHistory(this.sessionToken);
            }

            this.voiceProvider = 'web_speech';

            this.initSpeechRecognition();
            this.$nextTick(() => this.scrollToBottom());
            this.loadProfile();

            // Pause heavy polling when tab is hidden
            this._setupVisibilityHandler();
        },

        _setupVisibilityHandler() {
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this._wasListening = this.isListening;
                    if (this.isListening && this.recognition) {
                        this.recognition.stop();
                    }
                } else if (this._wasListening) {
                    try { this.recognition?.start(); } catch(_) {}
                }
            });
        },

        // ── Theme ────────────────────────────────────────────────────
        applyTheme() {
            const app = document.getElementById('app');
            if (app) app.classList.toggle('dark', this.isDark);
            document.documentElement.classList.toggle('dark', this.isDark);
            localStorage.setItem('theme', this.isDark ? 'dark' : 'light');
        },
        toggleTheme() { this.isDark = !this.isDark; this.applyTheme(); },

        // ── Session Management ────────────────────────────────────────
        async loadSessions() {
            try {
                const r = await fetch('/api/chat/sessions');
                if (r.ok) this.sessions = await r.json();
            } catch (_) {}
        },

        newChat() {
            this.sessionToken = '';
            this.messages = [];
            this.sessionTitle = 'New Chat';
            this.$nextTick(() => this.$refs.input?.focus());
        },

        async _createSession() {
            const r = await fetch('/api/chat/session/new', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json' },
            });
            if (!r.ok) throw new Error('Could not create chat session');
            const d = await r.json();
            this.sessionToken = d.session_token;
            return d.session_token;
        },

        async switchSession(token) {
            if (token === this.sessionToken) return;
            this.sessionToken = token;
            this.messages = [];
            await this.loadHistory(token);
            this.$nextTick(() => this.scrollToBottom());
        },

        async deleteSession(token, e) {
            e.stopPropagation();
            if (!confirm('Delete this chat?')) return;
            try {
                await fetch(`/api/chat/session/${token}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': CSRF },
                });
                await this.loadSessions();
                if (token === this.sessionToken) {
                    if (this.sessions.length > 0) {
                        await this.switchSession(this.sessions[0].token);
                    } else {
                        this.newChat();
                    }
                }
            } catch (_) {}
        },

        async loadHistory(token) {
            try {
                const r = await fetch(`/api/chat/session/${token}/history`);
                if (!r.ok) return;
                const d = await r.json();
                this.sessionTitle = d.session.title;
                this.messages = d.messages.map(m => ({
                    id: m.id, role: m.role,
                    content: m.content, audio_url: m.audio_url,
                    attachments: m.attachments || [],
                    time: this.formatTime(m.created_at),
                }));
            } catch (_) {}
        },
        
        // ── Profile ───────────────────────────────────────────────────
        async loadProfile() {
            try {
                const r = await fetch('/api/profile');
                if (r.ok) this.profile = await r.json();
            } catch (_) {}
        },

        async openProfile() {
            await this.loadProfile();
            this.profileOpen = true;
            this.profileSaved = false;
        },

        async saveProfile() {
            this.profileSaving = true;
            try {
                const r = await fetch('/api/profile', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                    body: JSON.stringify({ name: this.profile.name, phone: this.profile.phone, dob: this.profile.dob }),
                });
                if (r.ok) {
                    const d = await r.json();
                    this.profile.name = d.name;
                    this.profileSaved = true;
                    // Auto close modal after showing success for 1.5 secs
                    setTimeout(() => {
                        this.profileSaved = false;
                        this.profileOpen = false;
                    }, 1500);
                }
            } catch (_) {}
            this.profileSaving = false;
        },

        async uploadAvatar(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.avatarUploading = true;
            const form = new FormData();
            form.append('avatar', file);
            form.append('_method', 'POST');
            try {
                const r = await fetch('/api/profile/avatar', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF },
                    body: form,
                });
                if (r.ok) {
                    const d = await r.json();
                    this.profile.avatar_url = d.avatar_url + '?v=' + Date.now();
                }
            } catch (_) {}
            this.avatarUploading = false;
            e.target.value = '';
        },

        // ── File Attachments ─────────────────────────────────────────
        handleFileUpload(e) {
            const files = Array.from(e.target.files);
            this.stagedFiles = [...this.stagedFiles, ...files];
            e.target.value = '';
        },

        removeFile(idx) {
            this.stagedFiles.splice(idx, 1);
        },

        // ── Sending Messages ──────────────────────────────────────────
        // async sendMessage() {
        //     const rawText = this.inputText.trim();
        //     let promptText = rawText;
        //     const originalFileCount = this.stagedFiles.length;

        //     if ((!rawText && originalFileCount === 0) || this.isLoading) return;

        //     if (!this.sessionToken) {
        //         try {
        //             await this._createSession();
        //         } catch (e) {
        //             this.messages.push({
        //                 id: Date.now(), role: 'assistant',
        //                 content: '⚠️ Could not start a chat session. Please try again.',
        //                 time: this.formatTime(new Date().toISOString()),
        //             });
        //             return;
        //         }
        //     }

        //     // Optimistic UI for chat bubble
        //     let displayContent = rawText;
        //     if (originalFileCount > 0) {
        //         const names = this.stagedFiles.map(f => f.name).join(', ');
        //         displayContent += rawText ? `\n\n*(Attached: ${names})*` : `*(Attached: ${names})*`;
        //     }

        //     this.messages.push({
        //         id: Date.now(), role: 'user',
        //         content: rawText + (this.stagedFiles.length > 0 ? "\n*(Uploading files...)*" : ""),
        //         time: this.formatTime(new Date().toISOString()),
        //     });
        //     this.inputText = '';
        //     this.autoResize();
        //     this.$nextTick(() => this.scrollToBottom());

        //     this.isLoading = true;
        //     this.statusMode = 'loading';

        //     const formData = new FormData();
        //     formData.append('message', rawText);
        //     formData.append('session_token', this.sessionToken);
        //     this.stagedFiles.forEach(f => formData.append('files[]', f));

        //     this.inputText = '';
        //     this.stagedFiles = [];
        //     this.isLoading = true;
        //     this.$nextTick(() => this.scrollToBottom());
            
        //     // Read files into memory before sending
        //     if (originalFileCount > 0) {
        //         this.statusText = 'Reading files…';
        //         try {
        //             let fileTexts = await Promise.all(this.stagedFiles.map((file) => {
        //                 return new Promise((resolve) => {
        //                     const reader = new FileReader();
        //                     reader.onload = (e) => resolve(`\n\n--- [Attached File: ${file.name}] ---\n${e.target.result}\n---`);
        //                     reader.onerror = () => resolve(`\n\n--- [Attached File: ${file.name}] ---\n(Could not read file contents)\n---`);
        //                     reader.readAsText(file);
        //                 });
        //             }));
        //             const combined = fileTexts.join('');
        //             if (!promptText) promptText = "Please analyze the attached files." + combined;
        //             else promptText += combined;
        //         } catch (err) {
        //             console.error("File Read Error:", err);
        //         }
        //         this.stagedFiles = []; // clear staging area
        //     }

        //     this.statusText = 'AI is thinking…';

        //     try {
        //         const r = await fetch('/api/chat/send', {
        //             method: 'POST',
        //             headers: {
        //                 'Content-Type': 'application/json',
        //                 'X-CSRF-TOKEN': CSRF,
        //             },
        //             body: JSON.stringify({ message: promptText, session_token: this.sessionToken }),
        //         });

        //         if (!r.ok) {
        //             const err = await r.json().catch(() => ({}));
        //             throw new Error(err.message || `Server error ${r.status}`);
        //         }

        //         const d = await r.json();

        //         // Stop typing indicator FIRST, then stream in the response
        //         this.isLoading = false;
        //         this.statusMode = 'online';
        //         this.statusText = 'Ready';

        //         const fullContent = d.message.content;
        //         const msgId = d.message.id ?? Date.now();

        //         // Add empty placeholder, then typewrite into it
        //         this.messages.push({
        //             id: msgId, role: 'assistant',
        //             content: '', time: this.formatTime(d.message.created_at),
        //         });
        //         this.$nextTick(() => this.scrollToBottom());

        //         // Auto-speak using Web Speech immediately (CONCURRENT with typing effect)
        //         this.speakWebSpeech(fullContent);

        //         await this.typewriteMessage(msgId, fullContent);

        //         if (d.session_title && d.session_title !== 'New Chat') {
        //             this.sessionTitle = d.session_title;
        //             await this.loadSessions();
        //         }

        //     } catch (err) {
        //         this.isLoading = false;
        //         this.statusMode = 'online';
        //         this.statusText = 'Ready';
        //         this.messages.push({
        //             id: Date.now(), role: 'assistant',
        //             content: '⚠️ ' + (err.message || 'Network error. Please check your connection.'),
        //             time: this.formatTime(new Date().toISOString()),
        //         });
        //         this.$nextTick(() => this.scrollToBottom());
        //     }
        // },

        async sendMessage() {
            const rawText = this.inputText.trim();
            const originalFileCount = this.stagedFiles.length;

            if ((!rawText && originalFileCount === 0) || this.isLoading) return;

            if (!this.sessionToken) {
                try {
                    await this._createSession();
                } catch (e) {
                    this.messages.push({ id: Date.now(), role: 'assistant', content: '⚠️ Session error.', time: this.formatTime(new Date().toISOString()) });
                    return;
                }
            }

            // Display message in UI
            let displayMsg = rawText;
            if (originalFileCount > 0) {
                displayMsg += `\n*(Uploading ${originalFileCount} file/s...)*`;
            }

            const currentAttachments = this.stagedFiles.map(f => ({
                name: f.name,
                format: this.getFileFormat(f.name)
            }));

            this.messages.push({
                id: Date.now(), 
                role: 'user',
                content: rawText,
                attachments: currentAttachments, // Dito natin isasama yung listahan
                time: this.formatTime(new Date().toISOString()),
            });

            // Prepare Multipart Form Data (Dito isasama ang PDF)
            const formData = new FormData();
            formData.append('message', rawText);
            formData.append('session_token', this.sessionToken);
            this.stagedFiles.forEach(f => formData.append('files[]', f));

            this.inputText = '';
            this.stagedFiles = []; // Clear agad pagkatapos i-append
            this.isLoading = true;
            this.statusMode = 'loading';
            this.statusText = 'AI is reading files...';
            this.$nextTick(() => this.scrollToBottom());

            try {
                const r = await fetch('/api/chat/send', {
                    method: 'POST',
                    headers: { 
                        'X-CSRF-TOKEN': CSRF 
                    }, 
                    body: formData,
                });

                if (!r.ok) {
                    const errData = await r.json();
                    throw new Error(errData.message || `Server error ${r.status}`);
                }

                const d = await r.json();
                this.isLoading = false;
                this.statusMode = 'online';
                this.statusText = 'Ready';

                if (d.success) {
                    const msgId = d.message.id ?? Date.now();
                    this.messages.push({
                        id: msgId, role: 'assistant',
                        content: '', time: this.formatTime(d.message.created_at),
                    });

                    this.speakWebSpeech(d.message.content);
                    await this.typewriteMessage(msgId, d.message.content);

                    if (d.action === 'route_to_support') {
                        window.dispatchEvent(new CustomEvent('open-support'));
                    }
                }
            } catch (err) {
                this.isLoading = false;
                this.statusMode = 'online';
                this.messages.push({ id: Date.now(), role: 'assistant', content: '⚠️ Error: ' + err.message, time: this.formatTime(new Date().toISOString()) });
            }
        },

        // Typewriter streaming effect — uses direct DOM mutation + throttled scroll
        async typewriteMessage(id, fullText) {
            const words = fullText.split(' ');
            const msgIdx = this.messages.findLastIndex(m => m.id === id);
            if (msgIdx === -1) {
                const m = this.messages.find(m => m.id === id);
                if (m) { m.content = fullText; m.renderedHtml = this.renderContent(fullText); }
                return;
            }

            // Find the actual DOM element for direct mutation
            const msgContainer = this.$refs.msgContainer;
            const msgEls = msgContainer ? msgContainer.querySelectorAll('.message.assistant') : [];
            const targetEl = msgEls[msgEls.length - 1]; // last assistant message
            const bubbleEl = targetEl ? targetEl.querySelector('.msg-bubble.assistant') : null;

            let built = '';
            let lastScroll = 0;

            for (const word of words) {
                built += (built ? ' ' : '') + word;
                // Update Alpine state (for reactivity)
                this.messages[msgIdx].content = built;

                // Direct DOM mutation to bypass Alpine re-render cycle
                if (bubbleEl) {
                    bubbleEl.innerHTML = this.renderContent(built);
                }

                // Throttle scroll to ~100ms instead of every 16ms
                const now = Date.now();
                if (now - lastScroll > 100) {
                    this.scrollToBottom();
                    lastScroll = now;
                }

                await new Promise(r => setTimeout(r, 16));
            }

            // Final state: cache rendered HTML and scroll
            this.messages[msgIdx].renderedHtml = this.renderContent(fullText);
            this.scrollToBottom();
        },

        getFileFormat(name) {
            const ext = name.split('.').pop().toLowerCase();
            const formats = {
                // Documents
                pdf: '📕 PDF',
                doc: '📘 DOC',
                docx: '📘 DOCX',
                txt: '📄 TXT',
                md: '📝 MD',
                // Spreadsheets
                xls: '📗 XLS',
                xlsx: '📗 XLSX',
                csv: '📊 CSV',
                // Presentations
                ppt: '📙 PPT',
                pptx: '📙 PPTX',
                // Images
                jpg: '🖼️ JPG',
                jpeg: '🖼️ JPEG',
                png: '🖼️ PNG',
                gif: '🖼️ GIF',
                // Code/Data
                json: '📦 JSON'
            };
            return formats[ext] || '📁 FILE';
        },

        handleEnter(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        },

        setQuickPrompt(text) {
            this.inputText = text;
            this.$nextTick(() => this.$refs.input?.focus());
        },

        autoResize() {
            const el = this.$refs.input;
            if (!el) return;
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
        },

        // ── Voice Input (Web Speech API) ──────────────────────────────
        initSpeechRecognition() {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) return;
            this.recognition = new SpeechRecognition();
            this.recognition.continuous = false;
            this.recognition.interimResults = true;
            this.recognition.lang = 'en-US';

            this.recognition.onresult = (e) => {
                const transcript = Array.from(e.results).map(r => r[0].transcript).join('');
                this.inputText = transcript;
            };
            this.recognition.onend = () => { this.isListening = false; };
            this.recognition.onerror = () => { this.isListening = false; };
        },

        toggleVoiceInput() {
            if (!this.recognition) {
                alert('Speech recognition not supported in your browser. Try Chrome.');
                return;
            }
            if (this.isListening) {
                this.recognition.stop();
            } else {
                this.recognition.start();
                this.isListening = true;
            }
        },

        // ── Voice Output ──────────────────────────────────────────────
        speakMessage(content) {
            this.speakWebSpeech(content);
        },

        speakWebSpeech(text) {
            if (!window.speechSynthesis) return;
            // Cancel any ongoing speech immediately
            window.speechSynthesis.cancel();

            // Strip markdown/symbols for cleaner TTS
            const clean = text
                .replace(/```[\s\S]*?```/g, 'code block')
                .replace(/`([^`]+)`/g, '$1')
                .replace(/\*\*([^*]+)\*\*/g, '$1')
                .replace(/\*([^*]+)\*/g, '$1')
                .replace(/^#+\s/gm, '')
                .replace(/[#*_~`>]/g, '')
                .trim();

            const utt = new SpeechSynthesisUtterance(clean);
            utt.rate = 1.05;
            utt.pitch = 1;
            utt.lang = 'en-GB';

            // Pick the preferred UK English Male voice
            const voices = window.speechSynthesis.getVoices();
            const preferred = voices.find(v => 
                v.name === 'Google UK English Male' || 
                v.name.includes('UK English Male') || 
                v.name.includes('Daniel')
            ) || voices.find(v => v.lang.startsWith('en'));
            if (preferred) utt.voice = preferred;

            window.speechSynthesis.speak(utt);
        },

        playAudio(url) {
            if (this.currentAudio) { this.currentAudio.pause(); this.currentAudio = null; }
            this.currentAudio = new Audio(url);
            this.currentAudio.play().catch(() => {});
        },

        // ── Render cache ──────────────────────────────────────────────
        _renderCache: new Map(),
        _renderCacheMax: 500,

        renderContent(content) {
            if (!content) return '';
            // Check cache first
            if (this._renderCache.has(content)) {
                return this._renderCache.get(content);
            }
            
            const result = this._renderContentImpl(content);
            
            // Cache management
            if (this._renderCache.size >= this._renderCacheMax) {
                // Remove oldest entry (first key)
                const firstKey = this._renderCache.keys().next().value;
                this._renderCache.delete(firstKey);
            }
            this._renderCache.set(content, result);
            return result;
        },

        _renderContentImpl(content) {
            const c = this.$refs.msgContainer;
            if (c) c.scrollTop = c.scrollHeight;
        },

        formatTime(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
        },

        getCookie(name) {
            const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? match[2] : '';
        },

        // Render markdown-like content (basic)
        renderContent(content) {
            if (!content) return '';
            
            // ── 1. Escape HTML to prevent injection ──────────────────
            let text = content
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            // ── 2. Pre-process spacing and structure ─────────────────
            text = text
                .replace(/^[ \t]+/gm, '') // Remove all leading spaces per line to force alignment
                .replace(/^>\s?/gm, '')   // Remove blockquote arrows
                .replace(/\n{3,}/g, '\n\n') // Normalize multiple line breaks
                .trim();

            // ── 3. Parse Code Blocks first so they aren't messed up ──
            text = text.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
            text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

            // ── 4. Parse Headings ────────────────────────────────────
            text = text.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            text = text.replace(/^## (.+)$/gm, '<h2>$1</h2>');
            text = text.replace(/^# (.+)$/gm, '<h1>$1</h1>');

            // ── 5. Parse Bold and Italic ─────────────────────────────
            text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            // Support both *word* and _word_ for italics if needed, but for now we'll stick to bold
            
            // ── 6. Parse Lists (This fixes the alignment issue) ──────
            // First, convert lines starting with "- " or "* " to <li>
            text = text.replace(/^[-*] (.+)$/gm, '<li>$1</li>');
            
            // Then, wrap contiguous <li> elements in a single <ul>
            text = text.replace(/(<li>(?:.*?\n?)*?<\/li>)/gm, function(match) {
                return '<ul style="margin-left: 20px; margin-top: 4px; margin-bottom: 4px; list-style-type: disc;">' + match + '</ul>';
            });

            // ── 7. Handle Paragraphs ─────────────────────────────────
            // Split by double line breaks, then wrap non-HTML chunks in <p>
            const blocks = text.split('\n\n');
            const parsedBlocks = blocks.map(block => {
                // If the block already starts with an HTML tag (like <ul>, <h3>, <pre>), don't wrap it in <p>
                if (block.trim().startsWith('<')) {
                    return block;
                }
                return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
            });

            return parsedBlocks.join('\n');
        },
    };
};
