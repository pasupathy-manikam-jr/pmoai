<?php

namespace App\Jobs;

use App\Models\FundDetail;
use App\Services\FundAnalysis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs the single-fund AI analysis off the web request. The CLI provider
 * takes 1-2 minutes, which outlives MAMP's 30s FastCGI idle timeout —
 * so the click only queues; this job does the slow part.
 */
class AnalyzeFundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $detailId,
        public ?float $invested = null,
        public ?float $currentValue = null,
    ) {}

    public function handle(FundAnalysis $analysis): void
    {
        $detail = FundDetail::findOrFail($this->detailId);

        try {
            $analysis->run($detail, $this->invested, $this->currentValue);
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
        unset($payload['ai_status']);
        $payload['ai_error'] = mb_substr($e->getMessage(), 0, 500);
        $detail->update(['payload' => $payload]);
    }
}
