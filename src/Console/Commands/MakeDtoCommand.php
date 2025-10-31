<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use CreativeCrafts\DomainDrivenDesignLite\Support\StubRenderer;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;

final class MakeDtoCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:dto
        {module : Module name in PascalCase}
        {name : DTO class name (e.g. TripData)}
        {--in= : Optional subnamespace inside Domain/DTO, e.g. Trip}
        {--props= : Comma-separated list of properties: name:type[|nullable] (e.g. id:Ulid,name:string|nullable,age:int)}
        {--readonly : Force readonly class (default: true)}
        {--no-test : Do not create a test}
        {--dry-run : Preview without writing}
        {--force : Overwrite existing files}
        {--rollback= : Rollback a previous make run via manifest id}';

    protected $description = 'Scaffold a pure Domain DTO with typed constructor properties (Modules/<Module>/Domain/DTO).';

    /**
     * @throws RandomException
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $rollback = (string)($this->option('rollback') ?? '');
        if ($rollback !== '') {
            $m = $this->loadManifestOrFail($rollback);
            $m->rollback();
            $this->info("Rollback complete for {$rollback}.");
            return self::SUCCESS;
        }

        $module = Str::studly((string)$this->argument('module'));
        $class = Str::studly((string)$this->argument('name'));
        $suffixPath = trim((string)($this->option('in') ?? ''), '/\\');
        $propsOpt = (string)($this->option('props') ?? '');
        $noTest = $this->option('no-test') === true;
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $fs = app(Filesystem::class);
        $manifest = Manifest::begin($fs);

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException("Module not found: {$module}");
        }

        $dtoDir = $suffixPath === ''
            ? "{$moduleRoot}/Domain/DTO"
            : "{$moduleRoot}/Domain/DTO/{$suffixPath}";

        $phpPath = "{$dtoDir}/{$class}.php";

        $namespaceSuffix = $suffixPath === ''
            ? ''
            : '\\' . str_replace('/', '\\', str_replace('\\', '/', $suffixPath));

        $props = $this->parseProps($propsOpt);

        $imports = $this->collectImports($props);
        $importsBlock = $imports === [] ? '' : implode(PHP_EOL, $imports) . PHP_EOL;

        $ctorParams = $this->buildCtorParams($props);
        $getters = $this->buildGetters($props);

        $renderer = app(StubRenderer::class);
        $stubVars = [
            'module' => $module,
            'namespaceSuffix' => $namespaceSuffix,
            'imports' => $importsBlock,
            'class' => $class,
            'ctorParams' => $ctorParams,
            'getters' => $getters,
        ];

        $this->twoColumn('Module', $module);
        $this->twoColumn('DTO', $class);
        $this->twoColumn('Path', $this->rel($phpPath));
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');

        if (!$dry) {
            if (!is_dir($dtoDir)) {
                $fs->ensureDirectoryExists($dtoDir);
                $manifest->trackMkdir($this->rel($dtoDir));
            }

            if (!$force && is_file($phpPath)) {
                throw new RuntimeException('File already exists: ' . $this->rel($phpPath) . ' (use --force to overwrite)');
            }

            $code = $renderer->render('ddd-lite/dto.stub', $stubVars);
            $fs->put($phpPath, $code);
            $manifest->trackCreate($this->rel($phpPath));
        }

        if (!$noTest) {
            $testsDir = "{$moduleRoot}/tests/Unit/Domain/DTO";
            $testPath = "{$testsDir}/{$class}Test.php";

            $testImports = $this->buildTestImports($props);
            $ctorArrange = $this->buildTestArrange($props);
            $ctorArgs = $this->buildTestArgs($props);
            $ctorAsserts = $this->buildTestAsserts($class, $props);

            $testVars = [
                'module' => $module,
                'namespaceSuffix' => $namespaceSuffix,
                'class' => $class,
                'testImports' => $testImports,
                'ctorArrange' => $ctorArrange,
                'ctorArgs' => $ctorArgs,
                'ctorAsserts' => $ctorAsserts,
            ];

            if (!$dry) {
                if (!is_dir($testsDir)) {
                    $fs->ensureDirectoryExists($testsDir);
                    $manifest->trackMkdir($this->rel($testsDir));
                }

                if ($force && !is_file($testPath)) {
                    $test = $renderer->render('ddd-lite/dto-test.stub', $testVars);
                    $fs->put($testPath, $test);
                    $manifest->trackCreate($this->rel($testPath));
                }
            }

            $this->twoColumn('Test', $this->rel($testPath));
        }

        $manifest->save();
        $this->info('DTO scaffold complete. Manifest: ' . $manifest->id());
        return self::SUCCESS;
    }

    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'Module name in PascalCase'],
            ['name', InputArgument::REQUIRED, 'DTO class name'],
        ];
    }

    /**
     * @return array<int, array{name: string, type: string, nullable: bool}>
     */
    private function parseProps(string $spec): array
    {
        if (trim($spec) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $spec) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $pair = explode(':', $chunk, 2);
            if (count($pair) !== 2) {
                throw new RuntimeException('Invalid --props entry: ' . $chunk);
            }
            [$name, $typeSpec] = $pair;
            $name = trim($name);
            $typeSpec = trim($typeSpec);

            $nullable = false;
            if (str_contains($typeSpec, '|nullable')) {
                $nullable = true;
                $typeSpec = str_replace('|nullable', '', $typeSpec);
            }

            $out[] = [
                'name' => $name,
                'type' => $typeSpec,
                'nullable' => $nullable,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{name: string, type: string, nullable: bool}> $props
     * @return array<int, string>
     */
    private function collectImports(array $props): array
    {
        $imports = [];
        foreach ($props as $p) {
            $type = $p['type'];
            if ($type === 'Ulid') {
                $imports[] = 'use Symfony\Component\Uid\Ulid;';
            } elseif (str_contains($type, '\\')) {
                $imports[] = 'use ' . $type . ';';
            }
        }
        return array_values(array_unique($imports));
    }

    /**
     * @param array<int, array{name: string, type: string, nullable: bool}> $props
     */
    private function buildCtorParams(array $props): string
    {
        if ($props === []) {
            return '    ';
        }

        $lines = [];
        foreach ($props as $p) {
            $type = $this->normalizeType($p['type']);
            $nullable = $p['nullable'] ? '?' : '';
            $lines[] = '        public ' . $nullable . $type . ' $' . $p['name'];
        }
        return implode(',' . PHP_EOL, $lines);
    }

    /**
     * @param array<int, array{name: string, type: string, nullable: bool}> $props
     */
    private function buildGetters(array $props): string
    {
        if ($props === []) {
            return '';
        }

        $lines = [];
        foreach ($props as $p) {
            $type = $this->normalizeType($p['type']);
            $nullable = $p['nullable'] ? '?' : '';
            $method = 'get' . Str::studly($p['name']);
            $lines[] = '    public function ' . $method . '(): ' . $nullable . $type . PHP_EOL
                . '    {' . PHP_EOL
                . '        return $this->' . $p['name'] . ';' . PHP_EOL
                . '    }';
        }
        return implode(PHP_EOL . PHP_EOL, $lines) . PHP_EOL;
    }

    private function normalizeType(string $t): string
    {
        return match ($t) {
            'int', 'string', 'bool', 'float', 'array', 'object', 'mixed', 'callable' => $t,
            'Ulid' => 'Ulid',
            default => str_contains($t, '\\') ? class_basename($t) : $t,
        };
    }

    /**
     * @param array<int, array{name: string, type: string, nullable: bool}> $props
     */
    private function buildTestImports(array $props): string
    {
        $imports = [];
        foreach ($props as $p) {
            if ($p['type'] === 'Ulid') {
                $imports[] = 'use Symfony\Component\Uid\Ulid;';
            } elseif (str_contains($p['type'], '\\')) {
                $imports[] = 'use ' . $p['type'] . ';';
            }
        }
        $imports = array_values(array_unique($imports));
        return $imports === [] ? '' : implode(PHP_EOL, $imports) . PHP_EOL;
    }

    /**
     * @param array<int, array{name: string, type: string, nullable: bool}> $props
     */
    private function buildTestArrange(array $props): string
    {
        if ($props === []) {
            return '    // no props' . PHP_EOL;
        }

        $lines = [];
        foreach ($props as $p) {
            $name = $p['name'];
            $lines[] = '    $' . $name . ' = ' . $this->exampleValue($p['type'], $p['nullable']) . ';';
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<int, array{name: string, type: string, nullable: bool}> $props
     */
    private function buildTestArgs(array $props): string
    {
        if ($props === []) {
            return '    ';
        }
        $lines = [];
        foreach ($props as $p) {
            $lines[] = '        $' . $p['name'];
        }
        return implode(',' . PHP_EOL, $lines);
    }

    /**
     * @param array<int, array{name: string, type: string, nullable: bool}> $props
     */
    private function buildTestAsserts(string $class, array $props): string
    {
        if ($props === []) {
            return '    expect($dto)->toBeInstanceOf(' . $class . '::class);' . PHP_EOL;
        }

        $lines = ['    expect($dto)->toBeInstanceOf(' . $class . '::class);'];
        foreach ($props as $p) {
            $m = 'get' . Str::studly($p['name']);
            $lines[] = '    expect($dto->' . $m . '())->toBe($' . $p['name'] . ');';
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function exampleValue(string $type, bool $nullable): string
    {
        if ($nullable) {
            return 'null';
        }

        return match ($type) {
            'int' => '123',
            'string' => "'demo'",
            'bool' => 'true',
            'float' => '1.23',
            'array' => '[]',
            'Ulid' => 'new Ulid()',
            default => (str_contains($type, '\\')
                ? 'app(' . class_basename($type) . '::class)'
                : 'null'),
        };
    }
}
