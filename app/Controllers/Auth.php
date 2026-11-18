<?php

namespace App\Controllers;

use App\Libraries\HrisApiException;
use App\Libraries\HrisClient;
use Config\Hris;

/**
 * Login is delegated to the main PrimaZentra app (POST /api/v1/auth/login),
 * so all three apps share one set of accounts and roles.
 */
class Auth extends BaseController
{
    public function login()
    {
        if (session()->get('isLoggedIn')) {
            return redirect()->to('/');
        }

        return view('auth/login', ['appName' => config(Hris::class)->appName]);
    }

    public function processLogin()
    {
        $email    = trim((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');

        try {
            $user = HrisClient::main()->post('api/v1/auth/login', ['email' => $email, 'password' => $password])['data'];
        } catch (HrisApiException $e) {
            $msg = $e->httpStatus === 0 || $e->httpStatus >= 500
                ? 'The main PrimaZentra app is unavailable. Try again shortly.'
                : $e->getMessage();
            log_message('warning', "Login failed for {$email}: [{$e->errorCode}] {$e->getMessage()}");

            return redirect()->back()->withInput()->with('error', $msg);
        }

        if (! in_array($user['role'] ?? '', config(Hris::class)->allowedRoles, true)) {
            return redirect()->back()->withInput()->with('error', 'Your role (' . esc($user['role'] ?? 'none') . ') does not have access to this app.');
        }

        session()->regenerate(true);
        session()->set([
            'isLoggedIn' => true,
            'userId'     => $user['uid'],
            'userName'   => $user['name'] !== '' ? $user['name'] : $user['email'],
            'userEmail'  => $user['email'],
            'role'       => $user['role'],
            'login_time' => time(),
        ]);

        return redirect()->to('/');
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('login');
    }
}
