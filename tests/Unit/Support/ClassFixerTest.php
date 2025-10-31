<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\ClassFixer;
use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use Illuminate\Filesystem\Filesystem;

it('renames class inside file', function (): void {
    $fs = new Filesystem();
    $manifest = new Manifest($fs, 'test_' . bin2hex(random_bytes(2)));

    $tmp = tempnam(sys_get_temp_dir(), 'fix_class_');
    assert(is_string($tmp));
    $php = <<<'PHP'
    <?php
    namespace Modules\Demo\App\Providers;

    final class OldName {}
    PHP;
    file_put_contents($tmp, $php);

    $fixer = new ClassFixer($fs);
    $fixer->renameClassInFile($manifest, $tmp, 'OldName', 'NewName');

    $updated = (string)file_get_contents($tmp);
    expect($updated)->toContain('final class NewName');

    @unlink($tmp);
});

it('renames a file', function (): void {
    $fs = new Filesystem();
    $manifest = new Manifest($fs, 'test_' . bin2hex(random_bytes(2)));

    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fix_file_' . bin2hex(random_bytes(2));
    @mkdir($dir, 0777, true);

    $from = $dir . DIRECTORY_SEPARATOR . 'Foo.php';
    file_put_contents($from, '<?php final class Foo {}');

    $to = $dir . DIRECTORY_SEPARATOR . 'Bar.php';
    $fixer = new ClassFixer($fs);
    $fixer->renameFile($manifest, $from, $to);

    expect(is_file($to))->toBeTrue();

    @unlink($to);
    @rmdir($dir);
});
