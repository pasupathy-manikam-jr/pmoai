<?php

namespace App\Jobs;

use App\Services\Llm;
use App\Services\PortfolioAdvisor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Writes a plain-English narrative of the deterministic advisor plan. The AI
 * only *explains* the screener's grounded picks — it is told not to invent
 * numbers or new funds. Runs off-request (the CLI provider is slow); the page
 * polls the cache for the result. Cache key: advisor_ai.
 */
class AdviseNarrativeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public const KEY = 'advisor_ai';

    public function handle(PortfolioAdvisor $advisor, Llm $llm): void
    {
        try {
            $plan = $advisor->analyze();
            $t1rows = app(\App\Services\ExpectedNav::class)->forHeld()['rows'];
            $brief = app(\App\Services\AdvisorBrief::class)->build($plan, $t1rows, \App\Models\ActionItem::all());
            $planText = app(\App\Services\AdvisorBrief::class)->toText($brief)."\n\nFULL DETAIL (reference only):\n".$advisor->toText($plan);

            $question = "You are my portfolio assistant. Below is TODAY'S BRIEF for my Public Mutual unit-trust book, "
                ."already computed from real figures. Write me 4–6 short bullet lines, each starting with '- ' and a bold "
                ."lead word in **double asterisks**.

RULES — follow strictly:
"
                ."1. Lead with THE ONE THING NOW and why today (use the market/cut-off note if given).
"
                ."2. Then say only what CHANGED since last visit. If nothing changed, say so in one line and stop repeating.
"
                ."3. Never re-suggest anything under ALREADY DONE — acknowledge it in one clause at most.
"
                ."4. Plain everyday English, no jargon (no 'volatility', 'exposure', 'allocation', 'drawdown').
"
                ."5. Use only the numbers given; never invent. No generic advice, no filler, no repetition.
"
                ."6. One final short line noting this is information, not licensed advice. Nothing else.

"
                ."TODAY'S BRIEF:
".$planText;

            $fund = ['name' => 'Whole portfolio', 'fund_type' => 'Portfolio', 'risk' => null];
            $text = trim($llm->chat($fund, [], [], $question));

            Cache::put(self::KEY, [
                'status' => 'done',
                'text'   => $text,
                'at'     => now()->toDateTimeString(),
            ], now()->addHours(12));
        } catch (Throwable $e) {
            Cache::put(self::KEY, [
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 300),
                'at'     => now()->toDateTimeString(),
            ], now()->addHours(1));
            throw $e;
        }
    }
}
