<?php

namespace App\Libraries;

use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;

/**
 * Firestore access for the collections this app owns:
 *   payroll_runs/{period_id}                    one per pay period
 *   payroll_details/{period_id}_{employee_id}   one per employee per period
 */
class PayrollRepository
{
    public const RUNS           = 'payroll_runs';
    public const DETAILS        = 'payroll_details';
    public const SCHEMA_VERSION = 1;

    public function __construct(private ?FirestoreClient $db = null)
    {
        $this->db ??= FirestoreService::db();
    }

    /** @return list<array> runs, newest period first */
    public function runs(): array
    {
        $out = [];
        foreach ($this->db->collection(self::RUNS)->documents() as $doc) {
            if ($doc->exists()) {
                $out[] = $doc->data();
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['period_id'], $a['period_id']));

        return $out;
    }

    public function run(string $periodId): ?array
    {
        $snap = $this->db->collection(self::RUNS)->document($periodId)->snapshot();

        return $snap->exists() ? $snap->data() : null;
    }

    /** @return list<array> */
    public function details(string $periodId): array
    {
        $out = [];
        foreach ($this->db->collection(self::DETAILS)->where('period_id', '=', $periodId)->documents() as $doc) {
            if ($doc->exists()) {
                $out[] = $doc->data();
            }
        }
        usort($out, static fn ($a, $b) => strcmp($a['employee_id'], $b['employee_id']));

        return $out;
    }

    /**
     * Saves a computed run as a draft, replacing any earlier draft for the
     * period. Refuses to touch a finalized run.
     *
     * @throws \RuntimeException when the period is already finalized
     */
    public function saveDraft(array $result, string $userId, string $userName): void
    {
        /** @var PayPeriod $period */
        $period  = $result['period'];
        $runRef  = $this->db->collection(self::RUNS)->document($period->id);
        $oldDocs = $this->db->collection(self::DETAILS)->where('period_id', '=', $period->id);
        $db      = $this->db;

        // One transaction: the "not finalized" check and every write succeed
        // or fail together. Firestore allows 500 writes per transaction, so a
        // recompute (old details deleted + new written + run) fits ~249 employees.
        $db->runTransaction(static function ($tx) use ($db, $runRef, $oldDocs, $period, $result, $userId, $userName) {
            $existing = $tx->snapshot($runRef);
            if ($existing->exists() && ($existing->data()['status'] ?? '') === 'final') {
                throw new \RuntimeException("Payroll {$period->id} is finalized and cannot be recomputed.");
            }

            // Remove details of a previous draft (employees may have left the run).
            foreach ($tx->runQuery($oldDocs) as $doc) {
                $tx->delete($doc->reference());
            }

            foreach ($result['details'] as $d) {
                $ref = $db->collection(self::DETAILS)->document($period->id . '_' . $d['employee_id']);
                $tx->set($ref, $d + ['period_id' => $period->id, 'schema_version' => self::SCHEMA_VERSION]);
            }

            $tx->set($runRef, self::runDocument($period, $result, $userId, $userName));
        }, ['maxRetries' => 2]);
    }

    private static function runDocument(PayPeriod $period, array $result, string $userId, string $userName): array
    {
        $now = new Timestamp(new \DateTime());

        return [
            'period_id'        => $period->id,
            'period_start'     => $period->start,
            'period_end'       => $period->end,
            'status'           => 'draft',
            'totals'           => $result['totals'],
            'warnings'         => $result['warnings'],
            'computed_at'      => $now,
            'computed_by'      => $userId,
            'computed_by_name' => $userName,
            'input_hash'       => $result['input_hash'],
            'stale'            => false,
            'schema_version'   => self::SCHEMA_VERSION,
        ];
    }

    /** @param list<string> $acknowledgedWarnings warnings the finalizer ticked as reviewed (audit trail) */
    public function finalize(string $periodId, string $userId, string $userName, array $acknowledgedWarnings = []): void
    {
        $this->db->collection(self::RUNS)->document($periodId)->update([
            ['path' => 'status', 'value' => 'final'],
            ['path' => 'finalized_at', 'value' => new Timestamp(new \DateTime())],
            ['path' => 'finalized_by', 'value' => $userId],
            ['path' => 'finalized_by_name', 'value' => $userName],
            ['path' => 'acknowledged_warnings', 'value' => array_values($acknowledgedWarnings)],
        ]);
    }

    /** Finalized -> draft, marked stale so the next view recomputes it. */
    public function reopen(string $periodId, string $userName): void
    {
        $this->db->collection(self::RUNS)->document($periodId)->update([
            ['path' => 'status', 'value' => 'draft'],
            ['path' => 'stale', 'value' => true],
            ['path' => 'reopened_at', 'value' => new Timestamp(new \DateTime())],
            ['path' => 'reopened_by_name', 'value' => $userName],
        ]);
    }

    /**
     * Marks draft runs out of date after a change in another app.
     * Finalized runs are never touched.
     *
     * @param list<string>|null $periodIds null = every draft
     *
     * @return list<string> period ids marked
     */
    public function markStale(?array $periodIds, string $reason): array
    {
        $marked = [];
        foreach ($this->db->collection(self::RUNS)->where('status', '=', 'draft')->documents() as $doc) {
            if (! $doc->exists() || ($periodIds !== null && ! in_array($doc->id(), $periodIds, true))) {
                continue;
            }
            $doc->reference()->update([
                ['path' => 'stale', 'value' => true],
                ['path' => 'stale_reason', 'value' => $reason],
                ['path' => 'stale_since', 'value' => new Timestamp(new \DateTime())],
            ]);
            $marked[] = $doc->id();
        }

        return $marked;
    }

    /** @return list<array> one employee's payslip lines across all runs, newest first */
    public function detailsForEmployee(string $employeeId): array
    {
        $out = [];
        foreach ($this->db->collection(self::DETAILS)->where('employee_id', '=', $employeeId)->documents() as $doc) {
            if ($doc->exists()) {
                $out[] = $doc->data();
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['period_id'], $a['period_id']));

        return $out;
    }
}
