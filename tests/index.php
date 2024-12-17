<?php

const KIRBY_HELPER_DUMP = false;
const KIRBY_HELPER_E = false;

// require 'kirby/bootstrap.php';
require __DIR__.'/../vendor/autoload.php';

$kirby = new \Kirby\Cms\App;
$render = $kirby->render();
\Bnomei\Turbo::serverTimingHeader();
\Bnomei\Turbo::header('turbo.read');
\Bnomei\Turbo::header('page.render');
echo $render;
