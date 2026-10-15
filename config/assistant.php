<?php

return [
    'provider' => env('ASSISTANT_PROVIDER', 'openai'),
    'gemini_key' => env('GEMINI_API_KEY'),
    'gemini_model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
    'key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-5.4-mini'),
    // Must be the SAME primary connection used by application writes and proposals.
    'connection' => env('ASSISTANT_DB_CONNECTION', 'mysql'),
    'middleware' => ['web', 'auth'],
    'resources' => [
        // Example only: adapt this allowlist to your application's domain.
        'projects' => [
            'table' => 'projects',
            'columns' => ['id', 'name', 'status'],
            'rules' => ['name' => 'required|string|max:120', 'status' => 'required|in:planned,active,completed'],
        ],
    ],
];
