<?php
// page: student sign-up. Lecturer accounts are made by an admin instead.

if (current_user()) {
    redirect(url('dashboard'));
}
page_start('Create account');
?>
<div style="max-width:460px;margin:64px auto 0">
    <h1>Create a student account</h1>
    <p class="muted mt-2">Lecturer accounts are created by the academic administrator.</p>
    <div class="card card-pad mt-6">
        <?= form_start('register') ?>
            <label class="field"><span class="label">Full name</span><input class="input" name="name" required minlength="2" maxlength="80" autocomplete="name" value="<?= e(old('name')) ?>"></label>
            <label class="field"><span class="label">Email</span><input class="input" name="email" type="email" required autocomplete="email" value="<?= e(old('email')) ?>"></label>
            <label class="field"><span class="label">Student number</span><input class="input tnum" name="student_number" required inputmode="numeric" pattern="\d{10}" maxlength="10" placeholder="2802405150" value="<?= e(old('student_number')) ?>"><span class="hint">10 digits, as printed on your student card.</span></label>
            <label class="field"><span class="label">Password</span><input class="input" name="password" type="password" required minlength="10" autocomplete="new-password"><span class="hint"><?= e(PASSWORD_HINT) ?></span></label>
            <button class="btn btn-primary btn-block" type="submit">Create account</button>
        </form>
    </div>
    <p class="center small muted mt-4">Already registered? <a class="strong" style="color:var(--accent-text)" href="<?= url('login') ?>">Sign in</a></p>
</div>
<?php page_end(); ?>
