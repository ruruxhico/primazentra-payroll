<?php

namespace App\Controllers;

use App\Libraries\HrisApiException;
use App\Libraries\HrisClient;
use App\Libraries\HrisPayrollSource;
use App\Libraries\PayPeriod;
use App\Libraries\PayrollRepository;
use App\Libraries\PayrollService;

class Payroll extends BaseController
{
    private PayrollRepository $repo;

    public function __construct()
    {
        $this->repo = new PayrollRepository();
    }

    public function index()
    {
        try {
            $runs = $this->repo->runs();
        } catch (\Throwable $e) {
            log_message('error', 'Payroll index: ' . $e->getMessage());
            $runs = [];
            session()->setFlashdata('error', 'Could not load payroll runs.');
        }

        // Suggest the most recent period that has already ended.
        $current = PayPeriod::containing(date('Y-m-d'));
        $suggest = PayPeriod::containing((new \DateTimeImmutable($current->start))->modify('-1 day')->format('Y-m-d'));

        return view('payroll/index', ['title' => 'Payroll Runs', 'runs' => $runs, 'suggest' => $suggest]);
    }

    public function compute()
    {
        try {
            $period = PayPeriod::of(
                (int) $this->request->getPost('year'),
                (int) $this->request->getPost('month'),
                (string) $this->request->getPost('half'),
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', 'Choose a valid pay period.');
        }

        $error = $this->recompute($period);
        if ($error !== null) {
            return redirect()->back()->with('error', $error);
        }

        return redirect()->to('payroll/' . $period->id)->with('success', 'Draft payroll computed for ' . $period->label() . '.');
    }

    public function show(string $periodId)
    {
        try {
            $period = PayPeriod::fromId($periodId);
            $run    = $this->repo->run($periodId);
        } catch (\InvalidArgumentException $e) {
            return redirect()->to('/')->with('error', 'Unknown pay period.');
        }
        if ($run === null) {
            return redirect()->to('/')->with('error', 'No payroll has been computed for that period yet.');
        }

        // A draft marked out of date by a change in PrimaZentra or Attendance
        // is brought up to date before it is shown.
        $refreshError = null;
        $refreshed    = null;
        if ($run['status'] === 'draft' && ! empty($run['stale'])) {
            $refreshError = $this->recompute($period);
            if ($refreshError === null) {
                $refreshed = 'Recomputed automatically: ' . ($run['stale_reason'] ?? 'data changed in another app') . '.';
                $run       = $this->repo->run($periodId);
            }
        }

        return view('payroll/show', [
            'title'        => 'Payroll ' . $period->label(),
            'period'       => $period,
            'run'          => $run,
            'details'      => $this->repo->details($periodId),
            'refreshError' => $refreshError,
            'refreshed'    => $refreshed,
        ]);
    }

    /**
     * Finalize: lock the period's DTR in Attendance, confirm nothing changed
     * since the draft was computed, then mark the run final. If Attendance
     * cannot confirm the lock, nothing is finalized.
     */
    public function finalize(string $periodId)
    {
        $run = $this->repo->run($periodId);
        if ($run === null || ($run['status'] ?? '') !== 'draft') {
            return redirect()->to('payroll/' . $periodId)->with('error', 'Only a draft payroll can be finalized.');
        }
        // Warnings must be acknowledged, and the acknowledgment is kept with the
        // run (lesson from Phoenix, which went live despite known warnings).
        // The count must match, so a warning added by a recompute is never
        // finalized on an acknowledgment given for an older list.
        $warnings = $run['warnings'] ?? [];
        if ($warnings !== [] && (int) $this->request->getPost('ack_warnings') !== count($warnings)) {
            return redirect()->to('payroll/' . $periodId)->with('error', 'Not finalized: tick that you reviewed the ' . count($warnings) . ' warning(s) first.');
        }

        $period     = PayPeriod::fromId($periodId);
        $userName   = (string) session()->get('userName');
        $attendance = HrisClient::attendance();

        try {
            $attendance->event('payroll.finalized', ['period_id' => $periodId, 'by' => $userName]);
        } catch (HrisApiException $e) {
            return redirect()->to('payroll/' . $periodId)->with('error', 'Not finalized: Attendance could not lock this period\'s DTR (' . $e->getMessage() . '). Try again.');
        }

        try {
            $changed = (new PayrollService(new HrisPayrollSource()))->currentInputHash($period) !== ($run['input_hash'] ?? '');
            if (! $changed) {
                $this->repo->finalize($periodId, (string) session()->get('userId'), $userName, $warnings);
            }
        } catch (\Throwable $e) {
            $attendance->notify('payroll.reopened', ['period_id' => $periodId]);
            log_message('error', "Payroll finalize {$periodId}: " . $e->getMessage());

            return redirect()->to('payroll/' . $periodId)->with('error', 'Not finalized: ' . $e->getMessage() . ' The DTR lock was released.');
        }

        if ($changed) {
            // Data changed since this draft was computed: release the lock and refresh.
            $attendance->notify('payroll.reopened', ['period_id' => $periodId]);
            $error = $this->recompute($period);

            return redirect()->to('payroll/' . $periodId)->with('warning', 'Not finalized: employee or DTR data changed since this draft was computed. '
                . ($error ?? 'The draft has been updated. Review it and finalize again.'));
        }

        return redirect()->to('payroll/' . $periodId)->with('success', 'Payroll finalized. Attendance has locked this period\'s DTR.');
    }

    /** Reopen a finalized payroll so DTR corrections can be made and pay recomputed. */
    public function reopen(string $periodId)
    {
        $run = $this->repo->run($periodId);
        if ($run === null || ($run['status'] ?? '') !== 'final') {
            return redirect()->to('payroll/' . $periodId)->with('error', 'Only a finalized payroll can be reopened.');
        }

        try {
            HrisClient::attendance()->event('payroll.reopened', ['period_id' => $periodId]);
        } catch (HrisApiException $e) {
            return redirect()->to('payroll/' . $periodId)->with('error', 'Not reopened: Attendance could not unlock this period (' . $e->getMessage() . '). Try again.');
        }

        $this->repo->reopen($periodId, (string) session()->get('userName'));

        return redirect()->to('payroll/' . $periodId)->with('success', 'Payroll reopened as a draft. Attendance has unlocked this period\'s DTR.');
    }

    /** @return string|null error message, or null on success */
    private function recompute(PayPeriod $period): ?string
    {
        try {
            $result = (new PayrollService(new HrisPayrollSource()))->compute($period);
        } catch (HrisApiException $e) {
            log_message('error', "Payroll compute {$period->id}: [{$e->errorCode}] {$e->getMessage()}");

            return 'Payroll was not computed because a required system did not respond: ' . $e->getMessage() . ' Nothing was saved. Try again.';
        }

        try {
            $this->repo->saveDraft($result, (string) session()->get('userId'), (string) session()->get('userName'));
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            log_message('error', "Payroll save {$period->id}: " . $e->getMessage());

            return 'Payroll was computed but could not be saved. Nothing was changed; try again.';
        }

        return null;
    }
}
