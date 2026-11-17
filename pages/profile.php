<?php
// page: change your name, avatar, colour theme and password.

$user = require_login();
page_start('Profile', $user, '');
echo page_header('Profile and settings', $user['email'] . ' · ' . strtolower($user['role']));
?>
<div class="grid-2" style="max-width:1000px;align-items:start">
    <div class="stack-lg">
        <section>
            <?= section_head('Details') ?>
            <div class="card card-pad">
                <?= form_start('update_profile') ?>
                    <label class="field"><span class="label">Display name</span><input class="input" name="name" required minlength="2" maxlength="80" value="<?= e($user['name']) ?>"></label>
                    <fieldset style="border:0;padding:0;margin:0"><legend class="label">Avatar</legend><div class="cluster" style="gap:12px">
                        <label class="pick"><input class="sr-only" type="radio" name="avatar" value="none" <?= $user['avatar'] ? '' : 'checked' ?>><?= avatar($user['name'], null, 52) ?><span class="sr-only">Initials</span></label>
                        <?php foreach (AVATARS as $a): ?><label class="pick"><input class="sr-only" type="radio" name="avatar" value="<?= $a ?>" <?= $user['avatar'] === $a ? 'checked' : '' ?>><?= avatar($user['name'], $a, 52) ?><span class="sr-only">Avatar <?= substr($a, 1) ?></span></label><?php endforeach; ?>
                    </div></fieldset>
                    <button class="btn btn-primary" type="submit">Save details</button>
                </form>
            </div>
        </section>
        <section>
            <?= section_head('Theme') ?>
            <div class="card card-pad">
                <?= form_start('set_theme') ?>
                    <fieldset class="grid-2" style="border:0;padding:0;margin:0;gap:8px"><legend class="sr-only">Colour theme</legend>
                        <?php foreach (THEMES as $id => $t): ?>
                            <label class="theme-option"><input class="sr-only" type="radio" name="theme" value="<?= $id ?>" <?= theme_for($user) === $id ? 'checked' : '' ?>>
                                <span class="swatch"><span style="background:<?= $t['accent'] ?>"></span></span><?= e($t['label']) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <button class="btn btn-primary" type="submit">Use this theme</button>
                </form>
            </div>
        </section>
    </div>
    <section id="password">
        <?= section_head('Password') ?>
        <div class="card card-pad">
            <?= form_start('change_password') ?>
                <label class="field"><span class="label">Current password</span><input class="input" type="password" name="current_password" required autocomplete="current-password"></label>
                <label class="field"><span class="label">New password</span><input class="input" type="password" name="new_password" required minlength="10" autocomplete="new-password"><span class="hint"><?= e(PASSWORD_HINT) ?></span></label>
                <p class="small faint">Changing your password signs you out on every other device.</p>
                <button class="btn btn-primary" type="submit">Change password</button>
            </form>
        </div>
    </section>
</div>
<?php page_end(); ?>
