# Feature roadmap

Planning doc for the next minor release(s). Each feature has a brief description, the
user value, a UX sketch, the technical approach in prose, edge cases worth remembering,
and a scope cut between the first version and possible follow-ups. No code here — this
is for alignment before implementation.

The four features below were chosen together because they share infrastructure: all
four benefit from extracting the current search logic out of the REST controller into a
reusable "search service" that the REST endpoint, the CLI command, and the
popular-blocks endpoint can all consume. That refactor is described in the final
section.

---

## Table of contents

1. [Block search in theme templates and reusable blocks](#block-search-in-theme-templates-and-reusable-blocks)
2. [Post status filter](#post-status-filter)
3. [WP-CLI command](#wp-cli-command)
4. [Empty-state popular blocks](#empty-state-popular-blocks)
5. [Cross-cutting: extract a Search service](#cross-cutting-extract-a-search-service)

---

## Block search in theme templates and reusable blocks

### Summary

Extend Block Finder so it can also search inside theme templates, template parts, and
reusable blocks / synced patterns. Gated on `is_block_theme()` for the template side;
reusable blocks are searchable regardless of theme type.

### Why

For block themes a significant portion of the site's block markup lives outside posts —
in the templates that wrap them and in the reusable blocks that get embedded. The
current search misses both, which is the most common bug report we'd otherwise get
once a user gets used to the plugin.

### UX

The form gains a small "Search in" control under the existing post-type selector. It
defaults to "Posts" (current behaviour). On a block theme, the options expand to
include "Templates", "Template parts", and "Reusable blocks". Multiple sources can be
selected.

Each result row gets a small type badge (Post / Page / Template / Template part /
Reusable block) so mixed-source results stay readable. The Edit link routes to the
correct editor:

- Posts and pages: `post.php?action=edit&post=<id>` (unchanged)
- Templates and template parts: the Site Editor URL with the right `postType` /
  `postId` query args
- Reusable blocks: the standard post edit screen for `wp_block`

The result heading adapts so it doesn't claim "X pages" when results span multiple
sources. Something like "Paragraph block has been found in 12 entries" when the search
crosses source types, otherwise the existing per-type heading.

### Technical approach

- Templates and template parts: use the `get_block_templates()` / `get_block_template()`
  API rather than a raw `wp_posts` query. This is the only way to surface file-based
  templates that have never been customised through the Site Editor — they don't have
  rows in `wp_posts` until edited. The API returns a unified set covering disk and DB.
- Reusable blocks (`wp_block`): can use the existing `database_search()` path, just
  drop the `public => true` filter when this source is requested.
- REST endpoint: add a `sources` argument accepting any of `posts`, `templates`,
  `parts`, `reusable_blocks` (array; defaults to `['posts']`). Permission callback
  remains `current_user_can('edit_posts')`; templates are gated additionally by
  `current_user_can('edit_theme_options')` since editing them implies that cap.
- Conditional UI: the dashboard render reads `is_block_theme()` and renders the
  templates/parts checkboxes only when true. Reusable blocks checkbox is unconditional.
- Edit URL builder: a small helper that maps `(source_type, post_or_template)` to the
  correct editor URL, centralised so future link changes happen in one place.

### Edge cases

- File-based templates surface through `get_block_templates()` with an `id` like
  `theme-slug//template-slug` but no `wp_posts` row. The Site Editor handles editing
  them and will materialise a `wp_template` post on first save.
- Template content can embed `<!-- wp:template-part {"slug":"header"} /-->`. We do not
  recurse into the referenced part — each entity is searched independently. The
  template-part entity will surface on its own if the user includes parts in the
  search.
- InnerBlocks detection (the "As Innerblock: N" count we added) works identically for
  templates because the block grammar is the same.
- Cache keys must include the `sources` selection so a "Posts only" cached result
  isn't returned for a later "Posts + Templates" search.
- The post-status filter (next feature) doesn't apply to templates. The UI should
  hide or disable the post-status section when no post-like source is selected.

### Scope

**v1 ships with**: templates, template parts, reusable blocks. Source badges in
results. Site Editor edit links. Cache key expanded for the new dimension.

**Deferred**: navigation menus (`wp_navigation`) — same plumbing but separate UX
question because nav menus appear in templates too, which creates overlap. Theme.json
patterns are out of scope; they aren't block markup in the same shape.

---

## Post status filter

### Summary

Let users search across drafts, pending, private, and scheduled posts in addition to
published. Default behaviour is unchanged (published only).

### Why

Editors and theme migrators frequently need to find deprecated or about-to-change
blocks in unpublished work — most often draft content that's queued for an editorial
review. Today those drafts are invisible to the plugin, which means the audit is
incomplete and people only learn about the problem post-publish.

### UX

A small "Status" multi-select chip group appears under the post-type selector. Chips:
Published (on by default), Draft, Pending, Scheduled, Private. Trash is excluded by
default and lives behind a separate "Include trashed" toggle so it can't be enabled
accidentally.

The chip group collapses into a "More filters" disclosure on first load so the
dashboard widget doesn't grow noticeably. Once any non-default status is selected, the
disclosure stays expanded across requests in the same session.

The results heading can optionally note when non-published statuses are included, e.g.
"Paragraph block has been found in 12 pages (incl. 3 drafts)" — but only when those
extra statuses produced matches.

### Technical approach

- REST endpoint accepts a `post_status` argument as an array of slugs, validated
  against a known set. Defaults to `['publish']`.
- `database_search()` updates from `post_status = 'publish'` to
  `post_status IN (...)` with prepared placeholders.
- Permission already gates on `current_user_can('edit_posts')` via the REST
  controller, which is appropriate. We rely on the caller's permission to know what
  they can see; we do not implement per-author / per-status visibility ourselves
  (matches how WP-CLI and most admin tools behave).
- Cache key extension: include a sorted, joined post-status string so different
  selections don't collide. Cache invalidation hooks need to skip status-irrelevant
  noise (autosaves already excluded; revisions already excluded).

### Edge cases

- A site can register custom post statuses. The chip list should be the standard
  built-in set; if the underlying query receives an unknown status it's silently
  dropped. Future: surface custom statuses dynamically.
- Scheduled posts (`future`) appear in queries but their content can change on
  publish — that's fine, we re-query each time and the cache will be invalidated by
  the eventual `transition_post_status` to `publish`.
- The Site Editor "Include trashed" path should reuse the same toggle since trashed
  templates work the same way (deferred until the templates feature ships).

### Scope

**v1 ships with**: Published / Draft / Pending / Scheduled / Private chips. Cache key
extended. Heading adapts when extra statuses produced matches.

**Deferred**: trashed-content toggle. Custom post statuses surfaced dynamically.

---

## WP-CLI command

### Summary

Expose Block Finder's search functionality as a `wp block-finder` command set, so
developers can script audits, CI checks, and batch migrations against the same engine
the dashboard uses.

### Why

The dashboard widget is great for ad-hoc questions but useless inside a deployment
pipeline. Plugin / theme upgrades are exactly when "is this deprecated block still in
use?" matters most, and a CLI command makes that check trivial to wire into CI or
into a migration script.

The CLI command also gives agencies and consultants a way to bundle the plugin into
their workflow without ever having to surface its UI to clients.

### UX (developer-facing)

The command suite registers under the `block-finder` namespace:

- `wp block-finder search <block> [--post-type=<slug>] [--post-status=<list>]
  [--sources=<list>] [--filter=<all|nested>] [--format=<table|json|csv|count|ids>]
  [--fields=<list>]`
  - Returns the same data the dashboard sees: post IDs, titles, edit links, total
    instances, nested instances.
  - `--format=count` short-circuits to a single integer for easy `if [ $(...) -gt 0 ]`
    use.
  - `--format=ids` returns just the matching IDs, one per line, for piping into
    `xargs wp post update ...` or similar.
- `wp block-finder list-blocks` — print every registered block with its title and a
  hint of whether it's currently used anywhere (helps with deprecation audits).

Examples:

```
wp block-finder search core/paragraph
wp block-finder search core/group --sources=posts,templates --format=csv > usage.csv
wp block-finder search my-plugin/deprecated --format=count
```

Output formatting follows the WP-CLI convention via `WP_CLI\Utils\format_items()`.

### Technical approach

- New file `classes/class-cli-command.php`, registered only when `defined('WP_CLI')`.
- The command class is thin: it parses args, calls the shared Search service, and
  formats output. No duplication of query / parse / render logic.
- Permission model: CLI commands run as a system process and skip the
  `current_user_can('edit_posts')` gate. The CLI itself is the auth boundary. This
  matches how core's `wp post list` behaves.
- Bootstrap: gated registration in the main plugin file. `WP_CLI::add_command()` is
  called only when `defined('WP_CLI') && WP_CLI`.

### Edge cases

- Very large result sets: the existing search returns the full result array which
  could be heavy on huge sites. The CLI command should warn at a threshold (e.g.
  10,000 matches) and offer `--limit` / `--offset`. Or stream via a generator
  iteration if we extract the search to a service that supports it.
- The `--sources=templates` path requires that templates and reusable blocks work
  first — gated on the previous feature shipping.
- Test coverage: `WP_CLI::add_command` calls are loaded in CLI context only, so we
  test against the underlying service class rather than the command directly.

### Scope

**v1 ships with**: `wp block-finder search` with all relevant flags and standard
output formats.

**Deferred**: `wp block-finder list-blocks` (nice but not strictly needed for v1).
`wp block-finder replace` (out of scope — too much blast radius). Streaming for very
large sites.

---

## Empty-state popular blocks

### Summary

When the dashboard widget first loads (no search submitted yet), show the top 5 most
used blocks on the site as one-click chips. Clicking a chip pre-fills the form and
runs the search.

### Why

The widget currently sits inert until the user picks two dropdowns and clicks a
button. For users who don't know what the tool does (or what they want to look for),
the popular blocks act as a discovery aid and an immediate demonstration of value:
"oh, I didn't know I had 412 button blocks." First-time-user friction goes way down
and the tool feels alive.

### UX

Above the existing form, a small section labelled "Popular blocks on this site"
renders a row of five chips. Each chip shows the block's title and its total count
across published posts and pages:

```
Popular blocks on this site:
[ Paragraph · 4,213 ]  [ Heading · 1,892 ]  [ Image · 1,201 ]  [ Group · 876 ]  [ Button · 412 ]
```

Clicking a chip selects the block in the existing block selector, sets the post type
to "All Post Types", and triggers the existing submit handler. The popular-blocks
section is replaced by results, the same way it would be after a manual submit.

After the user submits a search, the section does not re-render in the current widget
state. On the next dashboard load it returns.

Empty case: a site with no Gutenberg content (a freshly-installed site, for example)
falls back to the current "Select a block to start" prompt. No chips, no fanfare.

### Technical approach

- New REST endpoint: `GET /wp-json/block-finder/v1/popular?limit=5&post_type=<slug>`.
  Returns `[{ name, label, count }, ...]` sorted by count descending.
- Underlying query: aggregate block usage across published posts. Two implementation
  options:
  1. **PHP-side parse**: pull all published posts containing the `<!-- wp:` marker,
     `parse_blocks()` each, tally counts. Slow on large sites but conceptually
     simple. Caches well behind a long-TTL transient.
  2. **SQL-side LIKE per block**: for each block in `WP_Block_Type_Registry`, run a
     `SELECT COUNT(*) ... LIKE '%<!-- wp:NAME%'`. N queries where N is the block
     count (~150 for a typical core install). Slow to issue, but each individual
     query is fast and cacheable.
  Approach (1) is the better fit because it counts instances (multiple per post),
  matches what the dashboard's per-result `Count: N` column shows, and gets us
  consistent semantics across the plugin. Approach (2) only counts posts.
- Caching: a single `block_finder_popular_<post_type>` transient with a 12-hour TTL.
  Invalidated by the same `save_post` / `delete_post` / `wp_trash_post` /
  `untrashed_post` hooks the search controller already implements, with no further
  surgery — the popular cache is global so any post change flushes it.
- The endpoint is called by `apiFetch` on dashboard load (same machinery as the
  search endpoint). Renders client-side so the widget initial HTML stays light.

### Edge cases

- Cold cache on a fresh dashboard load could feel slow if the site is huge. The
  rendered widget should render the form immediately and lazy-load the chips with a
  small skeleton placeholder, the same skeleton we already use for results.
- A `limit` of 5 is hard-coded for the UI but configurable via the REST endpoint for
  any future use.
- Blocks that no longer have a registered type (orphans) should be excluded from the
  popular list, since the dashboard form can't search for them anyway. They become
  candidates for the future "orphaned blocks" feature instead.
- The popular-blocks query and the per-search query both parse blocks server-side.
  Re-using `find_block_instances()` / `traverse_blocks()` from the service avoids
  drift.

### Scope

**v1 ships with**: top 5 popular blocks, "All Post Types" only, client-side
rendering with skeleton, hard-coded limit.

**Deferred**: per-post-type popular blocks ("most-used in Pages"). Configurable
limit. Time-window slicing (e.g. "popular in the last 30 days"). Aggregate dashboard
that goes beyond five chips.

---

## Cross-cutting: extract a Search service

### Summary

Before any of the above ships, factor the current search and parse logic out of
`REST_Controller` into a standalone service class — call it `Search_Service` or
similar. The REST endpoint, the CLI command, and the popular-blocks endpoint all
become thin adapters over this service.

### Why

Three of the four features above want to invoke the same query and parse pipeline
the dashboard uses. If that pipeline stays embedded in the REST controller, we end
up either duplicating it (CLI) or adding awkward indirection (popular-blocks endpoint
calling into the REST controller's private methods). Pulling it out now is cheap;
later it becomes painful.

### What moves

From `class-rest-controller.php` into the new service:

- `database_search()` — the core query
- `find_block_instances()` — block parse + instance discovery
- `traverse_blocks()` — recursive walker
- `get_cache_key()` and the cache read/write logic
- `invalidate_cache_for_post()` and its hook wiring stay on the REST controller for
  now; alternatively move them to the service and have the controller just construct
  it. Either is fine — pick whichever reads more naturally once we see the code.

What stays on `REST_Controller`:

- Route registration, args schema, permission callback
- `render_results()` and `render_no_results()` (these are presentation, not search)
- The HTTP response shape

### What this enables

- The CLI command instantiates the service directly, bypasses HTTP and permission
  checks, and gets the same parsed results.
- The popular-blocks endpoint reuses `find_block_instances()` so the per-post counts
  match between the popular list and the dashboard's per-result totals.
- The templates feature plugs into the service's source-selection path without
  changing the dashboard widget's wiring.

### Scope

This is purely a refactor with no user-facing change. Ship it as its own commit
before any of the four features so the diffs for each feature stay focused on the
new behaviour rather than the move.
