<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\ConversionDiscovery;
use Illuminate\Filesystem\Filesystem;

it('computes toNamespace from destination directory (not including filename)', function (): void {
    $fs = new Filesystem();

    $srcDir = base_path('app/Http/Controllers');
    $fs->ensureDirectoryExists($srcDir);

    // Use a unique filename to avoid interference with other tests running in parallel
    $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    $filename = "Sample{$suffix}Controller.php";
    $classname = "Sample{$suffix}Controller";

    $src = $srcDir . '/' . $filename;
    $code = "<?php\ndeclare(strict_types=1);\nnamespace App\\Http\\Controllers;\nfinal class {$classname} {}\n";
    $fs->put($src, $code);

    $discovery = new ConversionDiscovery();
    $plan = $discovery->discover('Planner');

    expect($plan->count())->toBeGreaterThan(0);

    $candidate = collect($plan->items)->first(
        fn ($c) => str_ends_with($c->fromAbs, $filename)
    );

    expect($candidate)->not->toBeNull()
        ->and($candidate->toNamespace)->toBe('Modules\\Planner\\App\\Http\\Controllers');
});
