<?php

namespace Tests\Feature;

use App\Models\FundDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerAccountHoldingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_fund_in_two_accounts_splits_into_positions_and_aggregates(): void
    {
        config(['ai.ingest_token' => 'test-token']);
        $detail = FundDetail::create(['name' => 'PRS ISLAMIC CONSERVATIVE', 'raw_text' => '', 'payload' => []]);

        $res = $this->withHeader('X-PMOAI-TOKEN', 'test-token')->postJson('/ingest-holdings', [
            'holdings' => [
                ['name' => 'PRS ISLAMIC CONSERVATIVE', 'account_no' => '06666763', 'market_value' => 12481.97, 'investment_cost' => 11946.14],
                ['name' => 'PRS ISLAMIC CONSERVATIVE', 'account_no' => '06270155', 'market_value' => 2981.69, 'investment_cost' => 3000.00],
            ],
        ]);

        $res->assertOk();
        $detail->refresh();

        // two per-account sub-positions
        $this->assertCount(2, $detail->payload['positions']);
        $accts = array_column($detail->payload['positions'], 'account_no');
        $this->assertContains('06666763', $accts);
        $this->assertContains('06270155', $accts);

        // aggregate = exact sum of the two accounts
        $this->assertEqualsWithDelta(14946.14, (float) $detail->payload['position']['invested'], 0.01);
        $this->assertEqualsWithDelta(15463.66, (float) $detail->payload['position']['current_value'], 0.01);
    }

    public function test_fund_absent_from_capture_is_cleared_as_exited(): void
    {
        config(['ai.ingest_token' => 'test-token']);
        $kept = FundDetail::create(['name' => 'PUBLIC ISLAMIC ASIA TACTICAL ALLOCATION FUND', 'raw_text' => '', 'payload' => [
            'position' => ['invested' => 52920.0, 'current_value' => 58000.0, 'since' => '2026-07-13'],
        ]]);
        $exited = FundDetail::create(['name' => 'PUBLIC INDONESIA SELECT FUND', 'code' => 'PINDOSF', 'raw_text' => '', 'payload' => [
            'position'  => ['invested' => 37322.84, 'current_value' => 30365.22, 'since' => '2020-05-18'],
            'positions' => [['account_no' => '074114785', 'invested' => 37322.84, 'current_value' => 30365.22]],
        ]]);

        $alert = \App\Models\Alert::create([
            'fund_code' => 'PINDOSF', 'condition' => 'above', 'level' => 0.19,
            'label' => 'Indonesia: breakout', 'active' => true,
        ]);

        $this->withHeader('X-PMOAI-TOKEN', 'test-token')->postJson('/ingest-holdings', [
            'holdings' => [
                ['name' => 'PUBLIC ISLAMIC ASIA TACTICAL ALLOCATION FUND', 'account_no' => '137974826',
                 'market_value' => 88356.96, 'investment_cost' => 83285.22],
            ],
        ])->assertOk();

        // price triggers on the exited fund are retired with the position
        $this->assertFalse((bool) $alert->refresh()->active);

        // absent from the capture → position dropped, no longer counted
        $this->assertArrayNotHasKey('position', $exited->refresh()->payload);
        $this->assertArrayNotHasKey('positions', $exited->payload);
        // present in the capture → updated, not cleared
        $this->assertEqualsWithDelta(88356.96, (float) $kept->refresh()->payload['position']['current_value'], 0.01);
    }

    public function test_partial_capture_does_not_clear_unmatched_funds(): void
    {
        config(['ai.ingest_token' => 'test-token']);
        $held = FundDetail::create(['name' => 'PUBLIC INDONESIA SELECT FUND', 'raw_text' => '', 'payload' => [
            'position' => ['invested' => 37322.84, 'current_value' => 30365.22, 'since' => '2020-05-18'],
        ]]);

        // a row that matches no fund detail = parse we cannot trust
        $this->withHeader('X-PMOAI-TOKEN', 'test-token')->postJson('/ingest-holdings', [
            'holdings' => [
                ['name' => 'SOME FUND NOT IN THE CATALOG', 'market_value' => 100.0, 'investment_cost' => 100.0],
            ],
        ])->assertOk();

        $this->assertEqualsWithDelta(30365.22, (float) $held->refresh()->payload['position']['current_value'], 0.01);
    }

    public function test_rejects_without_token(): void
    {
        $this->postJson('/ingest-holdings', ['holdings' => [
            ['name' => 'X', 'market_value' => 1, 'investment_cost' => 1],
        ]])->assertStatus(401);
    }

    /** PMO lists PRS on its own page — a PRS-only capture must not exit unit trusts. */
    public function test_prs_only_capture_leaves_unit_trusts_alone(): void
    {
        config(['ai.ingest_token' => 'test-token']);
        $ut = FundDetail::create(['name' => 'PUBLIC e-EMAS GOLD FUND', 'code' => 'PeEMAS', 'raw_text' => '', 'payload' => [
            'position' => ['invested' => 100000.0, 'current_value' => 106000.0, 'since' => '2025-01-01'],
        ]]);
        FundDetail::create(['name' => 'PRS EQUITY', 'raw_text' => '', 'payload' => []]);

        $this->withHeader('X-PMOAI-TOKEN', 'test-token')->postJson('/ingest-holdings', [
            'holdings' => [['name' => 'PRS EQUITY', 'market_value' => 3353.44, 'investment_cost' => 3000]],
        ])->assertOk();

        $this->assertEqualsWithDelta(106000.0, (float) $ut->refresh()->payload['position']['current_value'], 0.01);
    }

    /** First-invested comes from the PMO account page, never the capture date. */
    public function test_first_invested_reads_pmo_page_and_never_defaults_to_today(): void
    {
        config(['ai.ingest_token' => 'test-token']);
        \Illuminate\Support\Facades\DB::table('page_captures')->insert([
            'url' => 'https://www.publicmutualonline.com.my/Ut_AcctDetails.aspx', 'title' => 'x', 'hash' => 'h1',
            // PMO uses non-breaking spaces between words
            'text' => "077221901\u{00A0}PUBLIC e-ARTIFICIAL INTELLIGENCE TECHNOLOGY FUND\nInitial Investment on\u{00A0}07/09/2020\n Transact",
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ai = FundDetail::create(['name' => 'PUBLIC e-ARTIFICIAL INTELLIGENCE TECHNOLOGY FUND', 'code' => 'PeAITF', 'raw_text' => '', 'payload' => []]);
        $unknown = FundDetail::create(['name' => 'PRS STRATEGIC EQUITY', 'raw_text' => '', 'payload' => []]);

        $this->withHeader('X-PMOAI-TOKEN', 'test-token')->postJson('/ingest-holdings', [
            'holdings' => [
                ['name' => 'PUBLIC e-ARTIFICIAL INTELLIGENCE TECHNOLOGY FUND', 'code' => 'PeAITF', 'account_no' => '077221901', 'market_value' => 199160, 'investment_cost' => 159539],
                ['name' => 'PRS STRATEGIC EQUITY', 'account_no' => '06244382', 'market_value' => 18127, 'investment_cost' => 15046],
            ],
        ])->assertOk();

        $this->assertSame('2020-09-07', $ai->refresh()->payload['position']['since']);
        $this->assertNull($unknown->refresh()->payload['position']['since']);
    }
}
