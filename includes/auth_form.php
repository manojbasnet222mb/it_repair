<?php
/**
 * Shared Auth Form Layout
 *
 * Variables you must define before including this file:
 *   $title   string  (e.g. "Login")
 *   $subtitle string (e.g. "Access your panel...")
 *   $errors  array   (validation errors, can be [])
 *   $form    string  (the inner <form> HTML block)
 */
?>
<main class="hero" style="min-height:60vh;">
  <div class="hero-copy">
    <h1><?= e($title) ?></h1>
    <p class="subtitle"><?= e($subtitle) ?></p>

    <?php if($errors): ?>
      <div class="card" style="max-width:520px; margin:10px 0;">
        <strong>Fix the following:</strong>
        <ul class="badges" style="margin-top:8px;">
          <?php foreach($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?= $form ?>
  </div>
</main>
