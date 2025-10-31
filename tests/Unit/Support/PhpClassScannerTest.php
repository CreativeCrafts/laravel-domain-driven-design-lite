<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\PhpClassScanner;

it('extracts fqcn, namespace and short class', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'scanner_');
    assert(is_string($tmp));
    $php = <<<'PHP'
    <?php
    namespace Modules\Demo\App\Providers;

    final class DemoServiceProvider {}
    PHP;
    file_put_contents($tmp, $php);

    $scanner = new PhpClassScanner();
    expect($scanner->fqcnFromFile($tmp))->toBe('Modules\Demo\App\Providers\DemoServiceProvider')
        ->and($scanner->namespaceFromFile($tmp))->toBe('Modules\Demo\App\Providers')
        ->and($scanner->shortClassFromFile($tmp))->toBe('DemoServiceProvider');

    @unlink($tmp);
});
