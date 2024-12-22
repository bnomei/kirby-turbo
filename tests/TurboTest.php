<?php

use Bnomei\AbortCachingException;
use Bnomei\Turbo;
use Bnomei\TurboRedisCache;
use Bnomei\TurboStopwatch;
use Bnomei\TurboStorage;
use Bnomei\TurboUuidCache;
use Kirby\Cms\Collection;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Uuid\Uuids;

it('has a custom UUID cache-driver that uses a single file', function () {
    Uuids::populate();
    sleep(1); // wait for file write
    $actor = page('page://L8EgxOu9xdHGpzVg');
    $id = $actor->id();
    /** @var TurboUuidCache $cache */
    $cache = kirby()->cache('uuid');
    expect(Dir::isEmpty($cache->root()))->toBeFalse()
        ->and(F::read($cache->root().'/uuids.cache'))->toContain("page/L8/EgxOu9xdHGpzVg\t$id\n");
});

it('has a global singleton helper that know its models', function () {
    $turbo = turbo();
    $models = $turbo->modelsWithTurbo();
    $filenamePatterns = $turbo->modelsWithTurboFilenamePatterns();

    expect($turbo)->toBeInstanceOf(Turbo::class)
        ->and($models)->toHaveCount(20)
        ->and($models)->toContain('actor')
        ->and($models)->toContain('actors')
        ->and($filenamePatterns)->toHaveCount(20)
        ->and($filenamePatterns)->toContain('actor.txt')
        ->and($filenamePatterns)->toContain('actors.txt');
});

it('can run the find-indexer', function () {
    $t = new Turbo([
        'inventory.indexer' => 'find',
        'inventory.modified' => false,
    ]);

    $out = explode(PHP_EOL, $t->execWithFind());

    expect($out)->toContain('customer/leah-curtis/customer.txt')
        ->and($out)->toContain('film/italian-african/film.txt')
        ->and(count($out))->toBeGreaterThanOrEqual(20_000);
});

it('can run the turbo-indexer', function () {
    $t = new Turbo([
        // 'inventory.indexer' => 'turbo', // use auto-detect
        'inventory.modified' => true,
        'inventory.content' => true,
    ]);

    $out = json_decode($t->execWithTurbo(), true);
    $firstFile = array_shift($out['files']);

    expect($t->options['inventory.indexer'])->toContain('/turbo')
        ->and(count($out['files']))->toBeGreaterThanOrEqual(20_000)
        ->and($firstFile['modified'])->toBeInt()
        ->and($firstFile['content'])->toBeArray()
        ->and(count($firstFile['content']))->toBeGreaterThan(0)
        ->and($firstFile['dir'])->toStartWith('@/')
        ->and($firstFile['path'])->toStartWith($firstFile['dir']);
});

it('can flush caches', function () {
    expect(Turbo::flush())->toBeTrue()
        ->and(Turbo::flush('*'))->toBeTrue()
        ->and(Turbo::flush('all'))->toBeTrue()
        ->and(Turbo::flush('inventory'))->toBeTrue()
        ->and(Turbo::flush('storage'))->toBeTrue()
        ->and(Turbo::flush('tub'))->toBeTrue();
});

it('can detect Kirby internal URLs', function () {
    expect(Turbo::isUrlKirbyInternal('http://localhost/api'))->toBeTrue()
        ->and(Turbo::isUrlKirbyInternal('http://localhost/panel'))->toBeTrue()
        ->and(Turbo::isUrlKirbyInternal('http://localhost/media/1234567890'))->toBeTrue()
        ->and(Turbo::isUrlKirbyInternal('http://localhost'))->toBeFalse()
        ->and(Turbo::isUrlKirbyInternal('http://localhost/else'))->toBeFalse();
});

it('can serialize a value', function () {
    $page = page('film')->children()->first();

    // keep primitives
    expect(Turbo::serialize('value'))->toBe('value')
        ->and(Turbo::serialize(123))->toBe(123)
        // resolve closures
        ->and(Turbo::serialize([1, 2, ['c' => fn () => 3]]))->toBe([1, 2, ['c' => 3]])
        // resolve fields
        ->and(Turbo::serialize($page->title()))->toBe($page->title()->value())
        // resolves models (optionally, needed for keys)
        ->and(Turbo::serialize($page, true))->toBe('page://t4byNBnqBoMcJLhW');
});

it('has a global cache helper: tub', function () {
    $tub = tub();
    expect($tub)->toBeInstanceOf(TurboRedisCache::class);

    tub()->flush();
    tub()->set('key', 'value');
    $value = tub()->get('key');
    expect($value)->toBe('value');
});

it('tub can use getOrSet and closures', function () {
    tub()->flush();
    tub()->set('key', fn () => 'value');
    $value = tub()->getOrSet('key', fn () => 'value2');
    expect($value)->toBe('value');

    $value = tub()->getOrSet('key3', fn () => 'value3');
    expect($value)->toBe('value3');
});

it('tub can serialize keys', function () {
    tub()->flush();
    $films = page('film')->children();
    $filmTitles = $films->pluck('title', 'slug');
    $filmCount = $films->count();
    $key = tub()->key($filmTitles);
    expect($key)->toBe('#a5eae6b9dca8895e');
    tub()->set($filmTitles, $filmCount);
    expect(tub()->get($filmTitles))->toBe($filmCount);
});

it('tub can serialize values', function () {
    tub()->flush();
    $films = page('film')->children();
    $firstFilm = $films->first();

    $titlesAsStrings = tub()->getOrSet('titles',
        fn () => $films->toArray(fn ($p) => $p->title()->value())
    );
    expect($titlesAsStrings)->toHaveCount($films->count())
        ->and($titlesAsStrings[$firstFilm->id()])->toBe($firstFilm->title()->value());
});

it('tub can abort', function () {
    tub()->flush();
    $page = page('film')->children()->first();
    $value = tub()->getOrSet($page->id(), function () use ($page) {
        if ($page->title()->value() === 'ACADEMY DINOSAUR') { // up to you
            throw new AbortCachingException;
        }

        return [
            'uuid' => $page->uuid()->id(),
            'title' => $page->title(),
            'url' => $page->url(),
        ];
    });

    expect($value)->toBeNull();
});

it('has a global static cache helper: tubs', function () {
    $c = 1;
    $tubs = tubs('key', function () use ($c) {
        $c = $c + 1;

        return $c;
    });

    expect($tubs)->toBeInstanceOf(Closure::class)
        ->and($tubs())->toBe(2)
        ->and($tubs())->toBe(2);

    $films = collection('films'); // which is using tubs()
    expect($films)->toBeInstanceOf(Collection::class)
        ->and($films->count())->toBe(page('film')->children()->count());
});

it('can stop times and get duration', function () {
    TurboStopwatch::before('abc.d');
    sleep(1);
    TurboStopwatch::tick('abc.d:after');
    TurboStopwatch::after('abc.d'); // overwrite the tick
    $duration = TurboStopwatch::duration('abc.d');

    expect($duration)->not()->toBeNull()
        ->and($duration)->toBeGreaterThanOrEqual(1)
        ->and(TurboStopwatch::header('abc.d', true))->toBe('X-Stopwatch-Abc-D: '.$duration.'ms');
});

it('can read from the inventory cache for a model with turbo via the storage class', function () {
    Turbo::singleton([
        'inventory.read' => true,
        'storage.read' => true,
    ], true);

    $page = page('film')->children()->first();
    expect($page->hasTurbo())->toBeTrue()
        ->and($page->storage())->toBeInstanceOf(TurboStorage::class)
        ->and($page->modified())->toBeInt()
        ->and($page->title()->value())->toBe('ACADEMY DINOSAUR');
});

it('can read from the storage cache (skipping the inventory) for a model with turbo via the storage class', function () {
    Turbo::singleton([
        'inventory.read' => false,
        'storage.read' => true,
    ], true);

    $page = page('film')->children()->first();
    expect($page->hasTurbo())->toBeTrue()
        ->and($page->storage())->toBeInstanceOf(TurboStorage::class)
        ->and($page->modified())->toBeInt()
        ->and($page->title()->value())->toBe('ACADEMY DINOSAUR');
});

it('can read from the raw content file (skipping storage and inventory cache) for a model with turbo via the storage class', function () {
    Turbo::singleton([
        'inventory.read' => false,
        'storage.read' => false,
    ], true);

    /** @var \Bnomei\TurboPage $page */
    $page = page('film')?->children()->first();
    expect($page->hasTurbo())->toBeTrue()
        ->and($page->storage())->toBeInstanceOf(TurboStorage::class)
        ->and($page->modified())->toBeInt()
        ->and($page->title()->value())->toBe('ACADEMY DINOSAUR');
});
