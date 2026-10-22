# Action Hooks

## TOC

- [Action Hooks](#action-hooks)
  - [TOC](#toc)
  - [`ai_post_triage/get_template_part_$slug`](#ai_post_triageget_template_part_slug)
  - [`ai_post_triage/scored`](#ai_post_triagescored)
  - [`ai_post_triage/post_action`](#ai_post_triagepost_action)

## `ai_post_triage/get_template_part_$slug`

Fires when a template part is requested.

```php
do_action( 'ai_post_triage/get_template_part_' . $slug, string $slug, ?string $name, array $args );
```

### Parameters

- `$slug (string)`: Template slug.
- `$name (string|null)`: Optional. Template variation name.
- `$args (array<string, mixed>)`: Optional. Data to pass to the template.

## `ai_post_triage/scored`

Fires after the model scores a post and the score is saved. It doesn't fire when an unchanged post is skipped.

```php
do_action( 'ai_post_triage/scored', JDeepD\AI_Post_Triage\Scoring\Result $result );
```

### Parameters

- `$result (Result)`: The post ID, its score, band and per-question answers, the model snapshot that answered, and the cost in US dollars.

## `ai_post_triage/post_action`

Fires after an editor publishes, dismisses, restores or re-scores a wire post, from the review queue, the REST API or the command line, or adds a post with "Add to review queue" on a post list. It doesn't fire when the action fails.

```php
do_action( 'ai_post_triage/post_action', string $action, int $post_id, int $user_id );
```

### Parameters

- `$action (string)`: One of `publish`, `dismiss`, `restore`, `rescore` and `add`.
- `$post_id (int)`: Post ID.
- `$user_id (int)`: The user who took the action.
