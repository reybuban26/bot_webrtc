<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\QwenAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(private QwenAiService $qwen) {}

    /**
     * Main chat UI — requires auth (enforced by route middleware).
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Resolve or ignore the session cookie — only for this user
        $sessionToken = $request->cookie('chat_session');
        $session      = null;

        if ($sessionToken) {
            $session = ChatSession::where('session_token', $sessionToken)
                ->where('user_id', $user->id)
                ->active()
                ->first();
        }

        // Don't auto-create a session here anymore — let the frontend do it lazily
        $sessions = ChatSession::where('user_id', $user->id)
            ->active()
            ->withCount('messages')
            ->latest()
            ->limit(30)
            ->get();

        return view('chat', [
            'session'      => $session,
            'sessions'     => $sessions,
            'authUser'     => $user,
        ]);
    }

    /**
     * Start a new chat session for the authenticated user.
     */
    public function newSession(Request $request): JsonResponse
    {
        $session = ChatSession::create([
            'user_id'       => Auth::id(),
            'session_token' => Str::random(48),
            'title'         => 'New Chat',
            'is_active'     => true,
        ]);

        return response()->json([
            'session_token' => $session->session_token,
            'id'            => $session->id,
        ])->cookie('chat_session', $session->session_token, 60 * 24 * 7);
    }

    /**
     * Send a message and get AI response — user must own the session.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');

        $validated = $request->validate([
            'message'       => 'required|string|max:4000',
            'session_token' => 'required|string',
        ]);

        // The global scope already filters to Auth::id(); firstOrFail() is a final check.
        // Policy provides an explicit 403 if the token somehow belongs to another user.
        $session = ChatSession::where('session_token', $validated['session_token'])
            ->firstOrFail();

        $this->authorize('interact', $session);

        // Save user message
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role'            => 'user',
            'content'         => $validated['message'],
        ]);

        $messageCount = $session->messages()->count();

        // Generate or dynamically update title based on evolving context
        if ($session->title === 'New Chat' || in_array($messageCount, [3, 7])) {
            $title = $this->qwen->generateTitle($session);
            if ($title && $title !== 'New Chat') {
                $session->update(['title' => $title]);
            }
        }

        // Call Qwen AI
        $result = $this->qwen->chat($session, $validated['message']);

        // Save assistant message
        $assistantMessage = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role'            => 'assistant',
            'content'         => $result['content'],
            'tokens_used'     => $result['tokens'] ?? 0,
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => [
                'id'         => $assistantMessage->id,
                'role'       => 'assistant',
                'content'    => $result['content'],
                'created_at' => $assistantMessage->created_at->toISOString(),
            ],
            'session_title' => $session->fresh()->title,
        ]);
    }

    /**
     * Load session history — user must own the session.
     */
    public function history(Request $request, string $token): JsonResponse
    {
        $session = ChatSession::where('session_token', $token)->firstOrFail();
        $this->authorize('view', $session);

        $messages = $session->messages()
            ->select('id', 'role', 'content', 'audio_url', 'created_at')
            ->get()
            ->map(fn ($m) => [
                'id'         => $m->id,
                'role'       => $m->role,
                'content'    => $m->content,
                'audio_url'  => $m->audio_url,
                'created_at' => $m->created_at->toISOString(),
            ]);

        return response()->json([
            'session'  => [
                'id'    => $session->id,
                'title' => $session->title,
                'token' => $session->session_token,
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Delete a session — user must own it.
     */
    public function deleteSession(string $token): JsonResponse
    {
        $session = ChatSession::where('session_token', $token)->firstOrFail();
        $this->authorize('delete', $session);
        $session->delete();

        return response()->json(['success' => true]);
    }

    /**
     * List the current user's recent sessions.
     */
    public function sessions(): JsonResponse
    {
        // Global scope automatically limits to Auth::id() — no manual where() needed
        $sessions = ChatSession::active()
            ->withCount('messages')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn ($s) => [
                'id'             => $s->id,
                'token'          => $s->session_token,
                'title'          => $s->title,
                'messages_count' => $s->messages_count,
                'created_at'     => $s->created_at->toISOString(),
            ]);

        return response()->json($sessions);
    }
}
