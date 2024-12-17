<?php

namespace Bnomei;

use Kirby\Toolkit\A;

trait ServerTiming
{
    public static array $timestamps = [];

    public static function stopwatch(string $event): void
    {
        static::$timestamps[$event] = microtime(true);
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

    /*
     * miss|static
     */
    public static function serverTimingHeader(string|int $event = 'page.render', string $desc = 'miss'): void
    {
        $event = is_int($event) ? $event : static::duration($event);

        header('server-timing: cache;desc="'.$desc.'", rendertime;desc="'.$event.'ms"');
    }

    public static function header(string $event): void
    {
        header('X-'. implode('-', array_map('ucfirst', explode('-', str_replace('.', '-', $event)))) .': '.static::duration($event).'ms');
    }
}
