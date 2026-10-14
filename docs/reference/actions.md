# Action Hooks

## TOC

- [Action Hooks](#action-hooks)
  - [TOC](#toc)
  - [`wire_desk/get_template_part_$slug`](#wire_deskget_template_part_slug)
  - [`wire_desk/scored`](#wire_deskscored)
  - [`wire_desk/post_action`](#wire_deskpost_action)

## `wire_desk/get_template_part_$slug`

Fires when a template part is requested.

```php
do_action( 'wire_desk/get_template_part_' . $slug, string $slug, ?string $name, array $args );
```

### Parameters

- `$slug (string)`: Template slug.
- `$name (string|null)`: Optional. Template variation name.
- `$args (array<string, mixed>)`: Optional. Data to pass to the template.

## `wire_desk/scored`

Fires after the model scores a post and the score is saved. It doesn't fire when an unchanged post is skipped.

```php
do_action( 'wire_desk/scored', JDeepD\Wire_Desk\Scoring\Result $result );
```

### Parameters

- `$result (Result)`: The post ID, its score, band and per-question answers, the model snapshot that answered, and the cost in US dollars.

## `wire_desk/post_action`

Fires after an editor publishes, dismisses, restores or re-scores a wire post, from the review queue, the REST API or the command line. It doesn't fire when the action fails.

```php
do_action( 'wire_desk/post_action', string $action, int $post_id, int $user_id );
```

### Parameters

- `$action (string)`: One of `publish`, `dismiss`, `restore` and `rescore`.
- `$post_id (int)`: Post ID.
- `$user_id (int)`: The user who took the action.
