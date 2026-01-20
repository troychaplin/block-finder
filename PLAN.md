# Block Finder - Improvement Plan

This document outlines planned improvements for the Block Finder plugin.

---

## Performance Improvements

### 1. Add Result Caching

**Problem:** Every search scans all posts, even for repeated identical queries.

**Solution:**

- Implement transient-based caching for search results
- Cache key based on hash of block name + post type
- Set reasonable expiration (e.g., 1 hour)
- Invalidate cache on post save/update/delete via `save_post` hook
- Add option to force refresh/bypass cache

**Files affected:**

- `classes/class-dashboard.php`

---

### 2. Paginate Results

**Problem:** Using `nopaging=true` loads all posts into memory, which can cause performance issues on large sites.

**Solution:**

- Add pagination to search results
- Limit initial results (e.g., 20 per page)
- Add "Load More" button or pagination controls
- Update AJAX handler to accept page/offset parameter
- Display total count of matching posts

**Files affected:**

- `classes/class-dashboard.php`
- `src/scripts/form.js`
- `src/styles.scss`

---

### 3. Database-Level Search

**Problem:** Current implementation loads all posts then regex matches in PHP, which is inefficient.

**Solution:**

- Use `$wpdb` direct query with `LIKE '%<!-- wp:blockname%'` in WHERE clause
- Move filtering to database level instead of PHP memory
- Combine with pagination for optimal performance
- Consider adding database index recommendations for large sites

**Files affected:**

- `classes/class-dashboard.php`

---

## Functionality Enhancements

### 5. Show Block Count Per Post

**Problem:** Currently only shows that a block exists in a post, not how many times it's used.

**Solution:**

- Use `preg_match_all()` instead of `preg_match()` to count occurrences
- Display count next to each post in results (e.g., "Post Title (3 instances)")
- Consider adding sort option by count

**Files affected:**

- `classes/class-dashboard.php`
- `src/styles.scss` (for count badge styling)

---

### 7. Export Results

**Problem:** No way to export search results for reporting or further analysis.

**Solution:**

- Add "Export CSV" button below results
- CSV columns: Post ID, Title, Edit URL, View URL, Block Count
- Generate CSV server-side via new AJAX endpoint
- Trigger browser download with proper headers

**Files affected:**

- `classes/class-dashboard.php` (new AJAX handler)
- `src/scripts/form.js` (export button handler)
- `src/styles.scss` (button styling)

---

### 8. Nested/Inner Block Detection

**Problem:** Current regex may miss blocks nested inside other blocks (e.g., paragraph inside a group or column).

**Solution:**

- Parse full block structure using `parse_blocks()` WordPress function
- Recursively traverse `innerBlocks` arrays
- Count blocks at all nesting levels
- More accurate than regex-only approach

**Files affected:**

- `classes/class-dashboard.php`

---

## UX Improvements

### 11. Show Block Icon

**Problem:** Block dropdown only shows text names, making it harder to identify blocks quickly.

**Solution:**

- Retrieve block icon from block registration data
- Include icon in dropdown option data attribute
- Render icon (SVG or Dashicon) next to block name in autocomplete
- Handle blocks without icons gracefully (show default block icon)

**Files affected:**

- `classes/class-dashboard.php` (pass icon data)
- `src/scripts/form.js` (render icons in dropdown)
- `src/styles.scss` (icon sizing/alignment)

---

### 12. Empty State Messaging

**Problem:** Poor feedback when no blocks are registered or no post types are available.

**Solution:**

- Add meaningful empty state messages:
  - "No blocks found" if block registry is empty
  - "No post types available" if no editor-enabled types exist
  - "No results found for [block] in [post type]" with suggestions
- Style empty states consistently

**Files affected:**

- `classes/class-dashboard.php`
- `src/styles.scss`

---

### 13. Loading Skeleton

**Problem:** "Finding blocks..." text is basic and doesn't indicate progress.

**Solution:**

- Replace text with animated loading skeleton/spinner
- Show skeleton placeholders matching result item shape
- Add subtle animation for better perceived performance
- Disable form inputs during loading to prevent duplicate submissions

**Files affected:**

- `src/scripts/form.js`
- `src/styles.scss`

---

## Code Quality

### 14. Fix class-dashboard.php Header

**Problem:** File header comment incorrectly says "Class Enqueues" instead of "Class Dashboard".

**Solution:**

- Update the DocBlock header to correctly identify the class
- Review other files for similar inconsistencies

**Files affected:**

- `classes/class-dashboard.php`

---

### 15. Add PHPUnit Tests

**Problem:** No test coverage exists for the plugin.

**Solution:**

- Set up PHPUnit with WordPress test framework
- Add unit tests for:
  - Block detection regex/parsing logic
  - Post type filtering
  - AJAX handler input validation
  - Cache invalidation
- Add integration tests for:
  - Full search workflow
  - Export functionality
- Configure GitHub Actions for CI

**Files affected:**

- New `tests/` directory
- `composer.json` (dev dependencies)
- `phpunit.xml.dist`
- `.github/workflows/` (CI configuration)

---

### 16. TypeScript Migration

**Problem:** JavaScript lacks type safety, making refactoring riskier.

**Solution:**

- Convert `src/scripts/form.js` to TypeScript
- Add type definitions for:
  - `blockFinderAjax` global object
  - Form elements and event handlers
  - AJAX request/response shapes
- Update webpack config for TypeScript compilation
- Add `tsconfig.json`

**Files affected:**

- `src/scripts/form.js` → `src/scripts/form.ts`
- `src/script.js` → `src/script.ts`
- `webpack.config.js`
- `package.json` (TypeScript dependencies)
- New `tsconfig.json`

---

## Implementation Priority

Suggested order based on dependencies and impact:

1. **Phase 1 - Foundation**
   - [ ] 14. Fix class-dashboard.php header (quick win)
   - [ ] 3. Database-level search (foundation for performance)
   - [ ] 8. Nested/inner block detection (improves accuracy)

2. **Phase 2 - Performance**
   - [ ] 1. Add result caching
   - [ ] 2. Paginate results
   - [ ] 5. Show block count per post

3. **Phase 3 - UX Polish**
   - [ ] 13. Loading skeleton
   - [ ] 12. Empty state messaging
   - [ ] 11. Show block icon

4. **Phase 4 - Features & Quality**
   - [ ] 7. Export results
   - [ ] 16. TypeScript migration
   - [ ] 15. Add PHPUnit tests

---

## Notes

- Version bump strategy: Minor version for each phase completion
- Maintain backward compatibility with existing WordPress versions (6.0+)
- Test on both single site and multisite installations
