<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Authenticates service-to-service calls between the HRIS apps.
 *
 * Callers send `X-Api-Key: <key>`. Accepted keys come from the
 * HRIS_API_KEYS env value, formatted as `caller:key,caller:key`
 * (for example `attendance:abc123,payroll:def456`), so each consuming
 * app has its own key that can be rotated on its own.
 */
class ServiceKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $given = trim($request->getHeaderLine('X-Api-Key'));

        if ($given !== '') {
            foreach (self::configuredKeys() as $key) {
                if (hash_equals($key, $given)) {
                    return null;
                }
            }
        }

        return service('response')
            ->setStatusCode(401)
            ->setJSON(['error' => ['code' => 'unauthorized', 'message' => 'Missing or invalid X-Api-Key.']]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    /** @return array<string, string> caller => key */
    public static function configuredKeys(): array
    {
        $keys = [];
        foreach (explode(',', (string) env('HRIS_API_KEYS', '')) as $pair) {
            [$caller, $key] = array_pad(explode(':', trim($pair), 2), 2, '');
            if ($caller !== '' && $key !== '') {
                $keys[$caller] = $key;
            }
        }

        return $keys;
    }
}
