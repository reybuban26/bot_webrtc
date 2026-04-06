<?php

namespace App\Http\Controllers;

use App\Models\CallRequest;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\User;
use App\Services\QwenAiService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Events\UserTyping;
use App\Events\MessageSeen;

class SupportController extends Controller
{
    /**
     * Get or create the support thread for the authenticated user.
     * For admins: pass ?user_id=x to open a specific user's thread.
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

        $thread = SupportThread::forUser($userId);

        return response()->json([
            'thread_id' => $thread->id,
            'user_id'   => $thread->user_id,
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
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($t) => [
                'thread_id'         => $t->id,
                'user_id'           => $t->user_id,
                'user_name'         => $t->user->name,
                'last_message'      => $t->latestMessage?->body ?? null,
                'last_message_role' => $t->latestMessage?->sender->role ?? null,
                'unread_count'      => $t->unread_messages_count,
                'updated_at'        => $t->updated_at?->toISOString(),
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
            // If it has been deleted, remove recording_url from the payload so the
            // frontend never renders an audio player pointing to a dead 404 URL.
            if ($m->type === 'meeting_notes' && isset($metadata['recording_url'])) {
                $localPath = null;

                if (isset($metadata['recording_path'])) {
                    // New messages: path saved explicitly
                    $localPath = storage_path('app/public/' . $metadata['recording_path']);
                } else {
                    // Old messages: derive local path from the public URL
                    // URL format: https://domain.com/storage/call-recordings/file.webm
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
                'id'         => $m->id,
                'sender_id'  => $m->sender_id,
                'sender'     => $m->sender->name,
                'role'       => $m->sender->role,
                'body'       => $m->body,
                'type'       => $m->type,
                'metadata'   => $metadata,
                'created_at' => $m->created_at->toISOString(),
            ];
        });

        return response()->json(['messages' => $messages]);
    }

    /**
     * Send a plain text message to a thread.
     */
    public function send(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        $request->validate([
            'body' => 'nullable|string|max:2000',
            'file' => 'nullable|file|mimes:pdf,txt,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp|max:10240',
        ]);

        if (!$request->filled('body') && !$request->hasFile('file')) {
            return response()->json(['error' => 'Message or file is required.'], 422);
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

        $message = SupportMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => $request->user()->id,
            'body'      => $request->input('body', ''),
            'type'      => $type,
            'metadata'  => $metadata,
        ]);

        $thread->touch();

        $recipientId = $request->user()->role === 'admin'
            ? $thread->user_id
            : User::where('role', 'admin')->first()?->id;

        if ($recipientId) {
            PushController::sendToUser(
                userId: $recipientId,
                title:  $request->user()->name,
                body:   $message->body ?: '📎 Sent an attachment',
                url:    '/chat'
            );
        }

        return response()->json([
            'id'         => $message->id,
            'body'       => $message->body,
            'sender_id'  => $message->sender_id,
            'type'       => $message->type,
            'metadata'   => $message->metadata,
            'created_at' => $message->created_at->toISOString(),
        ], 201);
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

        // ─ Debug: file details before storage ──────────────────────────────────
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

        // Save recording file
        $path = $file->store('call-recordings', 'public');
        $url  = asset('storage/' . $path);

        // ─ Debug: check URL reachability ───────────────────────────────────
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

        // Transcribe & summarize via Qwen — pass local path so service can read file directly
        $localPath = storage_path('app/public/' . $path);
        $qwen  = app(QwenAiService::class);
        $notes = $qwen->transcribeAndSummarize($url, $request->input('duration', 0), $localPath);

        // Save meeting notes message in thread
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
     */
    public function markAsSeen(Request $request, int $threadId): JsonResponse
    {
        $thread = SupportThread::findOrFail($threadId);
        $this->authorizeThread($request->user(), $thread);

        $query = $thread->messages()->where('is_read', false);

        if ($request->user()->role === 'admin') {
            $query->whereHas('sender', fn($q) => $q->where('role', 'user'));
        } else {
            $query->whereHas('sender', fn($q) => $q->where('role', 'admin'));
        }

        $unreadCount = $query->count(); // ← DAGDAG: i-count muna

        if ($unreadCount > 0) { // ← DAGDAG: broadcast ONLY if may unread
            $query->update(['is_read' => true]);

            broadcast(new MessageSeen(
                threadId: $thread->id,
                seenByUserId: $request->user()->id,
                seenByName: $request->user()->name
            ));
        }

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
}
