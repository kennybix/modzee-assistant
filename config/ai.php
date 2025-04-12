<?php

return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],
    'moderation' => [
        'enabled' => env('AI_MODERATION_ENABLED', true),
        'prohibited_terms' => [
            'offensive', 'inappropriate', 'harmful'
            // Add more terms as needed
        ],
    ],
    'limits' => [
        'monthly_token_limit' => env('MONTHLY_TOKEN_LIMIT', 100000),
        'max_prompt_length' => env('MAX_PROMPT_LENGTH', 4000),
    ],
    'cache' => [
        'enabled' => env('AI_CACHE_ENABLED', true),
        'ttl' => env('AI_CACHE_TTL', 60 * 24), // 24 hours in minutes
    ],
];
