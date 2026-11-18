<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $peso = static fn ($v) => '₱' . number_format((float) $v, 2); $t = $run['totals'] ?? []; $isFinal = ($run['status'] ?? '') === 'final'; ?>

<a href="<?= base_url('/') ?>" class="small">← All payroll runs</a>
<div class="d-flex flex-wrap align-items-center gap-3 my-2">
    <h1 class="h4 mb-0">Payroll · <?= esc($period->label()) ?></h1>
    <span class="badge <?= $isFinal ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $isFinal ? 'Final' : 'Draft' ?></span>
    <?php if (! $isFinal): ?>
    <div class="ms-md-auto d-flex gap-2">
        <form method="post" action="<?= base_url('payroll/compute') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="year" value="<?= substr($period->start, 0, 4) ?>">
            <input type="hidden" name="month" value="<?= (int) substr($period->start, 5, 2) ?>">
            <input type="hidden" name="half" value="<?= substr($period->id, -1) ?>">
            <button class="btn btn-outline-secondary">Recompute</button>
        </form>
        <form method="post" action="<?= base_url('payroll/' . $period->id . '/finalize') ?>" class="d-flex align-items-center gap-2" onsubmit="return confirm('Finalize this payroll? Attendance will lock this period\'s DTR.');">
            <?= csrf_field() ?>
            <?php if (! empty($run['warnings'])): ?>
            <label class="small form-check-label">
                <input type="checkbox" name="ack_warnings" value="<?= count($run['warnings']) ?>" class="form-check-input" required>
                I reviewed the <?= count($run['warnings']) ?> warning(s) below
            </label>
            <?php endif; ?>
            <button class="btn btn-pz">Finalize</button>
        </form>
    </div>
    <?php else: ?>
    <form method="post" action="<?= base_url('payroll/' . $period->id . '/reopen') ?>" class="ms-md-auto" onsubmit="return confirm('Reopen this payroll? Attendance will unlock this period\'s DTR so corrections can be made.');">
        <?= csrf_field() ?>
        <button class="btn btn-outline-secondary">Reopen for corrections</button>
    </form>
    <?php endif; ?>
</div>
<p class="text-muted small">
    Computed by <?= esc($run['computed_by_name'] ?? '') ?><?= $isFinal ? ' · finalized by ' . esc($run['finalized_by_name'] ?? '') : '' ?>.
    Net pay is before SSS, PhilHealth, Pag-IBIG and withholding tax, which are not applied yet.
</p>

<?php if ($refreshed): ?>
<div class="alert alert-info"><?= esc($refreshed) ?></div>
<?php endif; ?>
<?php if ($refreshError): ?>
<div class="alert alert-danger">
    This draft is <strong>out of date</strong> (<?= esc($run['stale_reason'] ?? 'data changed in another app') ?>)
    and could not be updated automatically: <?= esc($refreshError) ?> Reload the page to try again.
</div>
<?php endif; ?>

<?php if (! empty($run['warnings'])): ?>
<div class="alert alert-warning">
    <strong>Check before finalizing:</strong>
    <ul class="mb-0"><?php foreach ($run['warnings'] as $w): ?><li><?= esc($w) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="table-responsive bg-white rounded shadow-sm">
<table class="table table-sm table-hover mb-0">
    <thead class="table-light">
        <tr>
            <th>Employee</th><th>Sched.</th>
            <th class="num">Daily</th><th class="num">Hourly</th>
            <th class="num">Basic</th>
            <th class="num" title="Days absent">Abs.</th><th class="num">Absence</th>
            <th class="num" title="Late minutes deducted / recorded">Late min</th><th class="num">Late</th>
            <th class="num">UT min</th><th class="num">Undertime</th>
            <th class="num">OT hrs</th><th class="num">OT pay</th>
            <th class="num">Gross</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($details as $d): ?>
        <tr>
            <td><span class="text-muted small"><?= esc($d['employee_id']) ?></span> <?= esc($d['employee_name']) ?>
                <?php foreach ($d['warnings'] ?? [] as $w): ?><div class="small text-warning-emphasis"><?= esc($w) ?></div><?php endforeach; ?></td>
            <td><?= esc($d['schedule_type']) ?></td>
            <td class="num"><?= $peso($d['daily_rate']) ?></td>
            <td class="num"><?= number_format((float) $d['hourly_rate'], 4) ?></td>
            <td class="num"><?= $peso($d['basic_pay']) ?></td>
            <td class="num"><?= (int) $d['days_absent'] ?></td>
            <td class="num"><?= $peso($d['absence_deduction']) ?></td>
            <td class="num"><?= (int) $d['late_minutes_deducted'] ?> / <?= (int) $d['late_minutes'] ?></td>
            <td class="num"><?= $peso($d['late_deduction']) ?></td>
            <td class="num"><?= (int) $d['undertime_minutes'] ?></td>
            <td class="num"><?= $peso($d['undertime_deduction']) ?></td>
            <td class="num"><?= number_format((float) $d['ot_hours'], 2) ?></td>
            <td class="num"><?= $peso($d['ot_pay']) ?></td>
            <td class="num fw-semibold"><?= $peso($d['gross_pay']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light fw-semibold">
        <tr>
            <td colspan="4"><?= (int) ($t['employee_count'] ?? 0) ?> employee(s)</td>
            <td class="num"><?= $peso($t['basic_pay'] ?? 0) ?></td>
            <td></td><td class="num"><?= $peso($t['absence_deduction'] ?? 0) ?></td>
            <td></td><td class="num"><?= $peso($t['late_deduction'] ?? 0) ?></td>
            <td></td><td class="num"><?= $peso($t['undertime_deduction'] ?? 0) ?></td>
            <td></td><td class="num"><?= $peso($t['ot_pay'] ?? 0) ?></td>
            <td class="num"><?= $peso($t['gross_pay'] ?? 0) ?></td>
        </tr>
    </tfoot>
</table>
</div>
<?= $this->endSection() ?>
