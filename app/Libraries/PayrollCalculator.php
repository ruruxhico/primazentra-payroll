<?php

namespace App\Libraries;

/**
 * Semi-monthly pay computation (M4 integration specification).
 *
 *   daily rate     = monthly ÷ 26 (8hrs schedule) or monthly ÷ 22 (9.6hrs schedule)
 *   hourly rate    = daily rate ÷ hours per day (8 or 9.6)
 *   basic pay      = monthly ÷ 2
 *   late           = late minutes × hourly ÷ 60, only when late > 15 minutes
 *                    (15-minute grace; past it, every late minute is deducted)
 *   undertime      = undertime minutes × hourly ÷ 60
 *   absence        = one daily rate per absent day
 *   OT pay         = OT hours × hourly × 1.25
 *   gross pay      = basic + OT pay − (late + undertime + absence)
 *
 * Rates are kept at full precision; each peso total is rounded to centavos
 * once, and gross is computed from the rounded totals so a payslip adds up.
 *
 * Statutory deductions (SSS, PhilHealth, Pag-IBIG, withholding tax) are NOT
 * applied yet: net pay equals gross pay and results carry
 * statutory_deductions_applied = false.
 */
final class PayrollCalculator
{
    public const OT_MULTIPLIER      = 1.25;
    public const LATE_GRACE_MINUTES = 15;

    private const SCHEDULES = [
        '8hrs'   => ['divisor' => 26, 'hours' => 8.0],
        '9.6hrs' => ['divisor' => 22, 'hours' => 9.6],
    ];

    public static function dailyRate(float $monthlySalary, string $scheduleType): float
    {
        return $monthlySalary / self::schedule($scheduleType)['divisor'];
    }

    public static function hourlyRate(float $monthlySalary, string $scheduleType): float
    {
        return self::dailyRate($monthlySalary, $scheduleType) / self::schedule($scheduleType)['hours'];
    }

    public static function lateDeduction(int $lateMinutes, float $hourlyRate): float
    {
        return $lateMinutes > self::LATE_GRACE_MINUTES ? $lateMinutes * $hourlyRate / 60 : 0.0;
    }

    public static function undertimeDeduction(int $undertimeMinutes, float $hourlyRate): float
    {
        return max(0, $undertimeMinutes) * $hourlyRate / 60;
    }

    public static function otPay(float $otHours, float $hourlyRate): float
    {
        return max(0.0, $otHours) * $hourlyRate * self::OT_MULTIPLIER;
    }

    /**
     * @param array{employee_id:string, first_name?:string, last_name?:string,
     *              schedule_type:string, monthly_salary:float|int} $employee
     * @param list<array> $dtrRows the employee's APPROVED DTR rows for the period
     */
    public static function compute(array $employee, array $dtrRows): array
    {
        $monthly  = (float) $employee['monthly_salary'];
        $schedule = (string) $employee['schedule_type'];
        if ($monthly <= 0) {
            throw new \InvalidArgumentException("Employee {$employee['employee_id']} has no valid monthly salary.");
        }

        $daily    = self::dailyRate($monthly, $schedule);
        $hourly   = self::hourlyRate($monthly, $schedule);
        $warnings = [];

        $late = $undertime = $absence = $ot = 0.0;
        $lateMin = $lateMinDeducted = $undertimeMin = $absentDays = 0;
        $otHours = 0.0;

        foreach ($dtrRows as $row) {
            if (empty($row['approved'])) {
                continue; // defensive: unapproved DTR never reaches pay
            }
            if (($row['schedule_type'] ?? $schedule) !== $schedule) {
                $warnings[] = "DTR {$row['date']} was recorded as {$row['schedule_type']}; paid using the current {$schedule} schedule.";
            }
            if (! empty($row['is_absent'])) {
                $absentDays++;
                $absence += $daily;
                continue;
            }

            $l = (int) ($row['late_minutes'] ?? 0);
            $u = (int) ($row['undertime_minutes'] ?? 0);
            $o = (float) ($row['ot_hours'] ?? 0);

            $lateMin      += $l;
            $undertimeMin += $u;
            $otHours      += $o;
            if ($l > self::LATE_GRACE_MINUTES) {
                $lateMinDeducted += $l;
            }

            $late      += self::lateDeduction($l, $hourly);
            $undertime += self::undertimeDeduction($u, $hourly);
            $ot        += self::otPay($o, $hourly);
        }

        $basic      = round($monthly / 2, 2);
        $late       = round($late, 2);
        $undertime  = round($undertime, 2);
        $absence    = round($absence, 2);
        $ot         = round($ot, 2);
        $deductions = round($late + $undertime + $absence, 2);
        $gross      = round($basic + $ot - $deductions, 2);

        if ($gross < 0) {
            $warnings[] = 'Deductions exceed basic pay plus OT; gross pay set to 0.00.';
            $gross      = 0.0;
        }

        return [
            'employee_id'                  => $employee['employee_id'],
            'employee_name'                => trim(($employee['last_name'] ?? '') . ', ' . ($employee['first_name'] ?? ''), ', '),
            'schedule_type'                => $schedule,
            'monthly_salary'               => round($monthly, 2),
            'daily_rate'                   => round($daily, 2),
            'hourly_rate'                  => round($hourly, 4),
            'days_recorded'                => count(array_filter($dtrRows, static fn ($r) => ! empty($r['approved']))),
            'days_absent'                  => $absentDays,
            'late_minutes'                 => $lateMin,
            'late_minutes_deducted'        => $lateMinDeducted,
            'undertime_minutes'            => $undertimeMin,
            'ot_hours'                     => round($otHours, 2),
            'basic_pay'                    => $basic,
            'ot_pay'                       => $ot,
            'late_deduction'               => $late,
            'undertime_deduction'          => $undertime,
            'absence_deduction'            => $absence,
            'total_deductions'             => $deductions,
            'gross_pay'                    => $gross,
            'net_pay'                      => $gross,
            'statutory_deductions_applied' => false,
            'warnings'                     => $warnings,
        ];
    }

    private static function schedule(string $type): array
    {
        return self::SCHEDULES[$type] ?? throw new \InvalidArgumentException("Unknown schedule type '{$type}'. Expected 8hrs or 9.6hrs.");
    }
}
