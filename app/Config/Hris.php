<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Hris extends BaseConfig
{
    public string $appName = 'PrimaZentra Payroll';

    /** Roles (from the main app) allowed to log in. Payroll is admin-level (M4 spec). */
    public array $allowedRoles = ['Administrator', 'Operations Manager'];
}
