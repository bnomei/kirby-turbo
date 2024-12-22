<?php if (get('index') == 'all') {

    snippet('layouts/default', slots: true);

    $modelCount = 0;
    ?>

    <blockquote>
        <ul>
            <?php foreach (site()->index() as $child) {
                $modelCount++; ?>
                <li><a href="<?= $child->url() ?>"><?= $child->title() ?> [<?= $child->modified() ?>
                        ] <?= $child->uuid() ?></a></li>
            <?php } ?>
        </ul>
    </blockquote>

    <div id="modelCount"><?= $modelCount ?></div>
<?php }
