<?php

namespace Tests\Unit;

use App\Console\Commands\IngestFloat;
use PHPUnit\Framework\TestCase;

/**
 * The float row regex must extract each field from PMO's pdftotext -layout
 * output exactly — a mis-capture silently records the wrong fund, amount, or
 * switch target. These are the real lines from the July 2026 float statements.
 */
class FloatLineParseTest extends TestCase
{
    public function test_unit_trust_switch_row_with_target(): void
    {
        $line = '  29/07/2026     SWR            128960916       PUBLIC e-ISLAMIC INDIA GLOBAL EQUITY                       0.00            100,000.00   077221901       PUBLIC e-ARTIFICIAL INTELLIGENCE';
        $r = IngestFloat::parseUtLine($line);

        $this->assertNotNull($r);
        $this->assertSame('29/07/2026', $r['date']);
        $this->assertSame('SWR', $r['type']);
        $this->assertSame('128960916', $r['acct']);
        $this->assertSame('PUBLIC e-ISLAMIC INDIA GLOBAL EQUITY', trim($r['fund']));
        $this->assertSame('0.00', $r['amount']);
        $this->assertSame('100,000.00', $r['units']);
        $this->assertSame('077221901', $r['swAcct']);
        $this->assertSame('PUBLIC e-ARTIFICIAL INTELLIGENCE', trim($r['swFund']));
    }

    public function test_unit_trust_row_without_switch_target(): void
    {
        $line = '13/07/2026  RP  074114785  PUBLIC INDONESIA SELECT FUND  1,234.56  5,000.00';
        $r = IngestFloat::parseUtLine($line);

        $this->assertNotNull($r);
        $this->assertSame('RP', $r['type']);
        $this->assertSame('074114785', $r['acct']);
        $this->assertNull($r['swAcct']);
        $this->assertNull($r['swFund']);
    }

    public function test_prs_contribution_row(): void
    {
        $line = ' 29/07/2026     AC            06244382     PRS STRATEGIC EQUITY                  IND                               3,000.00                0.00';
        $r = IngestFloat::parsePrsLine($line);

        $this->assertNotNull($r);
        $this->assertSame('AC', $r['type']);
        $this->assertSame('06244382', $r['acct']);
        $this->assertSame('PRS STRATEGIC EQUITY', trim($r['fund']));
        $this->assertSame('IND', $r['contrib']);
        $this->assertSame('3,000.00', $r['amount']);
        $this->assertSame('0.00', $r['units']);
    }

    public function test_header_and_junk_lines_are_ignored(): void
    {
        $this->assertNull(IngestFloat::parseUtLine('Tran Date   Description   Account   Fund'));
        $this->assertNull(IngestFloat::parseUtLine('Float Transactions/Urus Niaga Apungan'));
        $this->assertNull(IngestFloat::parseUtLine(''));
        $this->assertNull(IngestFloat::parsePrsLine('AC : ADDITIONAL CONTRIBUTION'));
    }

    public function test_prs_regex_does_not_match_ut_row(): void
    {
        // a UT row has no contribution-type column, so the PRS pattern must reject it
        $line = '13/07/2026  RP  074114785  PUBLIC INDONESIA SELECT FUND  1,234.56  5,000.00';
        $this->assertNull(IngestFloat::parsePrsLine($line));
    }
}
