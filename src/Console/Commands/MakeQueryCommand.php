<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;

final class MakeQueryCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:query
        {module : The target module name (e.g. Planner)}
        {name : The query class base name (e.g. TripIndexQuery)}
        {--no-test : Do not create a test}
        {--force : Overwrite the query if it already exists}
        {--dry-run : Print what would happen without writing files}
        {--rollback= : Rollback a previous run by manifest id}';

    protected $description = 'Scaffold a domain query class in Modules/[Module]/Domain/Queries';

    /**
     * @throws RandomException
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function handle(Filesystem $files): int
    {
        $this->prepare();

        $module = Str::studly($this->getStringArgument('module'));
        $name = Str::studly($this->getStringArgument('name'));
        $noTest = $this->option('no-test') === true;
        $force = (bool)$this->option('force');
        $dryRun = (bool)$this->option('dry-run');
        $rollbackId = $this->getStringOption('rollback');

        if ($rollbackId !== null) {
            $manifest = $this->loadManifestOrFail($rollbackId);

            $this->withProgress(1, static function (ProgressBar $bar) use ($manifest): void {
                $bar->setMessage('Rolling back query scaffold');
                $manifest->rollback();
                $bar->advance();
            });

            $this->successBox("Rollback complete for manifest {$rollbackId}");

            return self::SUCCESS;
        }

        $moduleRoot = base_path("modules/{$module}");
        $queryNamespace = "Modules\\{$module}\\Domain\\Queries";
        $queryPath = base_path("modules/{$module}/Domain/Queries/{$name}.php");
        $relativePath = $this->rel($queryPath);

        if (!is_dir($moduleRoot)) {
            throw new RuntimeException("Module not found: {$module}");
        }

        $this->summary('ddd-lite:make:query', [
            'Module' => $module,
            'Name' => $name,
            'Namespace' => $queryNamespace,
            'File' => $relativePath,
            'Force' => $force ? 'yes' : 'no',
            'Dry run' => $dryRun ? 'yes' : 'no',
            'Tests' => $noTest ? 'skipped' : 'generate',
        ]);

        $existingContent = $files->exists($queryPath)
            ? (string)$files->get($queryPath)
            : null;

        $stub = $this->render('ddd-lite/query.stub', [
            'module' => $module,
            'class' => $name,
            // keeping namespace available in case the stub also uses it
            'namespace' => $queryNamespace,
        ]);

        if ($existingContent !== null && $existingContent === $stub) {
            $this->info('No changes detected; query is already up to date.');

            return self::SUCCESS;
        }

        if ($existingContent !== null && !$force) {
            $this->warn('Query already exists and content differs. Use --force to overwrite.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line('');
            $this->info('[DRY RUN] No files were written.');
            $this->line($stub);

            return self::SUCCESS;
        }

        $manifest = $this->beginManifest();

        // Ensure the directory exists before writing.
        $dir = dirname($queryPath);
        if (!$files->isDirectory($dir)) {
            $files->ensureDirectoryExists($dir);
            $manifest->trackMkdir($this->rel($dir));
        }

        if ($existingContent === null) {
            $files->put($queryPath, $stub);
            $manifest->trackCreate($relativePath);
        } else {
            $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($relativePath) . '.bak');
            $files->ensureDirectoryExists(dirname($backup));
            $files->put($backup, $existingContent);

            $files->put($queryPath, $stub);
            $manifest->trackUpdate($relativePath, $this->rel($backup));
        }

        if (!$noTest) {
            $testsDir = base_path("modules/{$module}/tests/Unit/Domain/Queries");
            $testPath = $testsDir . "/{$name}Test.php";

            if (!$files->isDirectory($testsDir)) {
                $files->ensureDirectoryExists($testsDir);
                $manifest->trackMkdir($this->rel($testsDir));
            }

            $testCode = $this->render('ddd-lite/query-test.stub', [
                'module' => $module,
                'class' => $name,
            ]);

            if ($files->exists($testPath) && !$force) {
                // Keep existing test if not forcing.
            } elseif ($files->exists($testPath)) {
                $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($this->rel($testPath)) . '.bak');
                $files->ensureDirectoryExists(dirname($backup));
                $files->put($backup, (string)$files->get($testPath));
                $files->put($testPath, $testCode);
                $manifest->trackUpdate($this->rel($testPath), $this->rel($backup));
            } else {
                $files->put($testPath, $testCode);
                $manifest->trackCreate($this->rel($testPath));
            }
        }

        $manifest->save();

        $this->successBox("Query {$name} created at {$relativePath}. Manifest: {$manifest->id()}");

        return self::SUCCESS;
    }
}
