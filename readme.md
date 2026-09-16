# Pattern Builder

A WordPress plugin for creating, editing and organising block patterns in the
admin. Theme patterns (PHP files in `patterns/`) and user patterns (`wp_block`
posts) are managed in one interface, edited in the WordPress editor, and
optionally shared through [patternbuilderwp.com](https://patternbuilderwp.com).

Agents can drive the same operations through WordPress core's Abilities API.

## Requirements

WordPress 6.8+ · PHP 7.4+

## Install

Download a release and install it as a plugin, or from source:

```bash
git clone https://github.com/Twenty-Bellows/pattern-builder.git
cd pattern-builder
npm install && composer install
npm run build
```

`npm run start` boots a local WordPress with the plugin active (requires
Docker). `npm run watch` rebuilds on change.

## Documentation

| | |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | How the plugin is built. |
| [`docs/abilities.md`](docs/abilities.md) | The agent interface. |
| [`docs/cloud.md`](docs/cloud.md) | The patternbuilderwp.com integration. |
| [`docs/collections.md`](docs/collections.md) | Collections. |
| [`docs/dependencies.md`](docs/dependencies.md) | Dependency trees and attribution. |
| [`CLAUDE.md`](CLAUDE.md) | Commands, invariants and coding standards. |

## Related

- [Synced Patterns for Themes](https://github.com/Twenty-Bellows/synced-patterns-for-themes)
  — the runtime half, for shipping a theme built with Pattern Builder.
- [patternbuilderwp.com](https://github.com/Twenty-Bellows/patternbuilderwp.com)
  — the service.

## License

GPL-2.0-or-later. Built by [Twenty Bellows](https://twentybellows.com).
Issues: [GitHub](https://github.com/Twenty-Bellows/pattern-builder/issues).
