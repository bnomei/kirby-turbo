<?php

const KIRBY_HELPER_DUMP = false;
const KIRBY_HELPER_E = false;

// require 'kirby/bootstrap.php';
require __DIR__.'/../vendor/autoload.php';

\Bnomei\TurboStopwatch::tick('kirby:before');
$kirby = new \Kirby\Cms\App;
$render = $kirby->render();
\Bnomei\TurboStopwatch::tick('kirby:after');

\Bnomei\TurboStopwatch::serverTiming();
\Bnomei\TurboStopwatch::header('page.render');
\Bnomei\TurboStopwatch::header('turbo.read');
// \Bnomei\TurboStopwatch::header('turbo.inventory.cache');
\Bnomei\TurboStopwatch::header('turbo.inventory.exec');
\Bnomei\TurboStopwatch::header('turbo.inventory.write');
\Bnomei\TurboStopwatch::header('kirby');

echo $render;
