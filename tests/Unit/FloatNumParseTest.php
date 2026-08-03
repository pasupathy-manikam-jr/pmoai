<?php

namespace Tests\Unit;

use App\Console\Commands\IngestFloat;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The float/statement amount parser must read PMO's number formats exactly —
 * commas as thousands separators and parentheses as negatives. A wrong sign or
 * a dropped comma silently corrupts transaction amounts.
 */
class FloatNumParseTest extends TestCase
{
    private function num(string $s): ?float
    {
        $m = new ReflectionMethod(IngestFloat::class, 'num');
        $m->setAccessible(true);

        return $m->invoke(new IngestFloat, $s);
    }

    public function test_plain_and_thousands(): void
    {
        $this->assertSame(3000.0, $this->num('3,000.00'));
        $this->assertSame(1234.56, $this->num('1,234.56'));
        $this->assertSame(100000.0, $this->num('100000.0000'));
        $this->assertSame(0.0, $this->num('0.00'));
    }

    public function test_parentheses_are_negative(): void
    {
        $this->assertSame(-1234.56, $this->num('(1,234.56)'));
        $this->assertSame(-300000.0, $this->num('(300,000.00)'));
    }

    public function test_whitespace_tolerated(): void
    {
        $this->assertSame(52920.0, $this->num('  52,920.00 '));
    }

    public function test_non_numeric_is_null(): void
    {
        $this->assertNull($this->num('—'));
        $this->assertNull($this->num(''));
        $this->assertNull($this->num('N/A'));
    }
}
