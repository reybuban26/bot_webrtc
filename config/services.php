<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ─── Qwen AI (Alibaba DashScope) ──────────────────────────────────────────
    'qwen' => [
        'api_key'     => env('QWEN_API_KEY'),
        'base_url'    => env('QWEN_BASE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions'),
        'model'       => env('QWEN_MODEL', 'qwen3.5-plus'),
        'max_tokens'  => env('QWEN_MAX_TOKENS', 2048),
        'temperature' => env('QWEN_TEMPERATURE', 0.7),
    ],

    // ─── Groq (Whisper audio transcription) ───────────────────────────────────
    // Key from: https://console.groq.com/keys
    'groq' => [
        'api_key'   => env('GROQ_API_KEY'),
        'asr_model' => env('GROQ_ASR_MODEL', 'whisper-large-v3'),
    ],

    // ─── Agora RTC ────────────────────────────────────────────────────────────
    'agora' => [
        'app_id'      => env('AGORA_APP_ID'),
        'certificate' => env('AGORA_APP_CERTIFICATE'),
    ],

];

