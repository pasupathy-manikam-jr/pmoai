<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\FundDetail;
use App\Services\FundAnalysis;
use Illuminate\Console\Command;

/**
 * Auto-arm price triggers for held funds from their captured price structure:
 * a buy-below-the-low level and a breakout-above-the-high level. Deterministic
 * (no LLM) and idempotent — skips a fund that already has an active trigger in
 * that direction, so it fills gaps without duplicating hand-curated alerts.
 * PRS (retirement) and money-market funds are skipped.
 */
class GenTriggers extends Command
{
    protected $signature = 'pmoai:gen-triggers {--force : Re-arm even if an active trigger already exists}';

    protected $description = 'Auto-generate armed price triggers for held funds from their captured high/low.';

    public function handle(FundAnalysis $analysis): int
    {
        $held = FundDetail::whereRaw("payload->'position'->>'current_value' is not null")->get();
        $made = 0;

        foreach ($held as $d) {
            [$code, $hist, $fund] = $analysis->resolve($d);
            if (! $code || $hist->isEmpty()) {
                continue;
            }
            // resolve() uppercases; use the catalog's canonical casing so
            // triggers don't split from hand-curated mixed-case ones (PeEMAS).
            $code = $fund?->code ?: $code;
            $name = $fund?->name ?? $d->name;
            if (preg_match('/PRS|CASH|MONEY MARKET/i', $name)) {
                continue;   // retirement-locked or cash — no actionable trigger
            }

            $prices = $hist->pluck('price');
            $lo = round((float) $prices->min(), 4);
            $hi = round((float) $prices->max(), 4);
            $short = trim(preg_replace('/^PUBLIC\s+/i', '', $name));

            $made += $this->arm($code, 'below', $lo,
                "{$short}: below captured low ({$lo}) — pullback / lower-price level");
            $made += $this->arm($code, 'above', $hi,
                "{$short}: above captured high ({$hi}) — breakout / recovery level");
        }

        $this->info("$made trigger(s) armed.");
        return self::SUCCESS;
    }

    private function arm(string $code, string $condition, float $level, string $label): int
    {
        $exists = Alert::whereRaw('upper(fund_code) = ?', [strtoupper($code)])
            ->where('condition', $condition)
            ->where('active', true)
            ->whereNull('fired_at')
            ->exists();
        if ($exists && ! $this->option('force')) {
            return 0;
        }

        Alert::updateOrCreate(
            ['fund_code' => $code, 'condition' => $condition, 'level' => $level],
            ['label' => $label, 'active' => true, 'fired_at' => null,
                'explanation' => 'Auto-armed from captured price range; review before acting.'],
        );

        $this->line("  armed {$code} {$condition} {$level}");

        return 1;
    }
}
