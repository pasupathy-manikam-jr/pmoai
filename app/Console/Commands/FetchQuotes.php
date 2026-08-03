<?php

namespace App\Console\Commands;

use App\Models\MarketQuote;
use App\Services\MarketQuoteService;
use Illuminate\Console\Command;

/**
 * Poll live index / FX / commodity quotes (config/quotes.php) and store the
 * latest per symbol. Run on a schedule (cron) for a near-live dashboard and
 * for index-aware decisions.
 */
class FetchQuotes extends Command
{
    protected $signature = 'pmoai:fetch-quotes {--backfill= : Backfill daily history for this range (e.g. 3mo, 1y) instead of a live poll}';

    protected $description = 'Fetch live market index/FX/commodity quotes into market_quotes (+ daily history).';

    public function handle(MarketQuoteService $svc, \App\Services\PortfolioIndices $portfolio): int
    {
        // Indices are derived from the portfolio's real geographic exposure.
        $indices = $portfolio->derive();
        $symbols = array_column($indices, 'symbol');
        $labels = array_column($indices, 'label', 'symbol');

        // Backfill mode: pull daily close history and stop.
        if ($range = $this->option('backfill')) {
            $rows = 0;
            foreach ($symbols as $symbol) {
                foreach ($svc->history($symbol, $range) as $pt) {
                    \App\Models\MarketQuoteDay::updateOrCreate(
                        ['symbol' => $symbol, 'quote_date' => $pt['date']],
                        ['price' => $pt['close']],
                    );
                    $rows++;
                }
                $this->line("  $symbol backfilled");
            }
            $this->info("Backfilled $rows daily history rows ($range).");

            return self::SUCCESS;
        }

        $quotes = $svc->fetch($symbols);
        if (! $quotes) {
            $this->error('No quotes fetched (network / source issue).');
            return self::FAILURE;
        }

        foreach ($quotes as $symbol => $q) {
            MarketQuote::updateOrCreate(
                ['symbol' => $symbol],
                [
                    'label'      => $labels[$symbol] ?? null,
                    'price'      => $q['price'],
                    'prev_close' => $q['prev_close'],
                    'change_pct' => $q['change_pct'],
                    'currency'   => $q['currency'],
                    'fetched_at' => now(),
                ],
            );

            // Accrue today's point in the daily history store.
            \App\Models\MarketQuoteDay::updateOrCreate(
                ['symbol' => $symbol, 'quote_date' => now()->toDateString()],
                ['price' => $q['price'], 'change_pct' => $q['change_pct']],
            );
            $this->line(sprintf('  %-8s %s (%s%%)', $symbol, number_format($q['price'], 2),
                $q['change_pct'] !== null ? ($q['change_pct'] >= 0 ? '+' : '').$q['change_pct'] : '?'));
        }

        $this->info(count($quotes).' of '.count($symbols).' quotes updated.');

        // New quotes in → evaluate index/market triggers.
        $fired = app(\App\Services\AlertCheck::class)->run();
        if ($fired) {
            $this->warn(count($fired).' alert(s) fired: '.collect($fired)->map->label->implode(', '));
        }

        return self::SUCCESS;
    }
}
