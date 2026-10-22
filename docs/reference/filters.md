# Filter Hooks

## TOC

- [Filter Hooks](#filter-hooks)
  - [TOC](#toc)
  - [`ai_post_triage/template_args`](#ai_post_triagetemplate_args)
- [`ai_post_triage/template_file_names`](#ai_post_triagetemplate_file_names)
  - [`ai_post_triage/located_template`](#ai_post_triagelocated_template)
  - [`ai_post_triage/template_paths`](#ai_post_triagetemplate_paths)
  - [`ai_post_triage/capability_map`](#ai_post_triagecapability_map)
  - [`ai_post_triage/openrouter_api_key`](#ai_post_triageopenrouter_api_key)
  - [`ai_post_triage/unsupported_models`](#ai_post_triageunsupported_models)
  - [`ai_post_triage/state`](#ai_post_triagestate)
  - [`ai_post_triage/is_wire_content`](#ai_post_triageis_wire_content)
  - [`ai_post_triage/details_retention_days`](#ai_post_triagedetails_retention_days)

## `ai_post_triage/template_args`

Filters the arguments passed to a template part.

```php
apply_filters( 'ai_post_triage/template_args', array $args, string $slug, ?string $name );
```

### Parameters

- `$args (array<string, mixed>)`: Data passed to the template.
- `$slug (string)`: Template slug.
- `$name (string|null)`: Optional. Template variation name.

## `ai_post_triage/template_file_names`

Filters the list of template file names to locate.

```php
apply_filters( 'ai_post_triage/template_file_names', array $templates, string $slug, ?string $name );
```

### Parameters

- `$templates (array<int, string>)`: List of template file names to locate.
- `$slug (string)`: Template slug.
- `$name (string|null)`: Optional. Template variation name.

## `ai_post_triage/located_template`

Filters the located template path.

```php
apply_filters( 'ai_post_triage/located_template', string|false $template, string[] $templates );
```

### Parameters

- `$template (string|false)`: Full path to the located template, or false if not found.
- `$templates (string[])`: Template files that were searched for.

## `ai_post_triage/template_paths`

Filters the list of paths to search for templates.

```php
apply_filters( 'ai_post_triage/template_paths', array $paths );
```

### Parameters

- `$paths (array<int, string>)`: List of paths to search for templates, keyed by their priority (lower number = higher priority).

## `ai_post_triage/capability_map`

Filters the capability each AI Post Triage capability is granted from. Values must be primitive capabilities, such as `edit_posts`. An AI Post Triage capability added to a role directly takes precedence.

```php
apply_filters( 'ai_post_triage/capability_map', array $map );
```

### Parameters

- `$map (array<string, string>)`: AI Post Triage capability => capability that grants it. Defaults to `ai_post_triage_manage` => `manage_options` and `ai_post_triage_review` => `edit_others_posts`.

## `ai_post_triage/openrouter_api_key`

Filters the OpenRouter API key. By default the key comes from the `AI_POST_TRIAGE_OPENROUTER_API_KEY` constant, then the key saved on the AI Post Triage settings screen, then the OpenRouter connector under Settings → Connectors.

```php
apply_filters( 'ai_post_triage/openrouter_api_key', string $key );
```

### Parameters

- `$key (string)`: API key. Empty when none is configured.

## `ai_post_triage/unsupported_models`

Filters the model ID prefixes left out of the settings screen's model list. The list is cached for a day, so changes show up once the `ai_post_triage_models` transient expires or is deleted.

```php
apply_filters( 'ai_post_triage/unsupported_models', array $prefixes );
```

### Parameters

- `$prefixes (string[])`: Model ID prefixes. Defaults to `scaledown/` and `respan/`, whose models expect a different input.

## `ai_post_triage/state`

Filters what the model reads about a post. Values must be strings; empty values, non-string keys and non-scalar values are dropped. Everything in the state is part of the fingerprint, so changing it makes posts be scored again.

```php
apply_filters( 'ai_post_triage/state', array $state, WP_Post $post );
```

### Parameters

- `$state (array<string, string>)`: Facts about the post. `headline` is always set; `summary` or `body` depend on the "Model reads" setting, and `newsroom` holds the newsroom context when one is saved.
- `$post (WP_Post)`: The post being scored.

## `ai_post_triage/is_wire_content`

Filters whether a post is wire content. By default a post is wire content when its post type is one of the wire post types, or it has one of the wire terms. Runs when a post is saved or its wire terms change.

```php
apply_filters( 'ai_post_triage/is_wire_content', bool $is_wire, WP_Post $post );
```

### Parameters

- `$is_wire (bool)`: Whether the post is wire content.
- `$post (WP_Post)`: The post being saved.

## `ai_post_triage/details_retention_days`

Filters how many days the model's per-question answers are kept, counted from the post date. A daily job, and `wp ai-post-triage prune`, clear older answers; scores and bands are kept. Return 0 to keep every answer.

```php
apply_filters( 'ai_post_triage/details_retention_days', int $days );
```

### Parameters

- `$days (int)`: Days. Default 90.
