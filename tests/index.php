<?php

const KIRBY_HELPER_DUMP = false;
const KIRBY_HELPER_E = false;

require 'kirby/bootstrap.php';

$time = microtime(true);

$kirby = new \Kirby\Cms\App;
if ($kirby->option('stopwatch')) {
    $time = microtime(true) - $time;
    header('X-Stopwatch-Load: '.number_format($time * 1000, 0).'ms');

    $time = microtime(true);
    $render = $kirby->render();
    $time = microtime(true) - $time;
    header('X-Stopwatch-Render: '.number_format($time * 1000, 0).'ms');
    echo $render;
} else {
    echo $kirby->render();
}
