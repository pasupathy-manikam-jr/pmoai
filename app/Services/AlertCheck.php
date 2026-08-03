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
            // Index/FX/commodity alert → evaluate against the latest quote;
            // otherwise a fund alert → evaluate against the fund's NAV.
            if ($alert->market_symbol) {
                $price = \App\Models\MarketQuote::where('symbol', $alert->market_symbol)->value('price');
            } else {
                $price = Fund::whereRaw('upper(code) = ?', [strtoupper($alert->fund_code)])
                    ->value('unit_price')
                    ?? \App\Models\FundPrice::whereRaw('upper(code) = ?', [strtoupper($alert->fund_code)])
                        ->orderByDesc('period')->value('price');
            }
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
        $who = $alert->fund_code ?: $alert->market_symbol;
        $dp = $price < 100 ? 4 : 2;   // fund NAVs need 4dp; index levels 2dp
        $title = 'pmoai trigger: '.$who;
        $body = $alert->label.' — '.number_format($price, $dp)
            .' ('.$alert->condition.' '.number_format((float) $alert->level, $dp).')';

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
