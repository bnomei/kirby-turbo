<?php snippet('layouts/default', slots: true); ?>

<blockquote>
  <ul>
    <?php foreach (site()->children() as $child) { ?>
      <li><a href="<?= $child->url() ?>"><?= $child->title() ?></a></li>
    <?php } ?>
  </ul>
</blockquote>
