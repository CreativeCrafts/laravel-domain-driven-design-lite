<?php

declare(strict_types=1);

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use Illuminate\Filesystem\Filesystem;

it('exercises readStub host override, render interpolation, rel, begin/load manifest', function (): void {
    $fs = new Filesystem();

    $cmd = new class () extends BaseCommand {
        protected $signature = 'ddd-lite:test-base';
        protected $description = 'Test harness for BaseCommand';

        public function handle(): int
        {
        return self::SUCCESS;
        }

        // Expose protected methods for testing
        public function callPrepare(): void
        {
        $this->prepare();
        }
        public function callReadStub(string $name): string
        {
        return $this->readStub($name);
        }
        /** @param array<string,string> $vars */
        public function callRender(string $name, array $vars): string
        {
        return $this->render($name, $vars);
        }
        public function callBeginManifest(): Manifest
        {
        return $this->beginManifest();
        }
        public function callRel(string $abs): string
        {
        return $this->rel($abs);
        }
        public function callLoadManifestOrFail(string $id): Manifest
        {
        return $this->loadManifestOrFail($id);
        }
    };

    $cmd->setLaravel(app());
    $cmd->callPrepare();

    // Host stub override
    $hostDir = base_path('stubs/ddd-lite');
    $fs->ensureDirectoryExists($hostDir);
    $hostStub = $hostDir . '/custom.stub';
    $fs->put($hostStub, "Hello {{ name }}\n");

    // readStub should resolve host stub
    $raw = $cmd->callReadStub('custom.stub');
    expect($raw)->toContain('Hello');

    // render should interpolate variables using StubRenderer
    $rendered = $cmd->callRender('ddd-lite/custom.stub', ['name' => 'World']);
    expect($rendered)->toBe("Hello World\n");

    // beginManifest should create a manifest we can save and then load via loadManifestOrFail
    $m = $cmd->callBeginManifest();
    $m->trackCreate('foo/bar.txt');
    $m->save();

    $loaded = $cmd->callLoadManifestOrFail($m->id());
    expect($loaded)->toBeInstanceOf(Manifest::class);

    // rel should strip base path
    $abs = base_path('modules/Demo/File.php');
    expect($cmd->callRel($abs))->toBe('modules/Demo/File.php');
});
