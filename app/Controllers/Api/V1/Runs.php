<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\PayrollRepository;

/**
 * Read API for the main app and hris-attendance.
 *
 *   GET /api/v1/runs                          all runs (summary)
 *   GET /api/v1/runs/{period_id}              one run with its details
 *   GET /api/v1/employees/{employee_id}/payslips  one employee's lines across runs
 *
 * Run summary fields: period_id, period_start, period_end, status
 * ("draft"|"final"), stale (bool), totals, warnings, computed_by_name.
 */
class Runs extends BaseController
{
    public function index()
    {
        try {
            $runs = array_map(self::summary(...), (new PayrollRepository())->runs());
        } catch (\Throwable $e) {
            log_message('error', 'API runs: ' . $e->getMessage());

            return $this->error(503, 'unavailable', 'Payroll data is temporarily unavailable.');
        }

        return $this->response->setJSON(['api_version' => 'v1', 'count' => count($runs), 'data' => $runs]);
    }

    public function show(string $periodId)
    {
        try {
            $repo = new PayrollRepository();
            $run  = $repo->run($periodId);
            if ($run === null) {
                return $this->error(404, 'not_found', "No payroll for {$periodId}.");
            }
            $details = $repo->details($periodId);
        } catch (\Throwable $e) {
            log_message('error', 'API run show: ' . $e->getMessage());

            return $this->error(503, 'unavailable', 'Payroll data is temporarily unavailable.');
        }

        return $this->response->setJSON(['api_version' => 'v1', 'data' => self::summary($run) + ['details' => $details]]);
    }

    public function payslips(string $employeeId)
    {
        try {
            $repo  = new PayrollRepository();
            $runs  = [];
            foreach ($repo->runs() as $r) {
                $runs[$r['period_id']] = $r;
            }
            $lines = [];
            foreach ($repo->detailsForEmployee($employeeId) as $d) {
                $run     = $runs[$d['period_id']] ?? [];
                $lines[] = $d + ['run_status' => $run['status'] ?? 'unknown', 'run_stale' => (bool) ($run['stale'] ?? false)];
            }
        } catch (\Throwable $e) {
            log_message('error', 'API payslips: ' . $e->getMessage());

            return $this->error(503, 'unavailable', 'Payroll data is temporarily unavailable.');
        }

        return $this->response->setJSON(['api_version' => 'v1', 'count' => count($lines), 'data' => $lines]);
    }

    private static function summary(array $r): array
    {
        return [
            'period_id'        => $r['period_id'],
            'period_start'     => $r['period_start'],
            'period_end'       => $r['period_end'],
            'status'           => $r['status'],
            'stale'            => (bool) ($r['stale'] ?? false),
            'totals'           => $r['totals'] ?? [],
            'warnings'         => $r['warnings'] ?? [],
            'computed_by_name' => $r['computed_by_name'] ?? null,
            'finalized_by_name' => $r['finalized_by_name'] ?? null,
        ];
    }

    private function error(int $status, string $code, string $message)
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => ['code' => $code, 'message' => $message]]);
    }
}
