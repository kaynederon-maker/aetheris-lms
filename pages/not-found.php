<?php
/** PAGE: shown when a page does not exist, or the user has no access to it. */

page_start('Not found', current_user());
?>
<div class="center" style="padding:80px 0">
    <p class="mono faint">404</p>
    <h1 class="mt-2" style="font-size:1.5rem">This page isn't available</h1>
    <p class="muted mt-2">It may not exist, or your account may not have access to it.</p>
    <a class="btn btn-primary mt-6" href="index.php">Back to the start</a>
</div>
<?php page_end(); ?>
