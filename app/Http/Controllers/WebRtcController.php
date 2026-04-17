<?php

namespace App\Http\Controllers;

use App\Models\WebRtcSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebRtcController extends Controller
{
    /**
     * Create a new WebRTC room.
     */
    public function createRoom(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'peer_id' => 'required|string|max:64',
        ]);

        $room = WebRtcSession::create([
            'room_id'      => Str::upper(Str::random(6)),
            'host_peer_id' => $validated['peer_id'],
            'status'       => 'waiting',
            'signals'      => [],
        ]);

        return response()->json([
            'room_id'  => $room->room_id,
            'peer_id'  => $validated['peer_id'],
            'role'     => 'host',
        ]);
    }

    /**
     * Join an existing WebRTC room.
     */
    public function joinRoom(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => 'required|string|max:32',
            'peer_id' => 'required|string|max:64',
        ]);

        $room = WebRtcSession::where('room_id', strtoupper($validated['room_id']))
            ->where('status', 'waiting')
            ->first();

        if (! $room) {
            return response()->json(['error' => 'Room not found or already in use.'], 404);
        }

        $room->update([
            'guest_peer_id' => $validated['peer_id'],
            'status'        => 'active',
            'started_at'    => now(),
        ]);

        return response()->json([
            'room_id'       => $room->room_id,
            'host_peer_id'  => $room->host_peer_id,
            'peer_id'       => $validated['peer_id'],
            'role'          => 'guest',
        ]);
    }

    /**
     * Send a WebRTC signal (SDP offer/answer, ICE candidate).
     */
    public function sendSignal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id'        => 'required|string|max:32',
            'from_peer_id'   => 'required|string|max:64',
            'to_peer_id'     => 'required|string|max:64',
            'signal'         => 'required|array',
        ]);

        $room = WebRtcSession::where('room_id', strtoupper($validated['room_id']))->first();

        if (! $room) {
            return response()->json(['error' => 'Room not found.'], 404);
        }

        $room->pushSignal($validated['to_peer_id'], [
            'from'   => $validated['from_peer_id'],
            'signal' => $validated['signal'],
            'ts'     => now()->toISOString(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Poll for pending signals (long-polling style).
     */
    public function pollSignals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => 'required|string|max:32',
            'peer_id' => 'required|string|max:64',
        ]);

        $room = WebRtcSession::where('room_id', strtoupper($validated['room_id']))->first();

        if (! $room) {
            return response()->json(['signals' => []]);
        }

        $signals = $room->drainSignals($validated['peer_id']);

        return response()->json(['signals' => $signals]);
    }

    /**
     * End a WebRTC session.
     */
    public function endRoom(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => 'required|string|max:32',
        ]);

        WebRtcSession::where('room_id', strtoupper($validated['room_id']))
            ->update([
                'ended_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }

    /**
     * Generate Agora RTC temporary token.
     */
    public function generateAgoraToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => 'required|string|max:64',
        ]);

        $appId = config('services.agora.app_id');
        $appCertificate = config('services.agora.certificate');
        
        if (!$appId || !$appCertificate) {
            return response()->json(['error' => 'Agora credentials are not configured.'], 500);
        }

        $channelName = $validated['channel'];
        
        // FIX: Tanggalin ang quotes para maging totoong Integer. 
        // 0 acts as a wildcard for all Integer UIDs.
        $uid = 0; 
        
        // Privilege expires in an hour
        $privilegeExpiredTs = now()->timestamp + 3600;

        $factory = new \Monyxie\Agora\TokenBuilder\TokenFactory($appId, $appCertificate);
        
        // Force cast to (int) para sigurado tayong Integer ang mababasa ng Agora SDK
        $token = $factory->create($channelName, (int)$uid, null, $privilegeExpiredTs);

        return response()->json([
            'token' => $token->toString(),
            'uid'   => $uid,
        ]);
    }
}
