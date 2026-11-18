# PrimaZentra Payroll (hris-payroll)

Semi-monthly payroll for the PrimaZentra HRIS (periods 1–15 and 16–end of
month). A run pulls employees from the main app and **approved** DTR from
`hris-attendance` over REST, computes pay, and saves a draft that can be
recomputed until it is finalized.

See `hris-attendance/README.md` for how the three apps fit together.

## Setup

1. `composer install --ignore-platform-req=ext-grpc`
2. Copy the Firebase service-account key to `writable/credentials/firebase_key.json` (git-ignored).
3. Create `.env` (git-ignored):

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost/hris-payroll/'
app.indexPage = ''
session.cookieName = 'hris_payroll_session'

HRIS_MAIN_URL = http://localhost/primazentra-hris/
HRIS_MAIN_API_KEY = <the "payroll:" key in the main app's HRIS_API_KEYS>
HRIS_ATTENDANCE_URL = http://localhost/hris-attendance/
HRIS_ATTENDANCE_API_KEY = <the "payroll:" key in the attendance app's HRIS_API_KEYS>

HRIS_APP_NAME = payroll
# Keys accepted on this app's /api/v1/* (caller:key)
HRIS_API_KEYS = main:<main's HRIS_PAYROLL_API_KEY>,attendance:<attendance's HRIS_PAYROLL_API_KEY>
```

## Draft → final

- Changes in PrimaZentra (employees) or Attendance (DTR) mark affected drafts
  **out of date**; opening one recomputes it automatically.
- **Finalize** locks the period's DTR in Attendance first, then checks that
  employees and DTR are unchanged since the draft was computed. If they
  changed, the lock is released and the draft is recomputed for review.
- If the draft has warnings, Finalize requires ticking that every warning was
  reviewed; the acknowledged warnings are saved on the run
  (`acknowledged_warnings`).
- Active employees with no DTR at all in the period are paid as present every
  day and are named in a warning, so someone confirms they actually worked.
- **Reopen for corrections** unlocks the DTR in Attendance and turns the run
  back into a draft.

## Access

Allowed roles: Administrator, Operations Manager (`app/Config/Hris.php`).

## Formulas (`app/Libraries/PayrollCalculator.php`)

| Item | Formula |
|---|---|
| Daily rate | monthly ÷ 26 (8hrs) or ÷ 22 (9.6hrs) |
| Hourly rate | daily ÷ 8 or ÷ 9.6 |
| Basic pay | monthly ÷ 2 |
| Late | late minutes × hourly ÷ 60, only when late > 15 min (then every minute counts) |
| Undertime | undertime minutes × hourly ÷ 60 |
| Absence | one daily rate per absent day |
| OT pay | OT hours × hourly × 1.25 |
| Gross | basic + OT − late − undertime − absence (never below 0) |

Run rules: no DTR in the period = present every day; pending DTR is not paid
and is listed as a warning; DTR for an employee missing from master data is
skipped with a warning. A recompute rewrites the whole draft in one
Firestore transaction (fits up to ~249 employees).

**Not yet implemented:** SSS, PhilHealth, Pag-IBIG and withholding tax.
Net pay currently equals gross pay, and each result carries
`statutory_deductions_applied: false`.

## Tests

`vendor/bin/phpunit`
