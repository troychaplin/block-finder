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
