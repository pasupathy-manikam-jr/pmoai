<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Fund;
use Symfony\Component\Process\Process;

/**
 * Evaluates active price triggers against the latest catalog prices.
 * Called after every price write (fund-list capture, holdings capture).
 * A fired alert stays fired (fired_at set) so it notifies exactly once;
 * re-arm by clearing fired_at.
 */
class AlertCheck
{
    /** @return array<int, Alert> alerts fired in this run */
    public function run(): array
    {
        $fired = [];

        foreach (Alert::where('active', true)->whereNull('fired_at')->get() as $alert) {
            $price = Fund::whereRaw('upper(code) = ?', [strtoupper($alert->fund_code)])
                ->value('unit_price')
                ?? \App\Models\FundPrice::whereRaw('upper(code) = ?', [strtoupper($alert->fund_code)])
                    ->orderByDesc('period')->value('price');
            if ($price === null) {
                continue;
            }
            $price = (float) $price;

            $hit = $alert->condition === 'below'
                ? $price <= (float) $alert->level
                : $price >= (float) $alert->level;
            if (! $hit) {
                continue;
            }

            $alert->update(['fired_at' => now(), 'fired_price' => $price]);
            $this->notify($alert, $price);
            $fired[] = $alert;
        }

        return $fired;
    }

    private function notify(Alert $alert, float $price): void
    {
        $title = 'pmoai trigger: '.$alert->fund_code;
        $body = $alert->label.' — price '.number_format($price, 4)
            .' ('.$alert->condition.' '.number_format((float) $alert->level, 4).')';

        // macOS notification; fire-and-forget, never let it break ingest.
        try {
            $p = new Process([
                '/usr/bin/osascript', '-e',
                'display notification '.escapeshellarg($body).' with title '.escapeshellarg($title).' sound name "Glass"',
            ], null, ['HOME' => posix_getpwuid(posix_geteuid())['dir'] ?? '/tmp'], null, 10);
            $p->run();
        } catch (\Throwable) {
            // banner in the app still shows it
        }
    }
}
