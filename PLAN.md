# Block Finder - Improvement Plan

This document outlines planned improvements for the Block Finder plugin.

---

## Functionality Enhancements

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

### ~~12. Empty State Messaging~~ ✅

~~**Problem:** Poor feedback when no blocks are registered or no post types are available.~~

~~**Solution:**~~

- ~~Add meaningful empty state messages:~~
  - ~~"No blocks found" if block registry is empty~~
  - ~~"No post types available" if no editor-enabled types exist~~
  - ~~"No results found for [block] in [post type]" with suggestions~~
- ~~Style empty states consistently~~

~~**Files affected:**~~

- ~~`classes/class-dashboard.php`~~
- ~~`src/styles.scss`~~

---

### ~~13. Loading Skeleton~~ ✅

~~**Problem:** "Finding blocks..." text is basic and doesn't indicate progress.~~

~~**Solution:**~~

- ~~Replace text with animated loading skeleton/spinner~~
- ~~Show skeleton placeholders matching result item shape~~
- ~~Add subtle animation for better perceived performance~~
- ~~Disable form inputs during loading to prevent duplicate submissions~~

~~**Files affected:**~~

- ~~`src/scripts/form.js`~~
- ~~`src/styles.scss`~~

---

## Code Quality

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

1. **Phase 1 - Foundation** (Completed)
   - [x] Database-level search
   - [x] Result caching
   - [x] Pagination

2. **Phase 2 - UX Polish** (Completed)
   - [x] Empty state messaging
   - [x] Loading skeleton

3. **Phase 3 - Features & Quality** (Remaining)
   - [ ] 8. Nested/inner block detection
   - [ ] 15. Add PHPUnit tests
   - [ ] 16. TypeScript migration

---

## Notes

- Version bump strategy: Minor version for each phase completion
- Maintain backward compatibility with existing WordPress versions (6.0+)
- Test on both single site and multisite installations
