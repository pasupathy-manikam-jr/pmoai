<?php

namespace Tests\Unit;

use App\Services\Xirr;
use PHPUnit\Framework\TestCase;

class XirrTest extends TestCase
{
    public function test_simple_one_year_ten_percent_gain(): void
    {
        $xirr = Xirr::compute([
            ['date' => '2020-01-01', 'amount' => -1000],
            ['date' => '2021-01-01', 'amount' => 1100],
        ]);

        $this->assertEqualsWithDelta(10.0, $xirr, 0.3);
    }

    public function test_two_year_doubling_is_about_41_percent(): void
    {
        $xirr = Xirr::compute([
            ['date' => '2020-01-01', 'amount' => -1000],
            ['date' => '2022-01-01', 'amount' => 2000],
        ]);

        $this->assertEqualsWithDelta(41.4, $xirr, 0.6);
    }

    public function test_a_loss_returns_negative(): void
    {
        $xirr = Xirr::compute([
            ['date' => '2020-01-01', 'amount' => -1000],
            ['date' => '2021-01-01', 'amount' => 900],
        ]);

        $this->assertNotNull($xirr);
        $this->assertEqualsWithDelta(-10.0, $xirr, 0.3);
    }

    public function test_multiple_contributions_then_value(): void
    {
        // two RM1,000 buys a year apart, worth RM2,300 after the second year
        $xirr = Xirr::compute([
            ['date' => '2020-01-01', 'amount' => -1000],
            ['date' => '2021-01-01', 'amount' => -1000],
            ['date' => '2022-01-01', 'amount' => 2300],
        ]);

        $this->assertNotNull($xirr);
        $this->assertGreaterThan(0, $xirr);      // net gain → positive
        $this->assertLessThan(20, $xirr);
    }

    public function test_fewer_than_two_flows_returns_null(): void
    {
        $this->assertNull(Xirr::compute([]));
        $this->assertNull(Xirr::compute([['date' => '2020-01-01', 'amount' => -1000]]));
    }

    public function test_all_inflows_no_sign_change_returns_null(): void
    {
        $this->assertNull(Xirr::compute([
            ['date' => '2020-01-01', 'amount' => 1000],
            ['date' => '2021-01-01', 'amount' => 1100],
        ]));
    }
}
