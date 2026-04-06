/**
 * webrtc.js — Role-based Agora RTC integration
 *
 * Roles:
 * user  → clicks "Call Admin", polls for accepted/rejected status
 * admin → polls for incoming calls, shows accept/reject modal
 */

window.webrtcApp = function () {
    return {
        // ── State ─────────────────────────────────────────────────
        showRtcModal: false,

        // 'idle' | 'ringing' | 'incoming' | 'connecting' | 'active' | 'ended' | 'rejected'
        status: 'idle',
        statusLabel: 'No active call',

        roomId: '',
        callId: null,         // current CallRequest ID
        callerName: '',       // admin view: who is calling

        agoraClient: null,
        localAudioTrack: null,
        localVideoTrack: null,

        micMuted:     false,
        camOff:       false,
        speakerMuted: false,
        remoteMuted:  false,
        facingMode:   'user',

        _pollTimer: null,           // interval handle for status/pending poll
        _activeCallPollTimer: null,  // polls DB while call is active (fallback for missed user-left)
        _incomingPollTimer: null,    // user polls for admin-initiated incoming calls
        _visibilityPaused: false,    // tracks if polls were paused due to tab hidden
        _pausedTimers: [],           // stores timer refs that were paused

        appId:  document.querySelector('meta[name="agora-app-id"]')?.content || '',
        userRole: document.querySelector('meta[name="user-role"]')?.content || 'user',

        // ── Recording state ───────────────────────────────────────
        _recorder:        null,
        _recordingChunks: [],
        _callStartTime:   null,
        _supportThreadId: null,   // thread to post meeting notes to
        _audioContext:    null,   // AudioContext used for local+remote mix
        _mixedDest:       null,   // MediaStreamAudioDestinationNode (mixed output)
        _callEnding:      false,  // guard: prevents endCall() running twice

        // ── Init ──────────────────────────────────────────────────
        init() {
            if (!window.AgoraRTC) {
                console.error('[Agora] AgoraRTC not loaded.');
            }
            this.setupWebSockets();

            // Auto-start polling as fallback for WebSockets
            if (this.userRole === 'admin') {
                this._startAdminPoll();
            } else {
                this._startUserIncomingPoll();
            }

            // Pause non-critical polls when tab is hidden
            this._setupVisibilityHandler();
        },

        _setupVisibilityHandler() {
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this._pausePolls();
                } else {
                    this._resumePolls();
                }
            });
        },

        _pausePolls() {
            this._visibilityPaused = true;
            // Pause non-critical polls (keep active-call poll running during calls)
            if (this._pollTimer) {
                this._pausedTimers.push({ type: 'poll', timer: this._pollTimer });
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
            if (this._incomingPollTimer) {
                this._pausedTimers.push({ type: 'incoming', timer: this._incomingPollTimer });
                clearInterval(this._incomingPollTimer);
                this._incomingPollTimer = null;
            }
        },

        _resumePolls() {
            if (!this._visibilityPaused) return;
            this._visibilityPaused = false;

            for (const { type } of this._pausedTimers) {
                if (type === 'poll') {
                    if (this.userRole === 'admin') this._startAdminPoll();
                    else this._startUserPoll();
                } else if (type === 'incoming') {
                    this._startUserIncomingPoll();
                }
            }
            this._pausedTimers = [];
        },

        // ── CSRF helper ───────────────────────────────────────────
        _csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        setupWebSockets() {
            if (!window.Echo) return;

            // 1. Admin: Nakikinig kung may papasok na tawag
            if (this.userRole === 'admin') {
                window.Echo.channel('admin-calls')
                    .listen('.CallStatusChanged', (e) => {
                        if (e.action === 'incoming_call' && this.status === 'idle') {
                            this.callId = e.call.id;
                            this.roomId = e.call.room_id;
                            this.callerName = e.call.caller_name;
                            this.status = 'incoming';
                            this.statusLabel = `Incoming call from ${this.callerName}`;
                            this.showRtcModal = true;
                        }
                    });
            }

            // 2. User/Admin: Nakikinig sa sarili nilang channel para sa call updates
            const userId = document.querySelector('meta[name="auth-user-id"]')?.content;
            if (userId) {
                window.Echo.channel('user-' + userId)
                    .listen('.CallStatusChanged', (e) => {
                        if (e.action === 'incoming_call' && this.status === 'idle') {
                            this.callId = e.call.id;
                            this.roomId = e.call.room_id;
                            this.callerName = e.call.caller_name;
                            this.status = 'incoming';
                            this.statusLabel = `Incoming call from ${this.callerName}`;
                            this.showRtcModal = true;
                        }
                        if (e.action === 'call_status') {
                            if (e.status === 'accepted') {
                                // Guard: skip if we've already started joining via the button click
                                if (this.status === 'active' || this.status === 'connecting') {
                                    console.log('[Call] Already connecting/active — skipping WebSocket join');
                                    return;
                                }
                                this.status = 'connecting';
                                this.statusLabel = 'Connecting…';
                                this.joinAndPublish(e.room_id);
                            } else if (e.status === 'rejected') {
                                this.status = 'rejected';
                                this.statusLabel = 'Call declined';
                                setTimeout(() => { this.endCall(); }, 3000);
                            }
                        }
                    });
            }
        },

        // ─────────────────────────────────────────────────────────
        // USER FLOW
        // ─────────────────────────────────────────────────────────

        async callAdmin() {

            alert("⚠️ IMPORTANT: For clear AI Meeting Notes, please use a WIRED headset or your device's built-in speaker.\n\nDo NOT use Bluetooth earpods on mobile devices to prevent choppy audio.");

            try {
                this.status = 'ringing';
                this.statusLabel = 'Calling admin…';

                const r = await fetch('/api/call/request', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({}),
                });
                const data = await r.json();

                if (!r.ok) {
                    throw new Error(data.error || 'Could not initiate call.');
                }

                // Server returned immediately rejected (admin is busy with another call)
                if (data.status === 'rejected') {
                    this.status = 'rejected';
                    this.statusLabel = data.reason || 'Admin is busy. Please try again later.';
                    setTimeout(() => {
                        this.status = 'idle';
                        this.statusLabel = 'No active call';
                    }, 3500);
                    return;
                }

                this.callId = data.call_id;
                this.roomId = data.room_id;
                console.log(`[Call] Request created. ID: ${this.callId}, Room: ${this.roomId}`);

                // Poll for admin response every 2 seconds
                this._startUserPoll();
            } catch (e) {
                console.error('[Call] callAdmin failed:', e.message);
                this.status = 'idle';
                this.statusLabel = 'No active call';
            }
        },

        _startUserPoll() {
            this._clearPoll();
            this._pollTimer = setInterval(() => this._checkCallStatus(), 2000);
        },

        async _checkCallStatus() {
            if (!this.callId) return;
            try {
                const r = await fetch(`/api/call/status/${this.callId}`, {
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
                const data = await r.json();

                if (data.status === 'accepted') {
                    this._clearPoll();
                    console.log('[Call] Admin accepted! Joining Agora…');
                    this.status = 'connecting';
                    this.statusLabel = 'Connecting…';
                    await this.joinAndPublish(data.room_id);
                } else if (data.status === 'rejected') {
                    this._clearPoll();
                    console.log('[Call] Admin rejected the call.');
                    this.status = 'rejected';
                    this.statusLabel = 'Call declined';
                    setTimeout(() => {
                        this.status = 'idle';
                        this.statusLabel = 'No active call';
                        this.callId = null;
                    }, 3000);
                } else if (data.status === 'ended') {
                    this._clearPoll();
                    this.endCall();
                }
            } catch (e) {
                console.warn('[Call] Status poll error:', e.message);
            }
        },

        // ─────────────────────────────────────────────────────────
        // USER — poll for incoming calls from admin
        // ─────────────────────────────────────────────────────────

        _startUserIncomingPoll() {
            // Use a separate timer name to avoid conflict with status poll
            if (this._incomingPollTimer) return;
            this._incomingPollTimer = setInterval(() => this._checkIncomingCall(), 3000);
        },

        async _checkIncomingCall() {
            if (this.status !== 'idle') return;
            try {
                const r = await fetch('/api/call/incoming', {
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
                const data = await r.json();
                if (data.call && data.call.status === 'pending') {
                    clearInterval(this._incomingPollTimer);
                    this._incomingPollTimer = null;
                    this.callId      = data.call.id;
                    this.roomId      = data.call.room_id;
                    this.callerName  = data.call.caller_name;
                    this.status      = 'incoming';
                    this.statusLabel = `Incoming call from ${this.callerName}`;
                    this.showRtcModal = true;
                    console.log(`[Call] Incoming call from admin: ${this.callerName}`);
                }
            } catch (e) {
                console.warn('[Call] Incoming poll error:', e.message);
            }
        },

        // ─────────────────────────────────────────────────────────────────
        // USER — accept / reject an admin-initiated incoming call
        // ─────────────────────────────────────────────────────────────────

        async acceptCallAsUser() {
            if (!this.callId || this.status === 'active' || this.status === 'connecting') {
                console.log('[Call] Ignoring redundant accept tap');
                return;
            }

            alert("⚠️ IMPORTANT: For clear AI Meeting Notes, please use a WIRED headset or your device's built-in speaker.\n\nDo NOT use Bluetooth earpods on mobile devices to prevent choppy audio.");

            try {
                this.status = 'connecting';
                this.statusLabel = 'Connecting…';

                const r = await fetch('/api/call/user-respond', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({ call_id: this.callId, action: 'accept' }),
                });
                const data = await r.json();

                if (r.status === 409) {
                    // Call was cancelled by admin before user tapped Accept
                    console.warn('[Call] Accept too late — call already ended.');
                    this.status = 'idle';
                    this.statusLabel = 'No active call';
                    this.callId = null;
                    this.showRtcModal = false;
                    this._startUserIncomingPoll();
                    return;
                }

                if (!r.ok) throw new Error(data.error || 'Accept failed.');

                console.log('[Call] Accepted admin call. Joining Agora…');
                await this.joinAndPublish(data.room_id);
            } catch (e) {
                console.error('[Call] acceptCallAsUser failed:', e.message);
                // Only reset UI if we aren't already active (fatal error during join)
                if (this.status !== 'active') {
                    this.status = 'idle';
                    this.statusLabel = 'No active call';
                    this.callId = null;
                    this.showRtcModal = false;
                    this._startUserIncomingPoll();
                }
            }
        },

        async rejectCallAsUser() {
            if (!this.callId) return;
            try {
                await fetch('/api/call/user-respond', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({ call_id: this.callId, action: 'reject' }),
                });
                console.log('[Call] Rejected admin call.');
            } catch (e) {
                console.warn('[Call] Reject error:', e.message);
            }
            this.status      = 'idle';
            this.statusLabel = 'No active call';
            this.callId      = null;
            this.callerName  = '';
            this.showRtcModal = false;
            // Resume listening for future incoming calls
            this._startUserIncomingPoll();
        },

        // ─────────────────────────────────────────────────────────
        // ADMIN FLOW
        // ─────────────────────────────────────────────────────────

        _startAdminPoll() {
            this._clearPoll();
            this._pollTimer = setInterval(() => this._checkPendingCall(), 2000);
        },

        async _checkPendingCall() {
            // Don't check for new incoming calls if already in a call
            if (this.status !== 'idle') return;
            try {
                const r = await fetch('/api/call/pending', {
                    headers: { 'X-CSRF-TOKEN': this._csrf() },
                });
                const data = await r.json();

                if (data.call && data.call.status === 'pending') {
                    // Stop polling, show the incoming call modal
                    this._clearPoll();
                    this.callId     = data.call.id;
                    this.roomId     = data.call.room_id;
                    this.callerName = data.call.caller_name;
                    this.status     = 'incoming';

                    // Show queue count if more than one caller is waiting
                    const queueCount = data.call.queue_count || 1;
                    const queueNote  = queueCount > 1 ? ` (+${queueCount - 1} more waiting)` : '';
                    this.statusLabel = `Incoming call from ${this.callerName}${queueNote}`;

                    this.showRtcModal = true;
                    console.log(`[Call] Incoming call from ${this.callerName} (${queueCount} in queue)`);
                }
            } catch (e) {
                console.warn('[Call] Pending poll error:', e.message);
            }
        },

        async acceptCall() {
            if (!this.callId || this.status === 'active' || this.status === 'connecting') {
                console.log('[Call] Ignoring redundant admin accept tap');
                return;
            }

            alert("⚠️ IMPORTANT: For clear AI Meeting Notes, please use a WIRED headset or your device's built-in speaker.\n\nDo NOT use Bluetooth earpods on mobile devices to prevent choppy audio.");

            try {
                this.status = 'connecting';
                this.statusLabel = 'Connecting…';

                const r = await fetch('/api/call/respond', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({ call_id: this.callId, action: 'accept' }),
                });
                const data = await r.json();

                // 409 = another admin already accepted this call (race condition loser)
                if (r.status === 409 && data.status === 'taken') {
                    console.warn('[Call] Lost race — another admin already accepted this call.');
                    this.status = 'idle';
                    this.statusLabel = 'No active call';
                    this.callId = null;
                    this.callerName = '';
                    this.showRtcModal = false;
                    // Resume polling — there may be another caller in queue (User B)
                    this._startAdminPoll();
                    return;
                }

                if (!r.ok) throw new Error(data.error || 'Accept failed.');

                console.log('[Call] Accepted. Joining Agora…');
                await this.joinAndPublish(data.room_id);
            } catch (e) {
                console.error('[Call] acceptCall failed:', e.message);
                if (this.status !== 'active') {
                    this.status = 'idle';
                    this.callId = null;
                    this.showRtcModal = false;
                }
            }
        },

        // ─────────────────────────────────────────────────────────
        // ADMIN FLOW — admin-initiated call
        // ─────────────────────────────────────────────────────────

        async callUser(userId, userName) {

            alert("⚠️ IMPORTANT: For clear AI Meeting Notes, please use a WIRED headset or your device's built-in speaker.\n\nDo NOT use Bluetooth earpods on mobile devices to prevent choppy audio.");

            try {
                this.status = 'ringing';
                this.statusLabel = `Calling ${userName}…`;

                const r = await fetch('/api/call/call-user', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({ user_id: userId }),
                });
                const data = await r.json();

                if (!r.ok) throw new Error(data.error || 'Could not call user.');

                if (data.status === 'rejected') {
                    this.status = 'rejected';
                    this.statusLabel = data.reason || 'User is busy.';
                    setTimeout(() => { this.status = 'idle'; this.statusLabel = 'No active call'; }, 3000);
                    return;
                }

                this.callId = data.call_id;
                this.roomId = data.room_id;
                // Admin polls for user's response
                this._startUserPoll();
            } catch (e) {
                console.error('[Call] callUser failed:', e.message);
                this.status = 'idle';
                this.statusLabel = 'No active call';
            }
        },

        async rejectCall() {
            try {
                await fetch('/api/call/respond', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({ call_id: this.callId, action: 'reject' }),
                });
                console.log('[Call] Call rejected.');
            } catch (e) {
                console.warn('[Call] Reject error:', e.message);
            }
            this.status = 'idle';
            this.statusLabel = 'No active call';
            this.callId = null;
            this.callerName = '';
            this.showRtcModal = false;
            // Resume polling for next incoming calls
            this._startAdminPoll();
        },

        // ─────────────────────────────────────────────────────────
        // AGORA — shared join/publish logic
        // ─────────────────────────────────────────────────────────

        async joinAndPublish(roomId) {
            this.roomId = roomId;
            this._recordingChunks = [];

            // 1. Initialize client at Listeners
            if (!this.agoraClient) {
                this.agoraClient = window.AgoraRTC.createClient({ mode: 'rtc', codec: 'vp8' });
                this.setupEventListeners(); // Ligtas na itong nandito
            }

            // 2. Kumuha ng Token
            let token = null;
            try {
                const r = await fetch('/api/webrtc/agora/token', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({ channel: this.roomId }),
                });
                const d = await r.json();
                token = d.token;
            } catch (e) {
                console.error('[Agora] Token fetch failed:', e.message);
            }

            // 3. I-ready ang Camera at Mic natin
            this.localAudioTrack = await window.AgoraRTC.createMicrophoneAudioTrack({
                AEC: true,
                ANS: true,
                AGC: true,
                encoderConfig: "high_quality" 
            });
            this.localVideoTrack = await window.AgoraRTC.createCameraVideoTrack();

            // 🔥 FIX SA AUDIOCONTEXT WARNING:
            // I-start at i-ready na agad ang Recorder bago pa man tayo sumali sa call!
            await this._startRecording();

            // 4. Sumali sa channel at i-publish ang camera/mic
            await this.agoraClient.join(this.appId, this.roomId, token, null);
            await this.agoraClient.publish([this.localAudioTrack, this.localVideoTrack]);

            // 5. I-update ang UI para lumitaw ang mga video boxes
            this.status = 'active';
            this.statusLabel = 'On call';

            this._startActiveCallPoll();

            // 🔥 FIX SA "Cannot read properties of undefined" WARNING:
            if (window.Echo && this.callId) {
                window.Echo.channel('call-' + this.callId)
                    .listen('.CallStatusChanged', (e) => {
                        if (e.action === 'call_ended' && this.status !== 'idle') {
                            this.endCall();
                        }
                });
            }

            // 🔥 FIX SA BLACK SCREEN NA CAMERA SA PC:
            // Ginawa natin itong pinakahuli para siguradong ready na ang HTML boxes
            this.$nextTick(() => {
                const el = document.getElementById('local-video');
                if (el) {
                    el.innerHTML = ''; // Linisin muna bago i-play
                    this.localVideoTrack.play(el);
                }
            });
        },

        // ── Active-call DB poll (fallback for missed user-left) ──────────────
        _startActiveCallPoll() {
            if (this._activeCallPollTimer) return;
            this._activeCallPollTimer = setInterval(async () => {
                if (!this.callId || this.status !== 'active') return;
                try {
                    const r = await fetch(`/api/call/status/${this.callId}`, {
                        headers: { 'X-CSRF-TOKEN': this._csrf() },
                    });
                    const data = await r.json();
                    if (data.status === 'ended') {
                        console.log('[Call] DB reports call ended — triggering endCall()');
                        this.endCall();
                    }
                } catch (e) {
                    console.warn('[Call] Active-call poll error:', e.message);
                }
            }, 2000); // 2 s — matches the user-poll interval for faster detection
        },

        _clearActiveCallPoll() {
            if (this._activeCallPollTimer) {
                clearInterval(this._activeCallPollTimer);
                this._activeCallPollTimer = null;
            }
        },

        // ── Agora event listeners ─────────────────────────────────
        setupEventListeners() {
            this.agoraClient.on('user-published', async (user, mediaType) => {
                
                let remoteUser = null;
                for (let i = 0; i < 10; i++) {
                    remoteUser = this.agoraClient.remoteUsers.find(u => u.uid === user.uid);
                    if (remoteUser) break;
                    await new Promise(r => setTimeout(r, 200));
                }
                if (!remoteUser) return;

                try {
                    await this.agoraClient.subscribe(remoteUser, mediaType);
                } catch (e) {
                    return;
                }

                this.status = 'active';
                this.statusLabel = 'Call active';

                if (mediaType === 'video') {
                    const playVideo = () => {
                        const el = document.getElementById('remote-video');
                        if (el && remoteUser.videoTrack) {
                            el.innerHTML = ''; // Linisin bago i-play
                            try { remoteUser.videoTrack.play(el); } catch(e) {}
                        }
                    };
                    
                    // Siguraduhing ready ang remote-video div bago i-play
                    this.$nextTick(() => {
                        setTimeout(playVideo, 100);
                        setTimeout(playVideo, 500);
                        setTimeout(playVideo, 1000);
                    });
                }
                
                if (mediaType === 'audio') {
                    try { remoteUser.audioTrack.play(); } catch(e) {}
                    // Diretso nang magwowork ito dahil inuna natin ang _startRecording!
                    this._addRemoteAudioToMix(remoteUser);
                }
            });

            this.agoraClient.on('user-unpublished', (user, mediaType) => {
                if (mediaType === 'video' && user.videoTrack) user.videoTrack.stop();
            });

            this.agoraClient.on('user-left', () => {
                if (this.status === 'idle') return;
                this.status = 'ended';
                this.statusLabel = 'Call ended';
                this.endCall();
            });
        },

        // ── Controls ──────────────────────────────────────────────
        toggleMic() {
            if (!this.localAudioTrack) return;
            this.micMuted = !this.micMuted;
            this.localAudioTrack.setMuted(this.micMuted);
        },

        async toggleCam() {
            if (!this.localVideoTrack) return;
            this.camOff = !this.camOff;
            this.localVideoTrack.setMuted(this.camOff);
        },

        async flipCam() {
            if (!this.localVideoTrack) return;
            try {
                const all = await window.AgoraRTC.getDevices();
                const cams = all.filter(d => d.kind === 'videoinput');
                if (cams.length < 2) return;
                this.facingMode = this.facingMode === 'user' ? 'environment' : 'user';
                const kw = this.facingMode === 'environment'
                    ? ['back', 'rear', 'environment']
                    : ['front', 'user', 'facetime', 'face'];
                let target = cams.find(d => kw.some(k => d.label.toLowerCase().includes(k)));
                if (!target) {
                    const idx = cams.findIndex(d => d.label === this.localVideoTrack.getTrackLabel());
                    target = cams[(idx + 1) % cams.length];
                }
                if (target) await this.localVideoTrack.setDevice(target.deviceId);
            } catch (e) {
                console.error('[Agora] Flip failed:', e.message);
            }
        },

        toggleSpeaker() {
            this.speakerMuted = !this.speakerMuted;
            if (this.agoraClient?.remoteUsers) {
                this.agoraClient.remoteUsers.forEach(u => {
                    try { u.audioTrack?.setVolume(this.speakerMuted ? 0 : 100); } catch(e) {}
                });
            }
        },

        // ── End Call ──────────────────────────────────────────────
        async endCall() {
            // Guard: prevent running twice when both the local "End" button
            // AND the remote `user-left` / active-call-poll fire nearly simultaneously.
            if (this._callEnding) {
                console.log('[Call] endCall() already in progress — skipping duplicate.');
                return;
            }
            this._callEnding = true;

            // ── 1. Stop all polling immediately ──────────────────
            this._clearPoll();
            this._clearActiveCallPoll();

            // ── 2. Snapshot values before clearing ───────────────
            const callIdToEnd   = this.callId;
            const durationSec   = this._callStartTime
                ? Math.floor((Date.now() - this._callStartTime) / 1000) : 0;
            this._callStartTime = null;

            // ── 3. INSTANT UI RESET ───────────────────────────────
            //    This must happen before ANY await so the button
            //    press feels immediate on every device / network.
            this.callId       = null;
            this.status       = 'idle';
            this.statusLabel  = 'No active call';
            this.roomId       = '';
            this.callerName   = '';
            this.micMuted     = false;
            this.camOff       = false;
            this.speakerMuted = false;
            this.remoteMuted  = false;
            this.showRtcModal = false;   // ← modal closes here, 0 ms delay

            // ── 4. Resume polling so both peers can recover ───────
            if (this.userRole === 'admin') this._startAdminPoll();
            if (this.userRole === 'user')  this._startUserIncomingPoll();

            // ── 5. Background cleanup (fire-and-forget) ───────────
            //    Nothing here blocks the calling code.  `self` refs
            //    capture the Alpine component for use inside the IIFE.
            this._callEnding   = false;   // reset guard for next call
            const self        = this;
            const recorderRef = this._recorder;
            const chunksRef   = this._recordingChunks;
            this._recorder        = null;
            this._recordingChunks = [];
            const audioRef = this.localAudioTrack;
            const videoRef = this.localVideoTrack;
            const agoraRef = this.agoraClient;
            this.localAudioTrack = null;
            this.localVideoTrack = null;
            this.agoraClient     = null;

            (async () => {
                // ── 5a. Stop recorder & notify server IN PARALLEL ──
                //       Both are fast; running together minimises the
                //       gap before the remote peer's DB poll fires.
                let recordingBlob  = null;
                let recordingExt   = 'webm';
                let recordingThread = null;

                await Promise.all([
                    // Recorder stop (max 2 s — local, should be ~10 ms normally)
                    (async () => {
                        if (!recorderRef || recorderRef.state === 'inactive') return;
                        recordingBlob = await Promise.race([
                            new Promise(resolve => {
                                recorderRef.onstop = () => {
                                    if (chunksRef.length) {
                                        const mime = recorderRef.mimeType || 'audio/webm';
                                        recordingExt = mime.includes('mp4') ? 'mp4' : 'webm';
                                        resolve(new Blob(chunksRef, { type: mime }));
                                    } else {
                                        resolve(null);
                                    }
                                };
                                recorderRef.stop();
                            }),
                            new Promise(r => setTimeout(() => r(null), 2000)),
                        ]);
                        recordingThread = self._supportThreadId
                            || document.getElementById('support-panel')
                                       ?._x_dataStack?.[0]?.threadId
                            || null;
                    })(),

                    // DB notification (keepalive so it survives tab unload)
                    callIdToEnd
                        ? fetch('/api/call/end', {
                              method: 'POST',
                              keepalive: true,
                              headers: {
                                  'Content-Type': 'application/json',
                                  'X-CSRF-TOKEN': self._csrf(),
                              },
                              body: JSON.stringify({ call_id: callIdToEnd }),
                          }).then(() => console.log('[Call] DB marked ended.'))
                            .catch(e  => console.warn('[Call] /api/call/end failed:', e.message))
                        : Promise.resolve(),
                ]);

                // ── 5b. Release media tracks & leave Agora ────────
                //    Leaving Agora fires `user-left` on the remote peer.
                //    We give it a 4-second max so a stale mobile
                //    connection can't block cleanup indefinitely.
                try { if (self._audioContext) { self._audioContext.close(); self._audioContext = null; self._mixedDest = null; } } catch (_) {}
                try { audioRef?.stop(); audioRef?.close(); } catch (_) {}
                try { videoRef?.stop(); videoRef?.close(); } catch (_) {}
                if (agoraRef) {
                    try {
                        await Promise.race([
                            agoraRef.leave(),
                            new Promise(r => setTimeout(r, 4000)),
                        ]);
                        console.log('[Agora] Left channel.');
                    } catch (e) {
                        console.warn('[Agora] Leave error (ignored):', e.message);
                    }
                }

                // ── 5c. Upload recording (only from admin side) ───
                //  Both peers record locally (so both voices are in the
                //  audio), but only the ADMIN posts meeting notes to the
                //  support thread.  This prevents duplicate cards when
                //  both endCall()s run (clicked end + remote user-left).
                if (self.userRole === 'admin' && recordingBlob && recordingThread) {
                    // ── Measure ACTUAL audio duration from the blob ────────
                    // durationSec from the call timer over-counts: it starts at
                    // call initiation but the recorder starts a moment later.
                    // Reading duration from the blob gives the precise encoded length.
                    let actualDurationSec = durationSec;   // safe fallback
                    try {
                        const blobUrl = URL.createObjectURL(recordingBlob);
                        await new Promise((resolve) => {
                            const probe = new Audio();
                            probe.addEventListener('loadedmetadata', () => {
                                if (isFinite(probe.duration) && probe.duration > 0) {
                                    actualDurationSec = Math.round(probe.duration);
                                    console.log(`[REC] Blob duration: ${actualDurationSec}s (timer was ${durationSec}s)`);
                                }
                                URL.revokeObjectURL(blobUrl);
                                probe.remove();
                                resolve();
                            }, { once: true });
                            probe.addEventListener('error', () => { URL.revokeObjectURL(blobUrl); resolve(); }, { once: true });
                            setTimeout(resolve, 3000);   // safety: don't block upload
                            probe.src = blobUrl;
                            probe.load();
                        });
                    } catch (_) { /* keep timer fallback */ }

                    const fd = new FormData();
                    fd.append('recording', recordingBlob, `call-recording.${recordingExt}`);
                    fd.append('call_id',   callIdToEnd || '');
                    fd.append('duration',  actualDurationSec);
                    console.log('[REC] Admin uploading recording…', {
                        sizeKB: Math.round(recordingBlob.size / 1024),
                        thread: recordingThread, duration: actualDurationSec,
                    });
                    fetch(`/api/support/thread/${recordingThread}/meeting`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': self._csrf() },
                        body: fd,
                    })
                        .then(r => r.ok
                            ? console.log('[REC] Upload succeeded ✓')
                            : r.text().then(t => console.error('[REC] Upload failed ✗', t.slice(0, 400))))
                        .catch(e => console.error('[REC] Upload error:', e.message));
                } else if (self.userRole !== 'admin' && recordingBlob) {
                    console.log('[REC] User-side recording complete (not uploaded — admin handles it).');
                } else if (!recordingBlob) {
                    console.warn('[REC] No recording blob — skipping upload.');
                }
            })();   // fire-and-forget — endCall() returns here instantly
        },

        // ── Recording helpers ─────────────────────────────────────

        /**
         * Start recording both local AND remote audio via an AudioContext
         * mixer so Groq transcribes the full conversation.
         *
         * MIME type priority: audio-only containers only.
         * video/mp4 and video/webm are intentionally excluded — they create
         * video containers for audio-only data which most players cannot play.
         */
        _startRecording() {
            if (!this.localAudioTrack) {
                console.warn('[REC] Cannot start — localAudioTrack is null.');
                return;
            }
            try {
                // 48 000 Hz = native WebRTC / Opus sample rate.
                // Matching the rate prevents the browser from resampling,
                // which removes the "muffled / robotic" artifact.
                const ctx  = new AudioContext({ sampleRate: 48000 });
                const dest = ctx.createMediaStreamDestination();
                this._audioContext = ctx;
                this._mixedDest    = dest;
                ctx.resume().catch(() => {});  // ensure context is running

                // Wire local mic into the mix
                const localTrack  = this.localAudioTrack.getMediaStreamTrack();
                const localSource = ctx.createMediaStreamSource(new MediaStream([localTrack]));
                localSource.connect(dest);
                console.log('[REC] Local audio wired into AudioContext mix.');

                // Audio-only MIME types in order of preference.
                // DO NOT use video/mp4 or video/webm for audio-only recordings —
                // browsers treat them as video and find no video stream → won't play.
                const preferredTypes = [
                    'audio/webm;codecs=opus',   // Chrome / Edge / Firefox
                    'audio/webm',               // Chrome / Firefox fallback
                    'audio/ogg;codecs=opus',    // Firefox
                    'audio/ogg',               // Firefox fallback
                    'audio/mp4',               // Safari (AAC in MP4 container)
                ];
                const mimeType = preferredTypes.find(t => MediaRecorder.isTypeSupported(t)) || '';

                // 128 kbps — broadcast-quality for voice; clearly captures
                // both sides of the conversation without being heavy.
                const recorderOpts = { audioBitsPerSecond: 128000 };
                if (mimeType) recorderOpts.mimeType = mimeType;

                console.log('[REC] Starting MediaRecorder on mixed stream', {
                    mimeType: mimeType || '(browser default)',
                    bitrate: '128 kbps',
                    sampleRate: ctx.sampleRate,
                    localTrackLabel: localTrack.label,
                });

                this._recorder = new MediaRecorder(dest.stream, recorderOpts);
                this._recorder.ondataavailable = (e) => {
                    if (e.data.size > 0) {
                        this._recordingChunks.push(e.data);
                    }
                };
                // 250 ms chunks: finer granularity means the last slice of audio
                // before endCall() is captured — no words are clipped at the end.
                this._recorder.start(1000);
                
                // ✅ NILAGAY NATIN ANG TIMER DITO PARA SAKTONG SYNC SA RECORDING!
                this._callStartTime = Date.now(); 

                console.log(`[REC] Recording started — mimeType: ${this._recorder.mimeType}`);
            } catch (e) {
                console.error('[REC] Failed to start recording:', e.message, e);
            }
        },

        /**
         * Wire a remote participant's audio track into the recording mix.
         * Called from setupEventListeners when remote audio is subscribed.
         */
        _addRemoteAudioToMix(remoteUser) {
            if (!this._audioContext || !this._mixedDest) {
                console.warn('[REC] AudioContext not ready — cannot add remote audio to mix.');
                return;
            }
            try {
                const remoteTrack = remoteUser.audioTrack?.getMediaStreamTrack();
                if (!remoteTrack) {
                    console.warn('[REC] Remote audioTrack not available yet.');
                    return;
                }
                const remoteSource = this._audioContext.createMediaStreamSource(
                    new MediaStream([remoteTrack])
                );
                remoteSource.connect(this._mixedDest);
                console.log(`[REC] Remote audio (uid: ${remoteUser.uid}) added to mix ✓`);
            } catch (e) {
                console.warn('[REC] Failed to add remote audio to mix:', e.message);
            }
        },

        // ── Utility ───────────────────────────────────────────────
        _clearPoll() {
            if (this._pollTimer) {
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
            // Also clear from paused timers if present
            this._pausedTimers = this._pausedTimers.filter(t => t.type !== 'poll');
        },
    };
};

// ─────────────────────────────────────────────────────────────────────────────
// Global bridge functions for the incoming-call Accept / Decline buttons.
//
// WHY not @click="acceptCallAsUser()"?
// Alpine evaluates @click expressions through its own reactive scope resolver.
// When the JS is freshly loaded or there is ANY scope chain ambiguity (timing,
// nested x-if, CDN caching of an older build, etc.) the function lookup fails
// with "acceptCallAsUser is not defined" even though it exists in the object.
//
// onclick="" is evaluated directly in window scope — always works, no Alpine
// dependency. The helpers below reach into the Alpine component via its data
// stack and call the right method.
// ─────────────────────────────────────────────────────────────────────────────

function __getRtcVm() {
    // Alpine v3 attaches the data stack on the root x-data element
    const el = document.querySelector('[x-data*="webrtcApp"]');
    return el?._x_dataStack?.[0] ?? null;
}

window.__rtcAccept = function () {
    const vm = __getRtcVm();
    if (!vm) { console.error('[RTC] webrtcApp not found'); return; }
    if (vm.userRole === 'user') {
        vm.acceptCallAsUser();
    } else {
        vm.acceptCall();
    }
};

window.__rtcDecline = function () {
    const vm = __getRtcVm();
    if (!vm) { console.error('[RTC] webrtcApp not found'); return; }
    if (vm.userRole === 'user') {
        vm.rejectCallAsUser();
    } else {
        vm.rejectCall();
        vm.showRtcModal = false;
    }
};