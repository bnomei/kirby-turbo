<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\App;
use Kirby\Toolkit\A;
use ReflectionClass;

final class Turbo
{
    private App $kirby;

    private ?array $data = null;

    private ?array $dirs = null;

    private array $options;

    public function __construct(array $options = [])
    {
        $this->kirby = kirby();
        $this->options = array_merge([
            'debug' => boolval(option('debug')),
            'expire' => option('bnomei.turbo.expire'),
            'compression' => boolval(option('bnomei.turbo.compression')),
            'fetch' => option('bnomei.turbo.fetch'),
        ], $options);
    }

    public function option(?string $key = null): mixed
    {
        if ($key) {
            return A::get($this->options, $key);
        }

        return $this->options;
    }

    public function data(): array
    {
        // lazy load
        if ($this->data === null) {
            [$data, $dirs] = $this->unwrap($this->read());
            $this->data = $data;
            $this->dirs = $dirs;
        }

        return $this->data;
    }

    public function dirs(): array
    {
        $this->data(); // ensure data is loaded

        return $this->dirs ?? [];
    }

    public function unwrap(string $cache): array
    {
        if (! $cache) {
            return [[], []];
        }

        $cache = explode(PHP_EOL, $cache);
        $cache = array_filter($cache);

        $dirs = [];
        $root = $this->kirby->root('content');
        foreach ($cache as &$item) {
            if (is_string($item)) {
                $item = explode(' ', $item);
            }
            $path = $root.'/'.$item[0];
            $item = [
                'dir' => dirname($path),
                'path' => $path,
                'slug' => basename($path),
                'modified' => isset($item[1]) ? (int) $item[1] : null,
            ];
            // add file
            $dirs[$item['dir']][] = $item['slug'];
            // add dir to parent
            $dirs[dirname($item['dir'])][] = basename($item['dir']);
        }

        // sort files/dirs inside dirs
        foreach ($dirs as $dir => $files) {
            natsort($dirs[$dir]);
        }

        return [$cache, $dirs];
    }

    public function read(): string
    {
        // no cache
        $expire = $this->options['expire'];
        if ($expire === null) {
            return $this->fetch();
        }

        // try cache
        $data = kirby()->cache('bnomei.turbo')->get('data');
        if ($data) {
            if ($this->options['compression']) {
                $data = gzuncompress(base64_decode($data));
            }
        } else {
            // update cache
            $data = $this->fetch();
            $this->write($data);
        }

        return $data;
    }

    public function fetch(): string
    {
        return match ($this->options['fetch']) {
            // 'turbo' => $this->fetchWithTurbo(), // TODO: implement
            'find' => $this->fetchWithFind(),
            default => [],
        };
    }

    public function models(): array
    {
        $models = [];
        foreach ($this->kirby->extensions('pageModels') as $model => $class) {
            $ref = new ReflectionClass(ucfirst($class));
            if ($ref->hasMethod('hasTurbo')) {
                $models[] = $model;
            }
        }

        return $models;
    }

    public function fetchWithFind(): string
    {
        $root = $this->kirby->root('content');
        $extension = $this->kirby->contentExtension();
        $pattern = implode(' -o ', array_map(fn ($model) => " -name '$model.$extension'", $this->models()));

        $cmd = "find '{$root}' -type f \( $pattern \) -exec stat -f '%N %m' {} \;";

        $output = shell_exec($cmd);
        $output = str_replace($root.'/', '', $output ?? ''); // store less data

        return $output;
    }

    public function write(mixed $data): bool
    {
        $expire = $this->options['expire'];
        if ($expire === null) {
            return false;
        }

        if ($this->options['compression']) {
            $data = base64_encode(gzcompress($data));
        }

        return kirby()->cache('bnomei.turbo')->set('data', $data, $expire);
    }

    public function is_dir(string $dir): bool
    {
        return array_key_exists($dir, $this->dirs());
    }

    public function scandir(string $dir): array
    {
        // SLOW: using the file system in PHP
        // $scandir = scandir($dir);

        // using TURBO
        return A::get($this->dirs(), $dir, []);
    }

    public function inventory(?string $root = null): ?array
    {
        if (! $root) {
            return null;
        }

        // use TURBO to get the inventory for a directory
        return TurboDir::inventory(
            $root,
            $this->kirby->contentExtension(),
            $this->kirby->contentIgnore(),
            $this->kirby->multilang()
        );
    }

    public function modified(?string $root = null, ?string $languageCode = null): ?int
    {
        if (! $root) {
            return null;
        }

        return A::get($this->data(), implode('.', array_filter([$root, 'modified', $languageCode])), null);
    }

    public function content(?string $root = null, ?string $languageCode = null): ?array
    {
        if (! $root) {
            return null;
        }

        return A::get($this->data(), implode('.', array_filter([$root, 'content', $languageCode])), null);
    }

    private static ?self $singleton = null;

    public static function singleton(array $options = []): Turbo
    {
        if (self::$singleton === null) {
            self::$singleton = new Turbo($options);
        }

        return self::$singleton;
    }

    public static function flush(): bool
    {
        if (kirby()->option('bnomei.turbo.expire') !== null) {
            return kirby()->cache('bnomei.turbo')->flush();
        }

        return true;
    }
}
