<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use Illuminate\Filesystem\Filesystem;

it('tracks move and rolls back by moving file back', function (): void {
    $fs = app(Filesystem::class);

    $src = base_path('temp_src/Foo.php');
    $dst = base_path('temp_dst/Foo.php');

    $fs->ensureDirectoryExists(dirname($src));
    $fs->ensureDirectoryExists(dirname($dst));
    $fs->put($src, "<?php\n\nclass Foo {}\n");

    $m = Manifest::begin($fs);
    $m->trackMkdir('temp_dst');
    $fs->move($src, $dst);
    $m->trackMove('temp_src/Foo.php', 'temp_dst/Foo.php');
    $m->save();

    expect(is_file($dst))->toBeTrue();

    $loaded = Manifest::load($fs, $m->id());
    $loaded->rollback();

    expect(is_file($src))->toBeTrue()
        ->and(is_file($dst))->toBeFalse();

    $fs->deleteDirectory(base_path('temp_src'));
    $fs->deleteDirectory(base_path('temp_dst'));
});
