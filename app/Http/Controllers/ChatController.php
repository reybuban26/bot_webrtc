<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\QwenAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

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
        $session = null;

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
            'session' => $session,
            'sessions' => $sessions,
            'authUser' => $user,
        ]);
    }

    /**
     * Start a new chat session for the authenticated user.
     */
    public function newSession(Request $request): JsonResponse
    {
        $session = ChatSession::create([
            'user_id' => Auth::id(),
            'session_token' => Str::random(48),
            'title' => 'New Chat',
            'is_active' => true,
        ]);

        return response()->json([
            'session_token' => $session->session_token,
            'id' => $session->id,
        ])->cookie('chat_session', $session->session_token, 60 * 24 * 7);
    }

    /**
     * Send a message and get AI response — user must own the session.
     */
    // public function sendMessage(Request $request): JsonResponse
    // {
    //     $request->headers->set('Accept', 'application/json');

    //     $validated = $request->validate([
    //         'message'       => 'required|string|max:4000',
    //         'session_token' => 'required|string',
    //         'files.*'       => 'nullable|file|mimes:pdf,txt,md,csv|max:10240',
    //     ]);

    //     // The global scope already filters to Auth::id(); firstOrFail() is a final check.
    //     // Policy provides an explicit 403 if the token somehow belongs to another user.
    //     $session = ChatSession::where('session_token', $validated['session_token'])
    //         ->firstOrFail();

    //     $this->authorize('interact', $session);

    //     $fileContext = '';
    //     if ($request->hasFile('files')) {
    //         $parser = new Parser();
    //         foreach ($request->file('files') as $file) {
    //             if ($file->getClientOriginalExtension() === 'pdf') {
    //                 $pdf = $parser->parseFile($file->getPathname());
    //                 $fileContext .= "\n\n--- [File: " . $file->getClientOriginalName() . "] ---\n" . $pdf->getText();
    //             } else {
    //                 $fileContext .= "\n\n--- [File: " . $file->getClientOriginalName() . "] ---\n" . file_get_contents($file->getPathname());
    //             }
    //         }
    //     }

    //     $userMessage = $validated['message'] ?? '';

    //     // 2. Sentiment Check
    //     $sentiment = $this->qwen->analyzeSentiment($userMessage);
    //     if (in_array($sentiment, ['ANGRY', 'FRUSTRATED'])) {
    //         return response()->json([
    //             'success' => true,
    //             'message' => [
    //                 'id' => time(),
    //                 'role' => 'assistant',
    //                 'content' => "⚠️ **System Notice:** Napansin ko pong kayo ay frustrated. Inilipat ko na po ang chat na ito sa aming Live Support team para mas matulungan kayo.",
    //                 'created_at' => now()->toISOString(),
    //             ],
    //             'action' => 'route_to_support'
    //         ]);
    //     }

    //     // Save user message
    //     ChatMessage::create([
    //         'chat_session_id' => $session->id,
    //         'role'            => 'user',
    //         'content'         => $userMessage . ($fileContext ? "\n*(Attached files)*" : ""),
    //     ]);

    //     // 4. Get AI Response with Context
    //     $result = $this->qwen->chat($session, $userMessage . $fileContext);

    //     $messageCount = $session->messages()->count();

    //     // Generate or dynamically update title based on evolving context
    //     if ($session->title === 'New Chat' || in_array($messageCount, [3, 7])) {
    //         $title = $this->qwen->generateTitle($session);
    //         if ($title && $title !== 'New Chat') {
    //             $session->update(['title' => $title]);
    //         }
    //     }

    //     // Call Qwen AI
    //     $result = $this->qwen->chat($session, $validated['message']);

    //     // Save assistant message
    //     $assistantMessage = ChatMessage::create([
    //         'chat_session_id' => $session->id,
    //         'role'            => 'assistant',
    //         'content'         => $result['content'],
    //         'tokens_used'     => $result['tokens'] ?? 0,
    //     ]);

    //     return response()->json([
    //         'success' => $result['success'],
    //         'message' => [
    //             'id'         => $assistantMessage->id,
    //             'role'       => 'assistant',
    //             'content'    => $result['content'],
    //             'created_at' => $assistantMessage->created_at->toISOString(),
    //         ],
    //         'session_title' => $session->fresh()->title,
    //         'action' => 'none'
    //     ]);
    // }

    public function sendMessage(Request $request): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');

        $validated = $request->validate([
            'message' => 'nullable|string|max:4000',
            'session_token' => 'required|string',
            'files.*' => 'nullable|file|mimes:pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,csv,json|max:15360',
        ]);

        $session = ChatSession::where('session_token', $validated['session_token'])->firstOrFail();
        $this->authorize('interact', $session);

        $fileContext = '';
        $userRawMessage = $validated['message'] ?? '';
        $attachmentsData = [];

        if ($request->hasFile('files')) {
            $pdfParser = new Parser;
            foreach ($request->file('files') as $file) {
                $ext = strtolower($file->getClientOriginalExtension());
                $fileName = $file->getClientOriginalName();

                $formats = ['pdf' => '📕 PDF', 'doc' => '📘 DOC', 'docx' => '📘 DOCX', 'txt' => '📄 TXT', 'md' => '📝 MD', 'xls' => '📗 XLS', 'xlsx' => '📗 XLSX', 'csv' => '📊 CSV', 'ppt' => '📙 PPT', 'pptx' => '📙 PPTX', 'jpg' => '🖼️ JPG', 'jpeg' => '🖼️ JPEG', 'png' => '🖼️ PNG', 'gif' => '🖼️ GIF', 'json' => '📦 JSON'];
                $attachmentsData[] = [
                    'name' => $fileName,
                    'format' => $formats[$ext] ?? '📁 FILE',
                ];

                try {
                    if ($ext === 'pdf') {
                        $pdf = $pdfParser->parseFile($file->getPathname());
                        $fileContext .= "\n\n--- [PDF Content: $fileName] ---\n".$pdf->getText();
                    } elseif ($ext === 'docx') {
                        // NEW: Direct DOCX Text Extraction
                        $fileContext .= "\n\n--- [DOCX Content: $fileName] ---\n".$this->extractDocxText($file->getPathname());
                    } elseif (in_array($ext, ['txt', 'md', 'csv', 'json', 'js', 'php', 'html', 'css'])) {
                        $fileContext .= "\n\n--- [Text Content: $fileName] ---\n".file_get_contents($file->getPathname());
                    } else {
                        $fileContext .= "\n\n[System Notice: User uploaded a file: $fileName ($ext format)]";
                    }
                } catch (\Exception $e) {
                    Log::error("File Parse Error ($fileName): ".$e->getMessage());
                }
            }
        }

        if (empty($userRawMessage) && empty($fileContext)) {
            return response()->json(['success' => false, 'message' => 'Empty message.'], 422);
        }

        // Sentiment Check
        $sentiment = $this->qwen->analyzeSentiment($userRawMessage ?: 'File Upload');
        if (in_array($sentiment, ['ANGRY', 'FRUSTRATED'])) {
            return response()->json([
                'success' => true,
                'message' => [
                    'id' => time(), 'role' => 'assistant',
                    'content' => "⚠️ **System Notice:** I noticed you're frustrated. I've escalated this chat to our Live Support team.",                    'created_at' => now()->toISOString(),
                ],
                'action' => 'route_to_support',
            ]);
        }

        // Save User Message
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $userRawMessage ?: '*(Sent an attachment)*',
            'metadata' => ! empty($attachmentsData) ? ['attachments' => $attachmentsData] : null,
        ]);

        // FIX: ISANG BESES LANG TATAWAGIN SI QWEN DITO
        // Pinagsama na natin yung message at yung text na nakuha sa files
        $fullPrompt = $userRawMessage.$fileContext;
        $result = $this->qwen->chat($session, $fullPrompt);

        // Update Title
        $messageCount = $session->messages()->count();
        if ($session->title === 'New Chat' || in_array($messageCount, [3, 7])) {
            $title = $this->qwen->generateTitle($session);
            if ($title) {
                $session->update(['title' => $title]);
            }
        }

        // Save AI Response
        $assistantMessage = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $result['content'],
            'tokens_used' => $result['tokens'] ?? 0,
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => $result['content'],
                'created_at' => $assistantMessage->created_at->toISOString(),
            ],
            'session_title' => $session->fresh()->title,
            'action' => 'none',
        ]);
    }

    private function extractDocxText($path)
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml) {
                // Linisin ang XML tags para makuha ang literal na text
                return strip_tags(str_replace(['</w:p>', '</w:r>', '<w:tab/>'], ["\n", ' ', "\t"], $xml));
            }
        }

        return '[Error: Could not extract DOCX text]';
    }

    /**
     * Load session history — user must own the session.
     */
    public function history(Request $request, string $token): JsonResponse
    {
        $session = ChatSession::where('session_token', $token)->firstOrFail();
        $this->authorize('view', $session);

        $messages = $session->messages()
            ->select('id', 'role', 'content', 'audio_url', 'created_at', 'metadata')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'audio_url' => $m->audio_url,
                'attachments' => $m->metadata['attachments'] ?? [],
                'created_at' => $m->created_at->toISOString(),
            ]);

        return response()->json([
            'session' => [
                'id' => $session->id,
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
                'id' => $s->id,
                'token' => $s->session_token,
                'title' => $s->title,
                'messages_count' => $s->messages_count,
                'created_at' => $s->created_at->toISOString(),
            ]);

        return response()->json($sessions);
    }
}
