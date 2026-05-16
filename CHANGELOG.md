# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Prefix the change with one of these keywords:

-   _Added_: for new features.
-   _Changed_: for changes in existing functionality.
-   _Deprecated_: for soon-to-be removed features.
-   _Removed_: for now removed features.
-   _Fixed_: for any bug fixes.
-   _Security_: in case of vulnerabilities.

## [Unreleased]

### Added

-   `uninstall.php` now cleans up all `block_finder_` transients from `wp_options` on plugin uninstall
-   `Search_Service::CACHE_VERSION` constant (currently `1`) folded into the cache-key hash; bumping it in a future release naturally invalidates the entire fleet of cached entries without needing `wp transient delete --all`
-   `@wordpress/url` added as a dev dependency; `wp-url` now listed in the compiled asset's dependency array so WordPress loads it before the dashboard script
-   `@wordpress/components` and `@wordpress/element` added as dev dependencies; `wp-components` and `wp-element` now in the compiled asset's dependency array
-   `REST_Controller::get_item_schema()` defines the response shape (`html`, `total`) so `OPTIONS /wp-json/block-finder/v1/search` returns a discoverable schema and the `/wp-json/` index lists it
-   `block_finder_sources` filter in `Search_Service::search()` lets third-party code add or remove sources before the fan-out; fires on cache miss only so filtered output is cached
-   `block_finder_results` filter in `Search_Service::search()` lets third-party code modify the assembled result set before it is cached and returned
-   REST API endpoint `GET /wp-json/block-finder/v1/search` for block search queries
-   Per-result row showing total instances of the block in each post, and how many appear as Innerblocks
-   Post-type-aware result heading that pluralises the type label (e.g. "Paragraph block has been found in 12 pages")
-   Locale-aware number formatting via `number_format_i18n()` so large counts render correctly (e.g. "2,345")
-   Post-status filter on the dashboard form: searches default to Published only, but the user can include Draft, Pending, Scheduled, and Private content. Non-published results display a status badge in the meta line, and the cache key partitions by status set so searches with different status selections don't collide
-   `wp block-finder search` WP-CLI command. Same engine as the dashboard, gated only by CLI access (no REST permission check). Supports `--post-type`, `--post-status`, `--filter=all|nested`, `--format=table|json|csv|count|ids`, `--fields`. Useful for CI checks (`--format=count`), batch operations (`--format=ids | xargs ...`), and audit exports (`--format=csv > report.csv`)
-   New "Search in" control on the dashboard form. Searches default to Posts (current behaviour), but can additionally include Patterns (the `wp_block` post type, renamed from "Reusable blocks" in WP 6.3) and — on block themes — Templates and Template parts (file-based + DB-stored). Each result row shows a source/type badge in the meta line, the result-list heading adapts to the scope ("12 templates", "5 entries" when mixed, etc.), and edit links route to the Site Editor for templates/parts. CLI mirrors via `--sources=posts,patterns,templates,parts`. Template / part / pattern changes flush the entire search cache (since they can appear in any cross-source search); `switch_theme` does the same to cover file-based template churn

### Changed

-   Block and post-type selectors on the dashboard widget replaced with `<ComboboxControl>` from `@wordpress/components`; the hand-rolled `makeAutocomplete` function (~200 lines) is deleted
-   Dashboard search URL is now built with `addQueryArgs` from `@wordpress/url` instead of `URLSearchParams`; array-typed params (`post_status`, `sources`) are serialized in the `key[]=value` format WordPress expects
-   Restructured the dashboard form to remove redundancy: Block selector is now first, "Search in" follows, then Post type and Post status appear only when relevant. Post type shows when "Posts" is checked; Post status shows when either "Posts" or "Patterns" is checked. The submit button is disabled when no source is selected
-   Post type defaults to "All Post Types" and is no longer required at the REST layer — searches with Patterns / Templates / Template parts as the only sources no longer fail with "post_type is required"
-   Extracted the query + parse + cache pipeline out of `REST_Controller` into a new `Search_Service` class. No user-facing behaviour change; the REST endpoint delegates to the service via `search()`, and the `save_post` / `delete_post` / trash hooks are now owned by the service. Sets up clean entry points for the WP-CLI and templates features still pending in [FEATURES.md](FEATURES.md)
-   Bumped minimum WordPress to 6.4 and minimum PHP to 8.0
-   Renamed "InnerBlocks (N)" filter toggle to "Innerblocks (N)"; per-row indicator renamed to "As Innerblock: N"
-   Switched the dashboard front-end from native `fetch()` to `@wordpress/api-fetch`; WordPress core now wires the REST root URL and `X-WP-Nonce` middleware automatically
-   Cache invalidation is now surgical: only transients for the affected post type are flushed, autosaves and revisions are skipped, and trash/untrash transitions are covered
-   Modernised PHP class structure: dropped the `tc_` method prefix, adopted PHP 8 nullsafe operators, replaced the `Plugin_Module` abstract with direct instantiation
-   Migrated ESLint to flat config (`eslint.config.cjs`); bumped `@wordpress/scripts` 31→32, `@wordpress/eslint-plugin` 24→25, `@wordpress/env` 10→11, `typescript` 5→6
-   Bumped `tsconfig.json` target to `esnext`

### Removed

-   Hand-rolled `makeAutocomplete` function and all associated `.autocomplete-wrapper`, `.autocomplete-input`, `.autocomplete-dropdown`, and `.autocomplete-item` CSS — replaced by `<ComboboxControl>`
-   Dead `function_exists( 'wp_is_block_theme' )` guard in `Dashboard::render_form()` — `wp_is_block_theme()` has been available since WP 5.9, which predates the WP 6.4 minimum
-   Dead `class_exists( WP_Block_Patterns_Registry::class )` guards in `Search_Service::search_registered_patterns()` and `Search_Service::traverse_blocks()` — available since WP 5.5
-   Dead `function_exists( 'get_block_templates' )` guard in `Search_Service::search_templates()` — available since WP 5.9
-   Legacy `admin-ajax.php` handler in favour of the REST endpoint
-   `Plugin_Module` abstract class
-   Italic "Parent: X" context line under each result row (subsumed by the new Count / As Innerblock totals)
-   `.prettierrc.js` (dead — `.prettierrc` JSON wins by priority)
-   `wp_localize_script()` / inline-script bootstrap that exposed `window.blockFinder` (no longer needed once `wp-api-fetch` is a script dependency)

### Fixed

-   Guarded against a fatal error when `get_post_type_object()` returns `null` for the supplied post type slug
-   `core/pattern` references are now resolved during search: blocks living inside theme-registered patterns (e.g. Twenty Twenty-Five's `header` template part, which is just a single `<!-- wp:pattern -->` reference) now surface when searching templates, parts, or any post content. Previously the search engine treated `core/pattern` as opaque and missed everything inside
-   The "Patterns" source now searches both user-saved synced patterns (`wp_block` posts) AND theme/plugin-registered patterns from `WP_Block_Patterns_Registry`. Previously it only checked `wp_block` posts, so most "Patterns" searches came back empty on default installs (which usually have no synced patterns) even though the theme might have 100+ registered patterns

### Security

-   REST endpoint enforces `current_user_can( 'edit_posts' )` via `permission_callback` and returns `WP_Error` on denial (previously a nonce-only check)

## [1.0.7]

### Added

-   InnerBlock detection to identify blocks nested inside other blocks
-   Filter toggle to switch between "All Blocks" and "InnerBlocks" views
-   Context indicators showing parent block names for nested blocks
-   Server-side filtering for accurate pagination with filters applied

### Changed

-   Migrated JavaScript to TypeScript for improved type safety
-   Updated README.md and readme.txt with new feature documentation
-   Improved filter UI with pill-style toggle buttons

## [1.0.6]

### Added

-   Searchable autocomplete dropdowns for post type and block selectors
-   Type-to-search functionality with real-time filtering
-   Keyboard navigation support (arrow keys, enter, escape) for dropdown options
-   Visual improvements including spacing between input and dropdown menu

## [1.0.5]

### Changed

-   Tested up to 6.7.1

## [1.0.4]

### Changed

-   Changed function names to include better prefix
-   Autoload suffix to avoid conflicts with other plugins

## [1.0.3]

### Changed

-   Readme text and other files related to publishing via SVN

## [1.0.2]

### Added

-   Missing composer.json file

### Changed

-   Improved escaping of php

### Fixed

-   Stable tags out of sync, updated constant to match

### Security

-   Blocked direct access to primary php file

## [1.0.1]

### Fixed

-   Scripts loading outside dashboard triggering a console error

## [1.0.0]

-   Initial release

## [0.1.0]

### Added

-   Base files for initial plugin setup
