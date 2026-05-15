# Feature roadmap

Planning doc for the next minor release(s). Each feature has a brief description, the
user value, a UX sketch, the technical approach in prose, edge cases worth remembering,
and a scope cut between the first version and possible follow-ups. No code here — this
is for alignment before implementation.

The two features below share a backbone: the `Search_Service` class (`classes/class-search-service.php`) now owns the query + parse + cache pipeline that the REST search endpoint already delegates to. Both new features should plug into the same service rather than re-implementing any of it.

---

## Table of contents

1. [Block search in theme templates and reusable blocks](#block-search-in-theme-templates-and-reusable-blocks)
2. [WP-CLI command](#wp-cli-command)

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

## Notes for implementers

The `Search_Service` class (`classes/class-search-service.php`) is the entry
point both new features should use. Relevant surface:

- `search( $block, $post_type, $post_status )` — runs query + parse, returns the
  same result shape the REST endpoint and dashboard renderer expect (`id`,
  `title`, `status`, `edit_link`, `view_link`, `block_instances`). Reads/writes
  the transient cache transparently.
- `invalidate_cache_for_post( $post_id, $post )` — hook target wired up by
  `init()` for `save_post`, `delete_post`, `wp_trash_post`, and
  `untrashed_post`. Both new features can reuse it as-is.
- Constants `CACHE_PREFIX` and `CACHE_EXPIRATION` live on the service.

The templates feature will likely add a `sources` parameter to `search()` and
branch internally between `database_search()` (existing) and a new
`templates_search()` path that uses `get_block_templates()`. The CLI command
should instantiate the service directly and bypass the HTTP layer entirely.
