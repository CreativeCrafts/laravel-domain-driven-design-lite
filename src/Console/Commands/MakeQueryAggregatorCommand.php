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
use function dirname;

final class MakeQueryAggregatorCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:aggregator
        {module? : Module name in PascalCase}
        {name? : Base name without suffix}
        {--no-test : Do not create a test}
        {--force : Overwrite if exists}
        {--dry-run : Preview only}
        {--rollback= : Rollback by manifest id}';

    protected $description = 'Generate a Query Aggregator inside a module (modules/<Module>/Domain/Aggregators).';

    /**
     * @throws RandomException
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $rollback = $this->getStringOption('rollback');
        if ($rollback !== null) {
            $m = $this->loadManifestOrFail($rollback);
            $m->rollback();
            $this->info('Rollback complete: ' . $rollback);
            return self::SUCCESS;
        }

        $module = Str::studly($this->getStringArgument('module'));
        $base = Str::studly($this->getStringArgument('name'));
        $class = $base . 'Aggregator';
        $noTest = $this->option('no-test') === true;

        $manifest = $this->beginManifest();

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException('Module not found: ' . $module);
        }

        $ns = "Modules\\{$module}\\Domain\\Aggregators";
        $dir = "{$moduleRoot}/Domain/Aggregators";
        $path = "{$dir}/{$class}.php";
        $testsDir = "{$moduleRoot}/tests/Unit/Domain/Aggregators";
        $testPath = "{$testsDir}/{$class}Test.php";

        $this->twoColumn('Module', $module);
        $this->twoColumn('Class', "{$ns}\\{$class}");
        $this->twoColumn('Path', $this->rel($path));
        $this->twoColumn('Dry run', $this->option('dry-run') ? 'yes' : 'no');
        if (!$noTest) {
            $this->twoColumn('Test', $this->rel($testPath));
        }

        $code = $this->render('ddd-lite/query.aggregator.stub', [
            'module' => $module,
            'class' => $class,
        ]);

        if ($this->option('dry-run')) {
            $this->line('Preview complete. No changes written.');
            return self::SUCCESS;
        }

        $fs = $this->files;
        $force = $this->option('force') === true;

        if (!is_dir($dir)) {
            $fs->ensureDirectoryExists($dir);
            $manifest->trackMkdir($this->rel($dir));
        }

        if (!$force && $fs->exists($path)) {
            $current = (string)$fs->get($path);
            if ($current === $code) {
                $this->info('No changes detected.');
                return self::SUCCESS;
            }
            $this->error('Aggregator already exists. Use --force to overwrite.');
            return self::FAILURE;
        }

        if ($fs->exists($path)) {
            $relativePath = $this->rel($path);
            $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($relativePath) . '.bak');
            $fs->ensureDirectoryExists(dirname($backup));
            $fs->put($backup, (string)$fs->get($path));
            $manifest->trackUpdate($relativePath, $this->rel($backup));
        } else {
            $manifest->trackCreate($this->rel($path));
        }

        try {
            $fs->put($path, $code);

            if (!$noTest) {
                if (!is_dir($testsDir)) {
                    $fs->ensureDirectoryExists($testsDir);
                    $manifest->trackMkdir($this->rel($testsDir));
                }

                $testCode = $this->render('ddd-lite/query-aggregator-test.stub', [
                    'module' => $module,
                    'class' => $class,
                ]);

                if ($fs->exists($testPath) && !$force) {
                    // keep existing test
                } elseif ($fs->exists($testPath)) {
                    $relativeTestPath = $this->rel($testPath);
                    $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($relativeTestPath) . '.bak');
                    $fs->ensureDirectoryExists(dirname($backup));
                    $fs->put($backup, (string)$fs->get($testPath));
                    $manifest->trackUpdate($relativeTestPath, $this->rel($backup));
                    $fs->put($testPath, $testCode);
                } else {
                    $fs->put($testPath, $testCode);
                    $manifest->trackCreate($this->rel($testPath));
                }
            }

            $manifest->save();

            $this->info('Aggregator created. Manifest: ' . $manifest->id());
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            try {
                $manifest->save();
                $manifest->rollback();
            } catch (Throwable) {
            }
            return self::FAILURE;
        }
    }
}
