<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cache\Cache;
use Kirby\Cms\App;
use Kirby\Toolkit\A;
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

        foreach ($this->options as $key => $value) {
            if ($value instanceof \Closure) {
                $this->options[$key] = $value($this->kirby);
            }
        }
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

    public function unwrap(string $output): array
    {
        $output = trim($output);

        // no data
        if (empty($output)) {
            return [[], []];
        }

        $root = $this->kirby->root('content') ?? '';

        // preloaded data as json
        if (strlen($output) > 2 &&
            str_starts_with($output, '{') &&
            str_ends_with($output, '}')
        ) {
            $output = str_replace('"@/', '"'.$root.'/', $output);
            if ($all = json_decode($output, true)) {
                return [A::get($all, 'files', []), A::get($all, 'dirs', [])];
            }
        }

        // data from "TIMESTAMP\tPATH\n..."
        $output = array_filter(explode(PHP_EOL, $output));

        $data = [];
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
            $data['#'.hash('xxh3', $path)] = $item; // avoid int casting on keys using prefix
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

        return [$data, $dirs];
    }

    public function read(): string
    {
        // no cache
        if ($this->options['expire'] === null) {
            return $this->exec();
        }

        // try cache
        $data = $this->cache()?->get('output-'.basename($this->options['cmd.exec']));
        if ($data && $this->options['compression']) {
            $data = gzuncompress(base64_decode($data));
        }
        if (! $data) {
            // update cache
            $data = $this->exec();
            $this->write($data);
        }

        return $data;
    }

    public function exec(): string
    {
        if ($this->options['cmd.exec'] === 'find') {
            return $this->execWithFind();
        } elseif (str_contains($this->options['cmd.exec'], 'turbo')) {
            return $this->execWithTurbo();
        }

        return '';
    }

    public function execWithTurbo(): string
    {
        $root = $this->kirby->root('content');
        $exec = $this->options['cmd.exec'];
        $patterns = implode(',', $this->modelsWithTurboFilenamePatterns());
        $cmd = "{$exec} --dir '{$root}' --filenames '$patterns'";
        $cmd .= $this->options['cmd.modified'] ? ' --modified' : '';
        $cmd .= $this->options['cmd.content'] ? ' --content' : '';
        $output = shell_exec($cmd);

        return $output ?: '';
    }

    public function execWithFind(): string
    {
        $root = $this->kirby->root('content');
        $exec = $this->options['cmd.exec'];
        $patterns = implode(' -o ', array_map(
            fn ($pattern) => "-name '$pattern'",
            $this->modelsWithTurboFilenamePatterns()
        ));
        $cmd = "{$exec} '{$root}' -type f \( $patterns \)";
        $cmd .= $this->options['cmd.modified'] ? " -exec stat -f '%N\t%m' {} \;" : '';
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

    public function write(mixed $data): bool
    {
        $expire = $this->options['expire'];
        if ($expire === null) {
            return false;
        }

        if ($this->options['compression']) {
            // compression is great for paths and repeated data
            // or if storing fewer data in the cache is required.
            // it comes with a delay and more memory usage as trade-off.
            $data = base64_encode(gzcompress($data));
        }

        return $this->cache()?->set('output-'.basename($this->options['cmd.exec']), $data, $expire);
    }

    public function cache(): ?Cache
    {
        return $this->options['expire'] !== null ? kirby()->cache('bnomei.turbo') : null;
    }

    public function storage(): ?Cache
    {
        return $this->options['storage'] ? $this->cache() : null;
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
        return A::get($this->data(), '#'.hash('xxh3', $root).'.modified', null);
    }

    public function content(string $root): ?array
    {
        return A::get($this->data(), '#'.hash('xxh3', $root).'.content', null);
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

        if (is_a($value, 'Kirby\Content\Field')) {
            return $value->value();
        }

        return $value;
    }

    private static ?self $singleton = null;

    public static function singleton(array $options = []): Turbo
    {
        if (self::$singleton === null) {
            self::$singleton = new Turbo($options);
        }

        return self::$singleton;
    }

    public static function flush(string $cache = 'cmd'): bool
    {
        if (kirby()->option('bnomei.turbo.expire') === null) {
            return false;
        }

        if ($cache === 'cmd') {
            kirby()->cache('bnomei.turbo')->remove('output-turbo');
            kirby()->cache('bnomei.turbo')->remove('output-turbo-darwin');
            kirby()->cache('bnomei.turbo')->remove('output-turbo-linux');
            kirby()->cache('bnomei.turbo')->remove('output-find');

            return true;
        }

        // Danger on redis this might flush storage and anything as well
        return kirby()->cache('bnomei.turbo')->flush();
    }
}
