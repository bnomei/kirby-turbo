<?php

use Bnomei\Turbo;
use Bnomei\TurboStopwatch;
use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\VersionId;
use Kirby\Data\Data;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;

beforeEach(function () {
    $this->originalKirby = kirby();
    $this->enableWhoops = App::$enableWhoops;
    App::$enableWhoops = false;
    $this->timestamps = TurboStopwatch::$timestamps;
    $this->root = sys_get_temp_dir().'/turbo-requests-'.bin2hex(random_bytes(8));
    Dir::make($this->root.'/content/films/first', true);
    F::write($this->root.'/content/films/films.txt', "Title: Films\n");
    F::write($this->root.'/content/films/first/film.txt', "Title: First\n");
    $this->appProps = [
        'roots' => [
            'index' => __DIR__,
            'content' => $this->root.'/content',
            'cache' => $this->root.'/cache',
        ],
        'options' => [
            'content.uuid' => false,
            'bnomei.turbo.storage.read' => false,
            'bnomei.turbo.storage.write' => false,
        ],
    ];
});

afterEach(function () {
    App::instance($this->originalKirby);
    ModelWithContent::$kirby = $this->originalKirby;
    App::$enableWhoops = $this->enableWhoops;
    Turbo::singleton([], true);
    TurboStopwatch::$timestamps = $this->timestamps;
    Dir::remove($this->root);
});

it('reads current Panel changes without leaking them into published content', function () {
    $app = new App($this->appProps);
    $turbo = Turbo::singleton(['inventory.enabled' => true, 'storage.read' => true, 'storage.write' => true], true);
    $page = $app->page('films/first');
    $storage = $page->storage();
    $language = Language::single();
    $changes = VersionId::changes();
    $storage->create($changes, $language, ['actors' => ['page://first']]);
    $path = $storage->contentFile($changes, $language);
    touch($path, 100);
    Turbo::flush('inventory');
    expect($turbo->modified($path))->toBe(100);

    $storage->update($changes, $language, ['actors' => ['page://second'], 'unused' => null]);
    touch($path, 200);
    expect($storage->modified($changes, $language))->toBe(200)
        ->and($storage->read($changes, $language))->toBe(Data::read($path))
        ->and($page->version('changes')->content()->actors()->yaml())->toBe(['page://second'])
        ->and($page->title()->value())->toBe('First');
});

it('uses Kirby to decode non-TXT content while retaining the directory index', function () {
    F::write($this->root.'/content/films/films.yaml', "title: Films\n");
    F::write($this->root.'/content/films/first/film.yaml', "title: First\nrating: PG\n");
    $app = new App([
        ...$this->appProps,
        'options' => [...$this->appProps['options'], 'content' => ['extension' => 'yaml']],
    ]);
    $turbo = Turbo::singleton(['inventory.enabled' => true], true);
    $film = $app->page('films/first');
    expect($film->title()->value())->toBe('First')
        ->and($film->rating()->value())->toBe('PG')
        ->and($turbo->modified($film->root().'/film.yaml'))->toBeInt();
});

it('uses the default URL policy regardless of request method', function (string $path, bool $enabled, string $method) {
    $app = new App([
        ...$this->appProps,
        'request' => ['url' => 'http://localhost/'.$path, 'method' => $method],
    ]);
    $turbo = Turbo::singleton([], true);
    TurboStopwatch::$timestamps = [];

    expect($app->option('bnomei.turbo.inventory.enabled'))->toBeInstanceOf(Closure::class)
        ->and($turbo->smartInventory())->toBe($enabled);

    $parent = $app->page('films');
    expect($parent->hasTurbo())->toBeTrue()
        ->and($parent->children()->pluck('slug'))->toBe(['first'])
        ->and($parent->children()->first()->title()->value())->toBe('First')
        ->and($turbo->files() !== [])->toBe($enabled)
        ->and($turbo->dirs() !== [])->toBe($enabled)
        ->and(isset(TurboStopwatch::$timestamps['turbo.inventory.exec:before']))->toBe($enabled);

    // A fresh Turbo instance on the same request must reuse the frontend cache,
    // or continue to bypass it on an internal URL, without running the indexer.
    TurboStopwatch::$timestamps = [];
    $turbo = Turbo::singleton([], true);
    expect($turbo->files() !== [])->toBe($enabled)
        ->and(TurboStopwatch::$timestamps)->not->toHaveKey('turbo.inventory.exec:before');
})->with([
    'frontend' => ['films', true],
    'similar frontend prefix' => ['panel-preview', true],
    'Panel root' => ['panel', false],
    'Panel child' => ['panel/pages/films', false],
    'API root' => ['api', false],
    'API child' => ['api/pages/films/children', false],
    'media' => ['media/pages/films/example.jpg', false],
])->with(['GET', 'HEAD', 'POST', 'PATCH', 'DELETE']);

it('honors an explicit inventory override on frontend and internal POST requests', function (string $path, bool $enabled) {
    new App([
        ...$this->appProps,
        'request' => ['url' => 'http://localhost/'.$path, 'method' => 'POST'],
        'options' => [
            ...$this->appProps['options'],
            'bnomei.turbo.inventory.enabled' => $enabled,
        ],
    ]);
    $turbo = Turbo::singleton([], true);

    expect($turbo->smartInventory())->toBe($enabled)
        ->and($turbo->files() !== [])->toBe($enabled);
})->with(['films', 'api/pages/films/children'])->with([true, false]);

it('invalidates the frontend snapshot without rebuilding during an API page creation', function () {
    $app = new App([
        ...$this->appProps,
        'request' => ['url' => 'http://localhost/films', 'method' => 'POST'],
    ]);
    $turbo = Turbo::singleton([], true);
    $parentRoot = $this->root.'/content/films';
    expect($turbo->inventory($parentRoot)->children())->toHaveCount(1);
    $key = 'output-'.basename($turbo->options['inventory.indexer']);
    expect($turbo->cache('inventory')->get($key))->toBeArray();

    $app = new App([
        ...$this->appProps,
        'request' => ['url' => 'http://localhost/api/pages/films/children', 'method' => 'POST'],
    ]);
    $turbo = Turbo::singleton([], true);
    TurboStopwatch::$timestamps = [];
    $app->impersonate('kirby');
    $parent = $app->page('films');
    expect($parent->children()->count())->toBe(1);

    $page = $parent->createChild([
        'slug' => 'second',
        'template' => 'film',
        'content' => ['title' => 'Second'],
    ]);

    expect($page->hasTurbo())->toBeTrue()
        ->and($page->title()->value())->toBe('Second')
        ->and(is_file($page->root().'/film.txt'))->toBeTrue()
        ->and($parent->childrenAndDrafts()->count())->toBe(2)
        ->and($turbo->files())->toBeEmpty()
        ->and($turbo->cache('inventory')->get($key))->toBeNull()
        ->and(TurboStopwatch::$timestamps)->not->toHaveKey('turbo.inventory.exec:before');

    // The next frontend POST rebuilds the invalidated snapshot and sees the write.
    $app = new App([
        ...$this->appProps,
        'request' => ['url' => 'http://localhost/films', 'method' => 'POST'],
    ]);
    $turbo = Turbo::singleton([], true);
    expect($turbo->content($page->root().'/film.txt')['title'])->toBe('Second')
        ->and($turbo->cache('inventory')->get($key))->toBeArray()
        ->and(TurboStopwatch::$timestamps)->toHaveKey('turbo.inventory.exec:before');
});
