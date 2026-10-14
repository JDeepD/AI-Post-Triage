# [WP-CLI](https://wp-cli.org/) Commands

## TOC

- [TOC](#toc)
- [wp wire-desk health-check](#wp-wire-desk-healthcheck)
- [wp wire-desk score](#wp-wire-desk-score)
- [wp wire-desk rescore](#wp-wire-desk-rescore)

## `wp wire-desk health-check`

Checks the health of the plugin and reports any issues.

### Usage

```bash
wp wire-desk health-check
```

## `wp wire-desk score`

Scores posts with the decision model and saves the result to the Wire Desk table. Posts unchanged since they were last scored aren't sent to the model again; their saved answers are scored against the current rubric at no cost.

### Usage

```bash
wp wire-desk score <id>... [--force]
```

### Options

- `<id>...`: One or more post IDs.
- `[--force]`: Ask the model even if a post is unchanged.

### Examples

```bash
wp wire-desk score 123
wp wire-desk score 123 456 --force
```

## `wp wire-desk rescore`

Queues the newest wire posts in the Wire Desk table to be scored again in the background, in batches of 25. Posts unchanged since they were last scored aren't sent to the model again, so they cost nothing. The same is on the settings screen, up to 1,000 posts.

### Usage

```bash
wp wire-desk rescore [--limit=<number>]
```

### Options

- `[--limit=<number>]`: How many of the newest posts to queue, up to 10,000. Default 100.

### Examples

```bash
wp wire-desk rescore
wp wire-desk rescore --limit=500
```
