---
name: laravel-ddd-lite-project
description: 
Guide for using Laravel Domain Driven Design Lite in a consuming application: module scaffolding, generators, wiring, refactors with dry-run/rollback, and boundary enforcement. Use when explaining or implementing DDD-lite workflows in a host Laravel app.
---

# Laravel DDD-Lite Usage Skill

Use this as a short operating manual for consuming applications.

## Core workflow (happy path)

1) Scaffold a module
```bash
php artisan ddd-lite:module Orders
```

2) Generate domain artifacts
```bash
php artisan ddd-lite:make:dto Orders CreateOrderData --props="customerId:int,total:int"
php artisan ddd-lite:make:action Orders CreateOrder --in=Order --input=FQCN
php artisan ddd-lite:make:contract Orders OrderRepository
```

3) Generate App adapters + bind contracts
```bash
php artisan ddd-lite:make:repository Orders Order --contract=OrderRepositoryContract
php artisan ddd-lite:bind Orders OrderRepositoryContract EloquentOrderRepository
```

4) Expose via HTTP
```bash
php artisan ddd-lite:make:controller Orders Order --resource
php artisan ddd-lite:make:request Orders StoreOrder
```

## Domain vs App separation

- Domain layer stays framework‑free (pure PHP).
- App layer uses Laravel services (Eloquent, queues, mail, HTTP).
- Cross‑module access goes through Domain contracts, not concrete classes.

## Safe refactor workflow (dry‑run + rollback)

1) Plan moves without writing:
```bash
php artisan ddd-lite:convert Billing --plan-moves --paths=app/Models,app/Http/Controllers --dry-run
```

2) Apply with review (creates a manifest id):
```bash
php artisan ddd-lite:convert Billing --apply-moves --review
```

3) Inspect and rollback if needed:
```bash
php artisan ddd-lite:manifest:show <manifest-id>
php artisan ddd-lite:convert Billing --rollback=<manifest-id>
```

## Boundary enforcement

- Deptrac layers + ruleset for module boundaries.
- PHPStan `forbiddenSymbols` to prevent cross‑module Domain imports.
- Use `Shared/Domain` for shared value objects and contracts.

## Reminders

- Keep examples aligned with current command signatures in the README.
- Always include dry‑run + rollback guidance for refactor workflows.
