<?php

namespace App\Http\Controllers;

use App\Models\CallRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Events\CallSignalingEvent;

class CallController extends Controller
{
    /**
     * User initiates a call to admin.
     * Creates a pending CallRequest and returns a pre-generated room_id.
     */
    public function request(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only users with role 'user' can initiate calls
        if ($user->role !== 'user') {
            return response()->json(['error' => 'Only users can initiate calls.'], 403);
        }

        // Check if admin is already in an active (accepted) call — return busy immediately.
        // This prevents the user from even entering the ringing loop.
        $adminBusy = CallRequest::where('status', 'accepted')->exists();
        if ($adminBusy) {
            return response()->json([
                'call_id' => null,
                'room_id' => null,
                'status'  => 'rejected',
                'reason'  => 'Admin is currently on another call. Please try again later.',
            ]);
        }

        // Cancel any existing pending call from this user first
        CallRequest::where('caller_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'ended']);

        // Generate a 6-digit numeric Agora channel name
        $roomId = (string) random_int(100000, 999999);

        $call = CallRequest::create([
            'caller_id' => $user->id,
            'room_id'   => $roomId,
            'status'    => 'pending',
        ]);

        event(new CallSignalingEvent('admin-calls', [
            'action' => 'incoming_call',
            'call'   => $call
        ]));

        return response()->json([
            'call_id' => $call->id,
            'room_id' => $roomId,
            'status'  => 'pending',
        ]);
    }

    /**
     * Admin initiates a call to a specific user.
     */
    public function callUser(Request $request): JsonResponse
    {
        $admin = $request->user();

        if ($admin->role !== 'admin') {
            return response()->json(['error' => 'Only admins can initiate calls to users.'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $targetUser = User::findOrFail($validated['user_id']);

        // Check if target user is already in a call
        $busy = CallRequest::where('target_user_id', $targetUser->id)
            ->where('status', 'accepted')
            ->exists();
        if ($busy) {
            return response()->json([
                'call_id' => null, 'status' => 'rejected',
                'reason'  => 'User is currently in another call.',
            ]);
        }

        $roomId = (string) random_int(100000, 999999);

        $call = CallRequest::create([
            'caller_id'      => $admin->id,
            'target_user_id' => $targetUser->id,
            'room_id'        => $roomId,
            'status'         => 'pending',
        ]);

        event(new CallSignalingEvent('user-' . $validated['user_id'], [
            'action' => 'incoming_call',
            'call'   => $call
        ]));

        return response()->json([
            'call_id' => $call->id,
            'room_id' => $roomId,
            'status'  => 'pending',
        ]);
    }

    /**
     * User polls for an incoming call initiated by admin.
     */
    public function incoming(Request $request): JsonResponse
    {
        $user = $request->user();

        $call = CallRequest::with('caller')
            ->where('target_user_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if (! $call) {
            return response()->json(['call' => null]);
        }

        return response()->json([
            'call' => [
                'id'          => $call->id,
                'room_id'     => $call->room_id,
                'caller_name' => $call->caller->name,
                'caller_id'   => $call->caller_id,
                'status'      => $call->status,
            ],
        ]);
    }

    /**
     * Admin polls for the latest pending call request.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'admin') {
            return response()->json(['error' => 'Only admins can view pending calls.'], 403);
        }

        // First-come-first-served: oldest pending call is shown first.
        // This ensures User A (who called first) is always served before User B.
        $call = CallRequest::with('caller')
            ->pending()
            ->oldest()
            ->first();

        if (! $call) {
            return response()->json(['call' => null]);
        }

        // Count how many others are waiting so admin knows there's a queue.
        $queueCount = CallRequest::pending()->count();

        return response()->json([
            'call' => [
                'id'          => $call->id,
                'room_id'     => $call->room_id,
                'caller_name' => $call->caller->name,
                'caller_id'   => $call->caller_id,
                'status'      => $call->status,
                'queue_count' => $queueCount, // total pending callers (including this one)
            ],
        ]);
    }

    /**
     * Admin accepts or rejects a call.
     */
    public function respond(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'admin') {
            return response()->json(['error' => 'Only admins can respond to calls.'], 403);
        }

        $validated = $request->validate([
            'call_id' => 'required|integer|exists:call_requests,id',
            'action'  => 'required|in:accept,reject',
        ]);

        $newStatus = $validated['action'] === 'accept' ? 'accepted' : 'rejected';

        // ── Atomic update ────────────────────────────────────────────────────────
        // One SQL query: UPDATE ... WHERE id = ? AND status = 'pending'
        // The database guarantees ONLY ONE admin wins this race.
        // If $affected = 0, another admin already accepted/rejected the call
        // between when admin polled and when they clicked Accept.
        $affected = CallRequest::where('id', $validated['call_id'])
            ->where('status', 'pending')
            ->update(['status' => $newStatus]);

        if ($affected === 0) {
            return response()->json([
                'error'  => 'This call was already answered by another admin.',
                'status' => 'taken',
            ], 409);
        }

        $call = CallRequest::findOrFail($validated['call_id']);

        // When admin accepts, auto-reject all OTHER pending calls (other users waiting).
        if ($newStatus === 'accepted') {
            CallRequest::pending()
                ->where('id', '!=', $call->id)
                ->update(['status' => 'rejected']);
        }

        event(new CallSignalingEvent('user-' . $call->caller_id, [
            'action'  => 'call_status',
            'status'  => $newStatus,
            'room_id' => $call->room_id
        ]));

        return response()->json([
            'call_id' => $call->id,
            'room_id' => $call->room_id,
            'status'  => $newStatus,
        ]);
    }


    /**
     * Either call participant (or any admin) may poll call status.
     *
     * For user-initiated calls:   caller_id = user,  target_user_id = null
     * For admin-initiated calls:  caller_id = admin, target_user_id = user
     *
     * Without the admin bypass, the admin gets 403 on user-initiated calls
     * because they are neither the caller nor a target_user_id (which is null).
     */
    public function status(Request $request, int $callId): JsonResponse
    {
        $call = CallRequest::findOrFail($callId);
        $user = $request->user();

        $isParticipant = $call->caller_id      === $user->id
                      || $call->target_user_id === $user->id;
        $isAdmin       = $user->role === 'admin';

        if (! $isParticipant && ! $isAdmin) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        return response()->json([
            'call_id' => $call->id,
            'room_id' => $call->room_id,
            'status'  => $call->status,
        ]);
    }

    /**
     * Target user accepts or rejects an admin-initiated call.
     * (The admin-side equivalent is respond() — admins cannot use this endpoint.)
     */
    public function userRespond(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'call_id' => 'required|integer|exists:call_requests,id',
            'action'  => 'required|in:accept,reject',
        ]);

        $call = CallRequest::findOrFail($validated['call_id']);

        // Only the intended target may respond
        if ($call->target_user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        if ($call->status !== 'pending') {
            return response()->json(['error' => 'Call is no longer pending.', 'status' => $call->status], 409);
        }

        $newStatus = $validated['action'] === 'accept' ? 'accepted' : 'rejected';
        $call->update(['status' => $newStatus]);

        event(new CallSignalingEvent('user-' . $call->caller_id, [
            'action'  => 'call_status',
            'status'  => $newStatus,
            'room_id' => $call->room_id
        ]));

        return response()->json([
            'call_id' => $call->id,
            'room_id' => $call->room_id,
            'status'  => $newStatus,
        ]);
    }

    /**
     * Mark a call as ended (either party can end the call).
     */
    public function end(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'call_id' => 'required|integer|exists:call_requests,id',
        ]);

        $call = CallRequest::findOrFail($validated['call_id']);

        $call->update(['status' => 'ended']);

        event(new CallSignalingEvent('call-' . $call->id, [
            'action' => 'call_ended'
        ]));

        return response()->json(['success' => true]);
    }

    /**
     * Return the current voice provider configuration.
     * Currently hardcoded to 'web_speech'.
     */
    public function voiceStatus(): JsonResponse
    {
        return response()->json([
            'provider' => 'web_speech',
            'enabled'  => true,
        ]);
    }
}
