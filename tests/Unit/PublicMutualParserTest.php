<?php

namespace Tests\Unit;

use App\Services\PublicMutualParser;
use Tests\TestCase;

class PublicMutualParserTest extends TestCase
{
    public function test_parses_a_csv_price_row_into_a_fund(): void
    {
        // col0 = "NAME\nCODE", then date, price, change, change%.
        $csv = "\"PUBLIC ISLAMIC OPTIMAL GROWTH FUND\nPIOGF\",06/08/2026,0.4521,-0.0012,-0.27\n";

        $funds = (new PublicMutualParser)->parse($csv);

        $this->assertCount(1, $funds);
        $f = $funds[0];
        $this->assertSame('PUBLIC ISLAMIC OPTIMAL GROWTH FUND', $f['name']);
        $this->assertSame('PIOGF', $f['extra']['code']);
        $this->assertEqualsWithDelta(0.4521, $f['unit_price'], 0.0001);
        $this->assertTrue($f['shariah']);                 // "ISLAMIC" → shariah flag
        $this->assertEqualsWithDelta(-0.27, $f['extra']['change_pct'], 0.001);
    }

    public function test_non_price_lines_are_ignored(): void
    {
        // A row whose date/price don't validate must not become a fund.
        $csv = "\"SOMETHING\",not-a-date,not-a-price,0,0\n";

        $this->assertSame([], (new PublicMutualParser)->parse($csv));
    }
}
