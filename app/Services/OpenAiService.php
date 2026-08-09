<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI chat provider (paid; the API key comes from platform.openai.com — a
 * chatgpt.com subscription does not include API access).
 *
 * No built-in web search: market/geopolitical context must come from the
 * user's pasted notes / feedback, or the model's own knowledge.
 *
 * Subclass and override prefix() to point this same OpenAI-compatible wire
 * format at another provider.
 */
class OpenAiService implements Llm
{
    /** Config key prefix under config('ai.*'). */
    protected function prefix(): string
    {
        return 'openai';
    }

    protected function call(array $payload): array
    {
        $p = $this->prefix();

        $res = Http::withToken(config("ai.{$p}_key"))
            ->asJson()
            ->timeout(120)
            ->post(config("ai.{$p}_chat_url"), array_merge([
                'model' => config("ai.{$p}_model"),
            ], $payload));

        if ($res->failed()) {
            throw new RuntimeException(ucfirst($p).' API failed: '.$res->body());
        }

        return $res->json();
    }

    /** @return array<int, array> arguments of every tool_call with $name */
    private function toolCalls(array $response, string $name): array
    {
        $out = [];
        foreach ($response['choices'][0]['message']['tool_calls'] ?? [] as $tc) {
            if (($tc['function']['name'] ?? null) === $name) {
                $args = json_decode($tc['function']['arguments'] ?? '{}', true);
                if (is_array($args)) {
                    $out[] = $args;
                }
            }
        }

        return $out;
    }

    public function extractFunds(string $rawText): array
    {
        $tool = [
            'type' => 'function',
            'function' => [
                'name' => 'extract_funds',
                'description' => 'Return every unit trust fund found in the pasted page.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'funds' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'fund_type' => ['type' => 'string'],
                                    'shariah' => ['type' => 'boolean'],
                                    'unit_price' => ['type' => ['number', 'null']],
                                    'selling_price' => ['type' => ['number', 'null']],
                                    'return_1y' => ['type' => ['number', 'null']],
                                    'return_3y' => ['type' => ['number', 'null']],
                                    'return_5y' => ['type' => ['number', 'null']],
                                    'currency' => ['type' => 'string'],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                    ],
                    'required' => ['funds'],
                ],
            ],
        ];

        $response = $this->call([
            'tools' => [$tool],
            'tool_choice' => ['type' => 'function', 'function' => ['name' => 'extract_funds']],
            'messages' => [
                ['role' => 'system', 'content' => FundAnalysisPrompt::EXTRACT_SYSTEM],
                ['role' => 'user', 'content' => $rawText],
            ],
        ]);

        return $this->toolCalls($response, 'extract_funds')[0]['funds'] ?? [];
    }

    public function recommend(array $funds, string $feedback, array $recalled = []): array
    {
        $recalledTxt = $recalled ? "\n\nPrior stated preferences:\n- ".implode("\n- ", $recalled) : '';
        $fundsJson = FundAnalysisPrompt::screenerLines($funds);

        $response = $this->call([
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => FundAnalysisPrompt::screenerSystem()],
                ['role' => 'user', 'content' => "User feedback / goals:\n{$feedback}{$recalledTxt}\n\nFunds:\n{$fundsJson}"],
            ],
        ]);

        return FundAnalysisPrompt::parseRecommendations(
            $response['choices'][0]['message']['content'] ?? '{}'
        );
    }

    public function chat(array $fund, array $context, array $history, string $question): string
    {
        $messages = [
            ['role' => 'system', 'content' => FundAnalysisPrompt::chatSystem(false)],
            ['role' => 'user', 'content' => "FUND DATA:\n".FundAnalysisPrompt::user($fund, $context)],
            ['role' => 'assistant', 'content' => 'Understood — I will answer using only the FUND DATA above.'],
        ];
        foreach ($history as $m) {
            $messages[] = [
                'role' => ($m['role'] ?? '') === 'user' ? 'user' : 'assistant',
                'content' => (string) ($m['text'] ?? ''),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $response = $this->call(['messages' => $messages]);
        $content = $response['choices'][0]['message']['content'] ?? '';
        $content = preg_replace('#<think>.*?</think>#s', '', $content);

        return trim($content) ?: 'No answer returned.';
    }

    public function analyzeFund(array $fund, array $context = []): string
    {
        $response = $this->call([
            'messages' => [
                ['role' => 'system', 'content' => FundAnalysisPrompt::system(! empty($context['position']))],
                ['role' => 'user', 'content' => FundAnalysisPrompt::user($fund, $context)],
            ],
        ]);

        $content = $response['choices'][0]['message']['content'] ?? '';
        $content = preg_replace('#<think>.*?</think>#s', '', $content);

        return trim($content) ?: 'No analysis returned.';
    }
}
