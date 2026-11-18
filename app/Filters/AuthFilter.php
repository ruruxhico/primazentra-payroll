<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    private const TIMEOUT = 8 * 60 * 60; // 8 hours, same as the main app

    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (! $session->get('isLoggedIn')) {
            return redirect()->to('login');
        }
        if (time() - (int) $session->get('login_time') > self::TIMEOUT) {
            $session->destroy();

            return redirect()->to('login')->with('error', 'Your session has expired. Please log in again.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
