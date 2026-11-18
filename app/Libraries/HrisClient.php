<?php

namespace App\Libraries;

use Config\Services;

/**
 * HTTP client for the other HRIS apps' v1 REST APIs.
 *
 * Contract rules (from the M4 integration specification):
 *  - JSON over HTTP, authenticated with an X-Api-Key per calling app.
 *  - Success bodies are {"api_version":"v1","data":...};
 *    errors are {"error":{"code":...,"message":...}}.
 *  - 10-second timeout; failures surface to the operator, who retries.
 *
 * Versioning: semantic. 1.x keeps every public method signature and the
 * HrisApiException codes stable; a breaking change becomes 2.0.0. The file is
 * kept identical in all three apps (check with diff before release).
 */
class HrisClient
{
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 10,
    ) {
    }

    /** Client for the main app (identity + employee master data). */
    public static function main(): self
    {
        return new self((string) env('HRIS_MAIN_URL'), (string) env('HRIS_MAIN_API_KEY'));
    }

    /** Client for hris-attendance (DTR records). */
    public static function attendance(): self
    {
        return new self((string) env('HRIS_ATTENDANCE_URL'), (string) env('HRIS_ATTENDANCE_API_KEY'));
    }

    /** Client for hris-payroll (payroll runs and payslips). */
    public static function payroll(): self
    {
        return new self((string) env('HRIS_PAYROLL_URL'), (string) env('HRIS_PAYROLL_API_KEY'));
    }

    /**
     * Sends a change event to another app: POST api/v1/events
     *   {"type": "...", "source": "<this app>", "occurred_at": ISO-8601, "data": {...}}
     *
     * Throws on failure. Use notify() instead when the caller's own change
     * must succeed even if the other app is down.
     *
     * @throws HrisApiException
     */
    public function event(string $type, array $data): array
    {
        return $this->post('api/v1/events', [
            'type'        => $type,
            'source'      => (string) env('HRIS_APP_NAME', 'unknown'),
            'occurred_at' => date(DATE_ATOM),
            'data'        => $data,
        ]);
    }

    /**
     * Best-effort event: never throws, logs failures. Receivers also have a
     * pull-based fallback, so a lost notification cannot corrupt data.
     */
    public function notify(string $type, array $data): bool
    {
        try {
            $this->event($type, $data);

            return true;
        } catch (HrisApiException $e) {
            log_message('warning', "HRIS event {$type} not delivered: [{$e->errorCode}] {$e->getMessage()}");

            return false;
        }
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    public function post(string $path, array $body): array
    {
        return $this->send('POST', $path, ['json' => $body]);
    }

    /**
     * @return array the decoded response body
     *
     * @throws HrisApiException
     */
    private function send(string $method, string $path, array $options): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new HrisApiException('config', 'HRIS API URL or key is not configured in .env.', 0);
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        try {
            $response = Services::curlrequest([], null, null, false)->request($method, $url, $options + [
                'headers'     => ['X-Api-Key' => $this->apiKey, 'Accept' => 'application/json'],
                'timeout'     => $this->timeout,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new HrisApiException('unreachable', 'Could not reach ' . $url . ': ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true);

        if (! is_array($body)) {
            throw new HrisApiException('bad_response', "Invalid JSON from {$url} (HTTP {$status}).", $status);
        }
        if ($status >= 400) {
            $err = $body['error'] ?? [];
            throw new HrisApiException((string) ($err['code'] ?? 'http_' . $status), (string) ($err['message'] ?? "HTTP {$status} from {$url}"), $status);
        }

        return $body;
    }
}
