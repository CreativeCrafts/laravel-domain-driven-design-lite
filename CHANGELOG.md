# Changelog

All notable changes to `laravel-domain-driven-design-lite` will be documented in this file.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.3] - 2026-02-04

### Added

- Documentation recipes and advanced workflows:
  - Controller → Action → Repository orchestration example.
  - Payments walkthrough (DTO → Contract → Repository → Action).
  - Multi‑tenant boundaries with Deptrac + PHPStan examples.
  - Safe refactor workflow with dry‑run + manifest rollback.
  - Monolith conversion to module (with namespace rewrite flow).
  - Orders module creation and typical directory structure.

### Changed

- README reorganized for clearer navigation and reduced duplication.
- `ddd-lite:init` now validates interactive choices with stricter typing.
- JSON output commands now throw on encoding failures for safer CLI output.
- `StubDiff` file collection hardened for stricter typing.
- PHPStan config updated to disable parallel TCP server usage in restricted environments.

## [0.0.2] – 2026-02-03

### Added

- `ddd-lite:init` wizard to bootstrap a project (publish stubs/quality, scaffold a starter module, optional CI snippet).
- `ddd-lite:modules:list` to list modules with optional health indicators.
- `ddd-lite:boundaries` as a friendly alias for Deptrac boundary checks.
- `ddd-lite:stubs:diff` and `ddd-lite:stubs:sync` to compare/sync customized stubs.
- `--deep` flag on `ddd-lite:doctor` to run domain + CI diagnostics in one go.
- `--shared` and `--yes` flags on `ddd-lite:module` for shared‑kernel scaffolding and non‑interactive runs.
- `--suggest-contracts` on `ddd-lite:convert --plan-moves` to emit recommended contracts/bindings.
- Test generation for query, query‑builder, and aggregator generators (with `--no-test` to skip).
- Optional validation tests for value objects via `--with-validation-test`.

### Changed

- README enhanced with quick start, initialization, new command coverage, and improved navigation.

## [0.0.1] – 2025-11-25

### Added

- **DDD-Lite module scaffolding**

  - `ddd-lite:module` command to scaffold a full domain module under `modules/<ModuleName>`, including:
    - `App/` layer (providers, controllers, requests, models, repositories)
    - `Domain/` layer (DTOs, actions, contracts, value objects, queries)
    - `Database/migrations` and `Routes/web.php` / `Routes/api.php`
    - Optional aggregate-based structure and tests folders.

- **Rich set of make:* generators for domain and app layers**

  - Domain-focused generators:
    - `ddd-lite:make:dto` – typed DTO classes in `Domain/DTO`.
    - `ddd-lite:make:action` – domain actions in `Domain/Actions`.
    - `ddd-lite:make:contract` – domain contracts with optional fake implementations.
    - `ddd-lite:make:repository` – Eloquent repositories in the app layer implementing contracts.
    - `ddd-lite:make:value-object` – small, strongly-typed value objects.
    - `ddd-lite:make:aggregate-root` – aggregate root skeletons in `Domain/Aggregates`.
    - `ddd-lite:make:query`, `ddd-lite:make:query-builder`, `ddd-lite:make:aggregator` – read-side helpers.
  - App-layer generators:
    - `ddd-lite:make:controller` – REST/Inertia controllers in the module’s `App/Http/Controllers`.
    - `ddd-lite:make:request` – form requests for validation.
    - `ddd-lite:make:model` – Eloquent models scoped to the module.
    - `ddd-lite:make:migration` – migrations under the module’s `Database/migrations`.
    - `ddd-lite:make:provider` – module service providers.

- **Module binding & wiring**

  - `ddd-lite:bind` command to bind domain contracts to implementations in the module’s service provider, keeping wiring explicit and testable.

- **Manifest-backed safe file operations**

  - All mutating commands (module scaffold, make:*, convert, doctor fixes, publish:quality) are tracked via a **manifest**:
    - `ddd-lite:manifest:list` – list manifests with filters for module, type, and date.
    - `ddd-lite:manifest:show` – show individual manifests, including created/updated/deleted/moved files.
  - Shared support for:
    - `--dry-run` – preview without changing files.
    - `--rollback=<manifest-id>` – rollback a previous run using its manifest id.

- **Conversion tooling for legacy applications**

  - `ddd-lite:convert` command to discover and move existing `app/*` code into modules:
    - Supports planning and applying moves for controllers, models, requests, actions, DTOs, and contracts.
    - Uses a safe `NamespaceRewriter` with optional AST-based namespace and `use` rewrites.
    - Options to:
      - Plan moves only (`--plan-moves`).
      - Apply moves (`--apply-moves`) with interactive review (`--review`) or non-interactive (`--all`).
      - Restrict to certain kinds via `--only` / `--except`.
      - Limit search to specific paths via `--paths`.
      - Export a move plan to JSON via `--export-plan=path.json`.

- **Quality configuration publishing**

  - `ddd-lite:publish:quality` command to publish quality tooling configs into the host application:
    - `phpstan.app.neon` for strict static analysis.
    - `deptrac.app.yaml` to model architectural boundaries and layer constraints.
    - `tests/ArchitectureTest.php` for Pest-based architectural checks (debug helpers, env usage, layer rules, etc.).
  - Targeted publishing:
    - `--target=all|phpstan|deptrac|pest-arch`.

- **Doctor commands for structural and domain checks**

  - `ddd-lite:doctor`:
    - Inspects module wiring (providers, routes, PSR-4 consistency, bootstrap wiring).
    - Can attempt automated fixes when appropriate.
    - JSON output for tooling (`--json`).
  - `ddd-lite:doctor:domain`:
    - Integrates with Deptrac to enforce domain purity and module boundaries.
    - Can consume Deptrac JSON reports or run Deptrac directly.
    - Configurable failure modes (`--fail-on` for violations, uncovered, errors).
  - `ddd-lite:doctor-ci`:
    - CI-oriented command combining structural and domain checks.
    - JSON output with CI-friendly exit codes.

- **JSON schema for doctor reports**

  - Published doctor report schema for downstream tooling:
    - Available under `stubs/doctor/schema/doctor-report.schema.json`.
    - Publishable into the application via `vendor:publish` tags.

- **Extensive stubs for a consistent code style**

  - `stubs/ddd-lite/*` provides consistent starting points for:
    - DTOs, Actions, Contracts, Repositories, Value Objects.
    - Aggregate roots.
    - Controllers (including Inertia-focused variants).
    - Requests, Models, Migrations.
    - Module service providers and route/event providers.
    - Routes (`web.php` / `api.php`).
    - Quality tooling configs and arch tests.

- **Internal support utilities**

  - Helper classes for:
    - Safe filesystem operations with manifest tracking.
    - Namespace scanning and rewriting.
    - PSR-4 guardrails.
    - Bootstrapping inspection and editing for `bootstrap/app.php`.
    - Conversion planning (`ConversionPlan`, `MoveCandidate`, `ConversionDiscovery`).
    - CI JSON reporting (`DoctorCiJson`, `JsonReporter`, etc.).

### Changed

- **Made the package strictly dev/CI tooling (no runtime dependency required)**

  - Generated code no longer needs any `CreativeCrafts\DomainDrivenDesignLite\*` class at runtime.
  - The aggregate root stub is now a **plain PHP class**:
    - `stubs/ddd-lite/aggregate-root.stub` no longer imports or extends a package base class.
    - Domain aggregates are self-contained and framework-agnostic by default.
  - This makes it safe for consuming applications to install the package as a **dev dependency** and deploy with `composer install --no-dev`, as long as their own code does not manually reference package internals.

- **Simplified the service provider; removed Spatie package-tools dependency**

  - `DomainDrivenDesignLiteServiceProvider` now extends `Illuminate\Support\ServiceProvider` directly.
  - All artisan commands are registered via the native `commands([...])` API.
  - Publish groups (`ddd-lite-stubs`, `ddd-lite-schemas`, `ddd-lite`) are registered using Laravel’s built-in `publishes()` method.
  - The previous dependency on `spatie/laravel-package-tools` has been removed from `composer.json`, reducing transitive dependencies for host applications.

- **Made php-parser optional and clearly suggested**

  - `nikic/php-parser` is no longer a hard requirement.
  - It is now declared under `suggest` in `composer.json`:
    - `"nikic/php-parser": "Required for ddd-lite:convert and advanced namespace rewrites"`.
  - The `NamespaceRewriter` performs a runtime `class_exists(ParserFactory::class)` check and throws a clear exception if php-parser is needed but not installed.
  - This keeps the default install lean for teams who do not use the AST-based conversion features.

- **Improved publish configuration safety**

  - Consolidated and corrected publish paths for:
    - Stubs directory (`stubs/ddd-lite`).
    - Doctor report schema.
  - The generic `ddd-lite` publish tag now publishes both stubs and the doctor schema, providing a single entry point for new users while keeping the more granular tags `ddd-lite-stubs` and `ddd-lite-schemas`.

- **Documentation & messaging**

  - Updated README and overall project story to:
    - Emphasise DDD-Lite as a **development and CI** tool.
    - Clarify recommended installation as a **dev dependency**.
    - Highlight that generated code is independent of the package at runtime.
    - Describe core workflows: greenfield modules, gradual migration with `ddd-lite:convert`, and CI integration with `ddd-lite:doctor-ci`.

### Removed

- **Spatie package-tools integration**

  - `spatie/laravel-package-tools` has been removed as a dependency.
  - All behaviour previously provided via package-tools is now handled by a lean, explicit Laravel service provider.

---

[0.0.1]: https://github.com/creativecrafts/laravel-domain-driven-design-lite/releases/tag/0.0.1
