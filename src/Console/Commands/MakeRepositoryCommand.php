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

final class MakeRepositoryCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:repository
        {module : Module name in PascalCase}
        {aggregate : Aggregate root name in PascalCase}
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

        $rollback = (string)($this->option('rollback') ?? '');
        if ($rollback !== '') {
            $m = $this->loadManifestOrFail($rollback);
            $m->rollback();
            $this->info("Rollback complete for {$rollback}.");
            return self::SUCCESS;
        }

        $module = Str::studly((string)$this->argument('module'));
        $aggregate = Str::studly((string)$this->argument('aggregate'));
        $noTest = $this->option('no-test') === true;
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $fs = app(Filesystem::class);
        $manifest = Manifest::begin($fs);

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException("Module not found: {$module}");
        }

        $reposDir = "{$moduleRoot}/App/Repositories";
        $phpPath = "{$reposDir}/Eloquent{$aggregate}Repository.php";
        $testsDir = "{$moduleRoot}/tests/Unit/App/Repositories";
        $testPath = "{$testsDir}/Eloquent{$aggregate}RepositoryTest.php";

        $renderer = app(StubRenderer::class);

        $stubVars = [
            'module' => $module,
            'aggregate' => $aggregate,
        ];

        $this->twoColumn('Module', $module);
        $this->twoColumn('Repository', "Eloquent{$aggregate}Repository");
        $this->twoColumn('Path', $this->rel($phpPath));
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');

        if (!$dry) {
            if (!is_dir($reposDir)) {
                $fs->ensureDirectoryExists($reposDir);
                $manifest->trackMkdir($this->rel($reposDir));
            }

            if (!$force && is_file($phpPath)) {
                throw new RuntimeException('File already exists: ' . $this->rel($phpPath) . ' (use --force to overwrite)');
            }

            $code = $renderer->render('ddd-lite/repository-eloquent.stub', $stubVars);
            $fs->put($phpPath, $code);
            $manifest->trackCreate($this->rel($phpPath));
        }

        if (!$noTest) {
            if (!$dry) {
                if (!is_dir($testsDir)) {
                    $fs->ensureDirectoryExists($testsDir);
                    $manifest->trackMkdir($this->rel($testsDir));
                }

                if ($force && !is_file($testPath)) {
                    $test = $renderer->render('ddd-lite/repository-test.stub', $stubVars);
                    $fs->put($testPath, $test);
                    $manifest->trackCreate($this->rel($testPath));
                }
            }
            $this->twoColumn('Test', $this->rel($testPath));
        }

        $manifest->save();
        $this->info('Repository scaffold complete. Manifest: ' . $manifest->id());
        return self::SUCCESS;
    }

    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'Module name in PascalCase'],
            ['aggregate', InputArgument::REQUIRED, 'Aggregate root name in PascalCase'],
        ];
    }
}
