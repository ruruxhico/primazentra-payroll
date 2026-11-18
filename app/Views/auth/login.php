<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="row justify-content-center mt-5">
    <div class="col-12 col-sm-8 col-md-5 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-1">Log in</h1>
                <p class="text-muted small mb-4">Use your PrimaZentra account.</p>
                <form method="post" action="<?= base_url('login') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" type="email" id="email" name="email" value="<?= esc(old('email')) ?>" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" type="password" id="password" name="password" required>
                    </div>
                    <button class="btn btn-pz w-100">Log in</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
