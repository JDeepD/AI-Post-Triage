# Filter Hooks

## TOC

- [Filter Hooks](#filter-hooks)
  - [TOC](#toc)
  - [`wire_desk/template_args`](#wire_desktemplate_args)
- [`wire_desk/template_file_names`](#wire_desktemplate_file_names)
  - [`wire_desk/located_template`](#wire_desklocated_template)
  - [`wire_desk/template_paths`](#wire_desktemplate_paths)
  - [`wire_desk/capability_map`](#wire_deskcapability_map)
  - [`wire_desk/openrouter_api_key`](#wire_deskopenrouter_api_key)
  - [`wire_desk/unsupported_models`](#wire_deskunsupported_models)
  - [`wire_desk/state`](#wire_deskstate)
  - [`wire_desk/is_wire_content`](#wire_deskis_wire_content)
  - [`wire_desk/details_retention_days`](#wire_deskdetails_retention_days)

## `wire_desk/template_args`

Filters the arguments passed to a template part.

```php
apply_filters( 'wire_desk/template_args', array $args, string $slug, ?string $name );
```

### Parameters

- `$args (array<string, mixed>)`: Data passed to the template.
- `$slug (string)`: Template slug.
- `$name (string|null)`: Optional. Template variation name.

## `wire_desk/template_file_names`

Filters the list of template file names to locate.

```php
apply_filters( 'wire_desk/template_file_names', array $templates, string $slug, ?string $name );
```

### Parameters

- `$templates (array<int, string>)`: List of template file names to locate.
- `$slug (string)`: Template slug.
- `$name (string|null)`: Optional. Template variation name.

## `wire_desk/located_template`

Filters the located template path.

```php
apply_filters( 'wire_desk/located_template', string|false $template, string[] $templates );
```

### Parameters

- `$template (string|false)`: Full path to the located template, or false if not found.
- `$templates (string[])`: Template files that were searched for.

## `wire_desk/template_paths`

Filters the list of paths to search for templates.

```php
apply_filters( 'wire_desk/template_paths', array $paths );
```

### Parameters

- `$paths (array<int, string>)`: List of paths to search for templates, keyed by their priority (lower number = higher priority).

## `wire_desk/capability_map`

Filters the capability each Wire Desk capability is granted from. Values must be primitive capabilities, such as `edit_posts`. A Wire Desk capability added to a role directly takes precedence.

```php
apply_filters( 'wire_desk/capability_map', array $map );
```

### Parameters

- `$map (array<string, string>)`: Wire Desk capability => capability that grants it. Defaults to `wire_desk_manage` => `manage_options` and `wire_desk_review` => `edit_others_posts`.

## `wire_desk/openrouter_api_key`

Filters the OpenRouter API key. By default the key comes from the `WIRE_DESK_OPENROUTER_API_KEY` constant, then the key saved on the Wire Desk settings screen, then the OpenRouter connector under Settings → Connectors.

```php
apply_filters( 'wire_desk/openrouter_api_key', string $key );
```

### Parameters

- `$key (string)`: API key. Empty when none is configured.

## `wire_desk/unsupported_models`

Filters the model ID prefixes left out of the settings screen's model list. The list is cached for a day, so changes show up once the `wire_desk_models` transient expires or is deleted.

```php
apply_filters( 'wire_desk/unsupported_models', array $prefixes );
```

### Parameters

- `$prefixes (string[])`: Model ID prefixes. Defaults to `scaledown/` and `respan/`, whose models expect a different input.

## `wire_desk/state`

Filters what the model reads about a post. Values must be strings; empty values, non-string keys and non-scalar values are dropped. Everything in the state is part of the fingerprint, so changing it makes posts be scored again.

```php
apply_filters( 'wire_desk/state', array $state, WP_Post $post );
```

### Parameters

- `$state (array<string, string>)`: Facts about the post. `headline` is always set; `summary` or `body` depend on the "Model reads" setting, and `newsroom` holds the newsroom context when one is saved.
- `$post (WP_Post)`: The post being scored.

## `wire_desk/is_wire_content`

Filters whether a post is wire content. By default a post is wire content when its post type is one of the wire post types, or it has one of the wire terms. Runs when a post is saved or its wire terms change.

```php
apply_filters( 'wire_desk/is_wire_content', bool $is_wire, WP_Post $post );
```

### Parameters

- `$is_wire (bool)`: Whether the post is wire content.
- `$post (WP_Post)`: The post being saved.

## `wire_desk/details_retention_days`

Filters how many days the model's per-question answers are kept, counted from the post date. A daily job, and `wp wire-desk prune`, clear older answers; scores and bands are kept. Return 0 to keep every answer.

```php
apply_filters( 'wire_desk/details_retention_days', int $days );
```

### Parameters

- `$days (int)`: Days. Default 90.
