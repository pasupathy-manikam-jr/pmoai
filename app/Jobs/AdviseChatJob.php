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
 * One chat turn about the advisor plan. The AI answers using the screener's
 * real figures + the running conversation — grounded, no invented numbers.
 * Runs off-request (slow provider); the page polls the cache. Key: advisor_chat.
 */
class AdviseChatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public const KEY = 'advisor_chat';

    public function handle(PortfolioAdvisor $advisor, Llm $llm): void
    {
        $state = Cache::get(self::KEY, ['status' => 'idle', 'messages' => []]);
        $history = $state['messages'] ?? [];

        try {
            $planText = $advisor->toText($advisor->analyze());
            $userMsg = end($history)['text'] ?? '';

            // Prior turns as readable transcript (the chat() context slot only
            // renders known fund fields, so everything must go in the question).
            $convo = '';
            foreach (array_slice($history, 0, -1) as $m) {
                $convo .= (($m['role'] ?? '') === 'user' ? 'ME' : 'YOU').': '.($m['text'] ?? '')."\n";
            }

            $question = "You are helping me understand suggestions about MY Public Mutual unit-trust portfolio. "
                ."The real figures for MY funds are right here — use them, and never say numbers are unavailable.\n\n"
                ."MY PORTFOLIO & THE SUGGESTIONS (real captured figures):\n".$planText."\n\n"
                .($convo !== '' ? "CONVERSATION SO FAR:\n".$convo."\n" : '')
                ."ANSWER THIS in SIMPLE everyday English for someone with NO finance background — short, and NO "
                ."jargon (never use words like beta, idiosyncratic, mean-reversion, volatility, alpha, drawdown, "
                ."opportunity cost, correlation; explain in plain words instead). Use only the figures above; "
                ."don't invent funds or numbers. It's information to weigh, not licensed advice.\n\n"
                ."MY QUESTION: ".$userMsg;

            $fund = ['name' => 'My portfolio', 'fund_type' => 'Portfolio', 'risk' => null];
            $reply = trim($llm->chat($fund, [], [], $question));

            $history[] = ['role' => 'assistant', 'text' => $reply, 'at' => now()->toDateTimeString()];
            Cache::put(self::KEY, ['status' => 'idle', 'messages' => array_slice($history, -20)], now()->addHours(12));
        } catch (Throwable $e) {
            $history[] = ['role' => 'assistant', 'text' => '⚠ Could not answer: '.mb_substr($e->getMessage(), 0, 200), 'at' => now()->toDateTimeString()];
            Cache::put(self::KEY, ['status' => 'idle', 'messages' => array_slice($history, -20)], now()->addHours(12));
            throw $e;
        }
    }
}
