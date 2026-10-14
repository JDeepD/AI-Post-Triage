# Filter Hooks

## TOC

- [Filter Hooks](#filter-hooks)
  - [TOC](#toc)
  - [`wire_desk/template_args`](#wire_desktemplate_args)
- [`wire_desk/template_file_names`](#wire_desktemplate_file_names)
  - [`wire_desk/located_template`](#wire_desklocated_template)
  - [`wire_desk/template_paths`](#wire_desktemplate_paths)
  - [`wire_desk/capability_map`](#wire_deskcapability_map)

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
