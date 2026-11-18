<?php

namespace App\Libraries;

/**
 * Builds a payroll run for one pay period from employee master data and
 * approved DTR. Pure orchestration: no Firestore, so it is unit tested
 * with an in-memory PayrollSource.
 *
 * Rules from the M4 specification:
 *  - Only approved DTR is paid; pending records are listed as warnings.
 *  - An employee with no DTR in the period is treated as present every day.
 *  - DTR for an employee missing from master data is logged as a warning
 *    and skipped; the rest of the run continues.
 *  - Inactive employees are paid only if they have approved DTR in the period.
 */
class PayrollService
{
    public function __construct(private readonly PayrollSource $source)
    {
    }

    /**
     * @return array{period: PayPeriod, details: list<array>, warnings: list<string>, totals: array}
     */
    public function compute(PayPeriod $period): array
    {
        $employees = [];
        foreach ($this->source->employees() as $e) {
            $employees[$e['employee_id']] = $e;
        }

        $dtr      = $this->source->dtr($period->start, $period->end);
        $byEmp    = [];
        $warnings = [];

        foreach ($dtr['data'] as $row) {
            $id = (string) ($row['employee_id'] ?? '');
            if (! isset($employees[$id])) {
                $warnings[] = "DTR for {$id} on {$row['date']} skipped: employee not found in master data.";
                log_message('warning', "Payroll {$period->id}: DTR for unknown employee {$id} on {$row['date']}");
                continue;
            }
            if (empty($row['approved'])) {
                continue;
            }
            $byEmp[$id][] = $row;
        }

        if (($pendingCount = count($dtr['pending'])) > 0) {
            $names      = array_unique(array_column($dtr['pending'], 'employee_id'));
            $warnings[] = "{$pendingCount} DTR record(s) are still pending approval and were NOT included ("
                . implode(', ', array_slice($names, 0, 10)) . (count($names) > 10 ? ', …' : '') . ').';
        }

        $details = [];
        foreach ($employees as $id => $e) {
            $active = ($e['status'] ?? 'active') === 'active';
            if (! $active && empty($byEmp[$id])) {
                continue;
            }
            try {
                $details[] = PayrollCalculator::compute($e, $byEmp[$id] ?? []);
            } catch (\InvalidArgumentException $ex) {
                $warnings[] = "{$id} skipped: " . $ex->getMessage();
            }
        }

        // "No DTR = present every day" pays a full period on no evidence at all.
        // Name those employees so the operator confirms they actually worked
        // (lesson from Queensland Health, which kept paying staff who had left).
        $withDtr  = array_flip(array_merge(array_keys($byEmp), array_column($dtr['pending'], 'employee_id')));
        $noRecord = [];
        foreach ($details as $d) {
            if (! isset($withDtr[$d['employee_id']])) {
                $noRecord[] = $d['employee_id'];
            }
        }
        if ($noRecord !== []) {
            sort($noRecord);
            $warnings[] = count($noRecord) . ' active employee(s) have no DTR for this period and were paid as present every day ('
                . implode(', ', array_slice($noRecord, 0, 10)) . (count($noRecord) > 10 ? ', …' : '') . '). Confirm they worked before finalizing.';
        }

        usort($details, static fn ($a, $b) => strcmp($a['employee_id'], $b['employee_id']));

        return [
            'period'     => $period,
            'details'    => $details,
            'warnings'   => $warnings,
            'totals'     => self::totals($details),
            'input_hash' => self::inputHash($employees, $dtr),
        ];
    }

    /**
     * Fingerprint of everything a run depends on (employee master data, approved
     * and pending DTR). If it differs at finalize time, data changed in another
     * app since the draft was computed, even if a change event was missed.
     */
    public function currentInputHash(PayPeriod $period): string
    {
        $employees = [];
        foreach ($this->source->employees() as $e) {
            $employees[$e['employee_id']] = $e;
        }

        return self::inputHash($employees, $this->source->dtr($period->start, $period->end));
    }

    private static function inputHash(array $employees, array $dtr): string
    {
        ksort($employees);
        $norm = static function (array $rows): array {
            usort($rows, static fn ($a, $b) => [$a['date'] ?? '', $a['employee_id'] ?? ''] <=> [$b['date'] ?? '', $b['employee_id'] ?? '']);

            return $rows;
        };

        return hash('sha256', json_encode([array_values($employees), $norm($dtr['data']), $norm($dtr['pending'])]));
    }

    public static function totals(array $details): array
    {
        $keys = ['basic_pay', 'ot_pay', 'late_deduction', 'undertime_deduction', 'absence_deduction', 'total_deductions', 'gross_pay', 'net_pay'];
        $t    = array_fill_keys($keys, 0.0);
        foreach ($details as $d) {
            foreach ($keys as $k) {
                $t[$k] += $d[$k];
            }
        }

        return array_map(static fn ($v) => round($v, 2), $t) + ['employee_count' => count($details)];
    }
}
