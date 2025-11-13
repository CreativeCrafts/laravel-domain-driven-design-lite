<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

it('creates a Value Object', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain/ValueObjects"));

    $path = base_path("modules/{$module}/Domain/ValueObjects/Email.php");
    if ($fs->exists($path)) {
        $fs->delete($path);
    }

    $exit = $this->artisan('ddd-lite:make:value-object', [
        'module' => $module,
        'name' => 'Email',
        '--scalar' => 'string',
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($path))->toBeTrue();

    $code = (string)$fs->get($path);
    expect($code)->toContain('final class Email')
        ->and($code)->toContain('public readonly string $value')
        ->and($code)->toContain('public static function make(string $value): self')
        ->and($code)->toContain('public function equals(self $other): bool');
});

it('supports dry-run without writes', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $target = base_path("modules/{$module}/Domain/ValueObjects/OrderId.php");
    if ($fs->exists($target)) {
        $fs->delete($target);
    }

    $exit = $this->artisan('ddd-lite:make:value-object', [
        'module' => $module,
        'name' => 'OrderId',
        '--scalar' => 'string',
        '--dry-run' => true,
    ])->run();

    expect($exit)->toBe(0)
        ->and($fs->exists($target))->toBeFalse();
});

it('is idempotent when unchanged (no --force)', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $name = 'TripId';
    $target = base_path("modules/{$module}/Domain/ValueObjects/{$name}.php");

    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain/ValueObjects"));

    if ($fs->exists($target)) {
        $fs->delete($target);
    }

    // First create
    $exit1 = $this->artisan('ddd-lite:make:value-object', [
        'module' => $module,
        'name' => $name,
        '--scalar' => 'string',
    ])->run();

    expect($exit1)->toBe(0);
    $original = $fs->exists($target) ? (string)$fs->get($target) : '';

    // Second run without --force, unchanged
    $exit2 = $this->artisan('ddd-lite:make:value-object', [
        'module' => $module,
        'name' => $name,
        '--scalar' => 'string',
    ])->run();

    expect($exit2)->toBe(0);
    expect((string)$fs->get($target))->toBe($original);
});

it('overwrites with --force and creates a backup', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $name = 'Uuid';
    $target = base_path("modules/{$module}/Domain/ValueObjects/{$name}.php");

    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain/ValueObjects"));

    if ($fs->exists($target)) {
        $fs->delete($target);
    }

    // First create string-backed VO
    $this->artisan('ddd-lite:make:value-object', [
        'module' => $module,
        'name' => $name,
        '--scalar' => 'string',
    ])->run();

    // Overwrite with int-backed VO using --force
    $exit = $this->artisan('ddd-lite:make:value-object', [
        'module' => $module,
        'name' => $name,
        '--scalar' => 'int',
        '--force' => true,
    ])->run();

    expect($exit)->toBe(0);

    $code = (string)$fs->get($target);
    expect($code)->toContain('public readonly int $value');

    // Backup existence (by convention in our SafeFileOps)
    $backupDir = base_path('storage/app/ddd-lite_scaffold/backups');
    expect($fs->exists($backupDir))->toBeTrue();
});

it('supports rollback by manifest id', function (): void {
    $fs = new Filesystem();

    $module = 'Planner';
    $name = 'EmailAddress';
    $target = base_path("modules/{$module}/Domain/ValueObjects/{$name}.php");

    $fs->ensureDirectoryExists(base_path("modules/{$module}/Domain/ValueObjects"));

    if ($fs->exists($target)) {
        $fs->delete($target);
    }

    // Create and capture manifest id from output
    $exitCreate = Artisan::call('ddd-lite:make:value-object', [
        'module' => $module,
        'name' => $name,
        '--scalar' => 'string',
    ]);

    expect($exitCreate)->toBe(0);

    $output = Artisan::output();
    preg_match('/Manifest:\s+([a-f0-9\-]+)/i', $output, $m);
    expect($m)->toHaveCount(2);
    $manifestId = $m[1];

    // Ensure file exists
    expect($fs->exists($target))->toBeTrue();

    // Rollback using captured manifest id
    $exitRollback = Artisan::call('ddd-lite:make:value-object', [
        'module' => $module, // ignored on rollback
        'name' => $name,     // ignored on rollback
        '--rollback' => $manifestId,
    ]);

    expect($exitRollback)->toBe(0)
        ->and($fs->exists($target))->toBeFalse();
});