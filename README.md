# hypervel-auditing

Audit changes to your Eloquent models on [Hypervel](https://github.com/hypervel/components).

> [!WARNING]
> **Development only — do not use this package in production until Hypervel 0.4
> is released.**
>
> It is built for Hypervel 0.4, which has no release yet: 0.4 exists only as the
> `0.4.x-dev` branch of [`hypervel/components`](https://github.com/hypervel/components),
> and this package is developed and tested against that moving branch. Until 0.4
> ships, anything here can change without a deprecation period — the API, the
> configuration and the database schema included. Use it to evaluate or to build
> against Hypervel 0.4, and pin the version you tested.

A port of [`owen-it/laravel-auditing`](https://github.com/owen-it/laravel-auditing)
with the same class topology, so upstream's documentation and mental model
mostly carry over. It is not a drop-in replacement: audits are stored normalized
across two tables, and auditing is coroutine-aware;
[Differences](src/docs/differences.md) lists the rest.

## Requirements

- PHP 8.4 or newer (CI runs 8.4 and 8.5)
- Hypervel 0.4, which today means `hypervel/components` at `0.4.x-dev`. The
  package requires `hypervel/components` itself rather than its split packages.

## Installation

Hypervel 0.4 is only available as a dev branch, so your application's
`composer.json` must already allow it: `"minimum-stability": "dev"` together
with `"prefer-stable": true`. [Installation](src/docs/installation.md) covers the
table names `auditing:install` asks for, adopting existing tables, publishing,
and how releases track `0.4.x-dev`.

```sh
composer require ipsocode/hypervel-auditing
php artisan auditing:install
php artisan migrate
```

## Usage

```php
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

class Article extends Model implements AuditableContract
{
    use Auditable;
}

$article->update(['title' => 'Revised title']);
$article->audits()->latest()->first()->getModified();
// ['title' => ['new' => 'Revised title', 'old' => 'Draft title']]
```

The model needs both the trait and the interface: the trait supplies the
behavior, and the `Auditor` and the driver type-hint the interface.
In a console process, tests included, model events are audited only once
`auditing.console` is `true`; see [Recording](src/docs/recording.md#when-a-model-is-audited).

## Documentation

Start at the [documentation index](src/docs/index.md), which lists every page in
reading order; [Configuration](src/docs/configuration.md) lists every key.

## Contributing

The development setup, the checks CI runs, the coroutine-safety rules every
change is held to, and how releases are cut are in [CONTRIBUTING.md](https://github.com/ipsocode/hypervel-auditing/blob/main/CONTRIBUTING.md).
Report security issues privately, as described in [SECURITY.md](https://github.com/ipsocode/hypervel-auditing/blob/main/.github/SECURITY.md),
rather than in a public issue.

## Credits

A port of [`owen-it/laravel-auditing`](https://github.com/owen-it/laravel-auditing)
by Antério Vieira, Quetzy Garcia, Raphael França and its contributors, to
[Hypervel](https://github.com/hypervel/components).

## License

MIT. See [LICENSE](LICENSE), which carries the copyright notices of this package
and of `owen-it/laravel-auditing`.
