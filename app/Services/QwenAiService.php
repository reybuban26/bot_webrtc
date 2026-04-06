<?php

namespace App\Services;

use App\Models\ChatSession;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QwenAiService
{
    // ── Models ────────────────────────────────────────────────────────────────
    /** Chat / summarization — DashScope International (Singapore) */
    private const CHAT_MODEL = 'qwen3.5-plus';

    /** Audio transcription — Groq Whisper */
    private const GROQ_ASR_ENDPOINT = 'https://api.groq.com/openai/v1/audio/transcriptions';

    /** Meeting notes summarization — Groq LLM (OpenAI-compatible) */
    private const GROQ_CHAT_ENDPOINT     = 'https://api.groq.com/openai/v1/chat/completions';
    private const GROQ_SUMMARIZE_MODEL   = 'llama-3.3-70b-versatile';

    // ── Chat endpoint ─────────────────────────────────────────────────────────
    private const CHAT_ENDPOINT = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions';

    // ── Credentials ───────────────────────────────────────────────────────────
    private string $qwenApiKey;
    private string $groqApiKey;
    private string $groqAsrModel;

    public function __construct()
    {
        $this->qwenApiKey   = config('services.qwen.api_key', '');
        $this->groqApiKey   = config('services.groq.api_key', '');
        $this->groqAsrModel = config('services.groq.asr_model', 'whisper-large-v3');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->qwenApiKey);
    }

    /**
     * Analyze user sentiment for Auto-Routing
     */
    public function analyzeSentiment(string $message): string
    {
        if (empty($this->qwenApiKey) || empty($message)) return 'NEUTRAL';

        try {
            $payload = [
                'model' => self::CHAT_MODEL,
                'messages' => [
                    [
                        'role' => 'system', 
                        'content' => 'Analyze the sentiment. Output STRICTLY ONE WORD: HAPPY, NEUTRAL, ANGRY, or FRUSTRATED.'
                    ],
                    ['role' => 'user', 'content' => $message],
                ],
                'max_tokens' => 10,
                'temperature' => 0.1,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->qwenApiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(10)->post(self::CHAT_ENDPOINT, $payload);

            if ($response->successful()) {
                return strtoupper(trim(trim($response->json('choices.0.message.content')), '"\'.,[]'));
            }
        } catch (\Throwable $e) {
            Log::error('[Sentiment] Error: ' . $e->getMessage());
        }
        return 'NEUTRAL';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CHAT  —  qwen3.5-plus  (DashScope Singapore)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Send a message to the Qwen chat model and return the response.
     */
    public function chat(ChatSession $session, string $userMessage): array
    {
        if (empty($this->qwenApiKey)) {
            return [
                'success' => false,
                'content' => '⚠️ Qwen API key not configured. Please add QWEN_API_KEY to your .env file.',
                'tokens'  => 0,
            ];
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an intelligent developed by Synermaxx Corporation, helpful AI assistant. Be concise, accurate, and friendly. '
                    . 'Use markdown formatting when appropriate. '
                    . 'IMPORTANT: You must always respond ONLY in English, even if the user writes in another language.',
            ],
            ...$session->getConversationHistory(20),
            ['role' => 'user', 'content' => $userMessage],
        ];

        $startTime = microtime(true);
        try {
            $payload = [
                'model'       => self::CHAT_MODEL,
                'messages'    => $messages,
                'max_tokens'  => (int) config('services.qwen.max_tokens', 2048),
                'temperature' => (float) config('services.qwen.temperature', 0.7),
                'stream'      => false,
            ];
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->qwenApiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(60)->post(self::CHAT_ENDPOINT, $payload);

            $durationMs = round((microtime(true) - $startTime) * 1000);

            \App\Models\ApiLog::create([
                'service' => 'qwen',
                'endpoint' => self::CHAT_ENDPOINT,
                'method' => 'POST',
                'status_code' => $response->status(),
                'request_payload' => $payload,
                'response_payload' => $response->json(),
                'error_message' => $response->successful() ? null : $response->body(),
                'duration_ms' => $durationMs,
                'ip_address' => request()->ip(),
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'content' => $response->json('choices.0.message.content') ?? 'No response received.',
                    'tokens'  => $response->json('usage.total_tokens') ?? 0,
                ];
            }

            $error = $response->json('error.message') ?? $response->json('message') ?? $response->body();
            Log::error('[Chat] API error', ['status' => $response->status(), 'error' => $error]);

            return [
                'success' => false,
                'content' => "⚠️ AI service error ({$response->status()}): {$error}",
                'tokens'  => 0,
            ];

        } catch (RequestException $e) {
            Log::error('[Chat] Request failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'content' => '⚠️ Could not reach the AI service. Please check your connection and API key.',
                'tokens'  => 0,
            ];
        }
    }

    /**
     * Generate a short chat title from the current conversation context.
     * It updates dynamically if the context evolves.
     */
    public function generateTitle(ChatSession $session): string
    {
        $history = $session->getConversationHistory(6);
        $historyText = collect($history)->map(fn($m) => strtoupper($m['role']) . ': ' . $m['content'])->implode("\n");

        if (empty($this->qwenApiKey)) {
            $firstMessage = $session->messages()->where('role', 'user')->oldest()->first();
            return $firstMessage ? $this->fallbackTitle($firstMessage->content) : 'New Chat';
        }

        $startTime = microtime(true);
        try {
            $payload = [
                'model'       => self::CHAT_MODEL,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => "You are an expert intent analyzer. Read the conversation and describe the user's core intent in exactly 3 to 6 words.\n\n"
                            . "CRITICAL RULES:\n"
                            . "1. NEVER repeat or quote the user's exact words.\n"
                            . "2. Start with an action word (e.g., 'Asking about...', 'Troubleshooting a...', 'Inquiring regarding...').\n"
                            . "3. Output ONLY the raw title. No quotes, no prefixes, no trailing punctuation.\n\n"
                            . "Example Input: 'who are you'\n"
                            . "Example Output: Asking about AI identity",
                    ],
                    ['role' => 'user', 'content' => "Conversation Context:\n" . $historyText],
                ],
                'max_tokens'  => 20,
                'temperature' => 0.1, // Binabaan natin to 0.1 para strict at sundin ang format
                'stream'      => false,
            ];
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->qwenApiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(15)->post(self::CHAT_ENDPOINT, $payload); // Tinaasan natin timeout to 15s

            $durationMs = round((microtime(true) - $startTime) * 1000);

            \App\Models\ApiLog::create([
                'service' => 'qwen_title',
                'endpoint' => self::CHAT_ENDPOINT,
                'method' => 'POST',
                'status_code' => $response->status(),
                'request_payload' => $payload,
                'response_payload' => $response->json(),
                'error_message' => $response->successful() ? null : $response->body(),
                'duration_ms' => $durationMs,
                'ip_address' => request()->ip(),
            ]);

            if ($response->successful()) {
                $title = trim(trim($response->json('choices.0.message.content') ?? ''), '"\'');
                if ($title && mb_strlen($title) <= 80) {
                    return $title;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Chat] Title generation failed', ['error' => $e->getMessage()]);
        }
        $firstMessage = $session->messages()->where('role', 'user')->oldest()->first();
        return $firstMessage ? $this->fallbackTitle($firstMessage->content) : 'New Chat';
    }

    private function fallbackTitle(string $message): string
    {
        $words = array_slice(preg_split('/\s+/', trim($message)), 0, 6);
        return rtrim(implode(' ', $words), '?!.,;:');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AUDIO TRANSCRIPTION  —  Groq Whisper (whisper-large-v3)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Transcribe a call recording using Groq Whisper and generate meeting notes.
     *
     * Groq Whisper reads the audio file directly from disk — no public URL needed.
     * Response is synchronous (no polling). Supported formats: webm, mp3, mp4,
     * ogg, wav, flac, m4a (up to 25 MB).
     *
     * @param  string      $audioUrl  Public URL (kept for metadata / logging only)
     * @param  int         $durationSec Call duration in seconds
     * @param  string|null $localPath   Absolute local path to the audio file
     * @return string  Markdown-formatted meeting notes
     */
    public function transcribeAndSummarize(string $audioUrl, int $durationSec = 0, ?string $localPath = null): string
    {
        Log::info('[Groq] Starting transcription pipeline', [
            'local_path'  => $localPath,
            'file_exists' => $localPath ? file_exists($localPath) : false,
            'duration'    => $durationSec,
            'model'       => $this->groqAsrModel,
            'groq_key'    => ! empty($this->groqApiKey) ? substr($this->groqApiKey, 0, 10) . '...' : 'MISSING',
        ]);

        if (empty($this->groqApiKey)) {
            Log::error('[Groq] API key not configured — add GROQ_API_KEY to .env');
            return '⚠️ **Transcription unavailable** — GROQ_API_KEY is not configured.';
        }

        if (! $localPath || ! file_exists($localPath)) {
            Log::error('[Groq] Local audio file not found', ['path' => $localPath]);
            return '⚠️ **Transcription unavailable** — audio file not found on server.';
        }

        $result = $this->transcribeWithGroq($localPath);

        // 1. Kapag totoong nag-error ang Groq API o network issue
        if (! $result) {
            Log::warning('[Groq] Transcription failed (API Error)', ['path' => $localPath]);
            return "⚠️ **Meeting notes could not be generated** — transcription failed.\n\n"
                . "The call recording is saved for manual review.";
        }

        // 2. Kunin ang text at linisin ang extra spaces
        $transcript = trim($result['text'] ?? '');
        $language   = $result['language'] ?? 'unknown';

        // 3. Kapag success ang API pero WALANG narinig na salita
        // Minsan nagbabalik din ang Whisper ng mga "[silence]" o "(background noise)" kaya pwede natin i-check
        $ignoreWords = ['[silence]', '(silence)', '[blank]', ''];
        if (in_array(strtolower($transcript), $ignoreWords)) {
            Log::info('[Groq] Transcription succeeded but no speech detected.', ['path' => $localPath]);
            return "ℹ️ **No Conversation Detected**\n\nThe call ended without any recognizable speech. The recording has been saved for your reference.";
        }

        Log::info('[Groq] Transcription succeeded — generating summary.', [
            'language' => $language,
            'chars'    => strlen($transcript),
        ]);
        return $this->summarizeTranscript($transcript, $durationSec, $language);
    }

    // ── Groq Whisper REST call ────────────────────────────────────────────────

    /**
     * Whisper context prompt. 
     * Ginagamit lang ito para bigyan ng idea si Whisper sa mga common words
     * na ginagamit sa meeting para hindi siya mag-imbento.
     */
    private const WHISPER_PROMPT = 'This is a high-quality meeting recording with mixed English and Tagalog (Filipino) speech. The speakers are clear. Focus on capturing the exact words: hello, kumain ka na ba, kamusta, yes, okay, sige po, opo, yung, anong, bakit.';
    /**
     * POST the audio file to Groq Whisper and return the transcript + detected language.
     *
     * @return array{text: string, language: string}|null
     */
    private function transcribeWithGroq(string $localPath): ?array
    {
        $mimeType = mime_content_type($localPath) ?: 'audio/webm';
        $fileName = basename($localPath);
        $sizeKb   = round(filesize($localPath) / 1024, 1);

        Log::info('[Groq] Sending audio to Whisper', [
            'file'     => $fileName,
            'size_kb'  => $sizeKb,
            'mime'     => $mimeType,
            'model'    => $this->groqAsrModel,
            'endpoint' => self::GROQ_ASR_ENDPOINT,
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->groqApiKey,
            ])->timeout(120)->attach(
                'file',
                file_get_contents($localPath),
                $fileName,
                ['Content-Type' => $mimeType]
            )->post(self::GROQ_ASR_ENDPOINT, [
                'model'                    => $this->groqAsrModel,
                'response_format'          => 'verbose_json',
                'timestamp_granularities[]' => 'segment',
                'temperature'              => 0,
                'language'                 => 'tl',
                'prompt'                   => self::WHISPER_PROMPT,
            ]);

            Log::info('[Groq] Whisper response', [
                'status'   => $response->status(),
                'language' => $response->json('language'),
                'preview'  => substr($response->json('text') ?? '', 0, 200),
            ]);

            if ($response->successful()) {
                $text     = trim($response->json('text') ?? '');
                $language = $response->json('language') ?? 'unknown';

                // --- 🛑 ANTI-HALLUCINATION FILTER 🛑 ---
                // Linisin ang mga common na iniimbento ng Whisper kapag may dead air
                $hallucinations = [
                    'Salamat sa panonood',
                    'Thank you for watching',
                    'Subtitles by',
                    'Amara.org',
                    'A clear recording',
                    'Please subscribe',
                    'Thank you.',
                    'Salamat.',
                ];
                
                $text = str_ireplace($hallucinations, '', $text);
                $text = trim($text);

                // Kapag napaka-ikli ng transcript (ex. 1-4 words) at puro noise lang, i-drop na natin.
                if (strlen($text) < 5) {
                    Log::info('[Groq] Transcript was too short or just a hallucination.', ['original' => $response->json('text')]);
                    return null; // I-treat as failed transcription para di na i-summarize ni Llama
                }
                // ---------------------------------------

                if ($text !== '') {
                    Log::info('[Groq] Transcript received', [
                        'chars'    => strlen($text),
                        'language' => $language,
                        'preview'  => substr($text, 0, 120),
                    ]);
                    return ['text' => $text, 'language' => $language];
                }

                Log::warning('[Groq] Response successful but text is empty after filtering', [
                    'body' => $response->body(),
                ]);
                return null;
            }

            Log::error('[Groq] Whisper request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;

        } catch (\Throwable $e) {
            Log::error('[Groq] Exception during transcription', [
                'error' => $e->getMessage(),
                'file'  => $localPath,
            ]);
            return null;
        }
    }

    private function summarizeTranscript(string $transcript, int $durationSec, string $language = 'unknown'): string
    {
        $duration     = $durationSec > 0 ? gmdate('i:s', $durationSec) . ' min' : 'unknown duration';
        $isTagalog    = in_array(strtolower($language), ['tl', 'tagalog', 'filipino', 'fil'], true)
                        || str_contains(strtolower($transcript), 'po ')
                        || str_contains(strtolower($transcript), ' po,')
                        || str_contains(strtolower($transcript), ' po.')
                        || preg_match('/\b(opo|kasi|talaga|naman|diba|sige po|yung)\b/i', $transcript);

        $languageNote = $isTagalog
            ? 'The transcript may contain Tagalog, English, or Taglish. Understand both, then summarize in English.'
            : 'Summarize in English.';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->groqApiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(60)->post(self::GROQ_CHAT_ENDPOINT, [
                'model'    => self::GROQ_SUMMARIZE_MODEL,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => 'You are a call summarizer. ' . $languageNote . ' '
                            . 'ALL of the following are FORBIDDEN: '
                            . '(1) saying the call is too short or lacks content; '
                            . '(2) explaining culture, emotions, intent, or social customs; '
                            . '(3) adding ANY word, phrase, or detail NOT literally derived from the transcript; '
                            . '(4) adding sections, bullet points, headers, or action items. '
                            . 'If the transcript is extremely short (e.g. 1-5 simple words like "Hello", "Thank you"), DO NOT create a formal note. '
                            . 'Instead, output a natural 1-sentence summary like: "The caller briefly acknowledged understanding." or "No significant conversation was detected beyond brief greetings/noise." '
                            . 'For longer transcripts, summarize accurately without adding fabricated details. '
                            . 'ONE sentence to ONE short paragraph maximum.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => "Detected language: {$language}\nCall duration: {$duration}\n\nTranscript:\n{$transcript}",
                    ],
                ],
                'max_tokens'  => 150,
                'temperature' => 0.1,   // near-deterministic for maximum literal accuracy
                'stream'      => false,
            ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content')
                    ?? '📋 Meeting notes could not be parsed.';
            }

            Log::warning('[Groq] Summarization failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Groq] Summarization exception', ['error' => $e->getMessage()]);
        }

        return "📋 **Call completed** ({$duration})\n\nTranscript was captured but summarization failed.";
    }
}