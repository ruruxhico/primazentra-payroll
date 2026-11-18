<?php

use App\Libraries\PayPeriod;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PayPeriodTest extends CIUnitTestCase
{
    public function testFirstHalfIsFirstToFifteenth(): void
    {
        $p = PayPeriod::containing('2026-11-15');
        $this->assertSame(['2026-11-A', '2026-11-01', '2026-11-15'], [$p->id, $p->start, $p->end]);
    }

    public function testSecondHalfRunsToEndOfMonth(): void
    {
        $this->assertSame('2026-11-30', PayPeriod::containing('2026-11-16')->end);
        $this->assertSame('2026-02-28', PayPeriod::of(2026, 2, 'B')->end);
        $this->assertSame('2028-02-29', PayPeriod::of(2028, 2, 'B')->end);
    }

    public function testFromIdRoundTrips(): void
    {
        $this->assertSame('2026-12-31', PayPeriod::fromId('2026-12-B')->end);
    }

    public function testInvalidIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PayPeriod::fromId('2026-13-C');
    }
}
