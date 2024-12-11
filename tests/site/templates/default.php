<?php snippet('layouts/default', slots: true); ?>

<?php snippet('list') ?>

<?= $page::class ?><br>
<?= $page->template() ?><br>
<?= $page->modified() ?><br>
<?= $page->title()->value() ?><br>
<?= $page->uuid() ?><br>
<?= $page->hasTurbo() === true ? 'TURBO' : 'NOPE' ?>

<pre>
<?php print_r($page->content()->toArray()) ?>
</pre>
