<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // "Ask Your Shop". The key lives only in the backend's .env (never in the frontend).
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'qwen/qwen3.8-27b'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1/chat/completions'),
        'timeout' => (int) env('GROQ_TIMEOUT', 45),
        // Keep this business assistant in Qwen's normal instruction mode. Hidden reasoning avoids
        // returning internal reasoning content while preserving OpenAI-compatible tool calls.
        'reasoning_effort' => env('GROQ_REASONING_EFFORT', 'none'),
        'reasoning_format' => env('GROQ_REASONING_FORMAT', 'hidden'),
        // Questions one shop may ask per day, so a runaway script can't run up the bill.
        'daily_limit' => (int) env('ASK_DAILY_LIMIT', 200),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
