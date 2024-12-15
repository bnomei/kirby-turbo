# Kirby Turbo 

[![Kirby 5](https://flat.badgen.net/badge/Kirby/5?color=ECC748)](https://getkirby.com)
![PHP 8.2](https://flat.badgen.net/badge/PHP/8.2?color=4E5B93&icon=php&label)
![Release](https://flat.badgen.net/packagist/v/bnomei/kirby-turbo?color=ae81ff&icon=github&label)
![Downloads](https://flat.badgen.net/packagist/dt/bnomei/kirby-turbo?color=272822&icon=github&label)
[![Coverage](https://flat.badgen.net/codeclimate/coverage/bnomei/kirby-turbo?icon=codeclimate&label)](https://codeclimate.com/github/bnomei/kirby-turbo)
[![Maintainability](https://flat.badgen.net/codeclimate/maintainability/bnomei/kirby-turbo?icon=codeclimate&label)](https://codeclimate.com/github/bnomei/kirby-turbo/issues)
[![Discord](https://flat.badgen.net/badge/discord/bnomei?color=7289da&icon=discord&label)](https://discordapp.com/users/bnomei)
[![Buymecoffee](https://flat.badgen.net/badge/icon/donate?icon=buymeacoffee&color=FF813F&label)](https://www.buymeacoffee.com/bnomei)

Speed up your content in Kirby with Redis caches and Rust powered preloading

## Installation

- unzip [master.zip](https://github.com/bnomei/kirby-turbo/archive/master.zip) as folder `site/plugins/kirby-turbo` or
- `git submodule add https://github.com/bnomei/kirby-turbo.git site/plugins/kirby-turbo` or
- `composer require bnomei/kirby-turbo`

## How it works

TODO

- cache-driver
- models with trait

> [!TIP]
> Once the cache is in place you can expect **consistent load times**. The amount of pages you are using within a single request will not make much of an impact any more.

## Setup

Set any in-memory cache-driver like `redis` for each of the plugins caches and the Kirby UUID cache. 

The `turbo-redis` cache-driver has a few advanced features like key/data-serialization or optional abort when setting a value.

If you load the majority of your content/uuids in a single request consider using `preload-redis` instead which preloads all of it's data into an PHP array for faster access. 

**site/config/config.php**
```php
<?php

return [
    // `redis` performs a query on demand
    'bnomei.turbo.cache.storage' => ['type' => 'redis', 'database' => 0],
    
    // `turbo-redis` has advanced features for turbo()->cache(), see below
    'bnomei.turbo.cache.tub' => ['type' => 'turbo-redis', 'database' => 0],

    // make the uuid cache fast
    'cache' => [
        'uuid' => ['type' => 'turbo-uuid'],
    ],
    
    // ... other options
];
```

> [!TIP]
> Using different databases in the caches helps to avoid unintended flushes from one cache-driver to another but it will decrease performance a little bit.

## Usage

### Models

TODO

## Cache

Turbo exposes a cache for your convenience to cache anything you want.

```php
turbo()->cache()->set('key', 'value');
$value = turbo()->cache()->get('key');
$value = turbo()->cache()->getOrSet('key', fn() => 'value');
```

## TurboRedis Cache-Driver

If you use the `turbo-redis` cache-driver you will get a few advanced features.

- keys can be arrays, not just strings.
- if the key and data can contains Kirby Fields they will be automatically resolved to their value.
- using a closure you can abort setting a value. which is handy if you have strict or time consuming requirements on when to set the data and do not want to perform that check again after the value has been cached.
- you can even double check if the data can be safely stored as JSON (see settings).

```php
turbo()->cache()->set($keyCanBeStringOrArray, $dataCanBeArrayAndContainingKirbyFields);

$value = turbo()->cache()->getOrSet($key, function() use ($page) {
    if ($page->performSomeExpensiveCheck()) {
        throw new \Bnomei\AbortCachingException();
    }
    return [
        'uuid' => $page->uuid()->id(), 
        'title' => $page->title(), 
        'url' => $page->url()
    ];
});
```

## TurboStaticCache Helper

TODO

## Roadmap

- [ ] support for Files (not just Pages)

## Settings

| bnomei.turbo. | Default | Description |
|---------------|---------|-------------|
| xxx           | `xxx`   | xxx         |


## Disclaimer

This plugin is provided "as is" with no guarantee. You can use it at your own risk and always test it before using it in a production environment. If you find any issues, please [create a new issue](https://github.com/bnomei/kirby-turbo/issues/new).

## License

[MIT](https://opensource.org/licenses/MIT)

It is discouraged to use this plugin in any project that promotes racism, sexism, homophobia, animal abuse, violence or any other form of hate speech.
