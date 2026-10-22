# [WP-CLI](https://wp-cli.org/) Commands

## TOC

- [TOC](#toc)
- [wp ai-post-triage health-check](#wp-ai-post-triage-health-check)
- [wp ai-post-triage score](#wp-ai-post-triage-score)
- [wp ai-post-triage rescore](#wp-ai-post-triage-rescore)
- [wp ai-post-triage backfill](#wp-ai-post-triage-backfill)
- [wp ai-post-triage stats](#wp-ai-post-triage-stats)
- [wp ai-post-triage prune](#wp-ai-post-triage-prune)

## `wp ai-post-triage health-check`

Checks the plugin's table, the OpenRouter API key, whether OpenRouter can be reached and lists the chosen model, and the scoring jobs: Action Scheduler, the backlog of posts waiting to be scored, failed jobs, the daily prune, and whether scoring is paused or today's budget is spent. Failures exit with an error; warnings are listed and counted.

### Usage

```bash
wp ai-post-triage health-check [--skip-remote]
```

### Options

- `[--skip-remote]`: Don't contact OpenRouter.

## `wp ai-post-triage score`

Scores posts with the decision model and saves the result to the AI Post Triage table. Posts unchanged since they were last scored aren't sent to the model again; their saved answers are scored against the current rubric at no cost.

### Usage

```bash
wp ai-post-triage score <id>... [--force]
```

### Options

- `<id>...`: One or more post IDs.
- `[--force]`: Ask the model even if a post is unchanged.

### Examples

```bash
wp ai-post-triage score 123
wp ai-post-triage score 123 456 --force
```

## `wp ai-post-triage rescore`

Queues the newest wire posts in the AI Post Triage table to be scored again in the background, in batches of 25. Posts unchanged since they were last scored aren't sent to the model again, so they cost nothing. The same is on the settings screen, up to 1,000 posts.

### Usage

```bash
wp ai-post-triage rescore [--limit=<number>]
```

### Options

- `[--limit=<number>]`: How many of the newest posts to queue, up to 10,000. Default 100.

### Examples

```bash
wp ai-post-triage rescore
wp ai-post-triage rescore --limit=500
```

## `wp ai-post-triage backfill`

Adds existing wire posts to AI Post Triage, newest first. It reads posts of the wire post types and of the wire terms' taxonomies, by ID from the newest down, and stops once a whole batch is older than the scoring window. Posts already in AI Post Triage are skipped. Progress is saved after each batch, so a stopped backfill carries on where it left off. Added posts are scored in the background when automatic scoring is on.

### Usage

```bash
wp ai-post-triage backfill [--batch=<number>] [--limit=<number>] [--restart]
```

### Options

- `[--batch=<number>]`: Posts read per batch, up to 2,000. Default 500.
- `[--limit=<number>]`: Stop after reading about this many posts. Run the command again to carry on.
- `[--restart]`: Forget the saved progress and start again from the newest post.

### Examples

```bash
wp ai-post-triage backfill
wp ai-post-triage backfill --batch=1000 --limit=50000
wp ai-post-triage backfill --restart
```

## `wp ai-post-triage stats`

Shows wire post counts by status and priority band, the number of scored posts and the cost of their latest scores by model, and today's spend.

### Usage

```bash
wp ai-post-triage stats [--days=<number>] [--format=<format>]
```

### Options

- `[--days=<number>]`: Only posts from the last this many days.
- `[--format=<format>]`: `table` (default), `csv`, `json` or `yaml`. Formats other than `table` print `states` and `models` together.

### Examples

```bash
wp ai-post-triage stats
wp ai-post-triage stats --days=7 --format=json
```

## `wp ai-post-triage prune`

Clears the model's per-question answers from posts older than the retention, and deletes finished AI Post Triage jobs. AI Post Triage does this once a day on its own; scores and bands are kept.

### Usage

```bash
wp ai-post-triage prune [--days=<number>]
```

### Options

- `[--days=<number>]`: Clear answers of posts older than this many days. Defaults to the [`ai_post_triage/details_retention_days`](filters.md#ai_post_triagedetails_retention_days) filter, 90 days.

### Examples

```bash
wp ai-post-triage prune
wp ai-post-triage prune --days=30
```
