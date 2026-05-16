# Small alignment wins — Block Finder

## Session context (read first)

You are working on the Block Finder WordPress plugin at:
`~/Develop/wp-projects/block-plugins/wp-content/plugins/block-finder`

This plugin provides a dashboard widget + WP-CLI command + REST endpoint for finding where specific blocks are used across posts, patterns (synced + theme-registered), templates, and template parts.

### Tech stack

- PHP 8.0 minimum, WordPress 6.4 minimum
- `@wordpress/scripts` 32.x with TypeScript 6.x
- ESLint flat config (`eslint.config.cjs`)
- Composer classmap autoload for `classes/` directory
- Reference Gutenberg clone at `~/Develop/wp-projects/block-plugins/wp-content/plugins/gutenberg`

### Architecture

- `Search_Service` (`classes/class-search-service.php`) — owns the query + parse + cache pipeline. All sources (posts / patterns / templates / parts) funnel through here.
- `REST_Controller` (`classes/class-rest-controller.php`) — thin HTTP wrapper, registers `GET /wp-json/block-finder/v1/search`, calls the service.
- `CLI_Command` (`classes/class-cli-command.php`) — thin CLI wrapper for `wp block-finder search`, calls the service directly (bypasses HTTP).
- `Dashboard` (`classes/class-dashboard.php`) — renders the dashboard widget form server-side.
- `Enqueues` (`classes/class-enqueues.php`) — enqueues JS / CSS on the dashboard screen.
- `src/scripts/form.ts` — dashboard form JS, calls REST via `@wordpress/api-fetch`.

### Before starting any item

1. Read `CHANGELOG.md` (Unreleased section) to see what's been built.
2. Read `FEATURES.md` to see deferred / future work.
3. Run `npm run lint && npm run build` to confirm a clean baseline.

### After each item

1. Run `npm run lint` (must pass clean — JS + CSS + PHP).
2. Run `npm run build` (must compile cleanly).
3. Add a bullet to `CHANGELOG.md` under `## [Unreleased]` in the appropriate section (Added / Changed / Fixed / Removed / Security).

Items below are independent — do them in any order. Each is small (under ~30 lines changed) and low-risk.

---

## 1. Drop dead-API guards

The plugin declares WP 6.4 minimum, but several `function_exists` / `class_exists` checks guard against APIs that all predate that. Cargo to remove.

### Locations

- [classes/class-dashboard.php](classes/class-dashboard.php) ~line 78 — `function_exists( 'wp_is_block_theme' )` (WP 5.9+)
- [classes/class-search-service.php](classes/class-search-service.php) ~line 337 — `! class_exists( WP_Block_Patterns_Registry::class )` (WP 5.5+)
- [classes/class-search-service.php](classes/class-search-service.php) ~line 386 — `! function_exists( 'get_block_templates' )` (WP 5.9+)
- [classes/class-search-service.php](classes/class-search-service.php) ~line 484 — `class_exists( WP_Block_Patterns_Registry::class )` (in `traverse_blocks()` pattern recursion) (WP 5.5+)

For each: remove the guard and any associated early `return`. The class / function is always present given our minimum, so the conditional always evaluates the same way.

### Acceptance

- All four guards removed.
- PHP lint passes (`npm run lint:php`).
- No functional change — search still works for all source types.

---

## 2. JS: replace URLSearchParams with addQueryArgs

In [src/scripts/form.ts](src/scripts/form.ts), the search request URL is built with `URLSearchParams` and `.append()` calls. Gutenberg uses `addQueryArgs` from `@wordpress/url` for this — it handles array-typed values automatically (`sources: ['posts','patterns']` becomes `?sources[]=posts&sources[]=patterns`).

### Before

```ts
const params = new URLSearchParams();
params.append('block', block);
params.append('post_type', postType);
params.append('page', page.toString());
params.append('filter', filter);
for (const status of checkedStatuses) {
    params.append('post_status[]', status);
}
for (const source of checkedSources) {
    params.append('sources[]', source);
}

const data = await apiFetch<SearchResponse>({
    path: `/block-finder/v1/search?${params.toString()}`,
    method: 'GET',
});
```

### After

```ts
import { addQueryArgs } from '@wordpress/url';

const data = await apiFetch<SearchResponse>({
    path: addQueryArgs('/block-finder/v1/search', {
        block,
        post_type: postType,
        page,
        filter,
        post_status: checkedStatuses,
        sources: checkedSources,
    }),
    method: 'GET',
});
```

`@wordpress/scripts` will detect the new import and add `wp-url` to the asset.php dependencies automatically.

### Acceptance

- `form.ts` uses `addQueryArgs`, no `URLSearchParams` usage remains.
- `build/block-finder.asset.php` now includes `wp-url` in its dependencies array.
- Browser-tested: dashboard search still returns correct results across all sources.

---

## 3. Add uninstall.php

The transient cache lingers in `wp_options` after plugin uninstall. WordPress runs `uninstall.php` automatically on uninstall (not on deactivation), which is the standard cleanup hook.

### Create `uninstall.php` at the plugin root

```php
<?php
/**
 * Fired when the plugin is uninstalled. Cleans up the transient cache from wp_options.
 *
 * @package Block_Finder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_block_finder_%'
    )
);
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_timeout_block_finder_%'
    )
);
// phpcs:enable
```

### Acceptance

- File exists at plugin root.
- PHPCS clean (no warnings or errors).
- No other code changes needed.

---

## 4. CACHE_VERSION for self-busting cache

During development we hit a real bug where stale cached results returned obsolete data after we changed search logic. Add a `CACHE_VERSION` constant to `Search_Service` and fold it into the cache key. Bumping it in a future release naturally invalidates the entire fleet of cached entries — no `wp transient delete --all` required.

### In `classes/class-search-service.php`

Add a constant alongside the existing ones:

```php
/**
 * Bump this when search semantics change. Old cached entries are then
 * naturally orphaned (different hash) and evicted by their TTL.
 *
 * @var int
 */
const CACHE_VERSION = 1;
```

Fold it into `get_cache_key()`:

```php
private function get_cache_key( $block, $post_type, $post_status, $sources ) {
    $statuses        = $post_status;
    $sorted_sources  = $sources;
    sort( $statuses );
    sort( $sorted_sources );
    return self::CACHE_PREFIX . $post_type . '_' . md5(
        self::CACHE_VERSION . '|' . $block . '|' . implode( ',', $statuses ) . '|' . implode( ',', $sorted_sources )
    );
}
```

### Acceptance

- `CACHE_VERSION` constant present and referenced in the cache-key hash.
- Existing searches return correct results (cache-key hash changes, so the next search after the upgrade is a fresh miss — that's expected and desired).
- CHANGELOG note mentions that future releases changing search semantics should bump this constant.

---

## 5. Filter hooks for extensibility

The plugin currently has zero `apply_filters()` calls — it's a closed black box to third parties. Two natural extension points to open.

### In `Search_Service::search()`

Add both filters inside the `if ( false === $results )` block so filtered results get cached.

**Filter the active sources before fanning out:**

```php
/**
 * Filters the list of sources to search for the current request.
 *
 * Third-party code can register additional sources by hooking this and
 * appending custom keys, then handling them via `block_finder_results`.
 *
 * @param string[] $sources Sources to search.
 * @param string   $block   Block name being searched for.
 */
$sources = apply_filters( 'block_finder_sources', $sources, $block );
```

**Filter the final result set:**

```php
/**
 * Filters the assembled result set before it's cached and returned.
 *
 * @param array  $results     Result rows merged across all sources.
 * @param string $block       Block name searched for.
 * @param string $post_type   Post type slug or "all".
 * @param array  $post_status Statuses included.
 * @param array  $sources     Sources included.
 */
$results = apply_filters( 'block_finder_results', $results, $block, $post_type, $post_status, $sources );
```

The order:

```
1. cache check (miss)
2. apply_filters('block_finder_sources', ...)
3. fan out to source-specific methods, merge results
4. apply_filters('block_finder_results', ...)
5. set_transient
```

### Acceptance

- Both filters present in `Search_Service::search()`.
- Filters fire on cache miss only (filtered output is cached).
- PHPDoc complete with `@param` for each filter.
- CHANGELOG bullet documents the new extension points.

---

## 6. get_item_schema() for the REST response

Gutenberg's REST controllers define `get_item_schema()` so response shape is discoverable via `OPTIONS` requests and the `/wp-json/` index. We define `get_collection_params()` (request args) but not the response schema.

### In `classes/class-rest-controller.php`

Add the `$schema` property and the schema method:

```php
/**
 * Cached schema for response validation.
 *
 * @var array
 */
private $schema;

/**
 * Response schema for the search endpoint.
 *
 * @return array
 */
public function get_item_schema() {
    if ( $this->schema ) {
        return $this->add_additional_fields_schema( $this->schema );
    }

    $this->schema = array(
        '$schema'    => 'http://json-schema.org/draft-04/schema#',
        'title'      => 'block-finder-search-response',
        'type'       => 'object',
        'properties' => array(
            'html'  => array(
                'description' => __( 'Rendered HTML for the result list, ready to be inserted into the dashboard widget.', 'block-finder' ),
                'type'        => 'string',
                'context'     => array( 'view' ),
                'readonly'    => true,
            ),
            'total' => array(
                'description' => __( 'Total number of matches across all sources (pre-pagination).', 'block-finder' ),
                'type'        => 'integer',
                'context'     => array( 'view' ),
                'readonly'    => true,
            ),
        ),
    );

    return $this->add_additional_fields_schema( $this->schema );
}
```

Reference it in `register_routes()`:

```php
register_rest_route(
    $this->namespace,
    '/' . $this->rest_base,
    array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_items' ),
            'permission_callback' => array( $this, 'get_items_permissions_check' ),
            'args'                => $this->get_collection_params(),
        ),
        'schema' => array( $this, 'get_public_item_schema' ),
    )
);
```

`get_public_item_schema()` is inherited from `WP_REST_Controller` and calls our `get_item_schema()` under the hood.

### Acceptance

- Schema method exists and is referenced in route registration.
- `OPTIONS /wp-json/block-finder/v1/search` returns a response schema with `html` and `total` properties.
- `/wp-json/block-finder/v1/` index lists the schema.
- PHP lint clean.
