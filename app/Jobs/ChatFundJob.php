<?php

namespace App\Jobs;

use App\Models\FundDetail;
use App\Services\FundAnalysis;
use App\Services\Llm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One chat turn about one fund, off the web request (the CLI provider is
 * slow). The user message is already appended to payload.chat; this job
 * appends the assistant reply and clears chat_status.
 */
class ChatFundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $detailId,
        public string $question,
    ) {}

    public function handle(FundAnalysis $analysis, Llm $llm): void
    {
        $detail = FundDetail::findOrFail($this->detailId);

        try {
            $pos = $detail->payload['position'] ?? [];
            [$fund, $context] = $analysis->buildContext(
                $detail,
                $pos['invested'] ?? null,
                $pos['current_value'] ?? null,
            );

            // History = everything before the question this job answers.
            $chat = $detail->payload['chat'] ?? [];
            $history = array_slice($chat, 0, max(0, count($chat) - 1));
            $history = array_slice($history, -10);

            $answer = $llm->chat($fund->toArray(), $context, $history, $this->question);

            $detail->refresh();
            $payload = $detail->payload ?? [];
            $chat = $payload['chat'] ?? [];
            $chat[] = ['role' => 'ai', 'text' => $answer, 'at' => now()->toDateTimeString()];
            $payload['chat'] = array_slice($chat, -20);
            unset($payload['chat_status'], $payload['chat_error']);
            $detail->update(['payload' => $payload]);
        } catch (Throwable $e) {
            $this->markFailed($e);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($e);
    }

    private function markFailed(Throwable $e): void
    {
        $detail = FundDetail::find($this->detailId);
        if (! $detail) {
            return;
        }
        $payload = $detail->payload ?? [];
        unset($payload['chat_status']);
        $payload['chat_error'] = mb_substr($e->getMessage(), 0, 500);
        $detail->update(['payload' => $payload]);
    }
}
