<?php

use App\Libraries\PayrollCalculator as Calc;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Contract tests for the payroll formulas (M4 Part E).
 * Reference employee: ₱20,000/month on the 8hrs schedule
 *   daily  = 20000 / 26 = 769.2308
 *   hourly = 769.2308 / 8 = 96.1538
 *
 * @internal
 */
final class PayrollCalculatorTest extends CIUnitTestCase
{
    private const EMP_8 = ['employee_id' => 'EMP001', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'schedule_type' => '8hrs', 'monthly_salary' => 20000];

    private static function day(string $date, array $over = []): array
    {
        return $over + ['employee_id' => 'EMP001', 'date' => $date, 'schedule_type' => '8hrs',
            'late_minutes' => 0, 'undertime_minutes' => 0, 'ot_hours' => 0, 'is_absent' => false, 'approved' => true];
    }

    public function testDailyRateEightHourScheduleDividesBy26(): void
    {
        $this->assertEqualsWithDelta(769.23, Calc::dailyRate(20000, '8hrs'), 0.005);
    }

    public function testDailyRateNinePointSixHourScheduleDividesBy22(): void
    {
        $this->assertEqualsWithDelta(909.09, Calc::dailyRate(20000, '9.6hrs'), 0.005);
    }

    public function testHourlyRateIsDailyRateOverHoursPerDay(): void
    {
        $this->assertEqualsWithDelta(96.1538, Calc::hourlyRate(20000, '8hrs'), 0.0001);
        $this->assertEqualsWithDelta(94.6970, Calc::hourlyRate(20000, '9.6hrs'), 0.0001);
    }

    public function testLateWithinGracePeriodIsNotDeducted(): void
    {
        $this->assertSame(0.0, Calc::lateDeduction(15, 96.1538));
    }

    public function testLateBeyondGraceDeductsEveryMinute(): void
    {
        // 29 minutes late: all 29 minutes deducted (grace then full deduction)
        $this->assertEqualsWithDelta(46.47, Calc::lateDeduction(29, Calc::hourlyRate(20000, '8hrs')), 0.005);
        $this->assertEqualsWithDelta(25.64, Calc::lateDeduction(16, Calc::hourlyRate(20000, '8hrs')), 0.005);
    }

    public function testUndertimeDeductionIsPerMinute(): void
    {
        $this->assertEqualsWithDelta(48.08, Calc::undertimeDeduction(30, Calc::hourlyRate(20000, '8hrs')), 0.005);
    }

    public function testOvertimePayUses125Multiplier(): void
    {
        $this->assertEqualsWithDelta(240.38, Calc::otPay(2, Calc::hourlyRate(20000, '8hrs')), 0.005);
    }

    public function testAbsenceDeductsOneFullDailyRate(): void
    {
        $r = Calc::compute(self::EMP_8, [self::day('2026-11-03', ['is_absent' => true])]);

        $this->assertSame(1, $r['days_absent']);
        $this->assertSame(769.23, $r['absence_deduction']);
        $this->assertSame(9230.77, $r['gross_pay']);
    }

    public function testPerfectAttendanceHasZeroDeductions(): void
    {
        $r = Calc::compute(self::EMP_8, [self::day('2026-11-03'), self::day('2026-11-04')]);

        $this->assertSame(0.0, $r['total_deductions']);
        $this->assertSame(10000.0, $r['basic_pay']);
        $this->assertSame(10000.0, $r['gross_pay']);
    }

    public function testNoDtrMeansPresentAllDays(): void
    {
        $r = Calc::compute(self::EMP_8, []);

        $this->assertSame(10000.0, $r['gross_pay']);
        $this->assertSame(0, $r['days_recorded']);
    }

    public function testMultipleDaysAreAggregated(): void
    {
        $r = Calc::compute(self::EMP_8, [
            self::day('2026-11-03', ['late_minutes' => 20]),
            self::day('2026-11-04', ['late_minutes' => 10]),   // within grace
            self::day('2026-11-05', ['late_minutes' => 40, 'ot_hours' => 2]),
        ]);

        $this->assertSame(70, $r['late_minutes']);
        $this->assertSame(60, $r['late_minutes_deducted']);
        $this->assertSame(96.15, $r['late_deduction']);       // 60 min × 96.1538 / 60
        $this->assertSame(240.38, $r['ot_pay']);
        $this->assertSame(10144.23, $r['gross_pay']);        // 10000 + 240.38 − 96.15
    }

    public function testUnapprovedDtrIsIgnored(): void
    {
        $r = Calc::compute(self::EMP_8, [self::day('2026-11-03', ['is_absent' => true, 'approved' => false])]);

        $this->assertSame(0.0, $r['absence_deduction']);
        $this->assertSame(10000.0, $r['gross_pay']);
    }

    public function testGrossPayNeverGoesNegative(): void
    {
        $days = [];
        for ($d = 1; $d <= 15; $d++) {
            $days[] = self::day(sprintf('2026-11-%02d', $d), ['is_absent' => true]);
        }
        $r = Calc::compute(self::EMP_8, $days);

        $this->assertSame(0.0, $r['gross_pay']);
        $this->assertNotEmpty($r['warnings']);
    }

    public function testInvalidScheduleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::compute(['schedule_type' => '10hrs'] + self::EMP_8, []);
    }

    public function testZeroSalaryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::compute(['monthly_salary' => 0] + self::EMP_8, []);
    }

    public function testStatutoryDeductionsAreFlaggedAsNotApplied(): void
    {
        $r = Calc::compute(self::EMP_8, []);

        $this->assertFalse($r['statutory_deductions_applied']);
        $this->assertSame($r['gross_pay'], $r['net_pay']);
    }
}
