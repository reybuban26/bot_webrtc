<?php

namespace App\Services;

use App\Models\ChatSession;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QwenAiService
{
    private const CHAT_MODEL = 'qwen3.5-plus';
    private const GROQ_ASR_ENDPOINT = 'https://api.groq.com/openai/v1/audio/transcriptions';
    private const GROQ_CHAT_ENDPOINT     = 'https://api.groq.com/openai/v1/chat/completions';
    private const GROQ_SUMMARIZE_MODEL   = 'llama-3.3-70b-versatile';
    private const CHAT_ENDPOINT = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions';
    private string $qwenApiKey;
    private string $groqApiKey;
    private string $groqAsrModel;

    public function __construct()
    {
        $this->qwenApiKey   = config('services.qwen.api_key', '');
        $this->groqApiKey   = config('services.groq.api_key', '');
        $this->groqAsrModel = config('services.groq.asr_model', 'whisper-large-v3-turbo');
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

    public function generateTitle(ChatSession $session): string
    {
        $history = $session->getConversationHistory(4); // Bawasan sa 4 lang
        $historyText = collect($history)
            ->map(fn($m) => strtoupper($m['role']) . ': ' . Str::limit($m['content'], 200))
            ->implode("\n");

        $firstMessage = $session->messages()->where('role', 'user')->oldest()->first();

        if (empty($this->groqApiKey)) {
            return $firstMessage ? $this->fallbackTitle($firstMessage->content) : 'New Chat';
        }

        try {
            $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->groqApiKey,
            'Content-Type'  => 'application/json',
        ])->timeout(15)->post(self::GROQ_CHAT_ENDPOINT, [ // ← Groq na, hindi na DashScope
            'model'       => 'llama-3.3-70b-versatile',
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => "Generate a short, clear chat title based on the user's message. "
                        . "Summarize the intent in 5–8 words, avoid generic titles like 'New Chat'. "
                        . "Output ONLY the raw title. No quotes, no punctuation at the end.\n\n"
                        . "Example: User: 'What is the title inside this PDF?' → Extracting PDF Title",
                ],
                ['role' => 'user', 'content' => "Conversation:\n" . $historyText],
            ],
            'max_tokens'  => 20,
            'temperature' => 0.1,
            'stream'      => false,
        ]);

        if ($response->successful()) {
            $title = trim(trim($response->json('choices.0.message.content') ?? ''), '"\'');
            if ($title && mb_strlen($title) <= 80) {
                return $title;
            }
        }
    } catch (\Throwable $e) {
        Log::warning('[Title] Groq title generation failed', ['error' => $e->getMessage()]);
    }

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
            Log::warning('[Groq] Transcription failed or no speech detected', [
                'path' => $localPath,
                'duration' => $durationSec,
            ]);

            $durationFormatted = $durationSec > 0 ? gmdate('i:s', $durationSec) : 'unknown';
            return "ℹ️ **No Conversation Detected**\n\n"
                . "The call lasted {$durationFormatted} but no significant speech was captured.\n\n"
                . "📎 The recording has been saved for manual review.";
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

    private const WHISPER_PROMPT = '';
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
            ]);

            Log::info('[Groq] Whisper response', [
                'status'   => $response->status(),
                'language' => $response->json('language'),
                'text_raw' => $response->json('text'),
            ]);

            if ($response->successful()) {
                $text     = trim($response->json('text') ?? '');
                $language = $response->json('language') ?? 'unknown';
                $segments = $response->json('segments') ?? [];

                // 🔥 NEW: Check if there's actual speech (segments with >0.5s duration)
                $totalSpeechDuration = 0;
                foreach ($segments as $seg) {
                    $totalSpeechDuration += ($seg['end'] ?? 0) - ($seg['start'] ?? 0);
                }

                Log::info('[Groq] Speech analysis', [
                    'segments_count' => count($segments),
                    'speech_seconds' => round($totalSpeechDuration, 1),
                    'text_length'    => strlen($text),
                ]);

                // 🛑 If total speech is less than 0.5 seconds, it's likely just noise/greeting
                if ($totalSpeechDuration < 0.5 && strlen($text) < 20) {
                    Log::info('[Groq] No significant speech detected — returning null');
                    return null;
                }

                // 🛑 Filter out common hallucinations but DON'T remove legitimate short text
                $hallucinations = [
                    'Salamat sa panonood',
                    'Thank you for watching',
                    'Subtitles by',
                    'Amara.org',
                    'Please subscribe',
                ];

                // Only filter if the text EXACTLY matches or starts with a hallucination
                $lowerText = strtolower($text);
                foreach ($hallucinations as $h) {
                    if (stripos($text, $h) === 0 || strtolower($text) === strtolower($h)) {
                        Log::info('[Groq] Hallucination detected and filtered', ['text' => $text]);
                        return null;
                    }
                }

                // 🔥 NEW: Accept even short transcripts if they have legitimate content
                // Previously we rejected anything < 5 chars. Now we check if it contains actual words.
                if (strlen($text) > 0 && preg_match('/[a-zA-Z]{2,}/', $text)) {
                    Log::info('[Groq] Valid transcript received', [
                        'chars'    => strlen($text),
                        'language' => $language,
                        'preview'  => substr($text, 0, 120),
                    ]);
                    return ['text' => $text, 'language' => $language];
                }

                Log::warning('[Groq] Transcript contains no recognizable words', ['text' => $text]);
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
                        'content' => implode(' ', [
                            'You are a professional call summarizer for a customer support team.',
                            $languageNote,
                            'Your job is to produce clean, structured meeting notes from the transcript below.',
                            'STRICT RULES:',
                            '(1) Only include facts that are EXPLICITLY stated in the transcript — never invent, infer, or embellish.',
                            '(2) Never describe tone, emotions, culture, or social customs.',
                            '(3) Never explain what is missing from the transcript.',
                            'OUTPUT FORMAT — use this exact structure (omit a section only if the transcript has no content for it):',
                            '**Summary**',
                            'One to two sentences describing what the call was about.',
                            '**Key Points**',
                            'Bullet list of the main topics discussed (max 5 bullets).',
                            '**Action Items**',
                            'Bullet list of tasks or follow-ups that were explicitly mentioned. If none, write "None."',
                            '**Duration:** [duration]',
                            'If the transcript is extremely short (1–5 words, just greetings or noise), skip all sections and output one sentence: "No significant conversation detected."',
                        ]),
                    ],
                    [
                        'role'    => 'user',
                        'content' => "Detected language: {$language}\nCall duration: {$duration}\n\nTranscript:\n{$transcript}",
                    ],
                ],
                'max_tokens'  => 500,
                'temperature' => 0.1,
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