=== Block Finder ===

Contributors: areziaal
Tags: blocks, gutenberg, search, site editor, patterns
Requires at least: 6.4
Requires PHP: 8.0
Tested up to: 6.9
Stable tag: 1.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Find any block, anywhere on your site — posts, patterns, templates, and template parts.

== Description ==

**Ever needed to track down where a specific block is being used?** Block Finder adds a dashboard widget that searches your entire site for any core or custom block. Pick a block, click Search, and instantly see every place it appears — along with a count of how many times it's used, whether it's nested inside another block, and a direct link to edit that content.

No more manually opening posts one by one hoping to find the right block. Block Finder does the searching so you don't have to.

= Who is this for? =

**Content editors and site managers** — need to update every post that uses a specific call-to-action block? Wondering which pages are still using a block you're about to change? Block Finder gives you the answer in seconds, with one-click access to edit each result.

**Developers and theme builders** — auditing block usage across templates and template parts in the Site Editor, checking registered patterns, or running automated checks from the command line. Block Finder has you covered.

= Features =

**Search your way:**

* Type-ahead block picker powered by WordPress's native Combobox — no hunting through a long list
* Search Posts, Patterns, Templates, and Template parts all from one form
* Filter by post type to narrow results, or search across everything at once
* Filter by post status — include drafts, pending, scheduled, and private content alongside published

**Understand what you find:**

* See the total number of times a block appears in each piece of content
* InnerBlock detection shows how many of those instances are nested inside another block
* Source badges make it clear at a glance whether a result came from a post, a pattern, a template, or a template part
* Status badges surface non-published content so nothing gets missed

**Act on results quickly:**

* Direct edit links open any result straight in the block editor or Site Editor
* Paginated results keep large result sets easy to browse
* Filter between all instances and nested-only with a single click

= For Developers and Theme Builders =

**WP-CLI** — run `wp block-finder search <block>` from the command line. Supports `--post-type`, `--post-status`, `--sources`, and `--format=table|json|csv|count|ids`. Use `--format=count` in CI pipelines or `--format=csv` for export and audit reports.

**Template and template part search** — find blocks inside Site Editor templates on any block theme. Both file-based and database-stored templates are included.

**Pattern search** — covers user-saved synced patterns and every pattern registered by your theme or plugins via `WP_Block_Patterns_Registry`, not just the ones saved to the database.

**Developer hooks** — extend or modify search behaviour without touching plugin code:

* `block_finder_sources` — add or remove sources before the search runs
* `block_finder_results` — modify the assembled result set before it is returned

**REST API** — `GET /wp-json/block-finder/v1/search` is available for custom integrations. Requires `edit_posts` capability.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install directly from the WordPress plugin directory.
2. Activate the plugin from the **Plugins** page.
3. Visit your WordPress **Dashboard** and find the **Block Finder** widget.

== Frequently Asked Questions ==

= How do I search for a block? =

Open the WordPress dashboard and find the Block Finder widget. Type the name of any block into the search field, choose where to look ("Search in"), optionally narrow by post type and status, then click **Find Block**.

= What does "Search in" control? =

It lets you choose which content sources to include:

* **Posts** — all public post types that support the block editor
* **Patterns** — user-saved synced patterns plus any registered by your theme or plugins
* **Templates** and **Template parts** — Site Editor content; only available on block themes

= What are InnerBlocks? =

InnerBlocks are blocks nested inside other blocks — a Paragraph inside a Group, or a Button inside a Cover. Block Finder shows you the total usage count and how many are nested, so you know whether a block appears standalone or embedded inside another.

= Can I search from the command line? =

Yes. With WP-CLI installed, run `wp block-finder search <block-name>` for a quick table output. Use `--format=count` for CI checks or `--format=csv` for audit exports. Run `wp block-finder search --help` for all available options.

= Does it work with custom post types? =

Yes. Any public post type that supports the block editor is automatically included.

= Does it work with classic themes? =

Partially. Post, pattern, and custom post type searches work on any theme. Template and template part search requires a block theme because those content types only exist in the Site Editor.

= How do I uninstall? =

Deactivate and delete the plugin from the Plugins page. All cached search data is removed from the database automatically on uninstall.

== Screenshots ==

1. Block Finder dashboard widget ready to search various content types.
2. Type ahead helps you easily find blocks in a list.
3. Search results for published and draft posts containing the core paragraph block.
4. Search results for the core group block in patterns and template parts.

== Changelog ==

View the full [changelog](https://github.com/troychaplin/block-finder/blob/main/CHANGELOG.md) on GitHub.
