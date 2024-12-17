# Kirby Turbo 

[![Kirby 5](https://flat.badgen.net/badge/Kirby/5?color=ECC748)](https://getkirby.com)
![PHP 8.2](https://flat.badgen.net/badge/PHP/8.2?color=4E5B93&icon=php&label)
![Release](https://flat.badgen.net/packagist/v/bnomei/kirby-turbo?color=ae81ff&icon=github&label)
![Downloads](https://flat.badgen.net/packagist/dt/bnomei/kirby-turbo?color=272822&icon=github&label)
[![Coverage](https://flat.badgen.net/codeclimate/coverage/bnomei/kirby-turbo?icon=codeclimate&label)](https://codeclimate.com/github/bnomei/kirby-turbo)
[![Maintainability](https://flat.badgen.net/codeclimate/maintainability/bnomei/kirby-turbo?icon=codeclimate&label)](https://codeclimate.com/github/bnomei/kirby-turbo/issues)
[![Discord](https://flat.badgen.net/badge/discord/bnomei?color=7289da&icon=discord&label)](https://discordapp.com/users/bnomei)
[![Buymecoffee](https://flat.badgen.net/badge/icon/donate?icon=buymeacoffee&color=FF813F&label)](https://www.buymeacoffee.com/bnomei)

Speed up Kirby with automatic caching

## BUGS

- [ ] indexer needs to return list all files no matter what, modified and content can be filtered

## TODOS

- [ ] turbo binaries and how to expose them to composer
- [ ] unit tests

## Installation

- unzip [master.zip](https://github.com/bnomei/kirby-turbo/archive/master.zip) as folder `site/plugins/kirby-turbo` or
- `git submodule add https://github.com/bnomei/kirby-turbo.git site/plugins/kirby-turbo` or
- `composer require bnomei/kirby-turbo`

## Overview

|      |                                                                                                                                                        |
|------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| 🤖   | Turbo is plugin that adds automatic caching layers to Kirby, when scanning the directory inventory, reading the content files and the UUID lookup.     |
| 💯   | While you could use Turbo in almost any project, you will benefit the most, in those project where you **query 100+ pages/files in a single request**. |
| 🔴   | Turbo relies on **Redis** being available.                                                                                                             |
| 🛁 | Turbo provides a global cache helper `tub()` that has advanced features like key/value serialization, optional set-abortion and more.                  |


## Quickstart

For each page you want Turbo's *storage* and *inventory* caching you need to create a PageModel either manually by adding the `Bnomei\ModelWithTurbo` Trait ...

**site/models/example.php**
```php
<?php
class ExamplePage extends \Kirby\Cms\Page
{
    use \Bnomei\ModelWithTurbo;
}
```

... or in running the following [Kirby CLI](https://github.com/getkirby/cli) command, which will generate a preconfigured model for each of your existing page blueprints (`site/blueprints/pages/*.yml`-files). See further below on how to setup Turbo for Kirby's Site and File Models.

```bash
kirby turbo:models
```

The last step is to configure optimized cache-drivers for various caches. Turbo is intended to use in-memory caching with Redis. If you do not have Redis available, then do not use Turbo.

**site/config/config.php**
```php
<?php
return [
    'bnomei.turbo.cache.storage' => ['type' => 'redis', 'database' => 0],
    'bnomei.turbo.cache.tub' => ['type' => 'turbo-redis', 'database' => 0],
    'cache' => [ 'uuid' => ['type' => 'turbo-uuid']],
    // ... other options
];
```

> [!TIP]
> Using different databases in Redis based caches helps avoiding unintended flushes from one cache-driver.

## Caching Layers

Once the cache is in place you can expect **consistent load times** independent of the request. The amount of pages/files you are using within a **single request** will not make much of an impact any more since most of the data will be preloaded. But if you use very little of the total cached data it might be slower than raw Kirby. Disclaimer: The load times only concern the loading of content not Kirby having to handle creating less or more models in PHP - that will still have an impact and can not be avoided.

### 🗄️ Storage

Instead of loading the content from the raw content TXT file every time, Turbo will first try to load the content from the output of the indexer command `bnomei.turbo.cache.cmd` (a mono file cache, see below). As a second fallback it will try to find it in the `bnomei.turbo.cache.storage` (your Redis cache, see above). If all fails it will resort to loading the TXT file from disk and store copy in the `storage` cache.

### 🔍 Inventory

Kirby would usually use PHP `scandir` to walk it's way through your content folder. It will gather the modified timestamps with `filemtime` as well. But it will do that again and again on every request. Turbo add a caching here and replaces the `inventory()` method on your model to query its `bnomei.turbo.cache.cmd` mono file cache instead. If the cache is not existing it will try to populate it automatically. The cache will be flushed (and later recreated) every time you modify content in Kirby.

If Turbo's default setting slow down the Panel to much then consider disabling the caching of content with `bnomei.turbo.cmd.content=false`. But that will also remove step 1) from the `storage` caching layer!

### 🆔 UUIDs

The default cache for UUIDs stores one file per UUID which is fine if you query only a few UUIDs in a single request. If you read this far you know you most likely will not only load a few in your setup and need a better solution. With the `turbo-uuid` cache driver all UUIDs will be preloaded and instantly available. Adding and removing entries a marginally slower. Use it and never look back. 

It requires the unix `sed` command to be available.

## 🛁 tub(), cache anything easily

Turbo exposes a cache for your convenience to cache anything you want. At its core it behaves like any [plugin cache](https://getkirby.com/docs/guide/cache#plugin-caches) you could define yourself.

```php
tub()->set('key', 'value');
$value = tub()->get('key');
$value = tub()->getOrSet('key', fn() => 'value');
```

## 🛁 tub() with TurboRedis Cache-Driver

If you use the `turbo-redis` cache-driver for `tub`, as recommended above, you will get a few advanced features.

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
Keys and values will be serialized. If they contain Kirby Fields these will automatically be resolved to their `->value()`. Models like Pages and Files will be resolved to their UUIDs. This will allow you to write less code when creating keys/values.

```php
tub()->set(
    $page/*->uuid()->toString()*/, 
    $pages->toArray(fn($p) => ['title' => $p->title()/*->value()*/])
);
```

### Set abortion
When using a closure as value you can abort setting the value on demand. This is handy if you have strict or time consuming requirements on when to set the value and do not want to perform that check again after the value has been cached.

```php
tub()->set($keyCanBeStringOrArray, $dataCanBeArrayAndContainingKirbyFields);

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
You can double check if the data can be safely stored as JSON (see settings).

## 🛀 tubs() or the TurboStaticCache Helper

While caching data beyond the current request with `tub()` is great, but it can not solve one issue well and that is **repeated calls to the same data within a single request**. This might sound silly at first but it happens in a lot of places you might not be aware of. The Kirby collections and the Panel queries being prime suspects. Turbo provides the `tubs()`-helper to help you elevate these issues.

### Example for tubs()

Unless you wrap the collection in the following example in the `fn() => tubs($key, $closure)` it's content will be evaluated again and again every time a block is evaluated. While you can easily avoid this in your frontend code, in this case the query in the panel wille be triggered multiple times when evaluating the options to show on blocks. Once for every of the same type that you added.

> [!NOTE]
> For exactly the same reason the excellent ZeroOne Theme switched to PHP based blueprints. It needed more control over what is loaded when due to it's hugh amount of available dynamic options.

**site/plugins/my-example/index.php**
```php
<?php
Kirby::plugin('my/example', [
  'collections' => [
    // collections have to return a closure, and that is why it is wrapped in a fn
    'recent-courses' => fn() => tubs(
        'recent-courses', // key: array|string
        function () {   // value: closure
            return page('courses')->children()->listed()->sortBy('name')->limit(10);
        }
    )
]);
```

**site/blueprints/blocks/recent-courses.yml**
```yml
name: Recent Courses
type: pages
query: collection('recent-courses') # <-- repeatedly called, but resolved once with tubs()
```

**site/blueprints/pages/course.yml**
```yml
fields:
  type: blocks
  fieldset:
      - recent-courses
```

## Indexer Command(s)

Turbo has two built-in indexer commands, `find` and `turbo`. Both can scan the directory tree and optionally gather the modified timestamp. But only the `turbo`-indexer can also preload the Kirby content file.

- The `find` indexer uses the Unix `find` in combination with `stat` to gather the data.
- The `turbo` indexer is a custom binary built with Rust that does the same thing but multi-threaded and async and it optionally can load the content files.

> [!TIP]
> You can use the `bnomei.turbo.cmd.exec` config option to set a custom binary location in case the automatic detection fails. <br>
> Make sure the `turbo` binaries are executable by the user running the php-fpm or it will fail.

## Site and Files

Turbo will provide the `inventory` cache layer for files based on it's page model. If you want the `storage` cache layer as well you would need to opt-in to have ALL models with that storage component and set the global storage component to Turbo. But unless you query the majority of all of your files in a single request, this makes no sense. Anyway, you have been warned. Here is how to do it.

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

> [!WARNING]
> You will most certainly not have to do that ever, unless you query the majority of all of your files in a single request.

## Performance

> [!TIP]
> The speed of Redis and the filesystem in general are vastly different on your local setup than on your staging/production server. Evaluate performance under real conditions! "If you can not measure it, you can not improve it." (Lord Kelvin)

## Settings

| bnomei.turbo. | Default | Description |
|---------------|---------|-------------|
| xxx           | `xxx`   | xxx         |


## Disclaimer

This plugin is provided "as is" with no guarantee. You can use it at your own risk and always test it before using it in a production environment. If you find any issues, please [create a new issue](https://github.com/bnomei/kirby-turbo/issues/new).

## License

[MIT](https://opensource.org/licenses/MIT)

It is discouraged to use this plugin in any project that promotes racism, sexism, homophobia, animal abuse, violence or any other form of hate speech.
