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
            'content' => boolval(option('bnomei.turbo.content')),
            'modified' => boolval(option('bnomei.turbo.modified')),
            'compression' => boolval(option('bnomei.turbo.compression')),
            'exec' => option('bnomei.turbo.exec'),
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
                $item = explode("\t", $item);
            }
            $path = $root.'/'.$item[0];
            $item = [
                'dir' => dirname($path),
                'path' => $path,
                'slug' => basename($path),
                'modified' => isset($item[1]) ? (int) $item[1] : null,
                'content' => isset($item[2]) ? json_decode($item[2], true) : null,
            ];
            // add file
            $dirs[$item['dir']][] = $item['slug'];
            // add dir to parent
            $dirs[dirname($item['dir'])][] = basename($item['dir']);
        }

        // sort files/dirs inside dirs
        foreach ($dirs as $path => $refs) {
            $dirs[$path] = array_unique($refs);
            natsort($dirs[$path]);
        }

        return [$cache, $dirs];
    }

    public function read(): string
    {
        // no cache
        $expire = $this->options['expire'];
        if ($expire === null) {
            return $this->exec();
        }

        // try cache
        $data = kirby()->cache('bnomei.turbo')->get('data');
        if ($data) {
            if ($this->options['compression']) {
                $data = gzuncompress(base64_decode($data));
            }
        } else {
            // update cache
            $data = $this->exec();
            $this->write($data);
        }

        return $data;
    }

    public function exec(): string
    {
        return match ($this->options['exec']) {
            // 'turbo' => $this->execWithTurbo(), // TODO: implement
            'find' => $this->execWithFind(),
            default => '',
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

    public function execWithFind(): string
    {
        $root = $this->kirby->root('content');
        $extension = $this->kirby->contentExtension();
        $codes = $this->kirby->multilang() ? array_map(fn ($item) => '.'.$item, $this->kirby->languages()->codes()) : [''];
        $pattern = [];
        foreach ($this->models() as $model) {
            foreach ($codes as $code) {
                $pattern[] = "-name '$model$code.$extension'";
            }
        }
        $pattern = implode(' -o ', $pattern);
        $cmd = "find '{$root}' -type f \( $pattern \)";
        if ($this->options['modified']) {
            $cmd .= " -exec stat -f '%N\t%m' {} \;";
        }
        $output = shell_exec($cmd);

        return str_replace($root.'/', '', $output ? $output : ''); // store less data
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

        // TODO: $languageCode might not be in $root yet
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
