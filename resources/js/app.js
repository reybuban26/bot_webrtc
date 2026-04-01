import './bootstrap';
import AgoraRTC from 'agora-rtc-sdk-ng';

// Expose to window so webrtc.js can use it
window.AgoraRTC = AgoraRTC;

// Suppress Agora SDK internal telemetry log spam.
// Level 2 = WARNING — real errors still surface, noisy analytics logs are hidden.
// (Browser-level CORS errors from Agora's statscollector servers cannot be
//  suppressed here — they originate on Agora's infrastructure, not in our code.)
AgoraRTC.setLogLevel(2);
