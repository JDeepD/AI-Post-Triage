# [WP-CLI](https://wp-cli.org/) Commands

## TOC

- [TOC](#toc)
- [wp wire-desk health-check](#wp-wire-desk-healthcheck)
- [wp wire-desk score](#wp-wire-desk-score)

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
