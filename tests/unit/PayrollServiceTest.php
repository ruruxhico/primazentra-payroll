<?php

use App\Libraries\PayPeriod;
use App\Libraries\PayrollService;
use App\Libraries\PayrollSource;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Failure-path and integration-rule tests for a payroll run (M4 Part E),
 * using an in-memory source instead of the REST APIs.
 *
 * @internal
 */
final class PayrollServiceTest extends CIUnitTestCase
{
    private static function source(array $employees, array $dtr, array $pending = []): PayrollSource
    {
        return new class ($employees, $dtr, $pending) implements PayrollSource {
            public array $calledWith = [];

            public function __construct(private array $e, private array $d, private array $p)
            {
            }

            public function employees(): array
            {
                return $this->e;
            }

            public function dtr(string $from, string $to): array
            {
                $this->calledWith = [$from, $to];

                return ['data' => $this->d, 'pending' => $this->p];
            }
        };
    }

    private static function emp(string $id, array $over = []): array
    {
        return $over + ['employee_id' => $id, 'first_name' => 'F' . $id, 'last_name' => 'L' . $id,
            'schedule_type' => '8hrs', 'monthly_salary' => 20000.0, 'status' => 'active'];
    }

    private static function dtr(string $id, string $date, array $over = []): array
    {
        return $over + ['employee_id' => $id, 'date' => $date, 'schedule_type' => '8hrs', 'late_minutes' => 0,
            'undertime_minutes' => 0, 'ot_hours' => 0.0, 'is_absent' => false, 'approved' => true];
    }

    public function testRequestsDtrForExactlyThePeriod(): void
    {
        $src = self::source([self::emp('EMP001')], []);
        (new PayrollService($src))->compute(PayPeriod::of(2026, 11, 'B'));

        $this->assertSame(['2026-11-16', '2026-11-30'], $src->calledWith);
    }

    public function testDtrForUnknownEmployeeIsWarnedAndRunContinues(): void
    {
        $r = (new PayrollService(self::source(
            [self::emp('EMP001')],
            [self::dtr('EMP999', '2026-11-03', ['is_absent' => true]), self::dtr('EMP001', '2026-11-03')],
        )))->compute(PayPeriod::of(2026, 11, 'A'));

        $this->assertCount(1, $r['details']);
        $this->assertSame('EMP001', $r['details'][0]['employee_id']);
        $this->assertStringContainsString('EMP999', implode(' ', $r['warnings']));
    }

    public function testPendingDtrIsReportedAndNotPaid(): void
    {
        $r = (new PayrollService(self::source(
            [self::emp('EMP001')],
            [],
            [['employee_id' => 'EMP001', 'date' => '2026-11-03']],
        )))->compute(PayPeriod::of(2026, 11, 'A'));

        $this->assertSame(10000.0, $r['details'][0]['gross_pay']);
        $this->assertStringContainsString('pending approval', implode(' ', $r['warnings']));
    }

    public function testEachEmployeeIsCalculatedSeparately(): void
    {
        $r = (new PayrollService(self::source(
            [self::emp('EMP001'), self::emp('EMP002', ['schedule_type' => '9.6hrs', 'monthly_salary' => 22000.0]), self::emp('EMP003')],
            [self::dtr('EMP001', '2026-11-03', ['is_absent' => true]), self::dtr('EMP002', '2026-11-03', ['schedule_type' => '9.6hrs', 'ot_hours' => 1.0])],
        )))->compute(PayPeriod::of(2026, 11, 'A'));

        $gross = array_column($r['details'], 'gross_pay', 'employee_id');
        $this->assertSame(9230.77, $gross['EMP001']);   // 10000 − 769.23
        $this->assertSame(11130.21, $gross['EMP002']);  // 11000 + 1 × (22000/22/9.6) × 1.25
        $this->assertSame(10000.0, $gross['EMP003']);   // no DTR: present all days
        $this->assertSame(30360.98, $r['totals']['gross_pay']);
        $this->assertSame(3, $r['totals']['employee_count']);
    }

    public function testInactiveEmployeeIsPaidOnlyWithDtrInPeriod(): void
    {
        $r = (new PayrollService(self::source(
            [self::emp('EMP001', ['status' => 'inactive']), self::emp('EMP002', ['status' => 'inactive'])],
            [self::dtr('EMP002', '2026-11-03')],
        )))->compute(PayPeriod::of(2026, 11, 'A'));

        $this->assertSame(['EMP002'], array_column($r['details'], 'employee_id'));
    }

    public function testEmployeeWithBadMasterDataIsSkippedWithWarning(): void
    {
        $r = (new PayrollService(self::source(
            [self::emp('EMP001', ['monthly_salary' => 0.0]), self::emp('EMP002')],
            [],
        )))->compute(PayPeriod::of(2026, 11, 'A'));

        $this->assertSame(['EMP002'], array_column($r['details'], 'employee_id'));
        $this->assertStringContainsString('EMP001 skipped', implode(' ', $r['warnings']));
    }

    public function testEmployeesWithNoDtrAtAllAreNamedInAWarning(): void
    {
        $r = (new PayrollService(self::source(
            [self::emp('EMP001'), self::emp('EMP002'), self::emp('EMP003')],
            [self::dtr('EMP001', '2026-11-16')],
            [self::dtr('EMP002', '2026-11-17', ['approved' => false])],
        )))->compute(PayPeriod::of(2026, 11, 'B'));
        $all = implode(' ', $r['warnings']);

        $this->assertStringContainsString('1 active employee(s) have no DTR', $all);
        $this->assertStringContainsString('(EMP003)', $all);
        $this->assertCount(3, $r['details'], 'Still paid as present; the warning only asks for confirmation.');
    }

    public function testNoAssumedPresentWarningWhenEveryoneHasDtr(): void
    {
        $r = (new PayrollService(self::source([self::emp('EMP001')], [self::dtr('EMP001', '2026-11-16')])))
            ->compute(PayPeriod::of(2026, 11, 'B'));

        $this->assertStringNotContainsString('no DTR', implode(' ', $r['warnings']));
    }
}
