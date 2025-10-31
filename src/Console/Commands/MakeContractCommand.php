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

final class MakeContractCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:contract
        {module : Module name in PascalCase}
        {name : Contract base name without "Contract" suffix}
        {--in= : Optional subnamespace inside Domain/Contracts, e.g. Trip}
        {--methods= : Semicolon-separated methods: name:ReturnType(params) e.g. "findById:TripData|null(id:Ulid); create:TripData(data:TripCreateData)"}
        {--with-fake : Generate a Fake implementation under tests/Unit/fakes}
        {--no-test : Do not create a contract test}
        {--dry-run : Preview without writing}
        {--force : Overwrite existing files}
        {--rollback= : Rollback a previous make run via manifest id}';

    protected $description = 'Scaffold a pure Domain Contract interface (Modules/<Module>/Domain/Contracts).';

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
        $base = Str::studly((string)$this->argument('name'));
        $suffixPath = trim((string)($this->option('in') ?? ''), '/\\');
        $methodsSpec = (string)($this->option('methods') ?? '');
        $withFake = $this->option('with-fake') === true;
        $noTest = $this->option('no-test') === true;
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $fs = app(Filesystem::class);
        $manifest = Manifest::begin($fs);

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException("Module not found: {$module}");
        }

        $contractsDir = $suffixPath === ''
            ? "{$moduleRoot}/Domain/Contracts"
            : "{$moduleRoot}/Domain/Contracts/{$suffixPath}";

        $class = Str::studly($base);
        $phpPath = "{$contractsDir}/{$class}Contract.php";

        $namespaceSuffix = $suffixPath === ''
            ? ''
            : '\\' . str_replace('/', '\\', str_replace('\\', '/', $suffixPath));

        $methods = $this->parseMethods($methodsSpec);

        $imports = $this->collectImports($methods);
        $importsBlock = $imports === [] ? '' : implode(PHP_EOL, $imports) . PHP_EOL;

        $methodsBlock = $this->renderMethods($methods);
        $fakeMethodsBlock = $withFake ? $this->renderFakeMethods($methods) : '';

        $renderer = app(StubRenderer::class);
        $stubVars = [
            'module' => $module,
            'namespaceSuffix' => $namespaceSuffix,
            'imports' => $importsBlock,
            'class' => $class,
            'methods' => $methodsBlock,
            'fakeMethods' => $fakeMethodsBlock,
        ];

        $this->twoColumn('Module', $module);
        $this->twoColumn('Contract', "{$class}Contract");
        $this->twoColumn('Path', $this->rel($phpPath));
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');

        if (!$dry) {
            if (!is_dir($contractsDir)) {
                $fs->ensureDirectoryExists($contractsDir);
                $manifest->trackMkdir($this->rel($contractsDir));
            }

            if (!$force && is_file($phpPath)) {
                throw new RuntimeException('File already exists: ' . $this->rel($phpPath) . ' (use --force to overwrite)');
            }

            $code = $renderer->render('ddd-lite/contract.stub', $stubVars);
            $fs->put($phpPath, $code);
            $manifest->trackCreate($this->rel($phpPath));
        }

        if ($withFake) {
            $fakesDir = "{$moduleRoot}/tests/Unit/fakes";
            $fakePath = "{$fakesDir}/{$class}Fake.php";
            if (!$dry) {
                if (!is_dir($fakesDir)) {
                    $fs->ensureDirectoryExists($fakesDir);
                    $manifest->trackMkdir($this->rel($fakesDir));
                }
                if ($force || !is_file($fakePath)) {
                    $fake = $renderer->render('ddd-lite/contract-fake.stub', $stubVars);
                    $fs->put($fakePath, $fake);
                    $manifest->trackCreate($this->rel($fakePath));
                }
            }
        }

        if (!$noTest) {
            $testsDir = "{$moduleRoot}/tests/Unit/Domain/Contracts";
            $testPath = "{$testsDir}/{$class}ContractTest.php";

            $testFakeImport = $withFake
                ? 'use Modules\\' . $module . '\\tests\\Unit\\fakes\\' . $class . 'Fake;'
                : '';

            $testImplements = $withFake
                ? '    $fake = new ' . $class . 'Fake();' . PHP_EOL
                . '    expect($fake)->toBeInstanceOf(' . $class . 'Contract::class);'
                : '    expect(true)->toBeTrue();';

            $testVars = [
                'module' => $module,
                'namespaceSuffix' => $namespaceSuffix,
                'class' => $class,
                'testFakeImport' => $testFakeImport,
                'testImplements' => $testImplements,
            ];

            if (!$dry) {
                if (!is_dir($testsDir)) {
                    $fs->ensureDirectoryExists($testsDir);
                    $manifest->trackMkdir($this->rel($testsDir));
                }
                if ($force || !is_file($testPath)) {
                    $test = $renderer->render('ddd-lite/contract-test.stub', $testVars);
                    $fs->put($testPath, $test);
                    $manifest->trackCreate($this->rel($testPath));
                }
            }

            $this->twoColumn('Test', $this->rel($testPath));
        }

        $manifest->save();
        $this->info('Contract scaffold complete. Manifest: ' . $manifest->id());
        return self::SUCCESS;
    }

    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'Module name in PascalCase'],
            ['name', InputArgument::REQUIRED, 'Contract base name'],
        ];
    }

    /**
     * @return array<int, array{name: string, returns: string, params: array<int, array{name: string, type: string}>}>
     */
    private function parseMethods(string $spec): array
    {
        if (trim($spec) === '') {
            return [];
        }

        $methods = [];
        foreach (explode(';', $spec) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $colon = strpos($raw, ':');
            if ($colon === false) {
                throw new RuntimeException('Invalid method spec (missing ":"): ' . $raw);
            }

            $name = trim(substr($raw, 0, $colon));
            $tail = trim(substr($raw, $colon + 1));

            $parenOpen = strpos($tail, '(');
            $parenClose = strrpos($tail, ')');
            if ($parenOpen === false || $parenClose === false || $parenClose < $parenOpen) {
                throw new RuntimeException('Invalid method signature parentheses: ' . $raw);
            }

            $returns = trim(substr($tail, 0, $parenOpen));
            $paramList = trim(substr($tail, $parenOpen + 1, $parenClose - $parenOpen - 1));

            $params = [];
            if ($paramList !== '') {
                foreach (explode(',', $paramList) as $praw) {
                    $praw = trim($praw);
                    if ($praw === '') {
                        continue;
                    }
                    $pcolon = strpos($praw, ':');
                    if ($pcolon === false) {
                        throw new RuntimeException('Invalid param spec (expected "name:Type"): ' . $praw);
                    }
                    $pname = trim(substr($praw, 0, $pcolon));
                    $ptype = trim(substr($praw, $pcolon + 1));
                    $params[] = ['name' => $pname, 'type' => $ptype];
                }
            }

            $methods[] = [
                'name' => $name,
                'returns' => $returns,
                'params' => $params,
            ];
        }

        return $methods;
    }

    /**
     * @param array<int, array{name: string, returns: string, params: array<int, array{name: string, type: string}>}> $methods
     * @return array<int, string>
     */
    private function collectImports(array $methods): array
    {
        $imports = [];
        $collect = static function (string $type) use (&$imports): void {
            $nullableType = ltrim($type, '?');
            if ($nullableType === 'Ulid') {
                $imports[] = 'use Symfony\Component\Uid\Ulid;';
            } elseif (str_contains($nullableType, '\\')) {
                $imports[] = 'use ' . $nullableType . ';';
            }
        };

        foreach ($methods as $m) {
            $retUnion = array_map('trim', explode('|', $m['returns']));
            foreach ($retUnion as $rt) {
                if ($rt !== 'null' && $rt !== 'void' && $rt !== '') {
                    $collect($rt);
                }
            }
            foreach ($m['params'] as $p) {
                $collect($p['type']);
            }
        }

        return array_values(array_unique($imports));
    }

    /**
     * @param array<int, array{name: string, returns: string, params: array<int, array{name: string, type: string}>}> $methods
     */
    private function renderMethods(array $methods): string
    {
        if ($methods === []) {
            return '    ';
        }

        $lines = [];
        foreach ($methods as $m) {
            $name = $m['name'];
            $returns = $this->renderReturnType($m['returns']);
            $params = $this->renderParams($m['params']);
            $lines[] = '    public function ' . $name . '(' . $params . '): ' . $returns . ';';
        }
        return implode(PHP_EOL . PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<int, array{name: string, type: string}> $params
     */
    private function renderParams(array $params): string
    {
        if ($params === []) {
            return '';
        }
        $items = [];
        foreach ($params as $p) {
            $type = $this->normalizeType($p['type']);
            $items[] = $type . ' $' . $p['name'];
        }
        return implode(', ', $items);
    }

    private function renderReturnType(string $returns): string
    {
        $returns = trim($returns);
        if ($returns === '') {
            return 'void';
        }
        $parts = array_map('trim', explode('|', $returns));
        $mapped = [];
        foreach ($parts as $p) {
            $mapped[] = $p === 'null' ? 'null' : $this->normalizeType($p);
        }
        return implode('|', $mapped);
    }

    private function normalizeType(string $t): string
    {
        $t = ltrim($t, '?');
        return match ($t) {
            'int', 'string', 'bool', 'float', 'array', 'object', 'mixed', 'callable', 'void', 'null' => $t,
            'Ulid' => 'Ulid',
            default => str_contains($t, '\\') ? class_basename($t) : $t,
        };
    }

    /**
     * @param array<int, array{name: string, returns: string, params: array<int, array{name: string, type: string}>}> $methods
     */
    private function renderFakeMethods(array $methods): string
    {
        if ($methods === []) {
            return '    ';
        }

        $lines = [];
        foreach ($methods as $m) {
            $name = $m['name'];
            $returns = $this->renderReturnType($m['returns']);
            $params = $this->renderParams($m['params']);
            $retStmt = $this->fakeReturn($m['returns']);
            $lines[] = '    public function ' . $name . '(' . $params . '): ' . $returns . PHP_EOL
                . '    {' . PHP_EOL
                . '        ' . $retStmt . PHP_EOL
                . '    }';
        }
        return implode(PHP_EOL . PHP_EOL, $lines) . PHP_EOL;
    }

    private function fakeReturn(string $returns): string
    {
        $returns = trim($returns);
        if ($returns === '' || $returns === 'void') {
            return '';
        }

        $parts = array_map('trim', explode('|', $returns));
        $first = $parts[0];

        return match ($first) {
            'null' => 'return null;',
            'int' => 'return 0;',
            'string' => "return '';",
            'bool' => 'return false;',
            'float' => 'return 0.0;',
            'array' => 'return [];',
            'Ulid' => 'return new Ulid();',
            default => str_contains($first, '\\') || ctype_upper(substr($first, 0, 1))
                ? 'return app(' . class_basename($first) . '::class);'
                : 'return null;',
        };
    }
}
