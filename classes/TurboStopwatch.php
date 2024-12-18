<?php

namespace Bnomei;

use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

class TurboStopwatch
{
    public static array $timestamps = [];

    public static function tick(string $event): void
    {
        static::$timestamps[$event] = microtime(true);
    }

    public static function events(): array
    {
        return array_unique(array_map(
            fn ($item) => explode(':', $item)[0],
            array_keys(static::$timestamps)
        ));
    }

    public static function duration(string $event): ?int
    {
        $before = A::get(self::$timestamps, $event.':before', null);
        $after = A::get(self::$timestamps, $event.':after', null);

        if (! $before || ! $after) {
            return null;
        }

        return (int) round(($after - $before) * 1000);
    }

    public static function header(string $event): void
    {
        $duration = static::duration($event);
        if ($duration === null) {
            return;
        }

        header('X-Stopwatch-'.implode('-', array_map('ucfirst', explode('-', str_replace('.', '-', $event)))).': '.$duration.'ms');
    }

    /*
 * miss|static
 */
    public static function serverTimingHeader(string $cache = 'miss'): void
    {
        $msg = '';
        $data = [
            'cache' => $cache,
        ];
        foreach (static::events() as $event) {
            $data[$event] = static::duration($event).'ms';
        }
        foreach ($data as $key => $value) {
            $msg .= Str::snake(str_replace('.', '_', $key)).';desc="'.$value.'",';
        }

        header('server-timing: '.rtrim($msg, ','));
    }
}
