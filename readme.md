# Kirby Turbo 

[![Kirby 5](https://flat.badgen.net/badge/Kirby/5?color=ECC748)](https://getkirby.com)
![PHP 8.2](https://flat.badgen.net/badge/PHP/8.2?color=4E5B93&icon=php&label)
![Release](https://flat.badgen.net/packagist/v/bnomei/kirby-turbo?color=ae81ff&icon=github&label)
![Downloads](https://flat.badgen.net/packagist/dt/bnomei/kirby-turbo?color=272822&icon=github&label)
[![Coverage](https://flat.badgen.net/codeclimate/coverage/bnomei/kirby-turbo?icon=codeclimate&label)](https://codeclimate.com/github/bnomei/kirby-turbo)
[![Maintainability](https://flat.badgen.net/codeclimate/maintainability/bnomei/kirby-turbo?icon=codeclimate&label)](https://codeclimate.com/github/bnomei/kirby-turbo/issues)
[![Discord](https://flat.badgen.net/badge/discord/bnomei?color=7289da&icon=discord&label)](https://discordapp.com/users/bnomei)
[![Buymecoffee](https://flat.badgen.net/badge/icon/donate?icon=buymeacoffee&color=FF813F&label)](https://www.buymeacoffee.com/bnomei)

XXX

## Installation

- unzip [master.zip](https://github.com/bnomei/kirby-turbo/archive/master.zip) as folder `site/plugins/kirby-turbo` or
- `git submodule add https://github.com/bnomei/kirby-turbo.git site/plugins/kirby-turbo` or
- `composer require bnomei/kirby-turbo`

## TODOS

- [ ] test with turbo
- [ ] test multilang of content
- [ ] support for Files

## Setup

**site/config/config.php**
```php
<?php

return [
    // you have to set caches from default `file`
    // to any in-memory cache like `adredis`, `redis` or `apcu`
    'bnomei.turbo.cache.content' => ['type' => 'adredis'],
    'bnomei.turbo.cache.dir' => ['type' => 'adredis'],
];
```

> [!TIP]
> Even if Kirby CMS v5 ships with built-in Redis support consider using my [advanced cache-driver for Redis](https://github.com/bnomei/kirby3-redis-cachedriver) with in-memory store, transactions and preloading.

## Usage

xxx

## Settings

| bnomei.turbo. | Default | Description |
|---------------|---------|-------------|
| xxx           | `xxx`   | xxx         |


## Disclaimer

This plugin is provided "as is" with no guarantee. You can use it at your own risk and always test it before using it in a production environment. If you find any issues, please [create a new issue](https://github.com/bnomei/kirby-turbo/issues/new).

## License

[MIT](https://opensource.org/licenses/MIT)

It is discouraged to use this plugin in any project that promotes racism, sexism, homophobia, animal abuse, violence or any other form of hate speech.
