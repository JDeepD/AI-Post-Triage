# [WP-CLI](https://wp-cli.org/) Commands

## TOC

- [TOC](#toc)
- [wp wire-desk health-check](#wp-wire-desk-health-check)
- [wp wire-desk score](#wp-wire-desk-score)
- [wp wire-desk rescore](#wp-wire-desk-rescore)
- [wp wire-desk backfill](#wp-wire-desk-backfill)
- [wp wire-desk stats](#wp-wire-desk-stats)
- [wp wire-desk prune](#wp-wire-desk-prune)

## `wp wire-desk health-check`

Checks the plugin's table, the OpenRouter API key, whether OpenRouter can be reached and lists the chosen model, and the scoring jobs: Action Scheduler, the backlog of posts waiting to be scored, failed jobs, the daily prune, and whether scoring is paused or today's budget is spent. Failures exit with an error; warnings are listed and counted.

### Usage

```bash
wp wire-desk health-check [--skip-remote]
```

### Options

- `[--skip-remote]`: Don't contact OpenRouter.

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

## `wp wire-desk backfill`

Adds existing wire posts to Wire Desk, newest first. It reads posts of the wire post types and of the wire terms' taxonomies, by ID from the newest down, and stops once a whole batch is older than the scoring window. Posts already in Wire Desk are skipped. Progress is saved after each batch, so a stopped backfill carries on where it left off. Added posts are scored in the background when automatic scoring is on.

### Usage

```bash
wp wire-desk backfill [--batch=<number>] [--limit=<number>] [--restart]
```

### Options

- `[--batch=<number>]`: Posts read per batch, up to 2,000. Default 500.
- `[--limit=<number>]`: Stop after reading about this many posts. Run the command again to carry on.
- `[--restart]`: Forget the saved progress and start again from the newest post.

### Examples

```bash
wp wire-desk backfill
wp wire-desk backfill --batch=1000 --limit=50000
wp wire-desk backfill --restart
```

## `wp wire-desk stats`

Shows wire post counts by status and priority band, the number of scored posts and the cost of their latest scores by model, and today's spend.

### Usage

```bash
wp wire-desk stats [--days=<number>] [--format=<format>]
```

### Options

- `[--days=<number>]`: Only posts from the last this many days.
- `[--format=<format>]`: `table` (default), `csv`, `json` or `yaml`. Formats other than `table` print `states` and `models` together.

### Examples

```bash
wp wire-desk stats
wp wire-desk stats --days=7 --format=json
```

## `wp wire-desk prune`

Clears the model's per-question answers from posts older than the retention, and deletes finished Wire Desk jobs. Wire Desk does this once a day on its own; scores and bands are kept.

### Usage

```bash
wp wire-desk prune [--days=<number>]
```

### Options

- `[--days=<number>]`: Clear answers of posts older than this many days. Defaults to the [`wire_desk/details_retention_days`](filters.md#wire_deskdetails_retention_days) filter, 90 days.

### Examples

```bash
wp wire-desk prune
wp wire-desk prune --days=30
```
