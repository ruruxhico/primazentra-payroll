<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5">Compute payroll</h1>
                <p class="text-muted small">Pulls active employees from PrimaZentra and approved DTR from Attendance. Re-computing a draft replaces it.</p>
                <form method="post" action="<?= base_url('payroll/compute') ?>">
                    <?= csrf_field() ?>
                    <div class="row g-2">
                        <div class="col-4">
                            <label class="form-label small" for="year">Year</label>
                            <input class="form-control" type="number" id="year" name="year" min="2020" max="2100" value="<?= substr($suggest->start, 0, 4) ?>" required>
                        </div>
                        <div class="col-8">
                            <label class="form-label small" for="month">Month</label>
                            <select class="form-select" id="month" name="month">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= (int) substr($suggest->start, 5, 2) === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small" for="half">Period</label>
                            <select class="form-select" id="half" name="half">
                                <option value="A" <?= str_ends_with($suggest->id, 'A') ? 'selected' : '' ?>>1st–15th</option>
                                <option value="B" <?= str_ends_with($suggest->id, 'B') ? 'selected' : '' ?>>16th–end of month</option>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn-pz w-100 mt-3">Compute draft</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <h2 class="h5">Payroll runs</h2>
        <div class="table-responsive bg-white rounded shadow-sm">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>Period</th><th>Status</th><th class="num">Employees</th><th class="num">Gross</th><th>Computed</th></tr>
            </thead>
            <tbody>
            <?php if (empty($runs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No payroll computed yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($runs as $r): $p = \App\Libraries\PayPeriod::fromId($r['period_id']); ?>
                <tr>
                    <td><a href="<?= base_url('payroll/' . $r['period_id']) ?>"><?= esc($p->label()) ?></a></td>
                    <td><span class="badge <?= $r['status'] === 'final' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= esc(ucfirst($r['status'])) ?></span>
                        <?php if ($r['status'] === 'draft' && ! empty($r['stale'])): ?>
                            <span class="badge text-bg-info" title="<?= esc($r['stale_reason'] ?? '') ?>">Out of date · updates when opened</span>
                        <?php endif; ?></td>
                    <td class="num"><?= (int) ($r['totals']['employee_count'] ?? 0) ?></td>
                    <td class="num">₱<?= number_format((float) ($r['totals']['gross_pay'] ?? 0), 2) ?></td>
                    <td class="small text-muted"><?= esc($r['computed_by_name'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
