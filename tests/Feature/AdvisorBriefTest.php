<?php

namespace Tests\Feature;

use App\Models\ActionItem;
use App\Models\AdvisorState;
use App\Models\Fund;
use App\Models\FundDetail;
use App\Models\Transaction;
use App\Services\AdvisorBrief;
use App\Services\PortfolioAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvisorBriefTest extends TestCase
{
    use RefreshDatabase;

    private function fund(string $name, string $code, string $cat, string $risk, float $r3): void
    {
        Fund::create(['name' => $name, 'code' => $code, 'category' => $cat, 'risk' => $risk, 'return_1y' => $r3, 'return_3y' => $r3]);
    }

    private function hold(string $name, string $code, float $value): void
    {
        FundDetail::create(['name' => $name, 'code' => $code, 'raw_text' => '',
            'payload' => ['position' => ['invested' => $value, 'current_value' => $value]]]);
    }

    private function brief(): array
    {
        $plan = app(PortfolioAdvisor::class)->analyze();
        // t1 rows: name (short) + value, no market data → not usable
        $t1 = collect($plan['board'])->map(fn ($r) => ['name' => (string) \Illuminate\Support\Str::of($r['name'])->after('PUBLIC '),
            'value' => 0.0, 'usable' => false, 'expected_pct' => 0, 'expected_rm' => 0])->all();

        return app(AdvisorBrief::class)->build($plan, $t1, ActionItem::all());
    }

    public function test_headline_is_the_single_top_action_and_memory_tracks_changes(): void
    {
        $this->fund('PUBLIC e-BIG', 'PeBIG', 'EQ', 'High', 20.0);
        $this->fund('PUBLIC e-SMALL', 'PeSMALL', 'EQ', 'High', 20.0);
        $this->hold('PUBLIC e-BIG', 'PeBIG', 70000);     // 70% → TRIM
        $this->hold('PUBLIC e-SMALL', 'PeSMALL', 30000);

        $b = $this->brief();
        $this->assertSame('TRIM', $b['headline']['action']);
        $this->assertSame('e-BIG', $b['headline']['fund']);
        $this->assertTrue($b['changes']['first']);            // first visit
        $this->assertSame(1, AdvisorState::count());

        // Second visit, nothing changed → no changes, no new state row.
        $b2 = $this->brief();
        $this->assertFalse($b2['changes']['first']);
        $this->assertFalse($b2['changes']['any']);
        $this->assertSame(1, AdvisorState::count());
    }

    public function test_a_switch_you_already_made_is_marked_done_not_resuggested(): void
    {
        $this->fund('PUBLIC e-WEAK', 'PeWEAK', 'EQ', 'High', 5.0);
        $this->fund('PUBLIC e-STRONG', 'PeSTRONG', 'EQ', 'High', 40.0);
        $this->fund('PUBLIC e-CORE', 'PeCORE', 'EQ', 'Moderate', 9.0);
        $this->hold('PUBLIC e-CORE', 'PeCORE', 70000);
        $this->hold('PUBLIC e-WEAK', 'PeWEAK', 20000);        // beaten → SWITCH to STRONG

        // You already switched out of WEAK last week.
        Transaction::create(['trans_date' => now()->subDays(7)->toDateString(), 'account_no' => '1', 'fund_code' => 'PeWEAK',
            'trans_type' => 'SWR', 'net' => -10000, 'units' => -5000, 'trans_ref' => 'TR-DONE']);

        $b = $this->brief();
        $done = collect($b['done'])->firstWhere('short', 'e-WEAK');
        $this->assertNotNull($done);
        $this->assertStringContainsString('switched RM10,000 out', $done['done']);
        // and it's not in the open list nor the headline
        $this->assertNull(collect($b['remaining'])->firstWhere('short', 'e-WEAK'));
        $this->assertNotSame('e-WEAK', $b['headline']['fund'] ?? null);
    }
}
