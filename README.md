[![Latest Version on Packagist](https://img.shields.io/packagist/v/creativecrafts/laravel-domain-driven-design-lite.svg?style=flat-square)](https://packagist.org/packages/creativecrafts/laravel-domain-driven-design-lite)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/creativecrafts/laravel-domain-driven-design-lite/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/creativecrafts/laravel-domain-driven-design-lite/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/creativecrafts/laravel-domain-driven-design-lite/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/creativecrafts/laravel-domain-driven-design-lite/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/creativecrafts/laravel-domain-driven-design-lite.svg?style=flat-square)](https://packagist.org/packages/creativecrafts/laravel-domain-driven-design-lite)

## 🧭 What is DDD-Lite?

**DDD-Lite** brings a pragmatic, Laravel-native way to apply Domain-Driven Design without a giant rewrite. It scaffolds **self-contained modules** with clean boundaries (Domain vs. App), gives you
**generators** (DTOs, Actions, Contracts, Models, Controllers, Queries…), enforces guardrails with doctor commands, and protects changes with a **manifest & rollback** mechanism.

The result: **clear seams, safer refactors, and better testability**—without fighting Laravel.

## 🧱 Architecture Overview

A **DDD-Lite Module** lives under:

```markdown
modules/<ModuleName>/
├─ App/
│ ├─ Http/
│ │ ├─ Controllers/
│ │ └─ Requests/
│ ├─ Models/
│ ├─ Providers/
│ └─ Repositories/
├─ Domain/
│ ├─ Actions/
│ ├─ Contracts/
│ ├─ DTO/
│ └─ Queries/
├─ Database/
│ └─ migrations/
├─ Routes/
│ ├─ api.php
│ └─ web.php
└─ tests/
├─ Feature/
└─ Unit/
```

✅ Rules this package helps you enforce

- **Domain purity**
    - Domain/Actions, Domain/Contracts, Domain/DTO, and Domain/Queries have **no Laravel runtime dependencies** (only PHP + contracts).
- **App wiring**
    - App/Repositories satisfy Domain/Contracts (infrastructure).
    - App/Http/Controllers are thin orchestrators.
- **IDs & DTOs**
    - Actions input/output are **ID/DTO** centric—no fat Eloquent models crossing domain boundaries.
- **Bounded modules**
    - Each module has its own providers & routes, registered via bootstrap/app.php.
- **Safety rails**
    - Generators use a **manifest** (with trackCreate/Update/Delete/Move/Mkdir) and support --dry-run and **rollback**.
- **Consistency**
    - All commands follow the same **BaseCommand + Manifest** workflow:
        - prepare → summary → dry-run branch → single manifest on write → track* ops → save → rollback on failure.

### ⚙️ Installation

```bash
composer require creativecrafts/laravel-domain-driven-design-lite --dev
```

> This is a developer tool (scaffolding, doctoring, CI helpers), so --dev is recommended.

### Provider & Publishing

The package service provider is auto-discovered. To publish quality configs (PHPStan, Deptrac, Pest arch rules, etc.):

```bash
php artisan vendor:publish --tag=ddd-lite-quality
```

> By default, this publishes:
> - phpstan.neon.dist
> - deptrac.package.yaml (package rules)
> - stubs/deptrac/deptrac.app.yaml (app rules to publish into consuming apps)
> - Pest arch rules under tests/Architecture/* (optional)

## 🚀 Getting Started (QuickStart)

**1) Scaffold a module**

```bash
php artisan ddd-lite:module Planner --aggregate=Trip
```

This creates modules/Planner with:

- Providers (module, route, event)
- Routes (api.php, web.php)
- ULID model (App/Models/Trip.php) + migration
- Domain DTOs & repository contract
- Tests folders

> Flags you can add: --dry-run (preview, no writes), --force (overwrite), --fix-psr4 (normalize folder casing), --rollback=<manifest-id>.

**2) Generate domain code**

```bash
# VOs => creates modules/Planner/Domain/ValueObjects/Email.php
php artisan ddd-lite:make:value-object Planner Email --scalar=string

# DTOs
php artisan ddd-lite:make:dto Planner CreateTripData
php artisan ddd-lite:make:dto Planner TripData

# Domain Action
php artisan ddd-lite:make:action Planner CreateTripAction

# Contract + Eloquent repository
php artisan ddd-lite:make:contract Planner TripRepositoryContract
php artisan ddd-lite:make:repository Planner TripRepository
```

**3) Generate HTTP Controller & Request**

```bash
php artisan ddd-lite:make:request Planner StoreTripRequest
php artisan ddd-lite:make:controller Planner TripController --inertia=false
```

**4) Bind contract to implementation**

```bash
php artisan ddd-lite:bind Planner TripRepositoryContract App\\Repositories\\TripRepository
```

> This updates the module provider with an app container binding.

- Checks provider placement, routing consistency, and file/class naming mismatches.
- In CI, use ddd-lite:doctor:ci for schema-stable JSON and a non-zero exit on policy.

## 🧠 DDD-Lite in the Real World: “Booking a Trip”

We’ll walk a realistic flow—from **DTO → Action → Repository → Controller → Route**—inside the Planner module.
**1) Domain DTO**

```php
<?php
// modules/Planner/Domain/DTO/CreateTripData.php

declare(strict_types=1);

namespace Modules\Planner\Domain\DTO;

final class CreateTripData
{
    public function __construct(
        public string $destination,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?string $notes = null,
    ) {}
}
```

**2) Domain Contract**

```php
<?php
// modules/Planner/Domain/Contracts/TripRepositoryContract.php

declare(strict_types=1);

namespace Modules\Planner\Domain\Contracts;

use Modules\Planner\Domain\DTO\CreateTripData;

interface TripRepositoryContract
{
    public function create(CreateTripData $data): string; // Returns ULID
    public function exists(string $tripId): bool;
}
```

**3) Domain Action**

```php
<?php
// modules/Planner/Domain/Actions/CreateTripAction.php

declare(strict_types=1);

namespace Modules\Planner\Domain\Actions;

use Modules\Planner\Domain\Contracts\TripRepositoryContract;
use Modules\Planner\Domain\DTO\CreateTripData;

final class CreateTripAction
{
    public function __construct(private TripRepositoryContract $repo) {}

    public function __invoke(CreateTripData $data): string
    {
        // Domain invariants would live here
        return $this->repo->create($data);
    }
}
```

**4) App Eloquent Model**

```php
<?php
// modules/Planner/App/Models/Trip.php

declare(strict_types=1);

namespace Modules\Planner\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Trip extends Model
{
    use HasUlids;

    protected string $table = 'trips';

    public bool $incrementing = false;
    protected string $keyType = 'string';

    protected $fillable = ['destination', 'start_date', 'end_date', 'notes'];
}
```

**5) App Repository (Eloquent)**

```php
<?php
// modules/Planner/App/Repositories/TripRepository.php

declare(strict_types=1);

namespace Modules\Planner\App\Repositories;

use Illuminate\Support\Str;
use Modules\Planner\App\Models\Trip;
use Modules\Planner\Domain\Contracts\TripRepositoryContract;
use Modules\Planner\Domain\DTO\CreateTripData;

final class TripRepository implements TripRepositoryContract
{
    public function create(CreateTripData $data): string
    {
        /** @var Trip $trip */
        $trip = new Trip();
        $trip->id = (string) Str::ulid();
        $trip->destination = $data->destination;
        $trip->start_date = $data->startDate->format('Y-m-d');
        $trip->end_date = $data->endDate->format('Y-m-d');
        $trip->notes = $data->notes;
        $trip->save();

        return $trip->id;
    }

    public function exists(string $tripId): bool
    {
        return Trip::query()->whereKey($tripId)->exists();
    }
}
```

**6) Provider binding**

```php
<?php
// modules/Planner/App/Providers/PlannerServiceProvider.php

declare(strict_types=1);

namespace Modules\Planner\App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Planner\App\Repositories\TripRepository;
use Modules\Planner\Domain\Contracts\TripRepositoryContract;

final class PlannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripRepositoryContract::class, TripRepository::class);
    }
}
```

**7) HTTP Request**

```php
<?php
// modules/Planner/App/Http/Requests/StoreTripRequest.php

declare(strict_types=1);

namespace Modules\Planner\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTripRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'destination' => ['required', 'string', 'max:255'],
            'start_date'  => ['required', 'date'],
            'end_date'    => ['required', 'date', 'after_or_equal:start_date'],
            'notes'       => ['nullable', 'string'],
        ];
    }
}
```

**8) Controller (thin)**

```php
<?php
// modules/Planner/App/Http/Controllers/TripController.php

declare(strict_types=1);

namespace Modules\Planner\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Planner\App\Http\Requests\StoreTripRequest;
use Modules\Planner\Domain\Actions\CreateTripAction;
use Modules\Planner\Domain\DTO\CreateTripData;

final class TripController
{
    public function store(StoreTripRequest $request, CreateTripAction $action): JsonResponse
    {
        $data = new CreateTripData(
            destination: $request->string('destination')->toString(),
            startDate: new \DateTimeImmutable($request->date('start_date')->format('Y-m-d')),
            endDate: new \DateTimeImmutable($request->date('end_date')->format('Y-m-d')),
            notes: $request->string('notes')->nullOrValue(),
        );

        $id = $action($data);

        return response()->json(['id' => $id], 201);
    }
}
```

**9) API Routing**

```php
<?php
// modules/Planner/Routes/api.php

use Illuminate\Support\Facades\Route;
use Modules\Planner\App\Http\Controllers\TripController;

Route::prefix('planner')->group(function () {
    Route::post('trips', [TripController::class, 'store']);
});
```

> This exposes POST /api/planner/trips in your app’s API namespace.

### 🔄 Example: Converting RegisteredUserController → CreateUserAction

**Before (fat controller)**

```php
<?php
// app/Http/Controllers/Auth/RegisteredUserController.php

public function store(Request $request)
{
    $request->validate([
        'name' => ['required', 'string'],
        'email' => ['required', 'email', 'unique:users,email'],
        'password' => ['required', 'confirmed', Password::defaults()],
    ]);

    $user = User::create([
        'name' => $request->string('name'),
        'email' => $request->string('email'),
        'password' => Hash::make($request->string('password')),
    ]);

    event(new Registered($user));
    Auth::login($user);

    return redirect(RouteServiceProvider::HOME);
}
```

**After (DDD-Lite module Auth)**

```php
<?php
// modules/Auth/Domain/DTO/CreateUserData.php
final class CreateUserData { /* name, email, password (hashed or plain based on policy) */ }

// modules/Auth/Domain/Contracts/UserRepositoryContract.php
interface UserRepositoryContract { public function create(CreateUserData $data): string; }

// modules/Auth/Domain/Actions/CreateUserAction.php
final class CreateUserAction {
    public function __construct(private UserRepositoryContract $repo) {}
    public function __invoke(CreateUserData $data): string {
        return $this->repo->create($data);
    }
}

// modules/Auth/App/Repositories/UserRepository.php
final class UserRepository implements UserRepositoryContract {
    public function create(CreateUserData $data): string { /* Eloquent create + return ULID */ }
}

// modules/Auth/App/Http/Controllers/RegisterController.php
public function store(RegisterRequest $request, CreateUserAction $action): RedirectResponse {
    $id = $action(new CreateUserData(...));
    Auth::loginUsingId($id);
    return redirect()->route('home');
}
```

✅ **Benefits**

- Controllers become thin and testable.
- Domain logic is **independent** and portable.
- Repositories hide persistence.
- DTOs define the **language** of your domain.

> That’s the DDD-Lite promise: same feature, clean separation, safer to evolve.

## 🧰 Command Reference

All generator/fixer commands follow the **same UX and safety rails:**

- --dry-run → print plan; no changes; no manifest written.
- --force → overwrite when content differs (creates **backup**).
- --rollback=<manifest-id> → revert previous run by manifest.
- Deterministic, idempotent output.

**Module scaffolding**

```bash
php artisan ddd-lite:module <Name> [--aggregate=Aggregate] [--dry-run] [--force] [--fix-psr4] [--rollback=<id>]
```

**Domain generators**

```bash
php artisan ddd-lite:make:dto <Module> <Name> [--dry-run] [--force] [--rollback=<id>]
php artisan ddd-lite:make:action <Module> <Name> [--dry-run] [--force] [--rollback=<id>]
php artisan ddd-lite:make:contract <Module> <Name> [--dry-run] [--force] [--rollback=<id>]
php artisan ddd-lite:make:model <Module> <Name> [--fillable=...] [--guarded=...] [--soft-deletes] [--no-timestamps] [--dry-run] [--force] [--rollback=<id>]
php artisan ddd-lite:make:repository <Module> <Name> [--dry-run] [--force] [--rollback=<id>]
php artisan ddd-lite:make:request <Module> <Name> [--dry-run] [--force] [--rollback=<id>]
php artisan ddd-lite:make:controller <Module> <Name> [--inertia] [--dry-run] [--force] [--rollback=<id>]
php artisan ddd-lite:make:provider <Module> <Type=Route|Event> [--dry-run] [--force] [--rollback=<id>]

# Query side (optional)
php artisan ddd-lite:make:query <Module> <Name> [...]
php artisan ddd-lite:make:query-builder <Module> <Name> [...]
php artisan ddd-lite:make:query-aggregator <Module> <Name> [...]
```

**Bindings & conversion**

```bash
php artisan ddd-lite:bind <Module> <ContractFQCN> <ImplementationFQCN> [--dry-run] [--force] [--rollback=<id>]

php artisan ddd-lite:convert [--apply-moves] [--dry-run] [--rollback=<id>]
# discovers legacy App classes, proposes/executes Moves to module namespaces (AST-safe), registers providers, and records a single manifest.
```

**Doctor & CI**

```bash
php artisan ddd-lite:doctor [--fix] [--json]
php artisan ddd-lite:doctor:ci [--json] [--fail-on=warning|error]
```

### 🧪 Safety Rails: Manifest & Rollback

Every write operation (except --dry-run) is tracked as **atomic actions**:

- trackMkdir(path)
- trackCreate(path)
- trackUpdate(path, backupPath)
- trackDelete(path)
- trackMove(from, to)

Manifests live under storage/app/ddd-lite_scaffold/manifests/<id>.json. To rollback:

```bash
php artisan ddd-lite:make:action Planner DoThing --rollback=<manifest-id>
```

> Rollback restores backups, deletes created files, moves files back, and tidies directories as needed. Tests ensure idempotency.

### 🧮 Quality: PHPStan, Deptrac & Pest Architecture

**PHPStan (Larastan)**

- Published phpstan.neon.dist includes strict rules tuned for this package.
- Run:

```bash
composer stan
```

**Deptrac (layer boundaries)**
We ship **two configs**:

1. **Package (this repo)** — deptrac.package.yaml
    - Ensures Console depends on Support, not vice-versa; prevents accidental circular deps.
2. **App (for consumers)** — stubs/deptrac/deptrac.app.yaml

- Users publish this into their app to enforce:
    - Domain layers don’t depend on App layers.
    - Domain/* has no Laravel runtime imports.
    - Repositories implement contracts from Domain only.

Run:

```bash
composer deptrac          # package rules
# and for apps, after publish:
vendor/bin/deptrac --config=deptrac.yaml
```

**Pest Architecture Tests**

We include optional Pest architecture tests that mirror Deptrac’s layers in a code-first way.
They’re resilient and fast, and great for teams that prefer build-in checks alongside Deptrac.

## 🧑‍🍳 Getting Started Example Project

For a fast demo:

1. New Laravel app
2. composer require creativecrafts/laravel-domain-driven-design-lite --dev
3. php artisan ddd-lite:module Planner --aggregate=Trip
4. php artisan migrate
5. Add route in your app’s routes/api.php (or rely on module’s own Routes/api.php)
6. php artisan serve

You now have a /api/planner/trips POST endpoint that uses the DDD-Lite flow.

## 🧩 Common Workflows

**Preview vs. Apply**

```bash
php artisan ddd-lite:make:dto Planner TripData --dry-run
# prints a plan but does not write

php artisan ddd-lite:make:dto Planner TripData
# applies changes and prints Manifest: <id>
```

**Rollback**

```bash
php artisan ddd-lite:make:dto Planner TripData --rollback=<manifest-id>
```

**Overwrite safely**

```bash
php artisan ddd-lite:make:model Planner Trip --force
# if content changed, a backup is written and tracked
```

### 🧪 Testing Philosophy

```bash
composer test
```

- Feature tests cover generator commands end-to-end.
- No tests for baseline Laravel features.
- Idempotency tests ensure “no changes” on re-runs unless --force.

### 🔒 Design Principles Recap

- **Consistency across commands:** same BaseCommand + Manifest flow.
- **Deterministic output:** same inputs produce same files.
- **Domain purity:** domain does not depend on Laravel runtime.
- **Safety first:** --dry-run, backup on overwrite, rollback any time.
- **Clarity & DX:** short closures, promoted properties, strict types, helpful console UX.

> If you’ve read this far, you’re ready to ship a healthier Laravel codebase.
> Start with one feature, one module. Let DDD-Lite keep it honest.

## 🧰 Troubleshooting

- **“Manifest not found” on rollback**
    - Ensure the manifest ID is correct and that the write run actually created one
      (no manifest is written in --dry-run runs).
- **“Module not found”**
    - Scaffold first with ddd-lite:module or pass the correct Module argument.
- **PSR-4 autoload can’t find module classes**
    - Run composer dump-autoload -o. Our doctor can also fix PSR-4 casing issues.
- **Deptrac violations explode**
    - Use our published deptrac.app.yaml (for apps) and start by allowing temporary edges while refactoring.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## 🙌 Credits

- [Godspower Oduose](https://github.com/rockblings)
- [All Contributors](../../contributors)

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.