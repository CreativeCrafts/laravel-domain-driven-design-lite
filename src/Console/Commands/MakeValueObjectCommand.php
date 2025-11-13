<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use Throwable;

final class MakeValueObjectCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:value-object
        {module : Module name in PascalCase (e.g., Planner)}
        {name : Value Object class name in PascalCase (e.g., Email)}
        {--scalar=string : Backing scalar type: string|int|float|bool}
        {--dry-run : Preview actions without writing}
        {--rollback= : Rollback a previous run using its manifest id}
        {--force : Overwrite existing files if they exist}';

    protected $description = 'Scaffold a Domain Value Object (immutable, equality-based) inside a module.';

    /**
     * @return int
     * @throws RandomException
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $rollbackOpt = $this->option('rollback');
        if (is_string($rollbackOpt) && $rollbackOpt !== '') {
            $m = $this->loadManifestOrFail($rollbackOpt);
            $m->rollback();
            $this->info('Rollback complete: ' . $rollbackOpt);
            $this->successBox('Rollback completed successfully.');
            return self::SUCCESS;
        }

        $module = Str::studly((string)$this->argument('module'));
        $name = Str::studly((string)$this->argument('name'));
        $scalar = (string)$this->option('scalar');
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $allowed = ['string', 'int', 'float', 'bool'];
        if (!in_array($scalar, $allowed, true)) {
            $this->error("Invalid --scalar={$scalar}. Allowed: " . implode('|', $allowed));
            return self::FAILURE;
        }

        $targetRelDir = "modules/{$module}/Domain/ValueObjects";
        $targetRelPath = "{$targetRelDir}/{$name}.php";
        $targetAbsPath = base_path($targetRelPath);

        $this->summary('Value Object scaffold plan', [
            'Module' => $module,
            'Class' => $name,
            'Scalar' => $scalar,
            'Target' => $targetRelPath,
            'Dry run' => $dry ? 'yes' : 'no',
            'Force overwrite' => $force ? 'yes' : 'no',
        ]);

        if ($dry) {
            $this->warnBox('Dry-run: no files will be written.');
            return self::SUCCESS;
        }

        $rendered = $this->render('ddd-lite/value-object.stub', [
            'Module' => $module,
            'Name' => $name,
            'Scalar' => $scalar,
        ]);

        if ($this->files->exists($targetAbsPath)) {
            $current = (string)$this->files->get($targetAbsPath);

            if ($current === $rendered) {
                $this->info('No changes detected. The file is already up-to-date.');
                return self::SUCCESS;
            }

            if (!$force) {
                $this->warn("Target file already exists and differs: {$this->rel($targetRelPath)}");
                $this->line('Re-run with --force to overwrite.');
                return self::FAILURE;
            }
        }

        $manifest = $this->beginManifest();

        try {
            $this->safe->ensureDirTracked($manifest, $targetRelDir);

            $this->safe->writeNew($manifest, $targetRelPath, $rendered, $force);

            $manifest->save();

            $this->successBox("Value Object {$name} created in module {$module}.");
            $this->line('Path: ' . $this->rel($targetRelPath));
            $this->line('Manifest: ' . $manifest->id());
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            $manifest->save();
            $this->warn('Rolling back (' . $manifest->id() . ') ...');
            $manifest->rollback();
            $this->info('Rollback complete.');
            return self::FAILURE;
        }
    }
}
