<?php

namespace Tests\Feature;

use App\Services\MarketQuoteService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketQuoteServiceTest extends TestCase
{
    private function yahoo(float $price, ?float $prev, string $ccy = 'USD'): array
    {
        return ['chart' => ['result' => [['meta' => array_filter([
            'regularMarketPrice' => $price,
            'chartPreviousClose' => $prev,
            'currency'           => $ccy,
        ], fn ($v) => $v !== null)]]]];
    }

    public function test_parses_price_prev_and_change_percent(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response($this->yahoo(110.0, 100.0, 'USD')),
        ]);

        $out = app(MarketQuoteService::class)->fetch(['^TEST']);

        $this->assertArrayHasKey('^TEST', $out);
        $this->assertSame(110.0, $out['^TEST']['price']);
        $this->assertSame(100.0, $out['^TEST']['prev_close']);
        $this->assertEqualsWithDelta(10.0, $out['^TEST']['change_pct'], 0.001);
        $this->assertSame('USD', $out['^TEST']['currency']);
    }

    public function test_negative_change_is_computed(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response($this->yahoo(96.0, 100.0)),
        ]);

        $out = app(MarketQuoteService::class)->fetch(['^DOWN']);

        $this->assertEqualsWithDelta(-4.0, $out['^DOWN']['change_pct'], 0.001);
    }

    public function test_missing_price_is_skipped_not_fatal(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => [['meta' => ['currency' => 'USD']]]]]),
        ]);

        $out = app(MarketQuoteService::class)->fetch(['^EMPTY']);

        $this->assertArrayNotHasKey('^EMPTY', $out);
    }

    public function test_http_failure_is_skipped_not_fatal(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response(null, 500),
        ]);

        $out = app(MarketQuoteService::class)->fetch(['^BOOM']);

        $this->assertSame([], $out);
    }

    public function test_twelvedata_fallback_used_when_yahoo_fails(): void
    {
        config(['services.twelvedata.key' => 'test-key']);
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response(null, 500),
            'query2.finance.yahoo.com/*' => Http::response(null, 500),
            'api.twelvedata.com/*' => Http::response([
                'symbol' => 'USD/MYR', 'close' => '4.2000', 'previous_close' => '4.1800',
                'percent_change' => '0.48', 'currency' => 'MYR',
            ]),
        ]);

        $out = app(MarketQuoteService::class)->fetch(['MYR=X']);

        $this->assertSame(4.2, $out['MYR=X']['price']);
        $this->assertEqualsWithDelta(0.48, $out['MYR=X']['change_pct'], 0.001);
    }

    public function test_no_fallback_without_key(): void
    {
        config(['services.twelvedata.key' => null]);
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response(null, 500),
            'query2.finance.yahoo.com/*' => Http::response(null, 500),
        ]);

        $this->assertSame([], app(MarketQuoteService::class)->fetch(['MYR=X']));
    }

    public function test_null_prev_close_yields_null_change(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response($this->yahoo(100.0, null)),
        ]);

        $out = app(MarketQuoteService::class)->fetch(['^NOPREV']);

        $this->assertSame(100.0, $out['^NOPREV']['price']);
        $this->assertNull($out['^NOPREV']['change_pct']);
    }

    public function test_crosscheck_flags_disagreement_beyond_tolerance(): void
    {
        config(['services.twelvedata.key' => 'test-key']);
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response($this->yahoo(4.00, 4.00, 'MYR')),
            'api.twelvedata.com/*' => Http::response(['close' => '4.20', 'currency' => 'MYR']),
        ]);

        $out = app(MarketQuoteService::class)->crossCheck(['MYR=X'], 1.0);

        $this->assertCount(1, $out);
        $this->assertFalse($out[0]['agree']);              // 5% apart > 1% tol
        $this->assertEqualsWithDelta(5.0, $out[0]['diff_pct'], 0.01);
    }

    public function test_crosscheck_agrees_within_tolerance(): void
    {
        config(['services.twelvedata.key' => 'test-key']);
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response($this->yahoo(4.000, 4.000, 'MYR')),
            'api.twelvedata.com/*' => Http::response(['close' => '4.010', 'currency' => 'MYR']),
        ]);

        $out = app(MarketQuoteService::class)->crossCheck(['MYR=X'], 1.0);

        $this->assertTrue($out[0]['agree']);               // 0.25% apart < 1% tol
    }

    public function test_crosscheck_dormant_without_key(): void
    {
        config(['services.twelvedata.key' => null]);

        $this->assertSame([], app(MarketQuoteService::class)->crossCheck(['MYR=X']));
    }

    public function test_crosscheck_skips_unmapped_symbols(): void
    {
        config(['services.twelvedata.key' => 'test-key']);

        // ^JKSE is not in TD_MAP → no second source → nothing to compare.
        $this->assertSame([], app(MarketQuoteService::class)->crossCheck(['^JKSE']));
    }
}
