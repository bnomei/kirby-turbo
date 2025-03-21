<?php snippet('layouts/default', slots: true);
$modelCount = 0;
$hash = '';
foreach (site()->index() as $page) {
    $hash .= $page->title();
    $modelCount++;
}

echo hash('xxh3', $hash);
?>

<div id="modelCount"><?= $modelCount ?></div>
