<div class="login">
    <div class="login-brand">
        <span class="brand-mark brand-mark-lg" aria-hidden="true">P</span>
        <h1>Purchase Requisition</h1>
        <p>PT Permata Indo Kemas</p>
    </div>

    <div class="card login-card">
        <?= \App\Core\View::partial('flash') ?>
        <form method="post" action="<?= e(url('/login')) ?>" class="stack" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" autocomplete="username" required autofocus maxlength="190">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required maxlength="72">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Masuk</button>
        </form>
    </div>

    <p class="login-footnote">Sistem internal. Aktivitas login dicatat pada audit trail.</p>
</div>
