<?php
/** PAGE: sign in. */

if (current_user()) {
    redirect(url('dashboard'));
}
page_start('Sign in');
?>
<div class="narrow" style="max-width:420px;margin:72px auto 0">
    <h1>Sign in</h1>
    <p class="muted mt-2">Use your campus email and password.</p>
    <div class="card card-pad mt-6">
        <?= form_start('login') ?>
            <label class="field"><span class="label">Email</span><input class="input" name="email" type="email" autocomplete="username" required value="<?= e(old('email')) ?>"></label>
            <label class="field"><span class="label">Password</span><input class="input" name="password" type="password" autocomplete="current-password" required></label>
            <button class="btn btn-primary btn-block" type="submit">Sign in</button>
        </form>
    </div>
    <p class="center small muted mt-4">New student? <a class="strong" style="color:var(--accent-text)" href="<?= url('register') ?>">Create an account</a></p>
    <details class="panel mt-8 small">
        <summary class="strong" style="cursor:pointer;min-height:32px">Demo accounts</summary>
        <dl class="stack-sm mt-2">
            <?php foreach ([['Student', 'kayne@student.aetheris.edu', 'Student#2026'], ['Lecturer', 'rina.anggraini@aetheris.edu', 'Lecturer#2026'], ['Admin', 'admin@aetheris.edu', 'Admin#Aetheris2026']] as [$role, $email, $pw]): ?>
                <div><dt class="faint"><?= e($role) ?></dt><dd class="mono" style="margin:0;word-break:break-all"><?= e("$email · $pw") ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </details>
</div>
<?php page_end(); ?>
