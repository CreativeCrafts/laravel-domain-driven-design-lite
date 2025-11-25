<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\BootstrapInspector;
use Illuminate\Filesystem\Filesystem;

it('handles quotes/escapes in findMatchingParen path and negative cases', function (): void {
    $fs = new Filesystem();
    $bootstrap = base_path('bootstrap');
    $fs->ensureDirectoryExists($bootstrap);

    // This source includes nested parentheses and quoted strings containing parentheses
    // to exercise findMatchingParen branches. It also omits withProviders inside the chain
    // so providerInsideConfigureChain returns false, and there is no standalone withProviders block.
    $src = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;

    // tricky strings with parens and quotes to push the parser branches
    $dummy = "paren ( inside \"double\" and 'single' ) end";
    $dummy2 = 'nested ( \') still string ) close';

    return Application::configure(
        basePath: dirname(__DIR__),
        options: [
            'callback' => fn ($x) => ($x * (2 + 3)),
        ],
    )
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
            api: __DIR__.'/../routes/api.php',
        )
        // intentionally do NOT call ->withProviders([...]) inside this chain
        ->create();
    PHP;

    $fs->put(base_path('bootstrap/app.php'), $src);

    $inspector = new BootstrapInspector($fs);

    expect($inspector->providerMentioned('Modules\\Foo\\Providers\\FooServiceProvider::class'))
        ->toBeFalse()
        ->and($inspector->providerInsideConfigureChain('Modules\\Foo\\Providers\\FooServiceProvider::class'))
        ->toBeFalse()
        ->and($inspector->hasStandaloneWithProvidersBlock())
        ->toBeFalse();

    // Also verify missingRoutingKeys detects a missing key when others present
    $missing = $inspector->missingRoutingKeys(['web', 'api', 'health']);
    sort($missing);
    expect($missing)->toBe(['health']);
});
