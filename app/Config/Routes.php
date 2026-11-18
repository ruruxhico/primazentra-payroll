<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Login (delegated to the main PrimaZentra app)
$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::processLogin');
$routes->post('logout', 'Auth::logout');

$routes->group('', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Payroll::index');
    $routes->post('payroll/compute', 'Payroll::compute');
    $routes->get('payroll/(:segment)', 'Payroll::show/$1');
    $routes->post('payroll/(:segment)/finalize', 'Payroll::finalize/$1');
    $routes->post('payroll/(:segment)/reopen', 'Payroll::reopen/$1');
});

// Service API for the main app and hris-attendance (X-Api-Key, see the servicekey filter)
$routes->post('api/v1/events', 'Api\V1\Events::receive');
$routes->get('api/v1/runs', 'Api\V1\Runs::index');
$routes->get('api/v1/runs/(:segment)', 'Api\V1\Runs::show/$1');
$routes->get('api/v1/employees/(:segment)/payslips', 'Api\V1\Runs::payslips/$1');
