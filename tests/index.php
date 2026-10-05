<?php

use Bnomei\TurboStopwatch;
use Kirby\Cms\App;

const KIRBY_HELPER_DUMP = false;
const KIRBY_HELPER_E = false;

// require 'kirby/bootstrap.php';
require __DIR__.'/../vendor/autoload.php';

TurboStopwatch::tick('kirby:before');
$kirby = new App;
$render = $kirby->render();
TurboStopwatch::tick('kirby:after');

TurboStopwatch::serverTiming();
TurboStopwatch::header('page.render');
TurboStopwatch::header('turbo.read');
// \Bnomei\TurboStopwatch::header('turbo.inventory.cache');
TurboStopwatch::header('turbo.inventory.exec');
TurboStopwatch::header('turbo.inventory.write');
TurboStopwatch::header('kirby');

echo $render;
