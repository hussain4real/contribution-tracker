<!--
=== SYNC IMPACT REPORT ===
Version change: 0.0.0 → 1.0.0
Bump rationale: Initial constitution creation (MAJOR)

Modified principles: N/A (initial creation)

Added sections:
  - Core Principles (5 principles)
  - Performance Standards
  - Development Workflow
  - Governance

Removed sections: N/A (initial creation)

Templates requiring updates:
  ✅ plan-template.md - Constitution Check section aligns with principles
  ✅ spec-template.md - Requirements and success criteria align
  ✅ tasks-template.md - Test-first approach embedded in phases
  ✅ checklist-template.md - Compatible with quality gates

Follow-up TODOs: None
===========================
-->

# Contribution Tracker Constitution

## Core Principles

### I. Laravel Best Practices (NON-NEGOTIABLE)

All code MUST follow Laravel conventions and the Laravel Boost guidelines defined in `.github/copilot-instructions.md`.

- **Eloquent First**: Use Eloquent models and relationships over raw DB queries. Avoid `DB::` facade; prefer `Model::query()`.
- **Form Requests**: All controller validation MUST use Form Request classes—no inline validation.
- **Type Safety**: All methods MUST have explicit return types and parameter type hints.
- **PHP 8+ Features**: Use constructor property promotion, named arguments, and match expressions where appropriate.
- **Artisan Commands**: Use `php artisan make:*` commands with `--no-interaction` to generate files.
- **Configuration**: Never use `env()` outside config files; always use `config()` helper.

**Rationale**: Consistency with Laravel ecosystem ensures maintainability and enables full use of framework features.

### II. Test-First Development (NON-NEGOTIABLE)

Every feature and bug fix MUST include tests. Tests MUST be written before or alongside implementation.

- **Pest Only**: All tests MUST be written using Pest PHP. Use `php artisan make:test --pest {name}`.
- **Test Coverage**: Tests MUST cover happy paths, failure paths, and edge cases.
- **Feature Tests First**: Prioritize feature tests in `tests/Feature/`; use unit tests for isolated logic.
- **Factories Required**: Use model factories for test data—never manually construct models in tests.
- **Minimal Test Runs**: Run filtered tests during development: `php artisan test --filter=...`.
- **No Test Deletion**: Tests MUST NOT be removed without explicit approval.

**Rationale**: Tests are the safety net that enables confident refactoring and prevents regressions.

### III. User Experience Consistency

All user-facing features MUST maintain consistent patterns across the application.

- **Inertia + Vue**: Use Inertia.js with Vue 3 for all pages. Components go in `resources/js/pages/`.
- **Wayfinder Routes**: Use Laravel Wayfinder for type-safe route generation in frontend code.
- **Form Component**: Use Inertia's `<Form>` component with proper error handling and loading states.
- **Tailwind v4**: Style with Tailwind CSS v4 classes. Use `@theme` for customization, not config files.
- **Dark Mode**: If existing components support dark mode, new components MUST also support it.
- **Loading States**: Deferred props MUST include skeleton/pulsing loading states.
- **Component Reuse**: Check for existing components before creating new ones.

**Rationale**: Consistent UX patterns reduce user confusion and accelerate development.

### IV. Performance Requirements

All code MUST be optimized for performance from the start.

- **N+1 Prevention**: Use eager loading (`with()`) for all relationship queries.
- **Limit Eager Loads**: When loading relations, apply limits: `$query->latest()->limit(10)`.
- **Queue Heavy Work**: Time-consuming operations MUST use queued jobs with `ShouldQueue`.
- **Indexed Queries**: Database queries on large tables MUST use indexed columns.
- **Chunked Processing**: Batch operations on large datasets MUST use `chunk()` or `cursor()`.

**Rationale**: Performance is a feature. Users expect responsive applications.

### V. Code Quality Standards

All code MUST pass quality gates before being considered complete.

- **Pint Formatting**: Run `vendor/bin/pint --dirty` before finalizing any PHP changes.
- **ESLint/Prettier**: Frontend code MUST pass ESLint and Prettier checks.
- **No Vague Naming**: Use descriptive names like `isRegisteredForDiscounts`, not `discount()`.
- **PHPDoc Blocks**: Use PHPDoc for complex methods. Add array shape definitions for arrays.
- **Curly Braces**: Always use curly braces for control structures, even single-line.
- **No Empty Constructors**: Remove empty `__construct()` methods with zero parameters.

**Rationale**: Consistent code style reduces cognitive load during reviews and maintenance.

## Performance Standards

Performance benchmarks MUST be met for all features:

| Metric | Requirement |
|--------|-------------|
| Page Load (TTFB) | < 200ms for authenticated pages |
| API Response | < 100ms for simple queries |
| Database Queries | < 10 queries per page load |
| Memory Usage | < 128MB per request |
| Queue Job Duration | < 30s for standard jobs |

**Measurement**: Use Laravel Telescope or Debugbar during development. Log slow queries.

**Violations**: Performance regressions MUST be documented with justification if unavoidable.

## Development Workflow

### Quality Gates

Every change MUST pass these gates:

1. **Lint Check**: `vendor/bin/pint --dirty` passes with no changes
2. **Test Suite**: Relevant tests pass with `php artisan test --filter=...`
3. **Type Check**: No PHPStan/Psalm errors at configured level
4. **Frontend Build**: `npm run build` completes without errors

### Code Review Requirements

- All PRs MUST verify compliance with this constitution
- Complexity additions MUST be justified in PR description
- Breaking changes MUST include migration documentation

### Commit Standards

- Use conventional commits: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`
- Reference issue numbers when applicable
- Keep commits atomic and focused

## Governance

This constitution supersedes all other development practices for this project.

### Amendment Process

1. Propose changes via PR to `.specify/memory/constitution.md`
2. Document rationale for each change
3. Obtain maintainer approval
4. Update version according to semantic versioning:
   - **MAJOR**: Backward-incompatible principle changes or removals
   - **MINOR**: New principles or sections added
   - **PATCH**: Clarifications, wording improvements

### Compliance

- All code reviews MUST verify adherence to constitution principles
- Violations MUST be addressed before merge or explicitly justified
- Runtime guidance lives in `.github/copilot-instructions.md` (Laravel Boost guidelines)

### Deferred Decisions

Any placeholder marked `TODO(<FIELD>)` indicates a decision requiring future resolution.

**Version**: 1.0.0 | **Ratified**: 2025-12-25 | **Last Amended**: 2025-12-25
