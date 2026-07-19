<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeService implements Llm
{
    private function call(array $payload): array
    {
        $res = Http::withHeaders([
            'x-api-key'         => config('ai.anthropic_key'),
            'anthropic-version' => config('ai.anthropic_ver'),
            'content-type'      => 'application/json',
        ])->timeout(120)->post(config('ai.anthropic_url'), array_merge([
            'model'      => config('ai.anthropic_model'),
            'max_tokens' => 4096,
        ], $payload));

        if ($res->failed()) {
            throw new RuntimeException('Claude API failed: '.$res->body());
        }

        return $res->json();
    }

    /** Pull the input of the first tool_use block with the given name. */
    private function toolInput(array $response, string $name): ?array
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $name) {
                return $block['input'];
            }
        }
        return null;
    }

    /**
     * Extract structured fund rows from a pasted Public Mutual price page.
     *
     * @return array<int, array<string, mixed>>  rows for the funds table
     */
    public function extractFunds(string $rawText): array
    {
        $tool = [
            'name'        => 'extract_funds',
            'description' => 'Return every unit trust fund found in the pasted page.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'funds' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'name'          => ['type' => 'string'],
                                'fund_type'     => ['type' => 'string', 'description' => 'equity, balanced, bond, sukuk, mixed, etc.'],
                                'shariah'       => ['type' => 'boolean', 'description' => 'true if Islamic/Shariah-compliant fund'],
                                'unit_price'    => ['type' => ['number', 'null']],
                                'selling_price' => ['type' => ['number', 'null']],
                                'return_1y'     => ['type' => ['number', 'null'], 'description' => 'percent'],
                                'return_3y'     => ['type' => ['number', 'null'], 'description' => 'percent'],
                                'return_5y'     => ['type' => ['number', 'null'], 'description' => 'percent'],
                                'currency'      => ['type' => 'string'],
                            ],
                            'required' => ['name'],
                        ],
                    ],
                ],
                'required' => ['funds'],
            ],
        ];

        $response = $this->call([
            'tools'       => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => 'extract_funds'],
            'messages'    => [[
                'role'    => 'user',
                'content' => "Extract every fund from this Public Mutual (Malaysia) price page. "
                    ."Numbers only, strip currency symbols and % signs. Unknown fields => null.\n\n"
                    .$rawText,
            ]],
        ]);

        return $this->toolInput($response, 'extract_funds')['funds'] ?? [];
    }

    /**
     * Reason over the fund table + user feedback + recalled context.
     * Claude uses web search for live market/geopolitical context, then
     * returns one structured recommendation per fund via recommend_action.
     *
     * @param  array<int, array>  $funds      extracted fund rows
     * @param  string             $feedback   user goals/constraints
     * @param  string[]           $recalled   prior feedback recalled via pgvector
     * @return array<int, array<string, mixed>>
     */
    public function recommend(array $funds, string $feedback, array $recalled = []): array
    {
        $recommendTool = [
            'name'        => 'recommend_action',
            'description' => 'Record the recommendation for ONE fund. Call once per fund.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'fund_name'     => ['type' => 'string'],
                    'action'        => ['type' => 'string', 'enum' => ['buy', 'watch', 'avoid', 'BUY', 'WATCH', 'AVOID']],
                    'target_weight' => ['type' => 'number', 'description' => 'suggested portfolio % 0-100'],
                    'rationale'     => ['type' => 'string', 'description' => 'tie to returns + current market/geopolitical context'],
                ],
                'required' => ['fund_name', 'action', 'rationale'],
            ],
        ];

        $webSearch = [
            'type'     => 'web_search_20250305',
            'name'     => 'web_search',
            'max_uses' => 5,
        ];

        $fundsJson = collect($funds)->map(fn ($f) => implode(' | ', [
            $f['name'],
            $f['fund_type'] ?? '?',
            ($f['shariah'] ?? false) ? 'Shariah' : '-',
            'px '.($f['unit_price'] ?? '?'),
            'd '.($f['extra']['change_pct'] ?? '?').'%',
            'YTD '.($f['return_ytd'] ?? 'na'),
            '1Y '.($f['return_1y'] ?? 'na'),
            '3Y '.($f['return_3y'] ?? 'na'),
            '5Y '.($f['return_5y'] ?? 'na'),
            '10Y '.($f['return_10y'] ?? 'na'),
            $f['perf_class'] ?? '',
        ]))->implode("\n");
        $recalledTxt = $recalled ? "\n\nPrior stated preferences:\n- ".implode("\n- ", $recalled) : '';

        $system = "You are a CONSERVATIVE screener for the PUBLIC Malaysian Public Mutual fund "
            ."list. NOT portfolio advice — you do NOT know which funds the user owns; you only "
            ."screen the public universe. Use web search for CURRENT market trends, Bursa "
            ."Malaysia, MYR, China/regional outlook, wars/geopolitical shocks. Each line: name | "
            ."type | Shariah | px | YTD/1Y/3Y/5Y/10Y trailing % | risk class. 'na' = not "
            ."provided; never invent history. No price history exists — px alone is not "
            ."cheap/expensive. Treat 1Y far above 5Y-annualised as EXTENDED (price ran up, near "
            ."a high). Action: BUY = attractive entry now (steady, not extended, fits goals); "
            ."WATCH = quality but extended/near a high or unclear — wait, do not buy now; "
            ."AVOID = weak or poor fit. EXTENDED can never be BUY. High 1Y is a risk signal not "
            ."a buy reason. Select 10-15 MOST relevant, recommend_action once each, respect "
            ."Shariah if stated, end with a one-paragraph summary. Informational screen, not "
            ."licensed financial advice.";

        $response = $this->call([
            'system'   => $system,
            'tools'    => [$webSearch, $recommendTool],
            'messages' => [[
                'role'    => 'user',
                'content' => "User feedback / goals:\n{$feedback}{$recalledTxt}\n\nFunds:\n{$fundsJson}",
            ]],
        ]);

        $recs = [];
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'recommend_action') {
                $recs[] = $block['input'];
            }
        }
        return $recs;
    }

    public function chat(array $fund, array $context, array $history, string $question): string
    {
        $messages = [
            ['role' => 'user', 'content' => "FUND DATA:\n".FundAnalysisPrompt::user($fund, $context)],
            ['role' => 'assistant', 'content' => 'Understood — I will answer using only the FUND DATA above.'],
        ];
        foreach ($history as $m) {
            $messages[] = [
                'role'    => ($m['role'] ?? '') === 'user' ? 'user' : 'assistant',
                'content' => (string) ($m['text'] ?? ''),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $response = $this->call([
            'max_tokens' => 1024,
            'system'     => FundAnalysisPrompt::chatSystem(false),
            'messages'   => $messages,
        ]);

        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            }
        }

        return trim($text) ?: 'No answer returned.';
    }

    public function analyzeFund(array $fund, array $context = []): string
    {
        $response = $this->call([
            'max_tokens' => 1400,
            'system'     => FundAnalysisPrompt::system(! empty($context['position'])),
            'messages'   => [[
                'role'    => 'user',
                'content' => FundAnalysisPrompt::user($fund, $context),
            ]],
        ]);

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                return trim($block['text']);
            }
        }

        return 'No analysis returned.';
    }
}
