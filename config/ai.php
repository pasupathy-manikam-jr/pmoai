<?php

return [

    // --- Userscript ingest auth ---
    'ingest_token' => env('PMOAI_INGEST_TOKEN'),

    // --- pdftotext binary (PHP-FPM PATH lacks homebrew; auto-detected if unset) ---
    'pdftotext_bin' => env('PMOAI_PDFTOTEXT_BIN'),

    // --- Self-healing queue worker (dev: no supervisor) ---
    // CLI php path. Under PHP-FPM, PHP_BINARY is the fpm/cgi binary and
    // cannot run artisan, so set PMOAI_PHP_BIN to the real php-cli.
    'queue_php_bin' => env('PMOAI_PHP_BIN', PHP_BINARY),

    // --- LLM provider: claude-cli (subscription) | openai (paid) | anthropic (paid) ---
    'llm_provider' => env('LLM_PROVIDER', 'claude-cli'),

    // --- OpenAI chat (paid; API key from platform.openai.com, NOT a ChatGPT sub) ---
    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'openai_chat_url' => 'https://api.openai.com/v1/chat/completions',

    // --- Claude Code CLI (headless `claude -p`; billed to the Claude
    //     subscription, no API credits; handles every LLM surface) ---
    'claude_bin' => env('PMOAI_CLAUDE_BIN', '/Users/pashupati/.local/bin/claude'),
    'claude_home' => env('PMOAI_CLAUDE_HOME'),
    'claude_token' => env('PMOAI_CLAUDE_TOKEN'), // from `claude setup-token` (web/FPM needs it — no Keychain there)

    // --- Anthropic (reasoning + extraction) ---
    'anthropic_key' => env('ANTHROPIC_API_KEY'),
    'anthropic_model' => env('ANTHROPIC_MODEL', 'claude-opus-4-7'),
    'anthropic_url' => 'https://api.anthropic.com/v1/messages',
    'anthropic_ver' => '2023-06-01',

    // --- Embeddings ---
    'embed_provider' => env('EMBED_PROVIDER', 'ollama'), // ollama|voyage|openai
    'embed_model' => env('EMBED_MODEL', 'mxbai-embed-large'),
    'embed_dim' => (int) env('EMBED_DIM', 1024),

    'ollama_url' => env('OLLAMA_URL', 'http://localhost:11434'),

    'voyage_key' => env('VOYAGE_API_KEY'),
    'voyage_url' => 'https://api.voyageai.com/v1/embeddings',

    'openai_key' => env('OPENAI_API_KEY'),
    'openai_url' => 'https://api.openai.com/v1/embeddings',
];
