# Wire Desk

[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue?logo=php)](composer.json)
[![WordPress](https://img.shields.io/badge/WordPress-7.1%2B-blue?logo=wordpress)](https://wordpress.org)

---

Wire Desk helps high-volume newsrooms triage wire-ingested stories. It asks a decision model a set of configurable yes/no questions about each incoming story, scores it, and gives editors a review queue to decide what gets published.

Built on [rtCamp/plugin-skeleton-d](https://github.com/rtCamp/plugin-skeleton-d) v0.2.0.

> [!IMPORTANT]
> Wire Desk sends the parts of each story you choose (the headline by default) to a third-party decision model through OpenRouter, using your own API key. You are responsible for making sure your wire agency agreements and data policies allow this. Wire Desk only suggests priorities; editors always decide what gets published.

## Documentation

- **[Development Guide](docs/DEVELOPMENT.md)** - Local setup, commands, testing, and contribution guidelines.

**Reference:**
[Action Hooks](docs/reference/actions.md) | [Filter Hooks](docs/reference/filters.md) | [Constants](docs/reference/constants.md) | [WP-CLI Commands](docs/reference/cli.md) | [REST API](docs/reference/rest.md)

## Project Structure

```
├── .github/workflows/      # CI/CD workflows (call the skeleton's reusable workflows)
├── docs/                   # Development guides and references
├── inc/                    # Plugin-specific PHP source
├── framework/              # Reusable framework (shared with the skeleton)
│   └── README.md
├── src/                    # TypeScript/JS entry points
│   └── README.md
├── templates/              # PHP templates with theme override support
└── tests/                  # PHPUnit tests
```

See [./docs/DEVELOPMENT.md](docs/DEVELOPMENT.md#directory-structure) for a detailed directory tree and descriptions.

## License

GPL-3.0-or-later. See [LICENSE.md](LICENSE.md).
