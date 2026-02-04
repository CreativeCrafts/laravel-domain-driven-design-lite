<?php

declare(strict_types=1);

it('runs deep checks when --deep is provided', function (): void {
    $exit = $this->artisan('ddd-lite:doctor', [
        '--deep' => true,
        '--json' => true,
    ])->run();

    expect($exit)->toBe(0);
    // Exit code is the key signal for deep checks in this command.
});
