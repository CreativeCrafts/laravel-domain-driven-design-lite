<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('plans moves with defaults', function (): void {
    $fs = new Filesystem();
    $fs->ensureDirectoryExists(base_path('app/Http/Controllers'));
    $fs->ensureDirectoryExists(base_path('app/Models'));

    $fs->put(base_path('app/Http/Controllers/TripController.php'), "<?php\nnamespace App\\Http\\Controllers;\nclass TripController {}\n");
    $fs->put(base_path('app/Models/Trip.php'), "<?php\nnamespace App\\Models;\nclass Trip {}\n");

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => 'Planner',
        '--plan-moves' => true,
    ])->run();

    expect($exit)->toBe(0);
});

it('respects only and except filters', function (): void {
    $fs = new Filesystem();
    $fs->ensureDirectoryExists(base_path('app/Http/Controllers'));
    $fs->ensureDirectoryExists(base_path('app/Models'));

    $fs->put(base_path('app/Http/Controllers/OnlyCtrl.php'), "<?php\nnamespace App\\Http\\Controllers;\nclass OnlyCtrl {}\n");
    $fs->put(base_path('app/Models/Skip.php'), "<?php\nnamespace App\\Models;\nclass Skip {}\n");

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => 'Planner',
        '--plan-moves' => true,
        '--only' => 'controllers',
        '--except' => 'models',
    ])->run();

    expect($exit)->toBe(0);
});

it('accepts explicit paths', function (): void {
    $fs = new Filesystem();
    $fs->ensureDirectoryExists(base_path('app/DTO'));
    $fs->put(base_path('app/DTO/TripData.php'), "<?php\nnamespace App\\DTO;\nclass TripData {}\n");

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => 'Planner',
        '--plan-moves' => true,
        '--paths' => base_path('app/DTO'),
        '--only' => 'dto',
    ])->run();

    expect($exit)->toBe(0);
});

it('suggests contracts when --suggest-contracts is enabled', function (): void {
    $fs = new Filesystem();

    $fs->ensureDirectoryExists(base_path('app/Models'));
    $fs->ensureDirectoryExists(base_path('app/Actions'));

    $fs->put(base_path('app/Models/Trip.php'), "<?php\nnamespace App\\Models;\nclass Trip {}\n");
    $fs->put(base_path('app/Actions/CreateTrip.php'), "<?php\nnamespace App\\Actions;\nclass CreateTrip {}\n");

    $exit = $this->artisan('ddd-lite:convert', [
        'module' => 'Planner',
        '--plan-moves' => true,
        '--paths' => 'app/Models,app/Actions',
        '--suggest-contracts' => true,
    ])->run();

    expect($exit)->toBe(0);
});
