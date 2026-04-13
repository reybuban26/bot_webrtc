<?php

namespace App\Http\Controllers;

use App\Events\ChatEnded;
use App\Events\MessageSeen;
use App\Events\SystemMessageCreated;
use App\Events\UserTyping;
use App\Models\CallRequest;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\User;
use App\Services\AiResponseService;
use App\Services\QwenAiService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupportController extends Controller
{
    public function __construct(
        private AiResponseService $aiService,
    ) {}
    /**
     * Find the support thread for the authenticated user (no auto-create).
     * Returns thread_id: null if no thread exists yet.
     * For admins: pass ?user_id=x to look up a specific user's thread.
     */
    public function thread(Request $request): JsonResponse
    {
        $auth = $request->user();

        if ($auth->role === 'admin') {
            $request->validate(['user_id' => 'required|integer|exists:users,id']);
            $userId = (int) $request->query('user_id');
        } else {
            $userId = $auth->id;
        }

        $thread = SupportThread::where('user_id', $userId)->first();

        if (! $thread) {
            return response()->json(['thread_id' => null]);
        }

        return response()->json([
            'thread_id'         => $thread->id,
            'user_id'           => $thread->user_id,
            'chat_status'       => $thread->chat_status,
            'assigned_admin_id' => $thread->assigned_admin_id,
        ]);
    }

    /**
     * Create (or return existing) support thread — called before the first send.
     * For admins: pass user_id in the body to open a specific user's thread.
     */
    public function createThread(Request $request): JsonResponse
    {
        $auth = $request->user();

        if ($auth->role === 'admin') {
            $request->validate(['user_id' => 'required|integer|exists:users,id']);
            $userId = (int) $request->input('user_id');
        } else {
            $userId = $auth->id;
        }

        $thread = SupportThread::forUser($userId);

        return response()->json([
            'thread_id'         => $thread->id,
            'user_id'           => $thread->user_id,
            'chat_status'       => $thread->chat_status,
            'assigned_admin_id' => $thread->assigned_admin_id,
        ]);
    }

    /**
     * List all user threads (admin only) — sidebar thread list.
     */
    public function threads(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $threads = SupportThread::with(['user', 'latestMessage.sender'])
            ->withCount(['unreadMessages'])
            ->whereHas('messages')   // only show threads that have at least one message
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($t) => [
                'thread_id'         => $t->id,
                'user_id'           => $t->user_id,
                'user_name'         => $t->user->name,
                'last_message'      => ($t->latestMessage?->is_encrypted)
                                        ? '🔒 Encrypted message'
                                        : ($t->latestMessage?->body ?? null),
                'last_message_role' => $t->latestMessage?->sender?->role ?? 'system',
                'unread_count'      => $t->unread_messages_count,
                'updated_at'        => $t->updated_at?->toISOString(),
                'chat_status'       => $t->chat_status,
                'assigned_admin_id' => $t->assigned_admin_id,
            ]);

        return response()->json(['threads' => $threads]);
    }

    /**
     * Poll for messages in a thread, optionally since a timestamp.
     * Returns only new messages when `since` parameter is provided.
     */
    public function messages(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        $since = $request->query('since'); // ISO 8601 timestamp

        $query = $thread->messages()->with('sender');
        if ($since) {
            $query->where('created_at', '>', $since);
        }

        $messages = $query->orderBy('created_at')->get()->map(function ($m) {
            $metadata = $m->metadata;

            // For meeting_notes, verify the recording file still exists on disk.
            if ($m->type === 'meeting_notes' && isset($metadata['recording_url'])) {
                $localPath = null;

                if (isset($metadata['recording_path'])) {
                    $localPath = storage_path('app/public/' . $metadata['recording_path']);
                } else {
                    $urlPath = parse_url($metadata['recording_url'], PHP_URL_PATH) ?? '';
                    if (str_starts_with($urlPath, '/storage/')) {
                        $relative  = substr($urlPath, strlen('/storage/'));
                        $localPath = storage_path('app/public/' . $relative);
                    }
                }

                if (! $localPath || ! file_exists($localPath)) {
                    unset($metadata['recording_url']);
                }
            }

            return [
                'id'           => $m->id,
                'sender_id'    => $m->sender_id,
                'sender'       => $m->sender?->name ?? 'System',
                'role'         => $m->sender?->role ?? 'system',
                'body'         => $m->body,
                'type'         => $m->type,
                'metadata'     => $metadata,
                'is_encrypted' => (bool) $m->is_encrypted,
                'created_at'   => $m->created_at->toISOString(),
            ];
        });

        return response()->json([
            'messages'    => $messages,
            'chat_status' => $thread->chat_status,
        ]);
    }

    /**
     * Send a plain text message to a thread.
     * If thread status is 'waiting', route message to AI first.
     */
    public function send(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        $request->validate([
            'body'         => 'nullable|string|max:65535',
            'iv'           => 'nullable|string|max:256',
            'is_encrypted' => 'nullable|boolean',
            'file'         => 'nullable|file|mimes:pdf,txt,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp|max:10240',
        ]);

        $isEncrypted = (bool) $request->input('is_encrypted', false);

        if (!$request->filled('body') && !$request->hasFile('file')) {
            return response()->json(['error' => 'Message or file is required.'], 422);
        }

        // ── Session control: inject waiting message when user starts/restarts ──
        if ($request->user()->role !== 'admin') {
            $needsWaiting = false;

            if ($thread->chat_status === 'ended') {
                // Re-open the thread — reset status to waiting
                $thread->update(['chat_status' => 'waiting', 'assigned_admin_id' => null]);
                $needsWaiting = true;
            } elseif ($thread->chat_status === 'waiting') {
                // First user message on a fresh thread (no non-system messages yet)
                $hasUserMessages = $thread->messages()
                    ->whereNotNull('sender_id')
                    ->exists();
                $needsWaiting = ! $hasUserMessages;
            }

            if ($needsWaiting) {
                $waitingMsg = SupportMessage::createSystem(
                    $thread->id,
                    '🤖 AI Assistant is here to help. What can I assist you with?'
                );
                broadcast(new SystemMessageCreated(
                    threadId:  $thread->id,
                    messageId: $waitingMsg->id,
                    body:      $waitingMsg->body,
                    createdAt: $waitingMsg->created_at->toISOString(),
                ));
            }
        }

        $metadata = null;
        $type     = 'text';

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('support-attachments', 'public');

            $type     = 'file';
            $metadata = [
                'attachment_path' => $path,
                'attachment_url'  => asset('storage/' . $path),
                'attachment_name' => $file->getClientOriginalName(),
                'attachment_mime' => $file->getMimeType(),
                'attachment_size' => $file->getSize(),
            ];
        }

        // Store encryption IV in metadata if message is E2EE
        if ($isEncrypted && $request->filled('iv')) {
            $metadata = array_merge($metadata ?? [], [
                'encryption' => [
                    'iv'   => $request->input('iv'),
                    'algo' => 'AES-GCM',
                ],
            ]);
        }

        $message = SupportMessage::create([
            'thread_id'    => $thread->id,
            'sender_id'    => $request->user()->id,
            'body'         => $request->input('body', ''),
            'type'         => $type,
            'metadata'     => $metadata,
            'is_encrypted' => $isEncrypted,
        ]);

        $thread->touch();

        // ── AI ROUTING: If thread is 'waiting', route to AI ──
        if ($request->user()->role !== 'admin' && $thread->chat_status === 'waiting' && $request->filled('body')) {
            return $this->handleAiResponse($thread, $message, $isEncrypted, $request->input('iv'));
        }

        // ── REGULAR MESSAGE: Broadcast to recipient ──
        $recipientId = $request->user()->role === 'admin'
            ? $thread->user_id
            : User::where('role', 'admin')->first()?->id;

        if ($recipientId) {
            PushController::sendToUser(
                userId: $recipientId,
                title:  $request->user()->name,
                body:   $isEncrypted ? '🔒 New encrypted message' : ($message->body ?: '📎 Sent an attachment'),
                url:    '/chat'
            );
        }

        return response()->json([
            'id'           => $message->id,
            'body'         => $message->body,
            'sender_id'    => $message->sender_id,
            'type'         => $message->type,
            'metadata'     => $message->metadata,
            'is_encrypted' => (bool) $message->is_encrypted,
            'created_at'   => $message->created_at->toISOString(),
        ], 201);
    }

    /**
     * Handle AI response: generate response, check confidence, escalate if needed
     */
    private function handleAiResponse(SupportThread $thread, SupportMessage $userMessage, bool $isEncrypted, ?string $iv): JsonResponse
    {
        // Check for explicit escalation request in user message
        if ($this->aiService->userRequestsEscalation($userMessage->body)) {
            return $this->initiateEscalation($thread, 'User requested escalation');
        }

        // Build conversation history
        $history = $this->buildConversationHistory($thread);

        // Generate AI response
        $aiResult = $this->aiService->generateResponse($thread, $userMessage->body, $history);

        // Check if escalation is needed
        if ($aiResult['should_escalate']) {
            return $this->initiateEscalation($thread, 'AI escalation triggered (confidence: ' . round($aiResult['confidence'], 2) . ')');
        }

        // Store AI response with same encryption status as user message
        $aiMetadata = ['confidence' => $aiResult['confidence']];
        if ($isEncrypted && $iv) {
            $aiMetadata = array_merge($aiMetadata, [
                'encryption' => [
                    'iv'   => $iv,
                    'algo' => 'AES-GCM',
                ],
            ]);
        }

        $aiMessage = SupportMessage::create([
            'thread_id'    => $thread->id,
            'sender_id'    => null, // AI message (no sender)
            'body'         => $aiResult['response'],
            'type'         => 'ai_response',
            'metadata'     => $aiMetadata,
            'is_encrypted' => $isEncrypted,
        ]);

        // Update thread state to ai_active
        $thread->update([
            'chat_status' => 'ai_active',
            'ai_confidence' => $aiResult['confidence'],
        ]);

        $thread->touch();

        return response()->json([
            'id'           => $aiMessage->id,
            'body'         => $aiMessage->body,
            'sender_id'    => null,
            'type'         => 'ai_response',
            'metadata'     => $aiMessage->metadata,
            'is_encrypted' => (bool) $aiMessage->is_encrypted,
            'created_at'   => $aiMessage->created_at->toISOString(),
            'is_ai'        => true,
        ], 201);
    }

    /**
     * Initiate escalation: set status to 'escalating', create system message, notify admins
     */
    private function initiateEscalation(SupportThread $thread, string $reason = null): JsonResponse
    {
        // Get next queue position
        $nextPosition = SupportThread::where('chat_status', 'escalating')
            ->count() + 1;

        // Update thread
        $thread->update([
            'chat_status' => 'escalating',
            'requires_escalation' => true,
            'queue_position' => $nextPosition,
        ]);

        // Create system message
        $sysMsg = SupportMessage::createSystem(
            $thread->id,
            'Connecting you with a live agent…'
        );

        // Broadcast escalation event
        broadcast(new SystemMessageCreated(
            threadId: $thread->id,
            messageId: $sysMsg->id,
            body: $sysMsg->body,
            createdAt: $sysMsg->created_at->toISOString(),
            chatStatus: 'escalating',
        ));

        Log::info('[Support] Escalation initiated', [
            'thread_id' => $thread->id,
            'reason' => $reason,
            'queue_position' => $nextPosition,
        ]);

        return response()->json(['escalated' => true], 201);
    }

    /**
     * Build conversation history for AI from thread messages
     */
    private function buildConversationHistory(SupportThread $thread): array
    {
        return $thread->messages()
            ->whereIn('type', ['text', 'ai_response'])
            ->latest()
            ->limit(10)
            ->get()
            ->reverse()
            ->map(fn($msg) => [
                'role' => $msg->type === 'ai_response' ? 'assistant' : 'user',
                'content' => $msg->body,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Upload call recording, transcribe with Qwen audio, generate meeting notes,
     * and save as a meeting_notes message in the thread.
     */
    public function saveMeeting(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        $request->validate([
            'recording' => 'required|file|mimetypes:audio/webm,audio/ogg,audio/mp4,video/webm,video/mp4|max:51200',
            'call_id'   => 'nullable|integer',
            'duration'  => 'nullable|integer',
        ]);

        $file = $request->file('recording');
        Log::info('[Meeting] File received', [
            'original_name' => $file->getClientOriginalName(),
            'client_mime'   => $file->getClientMimeType(),
            'detected_mime' => $file->getMimeType(),
            'size_bytes'    => $file->getSize(),
            'size_kb'       => round($file->getSize() / 1024, 1),
            'extension'     => $file->getClientOriginalExtension(),
            'call_id'       => $request->input('call_id'),
            'duration_secs' => $request->input('duration'),
            'thread_id'     => $threadId,
        ]);

        $path = $file->store('call-recordings', 'public');
        $url  = asset('storage/' . $path);

        Log::info('[Meeting] File stored', ['path' => $path, 'public_url' => $url]);
        try {
            $headResp = Http::timeout(8)->head($url);
            Log::info('[Meeting] URL reachability check', [
                'url'    => $url,
                'status' => $headResp->status(),
                'ok'     => $headResp->successful(),
            ]);
            if (! $headResp->successful()) {
                Log::warning('[Meeting] Public URL is NOT reachable — DashScope cannot download the file.', [
                    'url'    => $url,
                    'status' => $headResp->status(),
                    'hint'   => 'Run: php artisan storage:link',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Meeting] URL reachability check failed', ['error' => $e->getMessage()]);
        }

        $localPath = storage_path('app/public/' . $path);
        $qwen  = app(QwenAiService::class);
        $notes = $qwen->transcribeAndSummarize($url, $request->input('duration', 0), $localPath);

        $message = SupportMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => $request->user()->id,
            'body'      => $notes,
            'type'      => 'meeting_notes',
            'metadata'  => [
                'call_id'        => $request->input('call_id'),
                'duration_secs'  => $request->input('duration'),
                'recording_path' => $path,
                'recording_url'  => $url,
            ],
        ]);

        $thread->touch();

        return response()->json([
            'id'         => $message->id,
            'body'       => $notes,
            'type'       => 'meeting_notes',
            'created_at' => $message->created_at->toISOString(),
        ], 201);
    }

    /**
     * Mark all messages in a thread as read.
     * When admin opens a waiting thread for the first time, atomically claims it
     * and broadcasts a "You are now chatting with Admin X" system message.
     */
    public function markAsSeen(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        $auth = $request->user();

        // ── Admin first-contact: atomically claim a waiting thread ───────────
        if ($auth->role === 'admin' && $thread->chat_status === 'waiting') {
            $updated = SupportThread::where('id', $thread->id)
                ->where('chat_status', 'waiting')
                ->update(['chat_status' => 'active', 'assigned_admin_id' => $auth->id]);

            if ($updated) {
                $firstName = explode(' ', trim($auth->name))[0];
                $sysMsg = SupportMessage::createSystem(
                    $thread->id,
                    "You are now chatting with {$firstName}."
                );
                broadcast(new SystemMessageCreated(
                    threadId:   $thread->id,
                    messageId:  $sysMsg->id,
                    body:       $sysMsg->body,
                    createdAt:  $sysMsg->created_at->toISOString(),
                    chatStatus: 'active',
                ));
            }
        }

        $query = $thread->messages()->where('is_read', false);

        if ($auth->role === 'admin') {
            $query->whereHas('sender', fn($q) => $q->where('role', 'user'));
        } else {
            $query->whereHas('sender', fn($q) => $q->where('role', 'admin'));
        }

        $unreadCount = $query->count();

        if ($unreadCount > 0) {
            $query->update(['is_read' => true]);

            broadcast(new MessageSeen(
                threadId: $thread->id,
                seenByUserId: $auth->id,
                seenByName: $auth->name
            ));
        }

        return response()->json(['success' => true]);
    }

    /**
     * Admin ends the current chat session.
     * Creates a system message, broadcasts ChatEnded, and resets thread status.
     */
    public function endChat(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        if ($request->user()->role !== 'admin') {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $thread->update(['chat_status' => 'ended', 'assigned_admin_id' => null]);

        $sysMsg = SupportMessage::createSystem(
            $thread->id,
            'Chat session ended by admin. Feel free to send a new message anytime.'
        );

        broadcast(new ChatEnded(
            threadId:  $thread->id,
            messageId: $sysMsg->id,
            body:      $sysMsg->body,
            createdAt: $sysMsg->created_at->toISOString(),
        ));

        $thread->touch();

        return response()->json(['success' => true]);
    }

    /**
     * Assign next escalating thread to available admin
     * Admin assignment is automatic: admin with fewest active threads gets assigned
     */
    public function assignEscalation(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        // Get next escalating thread in queue
        $thread = SupportThread::queuedForEscalation()
            ->orderBy('queue_position')
            ->first();

        if (!$thread) {
            return response()->json(['message' => 'No pending escalations']);
        }

        // Find admin with fewest assigned threads
        $admin = User::where('role', 'admin')
            ->withCount('assignedThreads')
            ->orderBy('assigned_threads_count')
            ->first();

        if (!$admin) {
            return response()->json(['message' => 'No admins available'], 503);
        }

        // Assign thread to admin
        $thread->update([
            'chat_status' => 'active',
            'assigned_admin_id' => $admin->id,
            'queue_position' => null,
        ]);

        // Create system message
        $firstName = explode(' ', trim($admin->name))[0];
        $sysMsg = SupportMessage::createSystem(
            $thread->id,
            "You are now connected with {$firstName}."
        );

        // Broadcast assignment
        broadcast(new SystemMessageCreated(
            threadId: $thread->id,
            messageId: $sysMsg->id,
            body: $sysMsg->body,
            createdAt: $sysMsg->created_at->toISOString(),
            chatStatus: 'active',
        ));

        Log::info('[Support] Escalation assigned', [
            'thread_id' => $thread->id,
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
        ]);

        return response()->json(['assigned' => true, 'admin_id' => $admin->id]);
    }

    /**
     * User rates the chat experience after session ends
     */
    public function rateChat(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        $request->validate([
            'rating' => 'required|integer|between:1,5',
            'feedback' => 'nullable|string|max:1000',
        ]);

        // Update thread metadata with rating
        $metadata = $thread->metadata ?? [];
        $metadata['rating'] = $request->integer('rating');
        $metadata['feedback'] = $request->string('feedback', '');
        $metadata['rated_at'] = now()->toISOString();

        $thread->update(['metadata' => $metadata]);

        Log::info('[Support] Chat rated', [
            'thread_id' => $thread->id,
            'rating' => $request->integer('rating'),
            'has_feedback' => $request->filled('feedback'),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Ensure the auth user can access this thread.
     * Users can only access their own thread. Admins can access any.
     */
    private function authorizeThread(User $user, SupportThread $thread): void
    {
        if ($user->role !== 'admin' && $thread->user_id !== $user->id) {
            abort(403, 'Forbidden.');
        }
    }

    public function typing(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        broadcast(new UserTyping(
            threadId: $thread->id,
            userId: $request->user()->id,
            userName: $request->user()->name,
            isTyping: (bool) $request->input('is_typing', true)
        ));

        return response()->json(['success' => true]);
    }

    // ── E2EE Thread Key Exchange ──────────────────────────────────────────────

    /**
     * Store per-user encrypted copies of the thread's AES key.
     * Body: { "keys": { "<userId>": "<base64_wrapped_key>", ... } }
     */
    public function storeThreadKeys(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        $request->validate([
            'keys'   => 'required|array',
            'keys.*' => 'required|string|max:8192',
        ]);

        $thread->update(['encrypted_keys' => $request->input('keys')]);

        return response()->json(['success' => true]);
    }

    /**
     * Retrieve the encrypted thread key map so each participant can
     * decrypt their copy using their own RSA private key.
     */
    public function getThreadKeys(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        return response()->json([
            'thread_id'      => $thread->id,
            'encrypted_keys' => $thread->encrypted_keys ?? [],
        ]);
    }
}
