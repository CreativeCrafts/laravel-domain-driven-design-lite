<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;
use Throwable;

final class MakeQueryBuilderCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:query-builder
        {module : Module name (e.g. Planner)}
        {name : Base name without suffix (e.g. Trip)}
        {--no-test : Do not create a test}
        {--force : Overwrite existing file if contents differ}
        {--dry-run : Show the plan without writing files}
        {--rollback= : Rollback by manifest id}';

    protected $description = 'Scaffold a typed Eloquent Query Builder class inside a module';

    /**
     * @throws RandomException
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $id = $this->getStringOption('rollback');
        if ($id !== null) {
            $manifest = $this->loadManifestOrFail($id);
            $manifest->rollback();
            $this->components->info("Rolled back manifest: {$id}");

            return self::SUCCESS;
        }

        $module = Str::studly($this->getStringArgument('module'));
        if ($module === '') {
            throw new RuntimeException('Module is required.');
        }

        $base = Str::studly($this->getStringArgument('name'));
        if ($base === '') {
            throw new RuntimeException('Name is required.');
        }

        $noTest = $this->option('no-test') === true;
        $class = "{$base}QueryBuilder";
        $relativeDir = "modules/{$module}/Domain/Builders";
        $relativePath = "{$relativeDir}/{$class}.php";
        $absPath = base_path($relativePath);
        $testsDir = "modules/{$module}/tests/Unit/Domain/Builders";
        $testRelPath = "{$testsDir}/{$class}Test.php";
        $testAbsPath = base_path($testRelPath);

        $vars = [
            'module' => $module,
            'class' => $class,
        ];

        $contents = $this->render('ddd-lite/query.builder.stub', $vars);

        $this->twoColumn('Module', $module);
        $this->twoColumn('Class', $class);
        $this->twoColumn('Target', $this->rel($absPath));
        if (!$noTest) {
            $this->twoColumn('Test', $this->rel($testAbsPath));
        }

        $isDryRun = (bool)$this->option('dry-run');
        $force = (bool)$this->option('force');

        if ($isDryRun) {
            $this->components->info('Dry run: no files will be written.');

            return self::SUCCESS;
        }

        $dir = dirname($absPath);
        $dirExisted = $this->files->isDirectory($dir);
        $this->files->ensureDirectoryExists($dir);

        if ($this->files->exists($absPath)) {
            $current = (string)$this->files->get($absPath);

            if ($this->canon($current) === $this->canon($contents)) {
                $this->components->info('No changes detected. The file is already up to date.');

                return self::SUCCESS;
            }

            if (!$force) {
                $this->components->warn("Target exists and differs. Re-run with --force to overwrite.\n{$this->rel($absPath)}");

                return self::FAILURE;
            }
        }

        $manifest = $this->beginManifest();

        try {
            if (!$dirExisted) {
                $manifest->trackMkdir($relativeDir);
            }

            if ($this->files->exists($absPath)) {
                $backup = 'storage/app/ddd-lite_scaffold/backups/' . sha1($relativePath) . '.bak';
                $this->files->ensureDirectoryExists(dirname(base_path($backup)));
                $this->files->put(base_path($backup), (string)$this->files->get($absPath));
                $this->files->put($absPath, $contents);
                $manifest->trackUpdate($relativePath, $backup);
            } else {
                $this->files->put($absPath, $contents);
                $manifest->trackCreate($relativePath);
            }

            if (!$noTest) {
                if (!$this->files->isDirectory(base_path($testsDir))) {
                    $this->files->ensureDirectoryExists(base_path($testsDir));
                    $manifest->trackMkdir($testsDir);
                }

                $testCode = $this->render('ddd-lite/query-builder-test.stub', [
                    'module' => $module,
                    'class' => $class,
                ]);

                if ($this->files->exists($testAbsPath) && !$force) {
                    // keep existing test
                } elseif ($this->files->exists($testAbsPath)) {
                    $backup = 'storage/app/ddd-lite_scaffold/backups/' . sha1($testRelPath) . '.bak';
                    $this->files->ensureDirectoryExists(dirname(base_path($backup)));
                    $this->files->put(base_path($backup), (string)$this->files->get($testAbsPath));
                    $this->files->put($testAbsPath, $testCode);
                    $manifest->trackUpdate($testRelPath, $backup);
                } else {
                    $this->files->put($testAbsPath, $testCode);
                    $manifest->trackCreate($testRelPath);
                }
            }

            $manifest->save();

            $this->components->info('Query Builder created successfully.');
            $this->twoColumn('Manifest', $manifest->id());

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            try {
                $manifest->save();
                $manifest->rollback();
            } catch (Throwable) {
            }

            return self::FAILURE;
        }
    }

    private function canon(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);

        return rtrim($s);
    }
}
