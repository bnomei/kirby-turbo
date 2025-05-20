# Kirby Turbo 

[![Kirby 5](https://flat.badgen.net/badge/Kirby/5?color=ECC748)](https://getkirby.com)
![PHP 8.2](https://flat.badgen.net/badge/PHP/8.2?color=4E5B93&icon=php&label)
![Release](https://flat.badgen.net/packagist/v/bnomei/kirby-turbo?color=ae81ff&icon=github&label)
![Downloads](https://flat.badgen.net/packagist/dt/bnomei/kirby-turbo?color=272822&icon=github&label)
![Unittests](https://github.com/bnomei/kirby-turbo/actions/workflows/pest-tests.yml/badge.svg)
![PHPStan:9](https://github.com/bnomei/kirby-turbo/actions/workflows/phpstan.yml/badge.svg)
[![Discord](https://flat.badgen.net/badge/discord/bnomei?color=7289da&icon=discord&label)](https://discordapp.com/users/bnomei)
[![Buy License](https://flat.badgen.net/badge/icon/Buy%20License?icon=lemonsqueezy&color=FFC233&label=$)](https://buy-turbo.bnomei.com)

Speed up Kirby with caching

## Installation

- unzip [master.zip](https://github.com/bnomei/kirby-turbo/archive/master.zip) as folder `site/plugins/kirby-turbo` or
- `git submodule add https://github.com/bnomei/kirby-turbo.git site/plugins/kirby-turbo` or
- `composer require bnomei/kirby-turbo`

## Licensing

Kirby Turbo is a commercial plugin that requires a license. You can install and test the plugin locally without a license. However, production environments require a valid license. You can [purchase a license here](https://buy-turbo.bnomei.com).

## Overview

|        |                                                                                                                                                          |
|--------|----------------------------------------------------------------------------------------------------------------------------------------------------------|
| 📕     | Turbo expects **Redis** to be available and when installed it will use the faster msg_pack or igbinary PHP extensions to serialize data instead of JSON.  |
| 🔍🗄🆔 | Turbo adds automatic caching layers to Kirby on scanning the directory inventory, reading the content files from storage and in-between the UUID lookup. |
| 🏋️    | While you could use Turbo in almost any project, you will benefit the most, in those project where you **query 100+ pages/files in a single request**.   |
| 🛁     | Turbo provides a global cache helpers `tub()` that has advanced features like key/value serialization, optional set-abortion and more.                   |

## Quickstart

For each page you want Turbo's caching for `storage` and `inventory` you need to create a PageModel either manually by adding the `Bnomei\ModelWithTurbo` Trait ...

**site/models/example.php**
```php
<?php
class ExamplePage extends \Kirby\Cms\Page
{
    use \Bnomei\ModelWithTurbo;
}
```

... or in running the following [Kirby CLI](https://github.com/getkirby/cli) command, which will generate a preconfigured model for each of your existing page blueprints (`site/blueprints/pages/*.yml`-files). See further below on how to set up Turbo for Kirby's Site and File Models.

```bash
kirby turbo:models
```

The last step is to configure optimized cache-drivers for various caches. Turbo is intended to use in-memory caching with Redis. If you do not have Redis available, then you cannot use Turbo.

**site/config/config.php**
```php
<?php
return [
    // ✅ preconfigured defaults
    // 'bnomei.turbo.cache.inventory' => ['type' => 'turbo-file'],
    // 'bnomei.turbo.cache.storage' => ['type' => 'redis', 'database' => 0],
    // 'bnomei.turbo.cache.tub' => ['type' => 'turbo-redis', 'database' => 0],
    
    // ⚠️ the UUID cache-driver you need to set yourself!
    'cache' => ['uuid' => ['type' => 'turbo-uuid']],  // exec() + sed
    
    // ... other options
];
```

> [!CAUTION]
> Turbo defaults to Redis database `0`. Using different databases in Redis based caches helps to avoid unintended flushes from one cache-driver to another and to production databases! Adjust as needed.

## Caching Layers

Once the cache is in place, you can expect **consistent load times** independent of the request. The number of pages/files you are using within a **single request** will not make much of an impact any more since most of the data will be preloaded. But if you use very little of the total cached data, it might be slower than raw Kirby.

The load times only concern the loading of content, not Kirby having to handle creating fewer or more models in PHP - that will still have an impact and cannot be avoided.

<a title="click to open" target="_blank" style="cursor: zoom-in;" href="https://raw.githubusercontent.com/bnomei/kirby-turbo/main/screenshot.png"><img src="https://raw.githubusercontent.com/bnomei/kirby-turbo/main/screenshot.png" alt="screenshot" style="width: 100%;" /></a>

### 🔍 Inventory

Kirby would usually use PHP `scandir` to walk its way through your content folder. It will gather the modified timestamps with `filemtime` as well. But it will do that again and again on every request. Turbo adds a cache here and replaces the `inventory()` method on your model to query its `bnomei.turbo.cache.inventory` mono file cache instead. If the cache does not exist, it will try to populate it automatically. The cache will be flushed (and later recreated) every time you modify content in Kirby.

If Turbo's default setting slows down the Panel too much then consider disabling the caching of content with `bnomei.turbo.inventory.content=false`. But that will also remove step 1️⃣ from the `storage` caching layer!

### 🗄️ Storage

Instead of loading the content from the raw content TXT file every time, Turbo will

- 1️⃣ first try to load the content from the output of the indexer command `bnomei.turbo.cache.inventory` (a mono file cache, see below).
- 2️⃣ As a second fallback it will try to find the content in the `bnomei.turbo.cache.storage` (your Redis cache, see above).
- 3️⃣ If all fails it will resort to loading the TXT file from disk and store copy in the `storage` cache.

### 🆔 UUIDs

The default cache for UUIDs stores one file per UUID, which is fine if you query only a few UUIDs in a single request. If you read this far, you know that you want to load way more than a few in your setup and need a better solution. With the `turbo-uuid` cache driver all UUIDs will be preloaded and instantly available. Adding and removing entries are marginally slower. Use it and never look back. 

It requires the unix/Linux/OSX `sed` command to be available.

> [!NOTE]
> Using the default configuration Turbo will find all the content in the `inventory` (which is a single file cache) and never ping Redis at the `storage` layer. Which is very fast and absolutely the intended behaviour.

### Flushing the Caches

In the ideal case you would never need to flush any of Turbo's caches manually.

- The `inventory` cache flushes automatically when any site/page/file is edited in the Panel.
- The `storage` cache mirrors the content files and should never need flushing.
- The `uuid` cache is linked to the models as well and keeps itself up to date.

But if you make changes to content files outside the Panel, like uploading a batch of files via (S)FTP/git/rsync, you will need to flush them.

**PHP**
```php
\Bnomei\Turbo::flush();            // all
\Bnomei\Turbo::flush('inventory'); // specific one
```

**CLI**
```bash
env KIRBY_HOST=example.com vendor/bin/kirby turbo:flush
```

**Janitor button**
```yml
turboFlush:
  type: janitor
  label: Flush Turbo Caches
  command: 'turbo:flush'
```

## 🛁 tub(), cache anything easily

Turbo exposes a cache for your convenience to cache anything you want. At its core it behaves like any [plugin cache](https://getkirby.com/docs/guide/cache#plugin-caches) you could define yourself.

```php
tub()->set('key', 'value');
$value = tub()->get('key');
$value = tub()->getOrSet('key', fn() => 'value');
```

### tub() with TurboRedis Cache-Driver

Since you are using the `turbo-redis` cache-driver for `tub`, as recommended above, you will get a few advanced features.

### Array Keys
Keys can be arrays, not just strings. This is useful to create dynamic keys on the fly. 

```php
tub()->set($pages->pluck('uuid'), $pages->count());

tub()->set([
    kirby()->user()?->id(), 
    kirby()->language()?->code(), 
    $apiUrl
], $apiResponse, 24*60);
```

### Serialization
Keys and values will be serialized. If they contain Kirby Fields, these will automatically be resolved to their value. Models, like Pages and Files, will be resolved to their UUIDs + language code. This will allow you to write less code when creating keys/values.

```php
tub()->set($keyCanBeStringOrArray, $dataCanBeArrayAndContainingKirbyFields);

tub()->set(
    $page/*->uuid()->toString() + kirby()->language()?->code() */, 
    $pages->toArray(fn($p) => ['title' => $p->title()/*->value()*/])
);
```

### Expiration from human-readable strings
The `expire` parameter defaults to `0` which means storing the cached value forever. You can provide an `int` in minutes for how long you want the cache to be valid. With `tub()` you can also set human-readable strings which will be converted to minutes using the [core PHP `DateTime` class](https://www.php.net/manual/en/class.datetime.php).

```php
tub()->set('key', fn () => 'value', 'next monday');
tub()->set('key', fn () => 'value', 'last day of this month 23:59:59');
```

### Set abortion
When using a closure as value, you can abort setting the value on demand. This is handy, if you are while creating the cache value, you decide to rather not store that value after all.

```php
$value = tub()->getOrSet($key, function() use ($page) {
    if ($page->performCheck() === false) { // up to you
        throw new \Bnomei\AbortCachingException();
    }
    return [
        'uuid' => $page->uuid()->id(), 
        'title' => $page->title(), 
        'url' => $page->url()
    ];
});
```

### JSON Safety
Turbo will double-check if the data can be safely stored as JSON (see settings).

### msg_pack and igbinary PHP extensions
If your server has either the [msg_pack](https://msgpack.org) and [igbinary](https://github.com/igbinary/igbinary/) PHP extensions installed (via PECL) then Turbo will use these to serialize the data and not store it as JSON. Why? Because they are a bit faster on serialization, about 2x faster when deserializing and can produce smaller output sizes.

## 🛀 tubs() or the TurboStaticCache Helper

While caching data beyond the current request with `tub()` is great, but it cannot solve one issue well and that is **repeated calls to the same data within a single request**. This might sound silly at first, but it happens in a lot of places you might not be aware of. The Kirby collections and the Panel queries are prime suspects. Turbo provides the `tubs()`-helper to help you elevate these issues.

### Example: Using tubs() for caching collections used in the Panel queries

Unless you wrap the collection in the following example in the `tubs($key, $closure)` it's content will be evaluated again and again every time a block is evaluated. While you can easily avoid this in your frontend code, in this case the query in the panel will be triggered multiple times when evaluating the options to show on blocks. Once for every block of the same type that you added. Note that `tubs()` is always returning a Closure.

**site/plugins/my-example/index.php**
```php
<?php
Kirby::plugin('my/example', [
  'collections' => [
    // collections have to return a closure, and that is why tubs' value is a closure
    'recent-courses' => tubs(
        'recent-courses', // key: array|string
        function () {     // value: closure
            return page('courses')->children()->listed()->sortBy('name')->limit(10);
        }
    ),
    // or with an additional cache around the collection itself to minimize the lookup
    'recent-courses' => tubs(
        'recent-courses', // key: array|string
        function () {     // value: closure
            $uuids = tub()->getOrSet(
                'recent-courses', 
                fn() => page('courses')->children()->listed()->sortBy('name')->limit(10)->values(
                    fn(\Kirby\Cms\Page $p) => $p->uuid()->toString()
                ),
                1 // in minutes
            );
            
            return new \Kirby\Cms\Pages($uuids);
        }
    ),
]);
```

**site/blueprints/blocks/recent-courses.yml**
```yml
name: Recent Courses
type: pages
query: collection('recent-courses') # repeatedly called, resolved only once with tubs()
```

**site/blueprints/pages/course.yml**
```yml
fields:
  type: blocks
  fieldset:
      - recent-courses
```

## Other helpers

### $field->toFilesTurbo()/$field->toPagesTurbo()
If you are using the `turbo-uuid` as your UUID cache-driver then using these helpers will speed up the resolution even more.

```php
$actors = page('film/academy-dinosaur')->actors()->toPagesTurbo(); // Pages Collection
$images = page('actors/adam-grant')->gallery()->toFilesTurbo();    // Files Collection
```

### $pages/$files->modified(): ?int
You can get the most current modified timestamp of any Pages/Files-Collection with this helper.

```php
echo page('film')->children()->modified();           // 1734873708
echo page('actors/adam-grant')->files()->modified(); // 1737408738
```

### $site->modifiedTurbo(): int
Using the Kirby core `site()->modified()` to get the most current modified timestamp is not efficient as it will recursively walk all dirs and query all files in the content folder. Turbo adds a helper that will query its inventory cache instead.

```php
// Kirby core will scan all dirs and all files
echo site()->modified();

// new helper reads from turbo inventory cache
echo site()->modifiedTurbo(); // 1734873708
```

## Inventory Indexer Command(s)

Turbo has two built-in indexer commands, `find` and `turbo` (default). Both can scan the directory tree and optionally gather the modified timestamp.

- The `find`-indexer uses the Unix `find` in combination with `stat`.
- The `turbo`-indexer is a custom binary built with Rust that does the same thing but multithreaded and async. It can preload the content files.

> [!TIP]
> You can use the `bnomei.turbo.inventory.indexer` config option to set a custom binary location in case the automatic detection fails. <br>
> Make sure the `turbo` binaries are executable by the user running the php-fpm, or it will fail.

## Site and Files

Turbo will provide the `inventory` cache layer for files based on its page model. If you want the `storage` cache layer as well you would need to opt in to have ALL models with that storage component and set the global storage component to Turbo. But unless you query the majority of all of your files in a single request, this makes no sense. 

> [!WARNING]
> You will most certainly not have to set the global storage component to Turbo ever, unless you query the majority of all of your **files** (in addition to pages) in a single request. The global storage component in Kirby is intended for implementations to read/write all content from something like AWS-S3 or MySQL and not directly, like Turbo does, injecting a caching layer for selected models. Anyway, you have been warned. Here is how to do it.

```php
// on app initialisation
$kirby = new App([
  'components' => [
    'storage' => function (App $kirby, ModelWithContent $model) {
        return new \Bnomei\TurboStorage($model);
    ]
  ]  
]);

// or in a plugin
App::plugin('my/storage', [
  'components' => [
    'storage' => function (App $kirby, ModelWithContent $model) {
        return new \Bnomei\TurboStorage($model);
    ]
  ]  
]);
```

## Performance

> [!IMPORTANT]
> "If you can not measure it, you can not improve it."<br>- Lord Kelvin

The speed of Redis and the filesystem in general are vastly different on your local setup than on your staging/production server. Evaluate performance under real conditions!

To help you measure the time Turbo spends on reading its `inventory` cache and Kirby spends on rendering more thoroughly, you can have Turbo write HTTP headers.

**/index.php**
```php
<?php
// require 'kirby/bootstrap.php';
require 'vendor/autoload.php';

\Bnomei\TurboStopwatch::before('kirby');
$kirby = new \Kirby\Cms\App;
$render = $kirby->render();
\Bnomei\TurboStopwatch::after('kirby');

\Bnomei\TurboStopwatch::header('turbo.read');  // not included in page.render
\Bnomei\TurboStopwatch::header('page.render'); // turbo tracks that for you
\Bnomei\TurboStopwatch::header('kirby');       // total time spent by Kirby

// or https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Server-Timing
\Bnomei\TurboStopwatch::serverTiming();        // all events in a single header

echo $render;
```

```
X-Stopwatch-Turbo-Read: 77ms
X-Stopwatch-Page-Render: 33ms
X-Stopwatch-Kirby: 123ms
Server-Timing: Cache;desc=miss,Kirby;dur=187,Route;dur=3,TurboRead;dur=90,TurboInventoryCache;dur=90,PageRender;dur=62
```

> [!NOTE]
> Turbo does not (yet) provide you with a way to measure the time Kirby spends on building its directory inventory, so right now you can only compare total time spent by Kirby.

## Settings

| bnomei.turbo.                        | Default               | Description                                                                                                                                          |
|--------------------------------------|-----------------------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| license                              | `string/fn()`         | Enter your license key. You need to [buy a license](https://buy-turbo.bnomei.com) for non-development environments.                                                              |
| expire                               | `0`                   | cache duration where `0` = infinite, `n` = in minutes, `null` = disabled                                                                             |
| inventory.indexer                    | `fn()`                | `null/find` or `closure` with absolute path to indexer binary                                                                                        |
| inventory.enabled                    | `fn()`                | automatic toggled off for all Kirby internal routes (API, Panel, Media), set `true` to enforce indexer to run                                        |
| inventory.modified                   | `true`                | flag for indexer to retrieve modification timestamps                                                                                                 |
| inventory.content                    | `true`                | flag for indexer to retrieve content                                                                                                                 |
| inventory.read                       | `true`                | allow reading of data returned from indexer in inventory (directory scan and modified timestamps) and storage phase (preloaded content from indexer) |
| inventory.compression                | `false`               | compress store data from indexer                                                                                                                     |
| storage.read                         | `true`                | read from cache in storage phase (Redis)                                                                                                             |
| storage.write                        | `true`                | write to cache in storage phase (Redis)                                                                                                              |
| storage.compression                  | `false`               | compress data written in storage phase (Redis)                                                                                                       |
| preload-redis.validate-value-as-json | `true`                | fail on invalid JSON, Kirby would otherwise default to writing an empty string                                                                       |
| preload-redis.json-encode-flags      | `JSON_THROW_ON_ERROR` | sane default for encoding, could be extended with `JSON_INVALID_UTF8_IGNORE` etc.                                                                    |

## Disclaimer

This plugin is provided "as is" with no guarantee. You can use it at your own risk and always test it before using it in a production environment. If you find any issues, please [create a new issue](https://github.com/bnomei/kirby-turbo/issues/new).

## License

Kirby Turbo License © 2025-PRESENT Bruno Meilick
