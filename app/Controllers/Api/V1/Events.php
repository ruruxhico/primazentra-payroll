<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\PayPeriod;
use App\Libraries\PayrollRepository;

/**
 * POST /api/v1/events — change notifications from the other HRIS apps.
 *
 *   employee.changed {employee_id}          (main)       -> every draft run is out of date
 *   dtr.changed      {dates[], employee_ids[]} (attendance) -> drafts for those periods are out of date
 *
 * Out-of-date drafts recompute automatically when next opened. Finalized
 * runs never change; Attendance locks their DTR. Unknown event types are
 * accepted and ignored (202).
 */
class Events extends BaseController
{
    public function receive()
    {
        $event = $this->request->getJSON(true);
        $type  = (string) ($event['type'] ?? '');
        $data  = (array) ($event['data'] ?? []);

        if ($type === '') {
            return $this->error(422, 'validation', 'Event type is required.');
        }

        try {
            $repo = new PayrollRepository();

            switch ($type) {
                case 'employee.changed':
                    $marked = $repo->markStale(null, 'Employee ' . ($data['employee_id'] ?? '') . ' changed in PrimaZentra');
                    break;

                case 'dtr.changed':
                    $periods = [];
                    foreach ((array) ($data['dates'] ?? []) as $date) {
                        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            $periods[] = PayPeriod::containing($date)->id;
                        }
                    }
                    if ($periods === []) {
                        return $this->error(422, 'validation', 'dtr.changed needs at least one YYYY-MM-DD date.');
                    }
                    $marked = $repo->markStale(array_values(array_unique($periods)), 'DTR changed in Attendance');
                    break;

                default:
                    return $this->response->setStatusCode(202)->setJSON(['api_version' => 'v1', 'status' => 'ignored']);
            }
        } catch (\Throwable $e) {
            log_message('error', "HRIS event {$type} failed: " . $e->getMessage());

            return $this->error(503, 'unavailable', 'Event could not be applied.');
        }

        log_message('info', "HRIS event {$type} from " . ($event['source'] ?? '?') . ' marked stale: ' . implode(',', $marked));

        return $this->response->setJSON(['api_version' => 'v1', 'status' => 'applied', 'stale_runs' => $marked]);
    }

    private function error(int $status, string $code, string $message)
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => ['code' => $code, 'message' => $message]]);
    }
}
