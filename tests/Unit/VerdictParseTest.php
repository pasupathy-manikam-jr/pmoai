<?php

namespace Tests\Unit;

use App\Services\FundAnalysis;
use PHPUnit\Framework\TestCase;

class VerdictParseTest extends TestCase
{
    /** The bug this replaced: a loose word match read "sell-off" as a SELL call. */
    public function test_prose_words_do_not_override_the_verdict_line(): void
    {
        $memo = "**Signal read**\nThe KOSPI sell-off in July dragged this fund down; buyers stayed away.\n"
            ."**Verdict for a current holder: KEEP**\nPosition is one day old; switching now only books fees.";

        $this->assertSame('KEEP', FundAnalysis::verdict($memo));
    }

    public function test_reads_the_prospective_buyer_verdict_too(): void
    {
        $this->assertSame('AVOID', FundAnalysis::verdict("**Verdict for a prospective buyer: AVOID** — it has already run up."));
    }

    public function test_no_verdict_line_returns_null(): void
    {
        $this->assertNull(FundAnalysis::verdict('Insufficient data for a verdict. Do not buy or sell on this.'));
        $this->assertNull(FundAnalysis::verdict(null));
    }

    public function test_only_directional_calls_are_scoreable(): void
    {
        $this->assertSame('down', FundAnalysis::verdictDirection('REDUCE'));
        $this->assertSame('up', FundAnalysis::verdictDirection('BUY'));
        // KEEP means "don't churn", not "price will rise" — nothing to score.
        $this->assertNull(FundAnalysis::verdictDirection('KEEP'));
        $this->assertNull(FundAnalysis::verdictDirection(null));
    }
}
