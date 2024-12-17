<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cache\Cache;
use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\Field;
use Kirby\Toolkit\A;
use ReflectionClass;

final class Turbo
{
    use ServerTiming;

    private App $kirby;

    private ?array $data = null;

    public array $options;

    public function __construct(array $options = [])
    {
        $this->kirby = kirby();
        $this->options = array_merge([
            'debug' => boolval(option('debug')),
            'expire' => option('bnomei.turbo.expire'),
            'compression' => boolval(option('bnomei.turbo.compression')),
            'storage.read' => boolval(option('bnomei.turbo.storage.read')),
            'storage.write' => boolval(option('bnomei.turbo.storage.write')),
            'inventory.indexer' => option('bnomei.turbo.inventory.indexer'),
            'inventory.content' => boolval(option('bnomei.turbo.inventory.content')),
            'inventory.modified' => boolval(option('bnomei.turbo.inventory.modified')),
            'inventory.read' => boolval(option('bnomei.turbo.inventory.read')),
        ], $options);

        foreach ($this->options as $key => $value) {
            if ($value instanceof \Closure) {
                $this->options[$key] = $value($this->kirby);
            }
        }
    }

    public function files(): array
    {
        // lazy load
        if ($this->data === null) {
            Turbo::stopwatch('turbo.read:before');
            $this->data = $this->read();
            Turbo::stopwatch('turbo.read:after');
        }

        return $this->data['files'];
    }

    public function dirs(): array
    {
        $this->files(); // ensure data is loaded

        return $this->data['dirs'] ?? [];
    }

    public function unwrap(string $output): array
    {
        $output = trim($output);

        // no data
        if (empty($output)) {
            return ['data' => [], 'dirs' => []];
        }

        $root = $this->kirby->root('content') ?? '';

        // preloaded data as json
        if (strlen($output) > 2 &&
            str_starts_with($output, '{') &&
            str_ends_with($output, '}')
        ) {
            $output = str_replace('"@/', '"'.$root.'/', $output);
            if ($all = json_decode($output, true)) {
                return [
                    'files' => A::get($all, 'files', []),
                    'dirs' => A::get($all, 'dirs', []),
                ];
            }
        }

        // data from "TIMESTAMP\tPATH\n..."
        $output = array_filter(explode(PHP_EOL, $output));

        $files = [];
        $dirs = [];
        foreach ($output as $item) {
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
            $files['#'.hash('xxh3', $path)] = $item; // avoid int casting on keys using prefix
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

        return ['files' => $files, 'dirs' => $dirs];
    }

    public function read(): array
    {
        // no cache
        if ($this->options['expire'] === null) {
            return $this->unwrap($this->exec());
        }

        // try cache
        $data = $this->cache('inventory')?->get('output-'.basename($this->options['inventory.indexer']));
        if ($data && $this->options['compression']) {
            $data = json_decode(gzuncompress(base64_decode($data)), true);
        }
        if (! $data) {
            // update cache
            $data = $this->unwrap($this->exec());
            $this->write($data);
        }

        return $data;
    }

    public function exec(): string
    {
        if ($this->options['inventory.indexer'] === 'find') {
            return $this->execWithFind();
        } elseif (str_contains($this->options['inventory.indexer'], 'turbo')) {
            return $this->execWithTurbo();
        }

        return '';
    }

    public function execWithTurbo(): string
    {
        $root = $this->kirby->root('content');
        $exec = $this->options['inventory.indexer'];
        $patterns = implode(',', $this->modelsWithTurboFilenamePatterns());
        $cmd = "{$exec} --dir '{$root}' --filenames '$patterns'"; // patterns used to filter which files tread content
        $cmd .= $this->options['inventory.modified'] ? ' --modified' : '';
        $cmd .= $this->options['inventory.content'] ? ' --content' : '';
        $output = shell_exec($cmd);

        return $output ?: '';
    }

    public function execWithFind(): string
    {
        $root = $this->kirby->root('content');
        $exec = $this->options['inventory.indexer'];
        /* all files need to be scanned
        $patterns = implode(' -o ', array_map(
            fn ($pattern) => "-name '$pattern'",
            $this->modelsWithTurboFilenamePatterns()
        ));
        $cmd = "{$exec} '{$root}' -type f \( $patterns \)";
        */
        $cmd = "{$exec} '{$root}' -type f";
        $cmd .= $this->options['inventory.modified'] ? " -exec stat -f '%N\t%m' {} \;" : '';
        // NOTE: find does not do content so no option here
        $output = shell_exec($cmd);

        // store less data by trimming away the know kirby content root folder
        return str_replace($root.'/', '', $output ? $output : '');
    }

    public function modelsWithTurbo(): array
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

    public function modelsWithTurboFilenamePatterns(): array
    {
        $extension = $this->kirby->contentExtension();
        $codes = $this->kirby->multilang() ? array_map(fn ($item) => '.'.$item, $this->kirby->languages()->codes()) : [''];
        $patterns = [];
        foreach ($this->modelsWithTurbo() as $model) {
            foreach ($codes as $code) {
                $patterns[] = "$model$code.$extension";
            }
        }

        return $patterns;
    }

    public function write(array $data): bool
    {
        $expire = $this->options['expire'];
        if ($expire === null) {
            return false;
        }

        if ($this->options['compression']) {
            // compression is great for paths and repeated data
            // or if storing fewer data in the cache is required.
            // it comes with a delay and more memory usage as trade-off.
            $data = base64_encode(gzcompress(json_encode($data)));
        }

        return $this->cache('inventory')?->set('output-'.basename($this->options['inventory.indexer']), $data, $expire);
    }

    public function cache(string $cache): ?Cache
    {
        return $this->options['expire'] !== null ? kirby()->cache('bnomei.turbo.'.$cache) : null;
    }

    public function storage(): ?Cache
    {
        return $this->cache('storage');
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
        return A::get($this->files(), '#'.hash('xxh3', $root).'.modified', null);
    }

    public function content(string $root): ?array
    {
        return A::get($this->files(), '#'.hash('xxh3', $root).'.content', null);
    }

    public static function serialize(mixed $value): mixed
    {
        if (! $value) {
            return null;
        }

        $value = ! is_string($value) && is_callable($value) ? $value() : $value;

        if (is_array($value)) {
            return array_map(function ($item) {
                return Turbo::serialize($item);
            }, $value);
        }

        if ($value instanceof Field) {
            return $value->value();
        }

        if ($value instanceof ModelWithContent) {
            return $value->uuid()?->toString() ?? $value->id() ?? null;
        }

        return $value;
    }

    public function tub(): ?Cache
    {
        return $this->cache('tub');
    }

    private static ?self $singleton = null;

    public static function singleton(array $options = []): Turbo
    {
        if (self::$singleton === null) {
            self::$singleton = new Turbo($options);
        }

        return self::$singleton;
    }

    public static function flush(string $cache = 'all'): bool
    {
        if (kirby()->option('bnomei.turbo.expire') === null) {
            return false;
        }

        try {
            $caches = [];
            if (empty($cache) || $cache === '*' || $cache === 'all') {
                $caches[] = 'inventory';
                $caches[] = 'storage';
                $caches[] = 'tub';
            } else {
                $caches[] = $cache;
            }
            foreach ($caches as $c) {
                kirby()->cache('bnomei.turbo.'.$c)->flush();
            }

            return true;
        } catch (\Exception $e) {
            // if given a cache that does not exist or is not flushable
            return false;
        }
    }
}
