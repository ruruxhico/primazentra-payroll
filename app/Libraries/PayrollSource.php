<?php

namespace App\Libraries;

/**
 * Where a payroll run gets its inputs. Production uses the REST APIs
 * (HrisPayrollSource); tests use an in-memory implementation.
 */
interface PayrollSource
{
    /** @return list<array> employees from the main app (all statuses) */
    public function employees(): array;

    /**
     * @return array{data: list<array>, pending: list<array>} approved DTR rows
     *         and pending {employee_id, date} pairs for the range
     */
    public function dtr(string $from, string $to): array;
}
