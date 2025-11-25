[![Latest Version on Packagist](https://img.shields.io/packagist/v/creativecrafts/laravel-domain-driven-design-lite.svg?style=flat-square)](https://packagist.org/packages/creativecrafts/laravel-domain-driven-design-lite)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/creativecrafts/laravel-domain-driven-design-lite/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/creativecrafts/laravel-domain-driven-design-lite/actions?query=workflow%3ACI+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/creativecrafts/laravel-domain-driven-design-lite/pint-autofix.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/creativecrafts/laravel-domain-driven-design-lite/actions?query=workflow%3A"Pint+auto-fix"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/creativecrafts/laravel-domain-driven-design-lite.svg?style=flat-square)](https://packagist.org/packages/creativecrafts/laravel-domain-driven-design-lite)

> Pragmatic, Laravel-native **DDD modules** with generators, safety rails, and CI helpers – without drowning you in ceremony.

---

### 🧭 What is DDD-Lite?

**DDD-Lite** is a developer-tooling package that helps you organise your Laravel 12+ application into **modular, domain-oriented boundaries**.

It gives you:

- A **module structure** (`modules/<ModuleName>`) with a clear split between:
  - `App/` – adapters & Laravel-specific glue (controllers, requests, models, providers, repositories)
  - `Domain/` – pure PHP domain code (DTOs, actions, contracts, queries, value objects)
- **Generators** for:
  - DTOs, Actions, Contracts, Repositories, Value Objects
  - Queries and Query Builders
  - Aggregate Roots
  - Controllers, Requests, Models, Migrations, Providers, Routes
- A **conversion engine** to move existing `app/*` code into modules:
  - Discovers move candidates (controllers, models, requests, actions, DTOs, contracts)
  - Applies moves with AST-based namespace rewrites
- **Safety rails** for all file operations:
  - Every run produces a **Manifest** (with creates/updates/deletes/moves/mkdirs)
  - `--dry-run` on everything
  - **Rollback** by manifest id
- **Quality & CI tooling**:
  - Publishable PHPStan & Deptrac configs
  - Optional Pest architecture tests
  - `ddd-lite:doctor` / `ddd-lite:doctor:domain` / `ddd-lite:doctor-ci` to keep modules healthy

The goal is: **clean seams, safer refactors, better testability** – without requiring you to rewrite your entire app in one go.

---

## 🧱 Architecture Overview

A DDD-Lite **module** lives under `modules/<ModuleName>`:

```text
modules/<ModuleName>/
├─ App/
│  ├─ Http/
│  │  ├─ Controllers/
│  │  └─ Requests/
│  ├─ Models/
│  ├─ Providers/
│  └─ Repositories/
├─ Domain/
│  ├─ Actions/
│  ├─ Contracts/
│  ├─ DTO/
│  ├─ Queries/
│  └─ ValueObjects/
├─ Database/
│  └─ migrations/
├─ Routes/
│  ├─ api.php
│  └─ web.php
└─ tests/
   ├─ Feature/
   └─ Unit/
└─ Unit/
```

✅ Rules of thumb

**Domain:**
  - Pure PHP, no hard dependency on Laravel.
  - Orchestrates use cases with Actions (e.g. CreateTripAction).
  - Talks to the outside world through Contracts (e.g. TripRepositoryContract).
  - Uses DTOs and Value Objects for data.

**App:**
  - Typical Laravel adapters (controllers, form requests, models).
  - Implements contracts using Eloquent (e.g. TripRepository).
  - Wires things together using module service providers.

### ⚙️ Requirements
-	PHP: ^8.3
-	Laravel (Illuminate components): ^12.0
-	Composer

Recommended dev dependencies in your app (for quality tooling integration):
  -	larastan/larastan
  -	deptrac/deptrac
  -	pestphp/pest
  -	pestphp/pest-plugin-laravel
  -	pestphp/pest-plugin-arch

### ⚙️ Installation
Require the package in your Laravel app (usually as a dev dependency):

```bash
composer require creativecrafts/laravel-domain-driven-design-lite --dev
```

> This package is primarily a developer tool (scaffolding, conversion, quality helpers), so installing under --dev is recommended.
Laravel’s package discovery will automatically register the service provider.

### 📦 Provider & Publishing

DDD-Lite ships with stubs and quality configs you can copy into your app.

**Stubs (module scaffolding & generators)**

To publish the stubs:

```bash
php artisan vendor:publish --tag=ddd-lite
php artisan vendor:publish --tag=ddd-lite-stubs
```
This will create:
- stubs/ddd-lite/*.stub – templates for:
- DTOs, Actions, Contracts, Repositories, Value Objects, Aggregates
- Controllers (including an --inertia variant)
- Requests
- Models & migrations
- Module providers and route/event providers
- Routes (web & api)

You typically don’t need to touch these unless you want to customise the generated code style.

**Quality tooling**

To seed PHPStan, Deptrac and Pest architecture tests into your application:

```bash
php artisan ddd-lite:publish:quality --target=all
```
This will (in your app):
- Create phpstan.app.neon with sensible defaults for app/ + modules/
- Create deptrac.app.yaml describing layer boundaries (Http, Models, Domain, Modules, etc.)
- Add tests/ArchitectureTest.php with some baseline rules:
- No debug helpers (dd, dump, var_dump, …)
- No stray env() calls
- Enforce strict types

You can also publish selectively:

```bash
php artisan ddd-lite:publish:quality --target=phpstan
php artisan ddd-lite:publish:quality --target=deptrac
php artisan ddd-lite:publish:quality --target=pest-arch
```

## 🚀 Getting Started (QuickStart)

We’ll build a simple Planner module with a Trip aggregate.

**1) Scaffold a module**

```bash
php artisan ddd-lite:module Planner --aggregate=Trip
```

This creates modules/Planner with:
- Providers (module, route, event) under App/Providers
- Routes in Routes/api.php and Routes/web.php
- A ULID Eloquent model (App/Models/Trip.php) plus migrations in Database/migrations
- Domain DTOs and a repository contract
- Tests folders

Core flags:
- --dry-run – preview actions without writing
- --fix-psr4 – auto-rename lowercased module folders to proper PascalCase
- --rollback=<manifest-id> – undo a previous scaffold
- --force – overwrite content when needed (with backups)

> For full details see: docs/module-scaffold.md

**2) Generate a DTO**

```bash
php artisan ddd-lite:make:dto Planner CreateTripData \
  --props="id:Ulid|nullable,title:string,startsAt:CarbonImmutable,endsAt:CarbonImmutable"
```
This generates modules/Planner/Domain/DTO/CreateTripData.php:
- Properly typed constructor
- readonly by default
- Optional unit test (unless --no-test is passed)

**3) Generate a domain Action**

```bash
php artisan ddd-lite:make:action Planner CreateTrip \
  --in=Trip \
  --input=FQCN --param=data \
  --returns=ulid
```
This creates Domain/Actions/Trip/CreateTripAction.php similar to:
```php
namespace Modules\Planner\Domain\Actions\Trip;

use Modules\Planner\Domain\Contracts\TripRepositoryContract;
use Modules\Planner\Domain\DTO\CreateTripData;

final class CreateTripAction
{
    public function __construct(
        private TripRepositoryContract $repo,
    ) {}

    public function __invoke(CreateTripData $data): string
    {
        // Domain invariants live here
        return $this->repo->create($data);
    }
}
```

**4) Implement the repository & bind it**
Create an Eloquent repository in modules/Planner/App/Repositories/TripRepository.php (or let ddd-lite:make:repository scaffold it):

```bash
php artisan ddd-lite:make:repository Planner Trip
```
Then wire the contract to the implementation:
```bash
php artisan ddd-lite:bind Planner TripRepositoryContract TripRepository
```
ddd-lite:bind edits your module provider so that:
```php
$this->app->bind(
    TripRepositoryContract::class,
    TripRepository::class,
);
```
is registered.

**5) Expose via HTTP**
Generate a controller + request:

```bash
php artisan ddd-lite:make:controller Planner Trip --resource
php artisan ddd-lite:make:request Planner StoreTrip
```

### 🧠 DDD-Lite in Practice: Example Flow

A typical “vertical slice” in a module:
- **DTO:** CreateTripData – validated input from HTTP or CLI.
- **Action:** CreateTripAction – orchestrates creation, enforces invariants.
- **Contract:** TripRepositoryContract – interface for persistence.
- **Repository:** TripRepository (Eloquent) – implements contract.
- **Controller:** TripController@store – adapts HTTP to the action.

Generators help you keep this shape consistent across modules without hand-rolling boilerplate every time.

### 🧰 Command Reference

All commands share a consistent UX:
- --dry-run – print what would happen; no files written; no manifest saved.
- --force – overwrite when content changes (backups are tracked in manifests).
- --rollback=<manifest-id> – revert a previous run (see Manifest section below).

**1. Module scaffolding & conversion**

**ddd-lite:module**
Scaffold a new module skeleton:
```bash
php artisan ddd-lite:module Planner
```
Key flags:
- name (required) – module name in PascalCase.
- --dry-run – preview only.
- --force – overwrite files if they exist.
- --fix-psr4 – rename existing lowercased module folders to PSR-4 PascalCase.
- --rollback=<id> – rollback a previous scaffold.

See docs/module-scaffold.md￼ for details.

**ddd-lite:convert**
Discover and optionally apply moves from app/* into modules:
```bash
php artisan ddd-lite:convert Planner \
  --plan-moves \
  --paths=app/Http/Controllers,app/Models
```
> To use the namespace rewriting and AST-based moves, install nikic/php-parser:

> composer require --dev nikic/php-parser

Important options:
- module – target module name.
- --plan-moves – discover move candidates and print a plan (no writes).
- --apply-moves – actually apply moves (AST-safe namespace rewrites).
- --review – interactive confirmation per move (with --apply-moves).
- --all – apply all moves without prompts.
- --only=controllers,models,requests,actions,dto,contracts – include kinds.
- --except=... – exclude kinds.
- --paths=... – comma-separated paths to scan.
- --with-shims – include shim suggestions in the printed plan.
- --export-plan=path.json – write discovered move plan to JSON.
- --dry-run, --force, --rollback=<id>.

Use this to gradually migrate a legacy app into DDD-Lite modules.

**2. Domain generators**

**ddd-lite:make:dto**
Generate a DTO under Domain/DTO:
```bash
php artisan ddd-lite:make:dto Planner CreateTripData \
  --in=Trip \
  --props="id:Ulid|nullable,title:string,startsAt:CarbonImmutable"
```
- module – module name.
- name – DTO class name.
- --in= – optional subnamespace inside Domain/DTO.
- --props= – name:type[|nullable] comma-separated.
- --readonly – enforce readonly class (default: true).
- --no-test – skip generating a test.

**ddd-lite:make:action**
Generate a domain action in Domain/Actions:
```bash
php artisan ddd-lite:make:action Planner CreateTrip \
  --in=Trip \
  --input=FQCN --param=data \
  --returns=ulid
```
- --in= – optional subnamespace.
- --method= – method name (default __invoke).
- --input= – parameter type preset: none|ulid|FQCN.
- --param= – parameter variable name.
- --returns= – void|ulid|FQCN.
- --no-test – skip test.

**ddd-lite:make:contract**
Generate a domain contract:
```bash
php artisan ddd-lite:make:contract Planner TripRepository \
  --in=Trip \
  --methods="find:TripData|null(id:Ulid); create:TripData(data:TripCreateData)"
```
- --methods= – semi-colon separated: name:ReturnType(args...).
- --with-fake – generate a Fake implementation under tests/Unit/fakes.
- --no-test – skip the contract test.

**ddd-lite:make:repository**
Generate an Eloquent repository for an aggregate:
```bash
php artisan ddd-lite:make:repository Planner Trip
```
Creates:
- App/Repositories/TripRepository.php
- Optional tests (unless --no-test).

**ddd-lite:make:value-object**
Generate a value object:
```bash
php artisan ddd-lite:make:value-object Planner Email \
  --scalar=string
```
- --scalar= – backing scalar type: string|int|float|bool.

**ddd-lite:make:aggregate-root**
Generate an aggregate root base:
```bash
php artisan ddd-lite:make:aggregate-root Planner Trip
```
Useful for richer domain modelling around key aggregates.

**Query side**
- ddd-lite:make:query – generate a Query class in Domain/Queries.
- ddd-lite:make:query-builder – generate a QueryBuilder helper.
- ddd-lite:make:aggregator – generate an Aggregator to combine queries.

Example:
```bash
php artisan ddd-lite:make:query Planner TripIndexQuery
php artisan ddd-lite:make:query-builder Planner Trip
php artisan ddd-lite:make:aggregator Planner TripIndexAggregator
```

**3. App-layer generators**

**ddd-lite:make:model**
Generate an Eloquent model in App/Models:
```bash
php artisan ddd-lite:make:model Planner Trip \
  --table=trips \
  --fillable="title,starts_at,ends_at"
```
Options:
- --table=, --fillable=, --guarded=
- --soft-deletes
- --no-timestamps

**ddd-lite:make:migration**
Generate a migration under Database/migrations:
```bash
php artisan ddd-lite:make:migration Planner create_trips_table --create=trips
```
- module? – module name (optional).
- name? – migration base name.
- --table= – table name.
- --create= – shortcut for table creation.
- --path= – override path (defaults to module migrations).
- --force, --dry-run, --rollback=<id>.

**ddd-lite:make:controller**
Generate a controller:
```bash
php artisan ddd-lite:make:controller Planner Trip --resource
```
- --resource – standard Laravel resource methods.
- --inertia – generate methods that return Inertia pages.
- --suffix= – class suffix (default Controller).

**ddd-lite:make:request**
Generate a form request:
```bash
php artisan ddd-lite:make:request Planner StoreTrip
```
- --suffix= – class suffix (default Request).

**4. Binding & wiring**

**ddd-lite:bind**
Bind a domain contract to an implementation in the module provider:

```bash
php artisan ddd-lite:bind Planner TripRepositoryContract TripRepository
```
- module – module name.
- contract – contract short name or FQCN.
- implementation – implementation short name or FQCN.
- --force – skip class existence checks (e.g. when generating ahead of time).

**5. Manifest commands**
Every write operation (scaffolding, generate, convert, publish, doctor fixes) is tracked via a **Manifest**.

**ddd-lite:manifest:list**
List manifests:

```bash
php artisan ddd-lite:manifest:list --module=Planner --type=create --json
```
Options:
- --module= – filter by module.
- --type= – mkdir|create|update|delete|move.
- --after=, --before= – ISO8601 bounds for created_at.
- --json – machine-readable output.

**ddd-lite:manifest:show**
Inspect a single manifest:

```bash
php artisan ddd-lite:manifest:show 2025-11-24-13-54-01 --json
```
Shows the tracked operations for that run (created files, backups, moves, deletions, etc.).

**6. Doctor & Quality commands**

**ddd-lite:publish:quality**
(Described earlier) – publishes PHPStan, Deptrac, and Pest Arch configuration/stubs into your app.

**ddd-lite:doctor**
Run structural checks on your modules and wiring:

```bash
php artisan ddd-lite:doctor --module=Planner --json
```
Checks things like:
- Module provider registration
- Route/service provider wiring
- PSR-4 inconsistencies
- Missing or mis-wired module components

Flags:
- --module= – limit to a specific module.
- --fix – attempt automatic fixes (provider edits, PSR-4 renames, etc.).
- --json – JSON report for tooling.
- --prefer=file|class – strategy when class and filename mismatch.
- --rollback=<id> – undo fixes.

**ddd-lite:doctor:domain**
Run domain purity checks via Deptrac:

```bash
php artisan ddd-lite:doctor:domain \
  --config=deptrac.app.yaml \
  --bin=vendor/bin/deptrac \
  --json \
  --fail-on=violations
```

Options:
- --config= – Deptrac YAML config.
- --bin= – path to deptrac executable.
- --json – JSON summary.
- --strict – treat uncovered as failure.
- --stdin-report= – use pre-generated Deptrac JSON report.
- --fail-on= – violations|errors|uncovered|any.

**ddd-lite:doctor-ci**
Run both structural and domain checks in CI:

```bash
php artisan ddd-lite:doctor-ci --json --fail-on=error
```
- --paths= – paths to scan (defaults to modules/ and bootstrap/app.php).
- --fail-on=none|any|error – CI failure policy.
- --json – CI-friendly JSON result.

Use this in your CI pipeline to enforce module health.

### 🧪 Safety Rails: Manifest & Rollback

DDD-Lite never silently edits your app.

For each command run that changes files:
- A **Manifest** is written with:
- mkdir, create, update, delete, move records
- Backups of overwritten files
- You can inspect manifests with:
- ddd-lite:manifest:list
- ddd-lite:manifest:show {id}
- You can revert a run by passing --rollback=<manifest-id> to the original command (e.g. ddd-lite:module, ddd-lite:convert, ddd-lite:publish:quality, ddd-lite:doctor).

This makes DDD-Lite safe to use on large, existing codebases.

### 🧮 Package Quality: PHPStan, Deptrac & Pest

Inside this package:
- phpstan.neon.dist – strict rules for the package itself.
- deptrac.package.yaml – package-level dependency rules.
- tests/ArchTest.php – baseline architecture checks via Pest.

In your **application,** use:
```bash
php artisan ddd-lite:publish:quality --target=all
```
and then:

```bash
# In your app
vendor/bin/phpstan analyse -c phpstan.app.neon
vendor/bin/deptrac --config=deptrac.app.yaml
php artisan test tests/ArchitectureTest.php
```
Combine this with ddd-lite:doctor-ci in CI for a tight feedback loop.

### 🧩 Common Workflows

Greenfield project
- Install DDD-Lite.
- Scaffold your first module: ddd-lite:module.
- Generate DTOs, Actions, Contracts, Repositories, Controllers, Requests.
- Set up quality tooling with ddd-lite:publish:quality.
- Wire ddd-lite:doctor-ci into your CI.

Migrating a legacy app
- Install DDD-Lite.
- Scaffold a module for a coherent slice (e.g. Planner, Billing, Users).
- Use ddd-lite:convert with --plan-moves on a subset of app/*.
- Iterate with --apply-moves and --review, keeping an eye on manifests.
- Introduce contracts + repositories for areas you want to harden.
- Run ddd-lite:doctor and ddd-lite:doctor:domain regularly during the migration.

### 🧪 Testing Philosophy

The package itself is tested with:
- **Pest** for:
  - Feature tests of console commands
  - Unit tests for internals (filesystem, manifests, planners)
- Architecture tests to protect boundaries.

You’re encouraged to:
- Keep module tests close to modules (under modules/<Module>/tests).
- Use the provided stubs for DTO / Action / Contract / Repository tests to keep patterns consistent.

### 🔒 Design Principles
- **Domain purity** – Domain/ should know nothing about Laravel.
- **Explicit boundaries** – Domain <-> App contracts are interfaces, not facades.
- **Safety first** – manifests, backups, --dry-run, --rollback.
- **Deterministic generators** – running a command twice should be safe and idempotent.
- **CI-friendly** – all checks and reports can be consumed by automation via JSON / exit codes.

### 🧰 Troubleshooting
- “Nothing seems to happen when I run a command”
  - Check if you passed --dry-run.
  - Inspect manifests using ddd-lite:manifest:list.
- “I messed up my module structure”
  - Find the relevant manifest id: ddd-lite:manifest:list.
  - Rerun the original command with --rollback=<id>.
- “Deptrac or PHPStan fail after publishing quality configs”
  - Make sure you installed the suggested dev dependencies in your app.
  - Tweak phpstan.app.neon / deptrac.app.yaml to match your project’s structure.


### Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

### Security

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

### 🙌 Credits

- [Godspower Oduose](https://github.com/rockblings)
- [All Contributors](../../contributors)

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.