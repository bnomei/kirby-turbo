<?php $modelCount = 0; ?>
<ol>
  <?php
  /** @var \Kirby\Cms\Page $page * */
  foreach ($page->children() as $child) {
      $modelCount++;
      // NOTE: loading title() will slow down kirby intentionally as this means the content file needs to be loaded
      ?>
    <li><a href="<?= $child->url() ?>"><?= $child->title() ?></a></li><?php
  }
?>
</ol>

<div id="modelCount"><?= $modelCount ?></div>
