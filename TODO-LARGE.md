# Larger refactors — Block Finder

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

- `Search_Service` (`classes/class-search-service.php`) — owns the query + parse + cache pipeline. All sources funnel through here.
- `REST_Controller` (`classes/class-rest-controller.php`) — thin HTTP wrapper, registers `GET /wp-json/block-finder/v1/search`, calls the service.
- `CLI_Command` (`classes/class-cli-command.php`) — thin CLI wrapper for `wp block-finder search`, calls the service directly (bypasses HTTP).
- `Dashboard` (`classes/class-dashboard.php`) — currently renders the dashboard widget form server-side as plain HTML.
- `Enqueues` (`classes/class-enqueues.php`) — enqueues JS / CSS on the dashboard screen.
- `src/scripts/form.ts` — dashboard form JS, vanilla TypeScript with no framework. Hand-rolled autocomplete, conditional show/hide for sections, calls REST via `@wordpress/api-fetch`.

### Before starting

1. Read `CHANGELOG.md` (Unreleased) for what's already shipped.
2. Read `FEATURES.md` for roadmap notes.
3. Confirm `TODO-SMALL.md` is complete (the small wins set up the codebase cleanly for these refactors — particularly the `addQueryArgs` and filter-hook changes).
4. Run `npm run lint && npm run build` to confirm a clean baseline.

### Scope warning

These are multi-day refactors. Each one changes the user-facing UI noticeably. Read the full item before starting — don't pick away at pieces in isolation.

---

## 1. Migrate dashboard widget UI to React + @wordpress/components

Currently the dashboard widget is server-rendered HTML with vanilla TypeScript handling form interactions: autocomplete, conditional visibility (Post type / Post status hide when Posts isn't a selected source), submit. This works but reinvents wheels that Gutenberg's component library already ships.

### Scope

**Server side** — `classes/class-dashboard.php`:

- Replace `render_form()` HTML with a single React root mount point: `<div id="block-finder-root" data-..></div>`.
- Bootstrapping data (current registered blocks, post types, available sources gated on `wp_is_block_theme()`) can either be:
  - Passed via `wp_add_inline_script` as a `window.blockFinderBootstrap = { ... }` global, or
  - Fetched via a new `GET /wp-json/block-finder/v1/options` endpoint on mount

The inline-script approach is simpler and avoids an extra request. Match what Gutenberg does for the block editor (see `lib/experimental/class-wp-rest-block-editor-settings-controller.php` for the inline-script bootstrap pattern).

**Client side** — `src/scripts/form.ts` (rename to `src/scripts/form.tsx`):

- Replace `makeAutocomplete()` (~200 lines) with `<ComboboxControl>` from `@wordpress/components` for both the block picker and the post-type picker.
- Replace the hand-rolled checkbox fieldsets (sources, statuses) with `<CheckboxControl>` instances inside a `<Fieldset>` wrapper.
- Replace the submit button with `<Button variant="primary">`.
- Form state via `useState`. No `@wordpress/data` store — overkill for a single isolated widget.
- Conditional sections become `{showPostType && <PostTypeSection />}` JSX expressions; the `block-finder-hidden` class trick goes away.
- Server-rendered result HTML stays as `dangerouslySetInnerHTML` until item 2 swaps it for `<DataViews>`.

### Build setup

- `@wordpress/scripts` already handles JSX/TSX by default — no webpack config changes needed.
- Add the new imports; `@wordpress/scripts` auto-detects and adds `wp-components`, `wp-element` to the asset.php dependencies.
- Bundle size will grow by ~80–120 KB minified for the components used. Acceptable for an admin-only widget.

### Why bother

- ~200 lines of hand-rolled autocomplete deleted.
- Accessibility improvements come free: keyboard nav, screen reader announcements, focus management, ARIA labels, all battle-tested in Gutenberg.
- Visually matches Gutenberg controls automatically when WP core updates them — no future "the new WP design system broke our pills" maintenance.
- Sets up item 2 (DataViews for results) cleanly.

### Cost

- Significant rewrite of `src/scripts/form.ts` → `form.tsx`.
- Multi-day effort; expect UI polish iterations.
- The dashboard widget HTML shifts from "form with results below" to "React root with form + results inside" — small but visible diff.
- Translations: any `__()` calls inside JSX need to use the JS `@wordpress/i18n` package with the same `block-finder` domain.

### Acceptance

- Widget functionally identical: block search, post-type filter, status filter, sources filter, conditional sections (post type only when Posts checked; post status only when Posts or Patterns checked), submit-disabled when no sources checked.
- Visual parity within reason — Gutenberg components will look slightly different from the wp-admin defaults we mimic today; that's intended.
- Keyboard navigation works across all controls.
- Lighthouse a11y score ≥ current baseline.
- CLI behavior unchanged (CLI doesn't touch the dashboard UI).
- CHANGELOG documents the UI migration as a Changed entry.

---

## 2. Replace server-rendered results with `<DataViews>`

Today the REST endpoint returns `{ html: string, total: number }` and the JS does `innerHTML = data.html`. Gutenberg's `<DataViews>` would let us return structured data and render client-side with sorting, filtering, bulk actions — all out of the box.

### Prerequisites

Item 1 above (React UI migration) must land first. `<DataViews>` requires React.

### Scope

**REST endpoint** — `classes/class-rest-controller.php`:

- `get_items()` returns structured JSON: `{ items: SearchResult[], total: number, total_pages: number, available_filters: {...} }`.
- Drop `render_results()` and `render_no_results()` from the controller entirely (move to a renderer module or delete; the schema docblock in `get_item_schema()` updates to match the new shape).
- Update `get_item_schema()` (added in TODO-SMALL.md item 6) to describe the new shape.

**Service** — `classes/class-search-service.php`:

- No change. The service already returns structured arrays; the renderer was the HTML layer above it.

**Client side** — `src/scripts/form.tsx`:

- Add `<DataViews>` for the result list with fields:
  - `title` (sortable, primary)
  - `source` (filterable badge: Post / Page / Pattern / Template / Template part)
  - `status` (filterable badge: only shown if non-publish in row)
  - `count` (numeric, sortable)
  - `nested` (numeric, sortable — replaces the InnerBlocks filter row)
  - `actions` column with Edit + (optional) View
- Pagination via DataViews built-in (currently in `render_results()` PHP).
- The "Innerblocks" filter becomes a DataViews filter on the `nested` field (`> 0`).

### Why bother

- All rendering logic in JS, not split across PHP HTML generation + JS injection.
- Users get sortable columns, column hide/show, optional bulk actions — free with `<DataViews>`.
- HTML-injection responses are an awkward shape for a REST endpoint; JSON is the canonical answer.
- The result `{html, total}` response shape leaks rendering choices into the API contract; JSON keeps them inside the dashboard.

### Cost

- The PHP-side `render_results()` / `render_no_results()` (combined ~150 lines) gets replaced by JS-side column field definitions (~50–80 lines).
- All the result-rendering CSS (`.block-finder-meta`, `.block-finder-result-content`, `.block-finder-result-actions`, etc.) gets replaced by DataViews-native styling. Delete the obsolete rules from `src/styles/form.scss`.
- Status badges (currently italic via `.block-finder-meta-status`) need DataViews `render` callbacks per field.

### Acceptance

- Result rendering visually equivalent to current behavior, plus sortable columns.
- Pagination + Innerblocks filter functional via DataViews.
- REST endpoint returns JSON only (no HTML string).
- CLI command unchanged — it talks to the service directly, not the REST endpoint, so JSON-vs-HTML at the REST layer doesn't affect CLI.
- `src/styles/form.scss` shrinks by the result-rendering rules.
- CHANGELOG entries: Changed (REST response shape), Changed (result rendering).

---

## 3. Note: permission_callback consolidation (when adding new routes)

Mentioned for completeness — not a refactor to do speculatively. When we eventually add a `/popular`, `/orphans`, or `/aggregate` endpoint, refactor like this:

```php
public function get_items_permissions_check( $request ) {
    return $this->check_read_permission();
}

public function get_popular_permissions_check( $request ) {
    return $this->check_read_permission();
}

protected function check_read_permission() {
    if ( current_user_can( 'edit_posts' ) ) {
        return true;
    }
    return new WP_Error(
        'rest_forbidden',
        __( 'Sorry, you are not allowed to search for blocks.', 'block-finder' ),
        array( 'status' => rest_authorization_required_code() )
    );
}
```

Don't extract while there's a single callback — premature, and the shared helper would have only one caller.

---

## Suggested order

1. Item 1 (React + components migration) — foundational. Ship as 1.2.0 once stable.
2. Item 2 (DataViews) — builds on item 1. Ship as 1.3.0.
3. Item 3 (permission consolidation) — only when a second route is added, which would likely be its own feature release.
