<?php

namespace App\Services;

/**
 * OpenAI chat provider (paid, needs an API key from platform.openai.com —
 * a chatgpt.com subscription does not include API access). The wire format
 * is OpenAI's own, so everything is inherited from GroqService; only the
 * config prefix differs.
 */
class OpenAiService extends GroqService
{
    protected function prefix(): string
    {
        return 'openai';
    }
}
