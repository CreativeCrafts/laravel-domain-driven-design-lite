<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;

final class MakeRepositoryCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:repository
        {module? : Module name in PascalCase}
        {aggregate? : Aggregate root name in PascalCase}
        {--no-test : Do not create a test}
        {--dry-run : Preview without writing}
        {--force : Overwrite existing files}
        {--rollback= : Rollback a previous make run via manifest id}';

    protected $description = 'Scaffold an Eloquent repository at the module edge that implements the Domain contract.';

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
        $aggregate = Str::studly($this->getStringArgument('aggregate'));

        if ($module === '' || $aggregate === '') {
            $this->error('Arguments "module" and "aggregate" are required.');
            return self::FAILURE;
        }

        $noTest = $this->option('no-test') === true;
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException('Module not found: ' . $module);
        }

        $reposDir = "{$moduleRoot}/App/Repositories";
        $phpPath = "{$reposDir}/Eloquent{$aggregate}Repository.php";

        $testsDir = "{$moduleRoot}/tests/Unit/App/Repositories";
        $testPath = "{$testsDir}/Eloquent{$aggregate}RepositoryTest.php";

        $this->twoColumn('Module', $module);
        $this->twoColumn('Repository', "Eloquent{$aggregate}Repository");
        $this->twoColumn('Path', $this->rel($phpPath));
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');
        if (!$noTest) {
            $this->twoColumn('Test', $this->rel($testPath));
        }

        if ($dry) {
            $this->line('Preview complete. No changes written.');
            return self::SUCCESS;
        }

        $manifest = $this->beginManifest();

        if (!is_dir($reposDir)) {
            $this->files->ensureDirectoryExists($reposDir);
            $manifest->trackMkdir($this->rel($reposDir));
        }

        $repoCode = $this->render('ddd-lite/repository-eloquent.stub', [
            'module' => $module,
            'aggregate' => $aggregate,
        ]);

        if ($this->files->exists($phpPath)) {
            if (!$force) {
                $this->error('File already exists: ' . $this->rel($phpPath) . ' (use --force to overwrite)');
                return self::FAILURE;
            }
            $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($phpPath) . '.bak');
            $this->files->ensureDirectoryExists(dirname($backup));
            $this->files->put($backup, (string)$this->files->get($phpPath));
            $this->files->put($phpPath, $repoCode);
            $manifest->trackUpdate($this->rel($phpPath), $backup);
        } else {
            $this->files->put($phpPath, $repoCode);
            $manifest->trackCreate($this->rel($phpPath));
        }

        if (!$noTest) {
            if (!is_dir($testsDir)) {
                $this->files->ensureDirectoryExists($testsDir);
                $manifest->trackMkdir($this->rel($testsDir));
            }

            $shouldWriteTest = true;
            if ($this->files->exists($testPath) && !$force) {
                $shouldWriteTest = false;
            }

            if ($shouldWriteTest) {
                $testCode = $this->render('ddd-lite/repository-test.stub', [
                    'module' => $module,
                    'aggregate' => $aggregate,
                ]);

                if ($this->files->exists($testPath)) {
                    $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($testPath) . '.bak');
                    $this->files->ensureDirectoryExists(dirname($backup));
                    $this->files->put($backup, (string)$this->files->get($testPath));
                    $this->files->put($testPath, $testCode);
                    $manifest->trackUpdate($this->rel($testPath), $backup);
                } else {
                    $this->files->put($testPath, $testCode);
                    $manifest->trackCreate($this->rel($testPath));
                }
            }
        }

        $manifest->save();
        $this->info('Repository scaffold complete. Manifest: ' . $manifest->id());

        return self::SUCCESS;
    }
}
