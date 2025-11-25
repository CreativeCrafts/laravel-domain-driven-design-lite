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

final class MakeAggregateRootCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:aggregate-root
        {module : Module name in PascalCase (e.g., Planner)}
        {name : Aggregate Root base name in PascalCase (e.g., Trip)}
        {--dry-run : Preview actions without writing}
        {--force : Overwrite existing files if they exist}
        {--rollback= : Rollback a previous run using its manifest id}';

    protected $description = 'Scaffold a Domain Aggregate Root (DDD-lite) inside a module (Domain/Aggregates).';

    /**
     * @return int
     * @throws RandomException
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        // 1. Rollback branch
        $rollbackOpt = $this->option('rollback');
        if (is_string($rollbackOpt) && $rollbackOpt !== '') {
            $m = $this->loadManifestOrFail($rollbackOpt);
            $m->rollback();
            $this->info('Rollback complete: ' . $rollbackOpt);
            $this->successBox('Rollback completed successfully.');
            return self::SUCCESS;
        }

        // 2. Gather and normalize inputs
        $module = Str::studly((string)$this->argument('module'));
        $name = Str::studly((string)$this->argument('name'));
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException("Module not found: {$module}. Did you run ddd-lite:module:scaffold?");
        }

        $aggregatesRootRel = "modules/{$module}/Domain/Aggregates";
        $aggregateNamespaceSegment = $name;

        $aggregateDirRel = "{$aggregatesRootRel}/{$aggregateNamespaceSegment}";
        $aggregateFileRel = "{$aggregateDirRel}/{$name}.php";

        $aggregateDirAbs = base_path($aggregateDirRel);
        $aggregateFileAbs = base_path($aggregateFileRel);

        $internalDirRel = "{$aggregateDirRel}/Internal";
        $internalDirAbs = base_path($internalDirRel);

        // 3. UX summary
        $this->summary('Aggregate Root scaffold plan', [
            'Module' => $module,
            'Aggregate' => $name,
            'Namespace' => "Modules\\{$module}\\Domain\\Aggregates\\{$aggregateNamespaceSegment}",
            'Target' => $aggregateFileRel,
            'Dry run' => $dry ? 'yes' : 'no',
            'Force overwrite' => $force ? 'yes' : 'no',
        ]);

        if ($dry) {
            $this->warnBox('Dry-run: no files will be written.');
            return self::SUCCESS;
        }

        // 4. Render stub
        $code = $this->render('ddd-lite/aggregate-root.stub', [
            'Module' => $module,
            'Name' => $name,
        ]);

        // 5. Manifest-backed write operations
        $manifest = $this->beginManifest();

        try {
            // Ensure the aggregate directory exists
            if (!is_dir($aggregateDirAbs)) {
                $this->files->ensureDirectoryExists($aggregateDirAbs);
                $manifest->trackMkdir($this->rel($aggregateDirAbs));
            }

            // Ensure Internal/ directory exists (for child entities / VOs)
            if (!is_dir($internalDirAbs)) {
                $this->files->ensureDirectoryExists($internalDirAbs);
                $manifest->trackMkdir($this->rel($internalDirAbs));
            }

            $exists = $this->files->exists($aggregateFileAbs);

            if ($exists && !$force) {
                $current = (string)$this->files->get($aggregateFileAbs);
                if ($current === $code) {
                    $this->info('No changes detected. File already matches generated output.');
                    // No file system changes: do not save manifest
                    return self::SUCCESS;
                }

                $this->warn('File already exists. Use --force to overwrite.');
                return self::FAILURE;
            }

            if ($exists) {
                $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($aggregateFileAbs) . '.bak');
                $this->files->ensureDirectoryExists(dirname($backup));
                $this->files->put($backup, (string)$this->files->get($aggregateFileAbs));
                $this->files->put($aggregateFileAbs, $code);

                $manifest->trackUpdate($this->rel($aggregateFileAbs), $this->rel($backup));
            } else {
                $this->files->put($aggregateFileAbs, $code);
                $manifest->trackCreate($this->rel($aggregateFileAbs));
            }

            $manifest->save();

            $this->successBox("Aggregate Root {$name} created in module {$module}.");
            $this->line('Path: ' . $this->rel($aggregateFileRel));
            $this->line('Manifest: ' . $manifest->id());

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Ensure manifest is persisted before rollback
            $this->error('Error: ' . $e->getMessage());
            $manifest->save();
            $this->warn('Rolling back (' . $manifest->id() . ') ...');
            $manifest->rollback();
            $this->info('Rollback complete.');

            return self::FAILURE;
        }
    }
}
