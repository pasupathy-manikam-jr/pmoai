<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Free-tier testing provider. Groq is OpenAI-compatible (function calling).
 * No built-in web search: market/geopolitical context must come from the
 * user's pasted notes / feedback, or the model's own knowledge.
 */
class GroqService implements Llm
{
    /**
     * Config key prefix under config('ai.*'). Subclasses point the same
     * OpenAI-compatible wire format at another provider (see OpenAiService).
     */
    protected function prefix(): string
    {
        return 'groq';
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
            'type'     => 'function',
            'function' => [
                'name'        => 'extract_funds',
                'description' => 'Return every unit trust fund found in the pasted page.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'funds' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'name'          => ['type' => 'string'],
                                    'fund_type'     => ['type' => 'string'],
                                    'shariah'       => ['type' => 'boolean'],
                                    'unit_price'    => ['type' => ['number', 'null']],
                                    'selling_price' => ['type' => ['number', 'null']],
                                    'return_1y'     => ['type' => ['number', 'null']],
                                    'return_3y'     => ['type' => ['number', 'null']],
                                    'return_5y'     => ['type' => ['number', 'null']],
                                    'currency'      => ['type' => 'string'],
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
            'tools'       => [$tool],
            'tool_choice' => ['type' => 'function', 'function' => ['name' => 'extract_funds']],
            'messages'    => [
                ['role' => 'system', 'content' => 'Extract Malaysian Public Mutual funds. Numbers only, strip symbols and % signs. Unknown fields => null.'],
                ['role' => 'user', 'content' => $rawText],
            ],
        ]);

        return $this->toolCalls($response, 'extract_funds')[0]['funds'] ?? [];
    }

    public function recommend(array $funds, string $feedback, array $recalled = []): array
    {
        // Free-tier TPM caps the payload to ~35 lines. OWNED funds always go
        // in (they need a keep/sell verdict). The rest is a mixed shortlist:
        // ~60% top 3Y performers plus steadier 5Y compounders, so BUY
        // candidates aren't exclusively peaked flyers that all end up
        // tagged EXTENDED/AVOID.
        $all = collect($funds);
        $owned = $all->filter(fn ($f) => $f['owned'] ?? false)->values();
        $pool = $all->reject(fn ($f) => $f['owned'] ?? false)
            ->sortByDesc(fn ($f) => $f['return_3y'] ?? $f['return_1y'] ?? -999)
            ->values();
        $slots = max(0, 35 - $owned->count());
        $top = $pool->take(intdiv($slots * 3, 5));
        $mid = $pool->slice($top->count())
            ->sortByDesc(fn ($f) => $f['return_5y'] ?? -999)
            ->take($slots - $top->count());
        $funds = $owned->concat($top)->concat($mid)->values()->all();

        // Compact one line per fund + a derived peak-risk tag. With no price
        // history, a 1Y return far above the long-run annualised rate means
        // the fund already ran up (price near its high) -> mean-reversion risk.
        $fundsJson = collect($funds)->map(function ($f) {
            $r1 = is_numeric($f['return_1y'] ?? null) ? (float) $f['return_1y'] : null;
            $r5 = is_numeric($f['return_5y'] ?? null) ? (float) $f['return_5y'] : null;
            $ann5 = $r5 !== null ? $r5 / 5 : null;          // rough annualised 5Y
            $tag = 'NORMAL';
            $hd = (int) ($f['hist_days'] ?? 0);
            if ($hd >= 5 && array_key_exists('near_high', $f) && $f['near_high'] !== null) {
                // Real accumulated price history available — use it.
                $off = $f['pct_below_peak'];
                $tag = $f['near_high']
                    ? "NEAR-HIGH(at/near {$hd}d peak, {$off}% below peak)"
                    : "OFF-PEAK({$off}% below {$hd}d peak)";
            } elseif ($r1 !== null && ($r1 > 60 || ($ann5 !== null && $ann5 > 0 && $r1 > 3 * $ann5))) {
                $tag = 'EXTENDED(1Y≫long-run,price likely near high)';
            } elseif ($r1 !== null && $r5 !== null && $r1 < $ann5) {
                $tag = 'LAGGING';
            } elseif ($r5 !== null && $ann5 >= 6 && $r1 !== null && $r1 <= 2 * $ann5) {
                $tag = 'STEADY';
            }

            return implode(' | ', array_values(array_filter([
                $f['code'] ?? '??',
                $f['name'],
                ($f['shariah'] ?? false) ? 'S' : '-',
                ($f['owned'] ?? false) ? 'OWNED' : null,
                '1Y '.($f['return_1y'] ?? 'na'),
                '3Y '.($f['return_3y'] ?? 'na'),
                '5Y '.($f['return_5y'] ?? 'na'),
                $tag,
            ], fn ($v) => $v !== null)));
        })->implode("\n");
        $recalledTxt = $recalled ? "\n\nPrior stated preferences:\n- ".implode("\n- ", $recalled) : '';

        $system = 'You are a CONSERVATIVE screener for the PUBLIC list of Malaysian Public Mutual '
            .'unit trust funds. Each line: '
            .'CODE | name | S=Shariah(else -) | OWNED(only if the user holds it) | '
            .'1Y/3Y/5Y trailing return % | TAG. "na" = not provided. TAG meanings: '
            .'NEAR-HIGH = REAL accumulated price history shows price is at/near its peak — '
            .'strongest buy-the-top warning, trust this over returns. '
            .'OFF-PEAK = real history shows price is well below its peak (X% below). '
            .'EXTENDED = no real history yet, but 1Y far above long-run rate so price likely '
            .'already ran up. STEADY = durable multi-year compounding. LAGGING = weak. '
            .'NORMAL = nothing flagged. Actions: '
            .'For funds NOT marked OWNED: BUY = attractive entry NOW (STEADY/NORMAL/OFF-PEAK, '
            .'fits goals); WATCH = quality but NEAR-HIGH / EXTENDED or unclear — wait for a '
            .'pullback, do not buy now; AVOID = weak returns or poor fit with goals. '
            .'For funds marked OWNED (the user currently holds them): action MUST be '
            .'KEEP or SELL — SELL if LAGGING / weak returns / poor fit, or NEAR-HIGH where '
            .'locking in the gain is prudent for the user\'s goals; KEEP otherwise. Every '
            .'OWNED fund MUST get a recommendation. '
            .'HARD RULES: (1) NEAR-HIGH or EXTENDED funds can NEVER be BUY — mark WATCH and say '
            .'price is at/near its high. (2) A very high 1Y return is a RISK signal, not a buy '
            .'reason. (3) Prefer STEADY/OFF-PEAK funds aligned to goals. (4) "na" returns => say '
            .'data unavailable, do not invent history. (5) You have NO web access and NO '
            .'knowledge of current markets or news. NEVER cite market conditions, economic '
            .'events, news, wars, elections, or interest rates. Justify ONLY from the data '
            .'lines and the user\'s stated goals. (6) Every number in a rationale must be '
            .'copied EXACTLY from that fund\'s data line — never compute or estimate new '
            .'numbers. Respect Shariah if stated. '
            .'Select all OWNED funds plus the 10-15 most relevant others. '
            .'Return ONLY a JSON object, no prose, exactly: '
            .'{"recommendations":[{"fund_code":"<CODE copied from the line>",'
            .'"fund_name":"<exact name from the list>",'
            .'"action":"buy|watch|avoid|keep|sell","target_weight":<number 0-100>,'
            .'"rationale":"<why, state the TAG>"}]}';

        $response = $this->call([
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system."\n/no_think"],
                ['role' => 'user', 'content' => "User feedback / goals:\n{$feedback}{$recalledTxt}\n\nFunds:\n{$fundsJson}\n/no_think"],
            ],
        ]);

        $content = $response['choices'][0]['message']['content'] ?? '{}';
        $content = preg_replace('#<think>.*?</think>#s', '', $content);
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $content = $m[0];
        }
        $data = json_decode(trim($content), true);
        $recs = $data['recommendations'] ?? (is_array($data) ? $data : []);

        return collect($recs)
            ->filter(fn ($r) => (! empty($r['fund_name']) || ! empty($r['fund_code'])) && ! empty($r['action']))
            ->map(fn ($r) => [
                'fund_code'     => isset($r['fund_code']) ? (string) $r['fund_code'] : null,
                'fund_name'     => (string) ($r['fund_name'] ?? ''),
                'action'        => strtolower(trim((string) $r['action'])),
                'target_weight' => $r['target_weight'] ?? null,
                'rationale'     => (string) ($r['rationale'] ?? ''),
            ])->values()->all();
    }

    public function chat(array $fund, array $context, array $history, string $question): string
    {
        $messages = [
            ['role' => 'system', 'content' => FundAnalysisPrompt::chatSystem(false)."\n/no_think"],
            ['role' => 'user', 'content' => "FUND DATA:\n".FundAnalysisPrompt::user($fund, $context)],
            ['role' => 'assistant', 'content' => 'Understood — I will answer using only the FUND DATA above.'],
        ];
        foreach ($history as $m) {
            $messages[] = [
                'role'    => ($m['role'] ?? '') === 'user' ? 'user' : 'assistant',
                'content' => (string) ($m['text'] ?? ''),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $question."\n/no_think"];

        $response = $this->call(['messages' => $messages]);
        $content = $response['choices'][0]['message']['content'] ?? '';
        $content = preg_replace('#<think>.*?</think>#s', '', $content);

        return trim($content) ?: 'No answer returned.';
    }

    public function analyzeFund(array $fund, array $context = []): string
    {
        $response = $this->call([
            'messages' => [
                ['role' => 'system', 'content' => FundAnalysisPrompt::system(! empty($context['position']))."\n/no_think"],
                ['role' => 'user', 'content' => FundAnalysisPrompt::user($fund, $context)."\n/no_think"],
            ],
        ]);

        $content = $response['choices'][0]['message']['content'] ?? '';
        $content = preg_replace('#<think>.*?</think>#s', '', $content);

        return trim($content) ?: 'No analysis returned.';
    }
}
