<?php

namespace App\Libraries;

/**
 * Production PayrollSource: employee master data from the main app,
 * approved DTR from hris-attendance, both over their v1 REST APIs.
 * Errors propagate as HrisApiException so the run stops and nothing is saved.
 */
class HrisPayrollSource implements PayrollSource
{
    public function employees(): array
    {
        return HrisClient::main()->get('api/v1/employees')['data'] ?? [];
    }

    public function dtr(string $from, string $to): array
    {
        $body = HrisClient::attendance()->get('api/v1/dtr', ['from' => $from, 'to' => $to]);

        return ['data' => $body['data'] ?? [], 'pending' => $body['pending'] ?? []];
    }
}
