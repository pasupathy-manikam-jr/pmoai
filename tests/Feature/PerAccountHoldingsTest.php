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

    public function test_rejects_without_token(): void
    {
        $this->postJson('/ingest-holdings', ['holdings' => [
            ['name' => 'X', 'market_value' => 1, 'investment_cost' => 1],
        ]])->assertStatus(401);
    }
}
