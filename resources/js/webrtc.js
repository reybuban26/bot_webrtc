/**
 * webrtc.js — WebRTC peer-to-peer calling via polling signaling
 */

window.webrtcApp = function () {
    return {
        // ── State ─────────────────────────────────────────────────
        roomId: '',
        peerId: '',
        role: '',   // 'host' | 'guest'
        status: 'idle',  // idle | waiting | connecting | active | ended
        statusLabel: 'No active call',

        localStream: null,
        remoteStream: null,
        peerConnection: null,

        micMuted: false,
        camOff: false,

        joinRoomInput: '',
        pollInterval: null,

        stunServer: document.querySelector('meta[name="stun-server"]')?.content
            || 'stun:stun.l.google.com:19302',

        // ── Init ──────────────────────────────────────────────────
        init() {
            this.peerId = this.generatePeerId();
        },

        generatePeerId() {
            return 'peer_' + Math.random().toString(36).slice(2, 10);
        },

        // ── Create Room ───────────────────────────────────────────
        async createRoom() {

            alert("💡 For the best audio quality and accurate AI Meeting Notes, please use a headset or earphones.");

            try {
                const r = await fetch('/api/webrtc/room', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    body: JSON.stringify({ peer_id: this.peerId }),
                });
                const d = await r.json();
                this.roomId = d.room_id;
                this.role   = 'host';
                this.status = 'waiting';
                this.statusLabel = 'Waiting for someone to join…';

                await this.startLocalStream();
                this.startPolling();
            } catch (e) {
                alert('Failed to create room: ' + e.message);
            }
        },

        // ── Join Room ─────────────────────────────────────────────
        async joinRoom() {
            const rid = this.joinRoomInput.trim().toUpperCase();
            if (!rid) return;

            alert("💡 For the best audio quality and accurate AI Meeting Notes, please use a headset or earphones.");

            try {
                const r = await fetch('/api/webrtc/room/join', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    body: JSON.stringify({ room_id: rid, peer_id: this.peerId }),
                });
                if (!r.ok) { alert((await r.json()).error); return; }
                const d = await r.json();
                this.roomId   = d.room_id;
                this.role     = 'guest';
                this.status   = 'connecting';
                this.statusLabel = 'Connecting…';

                await this.startLocalStream();
                await this.initPeerConnection();

                // Guest creates offer
                const offer = await this.peerConnection.createOffer();
                await this.peerConnection.setLocalDescription(offer);
                await this.sendSignal(d.host_peer_id, { type: 'offer', sdp: offer });

                this.startPolling();
            } catch (e) {
                alert('Failed to join room: ' + e.message);
            }
        },

        // ── WebRTC Peer Connection ────────────────────────────────
        async initPeerConnection() {
            this.peerConnection = new RTCPeerConnection({
                iceServers: [{ urls: this.stunServer }],
            });

            // Add local tracks
            if (this.localStream) {
                this.localStream.getTracks().forEach(track => {
                    this.peerConnection.addTrack(track, this.localStream);
                });
            }

            // Remote stream
            this.peerConnection.ontrack = (event) => {
                this.remoteStream = event.streams[0];
                this.$nextTick(() => {
                    const rv = document.getElementById('remote-video');
                    if (rv) rv.srcObject = this.remoteStream;
                });
            };

            // ICE
            this.peerConnection.onicecandidate = async (event) => {
                if (event.candidate) {
                    const targetPeer = this.role === 'host'
                        ? this.guestPeerId
                        : this.hostPeerId;
                    if (targetPeer) {
                        await this.sendSignal(targetPeer, {
                            type: 'ice', candidate: event.candidate,
                        });
                    }
                }
            };

            this.peerConnection.onconnectionstatechange = () => {
                const state = this.peerConnection?.connectionState;
                if (state === 'connected') {
                    this.status = 'active';
                    this.statusLabel = 'Call active';
                } else if (['disconnected','failed','closed'].includes(state)) {
                    this.status = 'ended';
                    this.statusLabel = 'Call ended';
                    this.stopPolling();
                }
            };
        },

        // ── Signaling ─────────────────────────────────────────────
        async sendSignal(toPeerId, signal) {
            await fetch('/api/webrtc/signal', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                body: JSON.stringify({
                    room_id: this.roomId,
                    from_peer_id: this.peerId,
                    to_peer_id: toPeerId,
                    signal,
                }),
            });
        },

        startPolling() {
            this.pollInterval = setInterval(() => this.pollSignals(), 1500);
        },

        stopPolling() {
            if (this.pollInterval) { clearInterval(this.pollInterval); this.pollInterval = null; }
        },

        async pollSignals() {
            try {
                const r = await fetch('/api/webrtc/poll', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    body: JSON.stringify({ room_id: this.roomId, peer_id: this.peerId }),
                });
                const { signals } = await r.json();
                for (const s of signals) {
                    await this.handleSignal(s);
                }
            } catch (_) {}
        },

        async handleSignal({ from, signal }) {
            if (signal.type === 'offer') {
                this.guestPeerId = from;
                await this.initPeerConnection();
                await this.peerConnection.setRemoteDescription(new RTCSessionDescription(signal.sdp));
                const answer = await this.peerConnection.createAnswer();
                await this.peerConnection.setLocalDescription(answer);
                await this.sendSignal(from, { type: 'answer', sdp: answer });
                this.status = 'active';
                this.statusLabel = 'Call active';
            } else if (signal.type === 'answer') {
                await this.peerConnection.setRemoteDescription(new RTCSessionDescription(signal.sdp));
            } else if (signal.type === 'ice' && signal.candidate) {
                try { await this.peerConnection.addIceCandidate(new RTCIceCandidate(signal.candidate)); }
                catch (_) {}
            }
        },

        hostPeerId: '',
        guestPeerId: '',

        // async startLocalStream() {
        //     const customAudioConstraints = {
        //         echoCancellation: { ideal: true }, 
        //         noiseSuppression: { ideal: false }, // I-off o bawasan ang suppression para di mag-choppy
        //         autoGainControl: { ideal: true },   // Hayaan ang phone na itaas ang volume
                
        //         // I-set ang audio mode para sa voice communication
        //         // Makakatulong ito para piliin ng phone ang tamang mic processing
        //         googEchoCancellation: true,
        //         googAutoGainControl: true,
        //         googNoiseSuppression: false,
        //         googHighpassFilter: true
        //     };

        //     try {
        //         const stream = await navigator.mediaDevices.getUserMedia({ 
        //             video: true, 
        //             audio: customAudioConstraints 
        //         });

        //         // // --- START VOLUME BOOSTER ---
        //         const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        //         const source = audioContext.createMediaStreamSource(stream);
        //         const gainNode = audioContext.createGain();
                
        //         gainNode.gain.value = 2.5; // LAKAS: 2.5x

        //         const destination = audioContext.createMediaStreamDestination();
        //         source.connect(gainNode);
        //         gainNode.connect(destination);

        //         const boostedStream = new MediaStream();
        //         stream.getVideoTracks().forEach(track => boostedStream.addTrack(track));
        //         destination.stream.getAudioTracks().forEach(track => boostedStream.addTrack(track));
                
        //         this.localStream = boostedStream;
        //         // // --- END VOLUME BOOSTER ---

        //         this.$nextTick(() => {
        //             const lv = document.getElementById('local-video');
        //             if (lv) { lv.srcObject = this.localStream; 
        //                 lv.muted = true; 
        //                 lv.setAttribute('playsinline', '');
        //             }
        //         });
        //     } catch (e) {
        //         try {
        //             // Fallback for Audio Only
        //             const stream = await navigator.mediaDevices.getUserMedia({ audio: customAudioConstraints });
                    
        //             const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        //             const source = audioContext.createMediaStreamSource(stream);
        //             const gainNode = audioContext.createGain();
        //             gainNode.gain.value = 2.5;

        //             const destination = audioContext.createMediaStreamDestination();
        //             source.connect(gainNode);
        //             gainNode.connect(destination);

        //             this.localStream = destination.stream;

        //             this.$nextTick(() => {
        //                 const lv = document.getElementById('local-video');
        //                 if (lv) { lv.srcObject = this.localStream; 
        //                     lv.muted = true; 
        //                     lv.setAttribute('playsinline', '')
        //                 }
        //             });
        //         } catch (_) {
        //             console.error("Microphone access denied.");
        //         }
        //     }
        // },

        async startLocalStream() {
            const customAudioConstraints = {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
                channelCount: 1,      
                sampleSize: 16
            };

            try {
                this.localStream = await navigator.mediaDevices.getUserMedia({ 
                    video: true, 
                    audio: customAudioConstraints 
                });

                this.$nextTick(() => {
                    const lv = document.getElementById('local-video');
                    if (lv) { 
                        lv.srcObject = this.localStream; 
                        lv.muted = true; 
                        lv.setAttribute('playsinline', '');
                    }
                });
            } catch (e) {
                try {
                    this.localStream = await navigator.mediaDevices.getUserMedia({ audio: customAudioConstraints });
                    this.$nextTick(() => {
                        const lv = document.getElementById('local-video');
                        if (lv) { lv.srcObject = this.localStream; lv.muted = true; lv.setAttribute('playsinline', ''); }
                    });
                } catch (_) {
                    console.error("Microphone access denied.");
                }
            }
        },

        toggleMic() {
            if (!this.localStream) return;
            this.micMuted = !this.micMuted;
            this.localStream.getAudioTracks().forEach(t => { t.enabled = !this.micMuted; });
        },

        toggleCam() {
            if (!this.localStream) return;
            this.camOff = !this.camOff;
            this.localStream.getVideoTracks().forEach(t => { t.enabled = !this.camOff; });
        },

        // ── End Call ──────────────────────────────────────────────
        async endCall() {
            this.stopPolling();
            if (this.peerConnection) { this.peerConnection.close(); this.peerConnection = null; }
            if (this.localStream) { this.localStream.getTracks().forEach(t => t.stop()); this.localStream = null; }
            this.remoteStream = null;

            if (this.roomId) {
                await fetch('/api/webrtc/room/end', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    body: JSON.stringify({ room_id: this.roomId }),
                });
            }

            this.status = 'idle';
            this.statusLabel = 'Call ended';
            this.roomId = '';
        },

        copyRoomId() {
            navigator.clipboard?.writeText(this.roomId).catch(() => {});
        },
    };
};
