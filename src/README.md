# src/

This directory contains all source code for the buildable WordPress assets.

Each directory contains scripts that are compiled as separate entries defined in `webpack.config.js`.

Assets are built by running `npm run build:dev` or `npm run build:prod`, which compiles the source files and outputs them to the `build/` directory. You can also run `npm run start:js` to start a development server that watches for changes and rebuilds automatically.

## Script entries

Defined in [`webpack.config.js`](../webpack.config.js) under `scriptEntries`. Each produces an enqueue-ready `.js` / `.css` asset in `build/`. These are registered to WordPress via `inc/Core/Assets.php`, and then enqueued as needed in the plugin classes that call them.

| Entry   | Source               | Purpose                                  |
| ------- | -------------------- | ---------------------------------------- |
| `admin` | `src/admin/index.ts` | WordPress admin panel scripts and styles |

## Script module entries

Defined in [`webpack.config.js`](../webpack.config.js) under `scriptModuleEntries`. These are compiled as native ES modules and registered with `wp_register_script_module()` rather than `wp_enqueue_script()`. There are none yet.
