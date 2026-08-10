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
            $planText = $advisor->toText($plan);

            $question = "Below are automated screener suggestions for my Public Mutual unit-trust portfolio, "
                ."each already backed by real fund numbers (3-year return, risk, category) and the real Public "
                ."Mutual switch rules.\n\n"
                ."Write a plain-English summary for a non-expert. FORMAT STRICTLY as 4–6 short bullet points, "
                ."each on its own line starting with '- ', one idea per bullet, one or two sentences each, no jargon. "
                ."Start each bullet with a bold lead word in **double asterisks** (e.g. **Concentration:**, **Cash:**, "
                ."**Switch:**, **Diversify:**, **Timing:**). Keep numbers exactly as given — do NOT invent any numbers "
                ."or funds. After the bullets add one final line starting with '- ' that reminds this is past-performance "
                ."information, not licensed financial advice.\n\n"
                ."SUGGESTIONS:\n".$planText;

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
