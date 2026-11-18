<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= esc(config(\Config\Hris::class)->appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0">
    <style>
        :root { --pz-maroon: #7b1f1f; }
        body { background: #f6f6f7; }
        .navbar { background: var(--pz-maroon); }
        .btn-pz { background: var(--pz-maroon); color: #fff; }
        .btn-pz:hover { background: #5e1717; color: #fff; }
        .material-symbols-outlined { font-size: 1.1rem; vertical-align: -3px; }
        .table td, .table th { vertical-align: middle; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
    </style>
    <?= $this->renderSection('head') ?>
</head>
<body>
<nav class="navbar navbar-expand navbar-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand fw-semibold" href="<?= base_url('/') ?>"><?= esc(config(\Config\Hris::class)->appName) ?></a>
        <?php if (session()->get('isLoggedIn')): ?>
        <ul class="navbar-nav me-auto">
            <?= $this->renderSection('nav') ?>
        </ul>
        <span class="navbar-text text-white-50 me-3 d-none d-md-inline">
            <?= esc(session()->get('userName')) ?> · <?= esc(session()->get('role')) ?>
        </span>
        <form method="post" action="<?= base_url('logout') ?>" class="m-0">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-light">Log out</button>
        </form>
        <?php endif; ?>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4 pb-5">
    <?php foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning'] as $key => $cls): ?>
        <?php if ($msg = session()->getFlashdata($key)): ?>
            <div class="alert alert-<?= $cls ?>"><?= esc($msg) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?= $this->renderSection('content') ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
