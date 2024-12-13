<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cache\Cache;
use Kirby\Cms\App;
use Kirby\Cms\Files;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;
use ReflectionClass;

final class Turbo
{
    private App $kirby;

    private ?array $data = null;

    private ?array $dirs = null;

    public array $options;

    public function __construct(array $options = [])
    {
        $this->kirby = kirby();
        $this->options = array_merge([
            'debug' => boolval(option('debug')),
            'expire' => option('bnomei.turbo.expire'),
            'compression' => boolval(option('bnomei.turbo.compression')),
            'storage' => boolval(option('bnomei.turbo.storage')),
            'cmd.exec' => option('bnomei.turbo.cmd.exec'),
            'cmd.content' => boolval(option('bnomei.turbo.cmd.content')),
            'cmd.modified' => boolval(option('bnomei.turbo.cmd.modified')),
        ], $options);
    }

    private function data(): array
    {
        // lazy load
        if ($this->data === null) {
            [$data, $dirs] = $this->unwrap($this->read());
            $this->data = $data;
            $this->dirs = $dirs;
        }

        return $this->data;
    }

    private function dirs(): array
    {
        $this->data(); // ensure data is loaded

        return $this->dirs ?? [];
    }

    private function unwrap(string $cache): array
    {
        if (! $cache) {
            return [[], []];
        }

        $cache = explode(PHP_EOL, $cache);
        $cache = array_filter($cache);

        $dirs = [];
        $root = $this->kirby->root('content');
        $copy = $cache;
        $cache = [];
        foreach ($copy as $item) {
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
            $cache[hash('xxh3', $path)] = $item;
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

    private function read(): string
    {
        // no cache
        $expire = $this->options['expire'];
        if ($expire === null) {
            return $this->exec();
        }

        // try cache
        $data = $this->cmd()->get('output-'.$this->options['cmd.exec']);
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

    private function exec(): string
    {
        return match ($this->options['cmd.exec']) {
            // 'turbo' => $this->execWithTurbo(), // TODO: implement
            'find' => $this->execWithFind(),
            default => '',
        };
    }

    private function modelsWithTurbo(): array
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

    private function execWithFind(): string
    {
        $root = $this->kirby->root('content');
        $extension = $this->kirby->contentExtension();
        $codes = $this->kirby->multilang() ? array_map(fn ($item) => '.'.$item, $this->kirby->languages()->codes()) : [''];
        $pattern = [];
        foreach ($this->modelsWithTurbo() as $model) {
            foreach ($codes as $code) {
                $pattern[] = "-name '$model$code.$extension'";
            }
        }
        $pattern = implode(' -o ', $pattern);
        $cmd = "find '{$root}' -type f \( $pattern \)";
        if ($this->options['cmd.modified']) {
            $cmd .= " -exec stat -f '%N\t%m' {} \;";
        }
        $output = shell_exec($cmd);

        // store less data by trimming away the know kirby content root folder
        return str_replace($root.'/', '', $output ? $output : '');
    }

    private function write(mixed $data): bool
    {
        $expire = $this->options['expire'];
        if ($expire === null) {
            return false;
        }

        if ($this->options['compression']) {
            // compression is great for paths and repeated data
            // or if storing less data in the cache is required.
            // it comes with a delay and more memory usage as trade-off.
            $data = base64_encode(gzcompress($data));
        }

        return $this->cmd()->set('output-'.$this->options['cmd.exec'], $data, $expire);
    }

    public function is_dir(string $dir): bool
    {
        return array_key_exists($dir, $this->dirs());
    }

    public function scandir(string $dir): array
    {
        // SLOW: using the file system in PHP
        // $scandir = scandir($dir);

        // using TURBO from batch-loaded via command
        return A::get($this->dirs(), $dir, []);
    }

    public function cache(?string $string = 'anything'): Cache
    {
        return kirby()->cache('bnomei.turbo.'.$string);
    }

    public function cmd(): Cache
    {
        return $this->cache('cmd');
    }

    public function storage(): ?Cache
    {
        return $this->options['storage'] ? $this->cache('storage') : null;
    }

    public function inventory(?string $root = null): ?array
    {
        if (! $root) {
            return null;
        }

        // use TurboDir to get the inventory for a directory
        // using the batch-loaded data from a command
        return TurboDir::inventory(
            $root,
            $this->kirby->contentExtension(),
            $this->kirby->contentIgnore(),
            $this->kirby->multilang()
        );
    }

    public function modified(string $root): ?int
    {
        return A::get($this->data(), hash('xxh3', $root).'.modified', null);
    }

    public function content(string $root): ?array
    {
        return A::get($this->data(), hash('xxh3', $root).'.content', null);
    }

    public function serialize(mixed $value): mixed
    {
        if (! $value) {
            return null;
        }

        $value = ! is_string($value) && is_callable($value) ? $value() : $value;

        if (is_array($value)) {
            return array_map(function ($item) {
                return $this->serialize($item);
            }, $value);
        }

        if (is_a($value, 'Kirby\Content\Field')) {
            return $value->value();
        }

        return $value;
    }

    public function patchFilesClass(): bool
    {
        $key = 'files.'.App::versionHash().'.patch';
        $patch = $this->cache()->get($key);
        if (file_exists($patch)) {
            return false;
        }

        $filesClass = (new ReflectionClass(Files::class))->getFileName();
        if ($filesClass && F::exists($filesClass) && F::isWritable($filesClass)) {
            $code = F::read($filesClass);
            if ($code && Str::contains($code, '\Bnomei\TurboFile::factory') === false) {
                $code = str_replace('File::factory(', '\Bnomei\TurboFile::factory(', $code);
                F::write($filesClass, $code);

                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($filesClass); // @codeCoverageIgnore
                }
            }

            return $this->cache()->set($key, date('c'), 0);
        }

        return false;
    }

    private static ?self $singleton = null;

    public static function singleton(array $options = []): Turbo
    {
        if (self::$singleton === null) {
            self::$singleton = new Turbo($options);
        }

        return self::$singleton;
    }

    public static function flush(string $cache): bool
    {
        if (! in_array($cache, ['anything', 'cmd', 'storage'])) {
            return false;
        }

        if (kirby()->option('bnomei.turbo.expire') !== null) {
            return kirby()->cache('bnomei.turbo.'.$cache)->flush();
        }

        return true;
    }
}
