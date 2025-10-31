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

final class MakeActionCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:action
        {module : Module name in PascalCase}
        {name : Action class base name without suffix}
        {--in= : Optional subnamespace inside Domain/Actions, e.g. Trip}
        {--method=__invoke : Method name}
        {--input= : Parameter type preset: none|ulid|FQCN}
        {--param=id : Parameter variable name when using --input}
        {--returns=void : Return type: void|ulid|FQCN}
        {--no-test : Do not create a test}
        {--dry-run : Preview without writing}
        {--force : Overwrite existing files}
        {--rollback= : Rollback a previous make run via manifest id}';

    protected $description = 'Scaffold a pure Domain Action in DDD-lite (Modules/<Module>/Domain/Actions).';

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
        $method = (string)($this->option('method') ?? '__invoke');
        $inputOpt = (string)($this->option('input') ?? '');
        $paramName = (string)($this->option('param') ?? 'id');
        $returnsOpt = (string)($this->option('returns') ?? 'void');
        $noTest = $this->option('no-test') === true;
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $fs = app(Filesystem::class);
        $manifest = Manifest::begin($fs);

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException("Module not found: {$module}");
        }

        $actionsDir = $suffixPath === ''
            ? "{$moduleRoot}/Domain/Actions"
            : "{$moduleRoot}/Domain/Actions/{$suffixPath}";

        $class = Str::studly($base) . 'Action';
        $phpPath = "{$actionsDir}/{$class}.php";

        $namespaceSuffix = $suffixPath === ''
            ? ''
            : '\\' . str_replace('/', '\\', str_replace('\\', '/', $suffixPath));

        $imports = [];
        $params = '';
        $args = '';

        if ($inputOpt !== '') {
            if ($inputOpt === 'ulid') {
                $imports[] = 'use Symfony\Component\Uid\Ulid;';
                $params = 'Ulid $' . $paramName;
                $args = "new Ulid('00000000000000000000000000')";
            } elseif (str_contains($inputOpt, '\\')) {
                $short = class_basename($inputOpt);
                $imports[] = 'use ' . $inputOpt . ';';
                $params = $short . ' $' . $paramName;
                $args = 'app(' . $short . '::class)';
            } else {
                throw new RuntimeException('Invalid --input. Use none|ulid|FQCN.');
            }
        }

        $returnType = 'void';
        $body = '';

        if ($returnsOpt !== '') {
            if ($returnsOpt === 'ulid') {
                $imports[] = 'use Symfony\Component\Uid\Ulid;';
                $returnType = 'Ulid';
                $body = 'return new Ulid();';
            } elseif (str_contains($returnsOpt, '\\')) {
                $short = class_basename($returnsOpt);
                $imports[] = 'use ' . $returnsOpt . ';';
                $returnType = $short;
                $body = 'return app(' . $short . '::class);';
            } else {
                throw new RuntimeException('Invalid --returns. Use void|ulid|FQCN.');
            }
        }

        $imports = array_values(array_unique($imports));
        $importsBlock = implode(PHP_EOL, $imports);
        if ($importsBlock !== '') {
            $importsBlock .= PHP_EOL;
        }

        $renderer = app(StubRenderer::class);
        $stubVars = [
            'module' => $module,
            'namespaceSuffix' => $namespaceSuffix,
            'imports' => $importsBlock,
            'class' => Str::studly($base),
            'method' => $method,
            'params' => $params,
            'returnType' => $returnType,
            'body' => $body,
            'args' => $args,
        ];

        $this->twoColumn('Module', $module);
        $this->twoColumn('Class', $class);
        $this->twoColumn('Path', $this->rel($phpPath));
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');

        if (!$dry) {
            if (!is_dir($actionsDir)) {
                $fs->ensureDirectoryExists($actionsDir);
                $manifest->trackMkdir($this->rel($actionsDir));
            }

            if (!$force && is_file($phpPath)) {
                throw new RuntimeException('File already exists: ' . $this->rel($phpPath) . ' (use --force to overwrite)');
            }

            $code = $renderer->render('ddd-lite/action.stub', $stubVars);
            $fs->put($phpPath, $code);
            $manifest->trackCreate($this->rel($phpPath));
        }

        if (!$noTest) {
            $testsDir = "{$moduleRoot}/tests/Unit/Domain/Actions";
            $testPath = "{$testsDir}/{$class}Test.php";

            if (!$dry) {
                if (!is_dir($testsDir)) {
                    $fs->ensureDirectoryExists($testsDir);
                    $manifest->trackMkdir($this->rel($testsDir));
                }

                if ($force || !is_file($testPath)) {
                    $test = $renderer->render('ddd-lite/action-test.stub', $stubVars);
                    $fs->put($testPath, $test);
                    $manifest->trackCreate($this->rel($testPath));
                }
            }

            $this->twoColumn('Test', $this->rel($testPath));
        }

        $manifest->save();
        $this->info('Action scaffold complete. Manifest: ' . $manifest->id());
        return self::SUCCESS;
    }

    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'Module name in PascalCase'],
            ['name', InputArgument::REQUIRED, 'Action class base name without suffix'],
        ];
    }
}
