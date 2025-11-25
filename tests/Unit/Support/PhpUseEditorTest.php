<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\PhpUseEditor;

it('adds missing use statements after namespace and preserves existing ones', function () {
    $code = <<<'PHP'
    <?php
    
    namespace App\Providers;
    
    use Illuminate\Support\ServiceProvider;
    
    final class AppServiceProvider extends ServiceProvider {}
    PHP;

    $editor = new PhpUseEditor();
    $updated = $editor->ensureImports($code, [
        'App\\Contracts\\FooContract',
        'Illuminate\\Support\\ServiceProvider', // already exists
        '', // ignored
        'App\\Contracts\\FooContract', // duplicate ignored
    ]);

    expect($updated)
        ->toContain('use Illuminate\\Support\\ServiceProvider;')
        ->toContain('use App\\Contracts\\FooContract;')
        // Order of inserted use lines may vary; assert both present and class follows
        ->toMatch('/^<\\?php[\s\S]*namespace App\\\\Providers;[\s\S]*use (Illuminate\\\\Support\\\\ServiceProvider|App\\\\Contracts\\\\FooContract);[\s\S]*use (Illuminate\\\\Support\\\\ServiceProvider|App\\\\Contracts\\\\FooContract);[\s\S]*final class/s');
});

it('returns code unchanged when no imports are provided', function () {
    $code = "<?php\n\nfinal class A {}\n";
    $editor = new PhpUseEditor();
    expect($editor->ensureImports($code, []))->toBe($code);
});
