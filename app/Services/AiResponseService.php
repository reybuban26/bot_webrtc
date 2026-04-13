<?php

namespace App\Services;

use App\Models\SupportThread;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiResponseService
{
    private string $qwenApiKey;
    private const CHAT_ENDPOINT = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions';
    private const CHAT_MODEL = 'qwen3.5-plus';
    private const ESCALATION_THRESHOLD = 0.6;

    public function __construct()
    {
        $this->qwenApiKey = config('services.qwen.api_key', '');
    }

    /**
     * Handle user message with Qwen API
     * Returns: {response, confidence, should_escalate}
     */
    public function generateResponse(SupportThread $thread, string $userMessage, array $history): array
    {
        if (empty($this->qwenApiKey)) {
            return [
                'response' => '⚠️ AI service not configured. Please connect with a human agent.',
                'confidence' => 0.0,
                'should_escalate' => true,
            ];
        }

        $systemPrompt = "You are a helpful customer support agent. Answer questions concisely and professionally. "
            . "If you cannot help or are unsure, politely indicate that a human agent is needed. "
            . "Always be friendly and professional.";

        // Build conversation context
        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
        ];

        // Add conversation history
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $msg['content'],
            ];
        }

        // Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        try {
            $payload = [
                'model' => self::CHAT_MODEL,
                'messages' => $messages,
                'max_tokens' => (int) config('services.qwen.max_tokens', 500),
                'temperature' => (float) config('services.qwen.temperature', 0.7),
                'stream' => false,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->qwenApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post(self::CHAT_ENDPOINT, $payload);

            if ($response->successful()) {
                $responseText = $response->json('choices.0.message.content') ?? '';
                $confidence = $this->calculateConfidence($responseText, $userMessage);

                Log::info('[Support AI] Generated response', [
                    'thread_id' => $thread->id,
                    'confidence' => $confidence,
                    'response_length' => strlen($responseText),
                ]);

                return [
                    'response' => $responseText,
                    'confidence' => $confidence,
                    'should_escalate' => $confidence < self::ESCALATION_THRESHOLD || $this->containsEscalationIndicators($responseText),
                ];
            }

            $error = $response->json('error.message') ?? $response->body();
            Log::error('[Support AI] API error', [
                'thread_id' => $thread->id,
                'status' => $response->status(),
                'error' => $error,
            ]);

            return [
                'response' => '⚠️ AI service error. Connecting you with a human agent…',
                'confidence' => 0.0,
                'should_escalate' => true,
            ];

        } catch (\Throwable $e) {
            Log::error('[Support AI] Exception', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'response' => '⚠️ Could not reach the AI service. Connecting you with a human agent…',
                'confidence' => 0.0,
                'should_escalate' => true,
            ];
        }
    }

    /**
     * Check if user message contains explicit escalation keywords
     */
    public function userRequestsEscalation(string $message): bool
    {
        $keywords = [
            'agent', 'human', 'representative', 'talk to someone',
            'speak to', 'person', 'real person', 'escalate', 'manager',
            'supervisor', 'support team', 'customer service', 'tao', 'tao na',
        ];
        $lower = strtolower($message);
        foreach ($keywords as $keyword) {
            if (stripos($lower, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Calculate confidence score based on response quality heuristics
     */
    private function calculateConfidence(string $response, string $userMessage): float
    {
        // Heuristics:
        // - Very short response (< 50 chars) = low confidence (0.3)
        // - Medium response (50-200 chars) = medium confidence (0.7)
        // - Long, detailed response (> 200 chars) = high confidence (0.85)
        // - Presence of "sorry", "don't know", "can't help" = reduce by 0.2

        $length = strlen($response);
        $confidence = 0.5; // baseline

        if ($length < 50) {
            $confidence = 0.3;
        } elseif ($length > 200) {
            $confidence = 0.85;
        } else {
            $confidence = 0.7;
        }

        // Reduce confidence if response contains uncertainty markers
        $uncertaintyMarkers = ['sorry', "don't know", "can't help", 'unclear', 'not sure', 'i\'m unable', 'unable to', 'hindi ako sure'];
        foreach ($uncertaintyMarkers as $marker) {
            if (stripos($response, $marker) !== false) {
                $confidence -= 0.2;
            }
        }

        return max(0.1, min(1.0, $confidence));
    }

    private function containsEscalationIndicators(string $text): bool
    {
        return stripos($text, 'escalate') !== false ||
               stripos($text, 'human agent') !== false ||
               stripos($text, 'need human') !== false ||
               stripos($text, 'speak to agent') !== false;
    }
}
