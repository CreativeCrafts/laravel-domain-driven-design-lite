<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;

final class MakeMigrationCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:migration
        {module? : Module name in PascalCase}
        {name? : Migration base name, e.g. create_trips_table or add_flags_to_trips_table}
        {--table= : Table name}
        {--create= : Shortcut for creating table}
        {--path= : Override module path for migrations (default: database/migrations)}
        {--force : Overwrite if file exists}
        {--dry-run : Preview without writing}
        {--rollback= : Rollback a previous run via manifest id}';

    protected $description = 'Generate a migration file inside a module (modules/<Module>/database/migrations).';

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
        $name = Str::snake($this->getStringArgument('name'));

        $tableOpt = $this->getStringOption('table') ?? '';
        $createOpt = $this->getStringOption('create') ?? '';
        $pathOpt = $this->getStringOption('path') ?? 'database/migrations';

        $isCreate = $createOpt !== '' || str_starts_with($name, 'create_') || str_contains($name, '_create_');
        $table = $tableOpt !== '' ? Str::snake($tableOpt) : $this->inferTableFromName($name, $isCreate);

        if ($table === '') {
            $this->error('Unable to infer table. Use --table=<name> or --create=<name>.');
            return self::FAILURE;
        }

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException('Module not found: ' . $module);
        }

        $dir = "{$moduleRoot}/" . trim($pathOpt, '/');
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $path = "{$dir}/{$filename}";

        $stub = $isCreate ? 'migration.create.stub' : 'migration.update.stub';
        $code = $this->render("ddd-lite/{$stub}", ['table' => $table]);

        $this->twoColumn('Module', $module);
        $this->twoColumn('Name', $name);
        $this->twoColumn('Table', $table);
        $this->twoColumn('Stub', $stub);
        $this->twoColumn('Path', $this->rel($path));
        $this->twoColumn('Dry run', $this->option('dry-run') ? 'yes' : 'no');

        if ($this->option('dry-run')) {
            $this->line('Preview complete. No changes written.');
            return self::SUCCESS;
        }

        $manifest = $this->beginManifest();
        $force = $this->option('force') === true;

        if (!is_dir($dir)) {
            $this->files->ensureDirectoryExists($dir);
            $manifest->trackMkdir($this->rel($dir));
        }

        $exists = $this->files->exists($path);

        if ($exists && !$force) {
            $current = (string)$this->files->get($path);
            if ($current === $code) {
                $this->info('No changes detected.');
                return self::SUCCESS;
            }
            $this->error('Migration already exists. Use --force to overwrite.');
            return self::FAILURE;
        }

        if ($exists) {
            $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($path) . '.bak');
            $this->files->ensureDirectoryExists(dirname($backup));
            $this->files->put($backup, (string)$this->files->get($path));
            $this->files->put($path, $code);
            $manifest->trackUpdate($this->rel($path), $backup);
        } else {
            $this->files->put($path, $code);
            $manifest->trackCreate($this->rel($path));
        }

        $manifest->save();
        $this->info('Migration created. Manifest: ' . $manifest->id());

        return self::SUCCESS;
    }

    private function inferTableFromName(string $name, bool $isCreate): string
    {
        if ($isCreate) {
            if (preg_match('/create_(.+)_table/', $name, $m) === 1) {
                return Str::snake($m[1]);
            }
            if (preg_match('/(.+)_create_(.+)/', $name, $m) === 1) {
                return Str::snake($m[2]);
            }
        } else {
            if (preg_match('/(?:add|update|alter)_(.+)_to_(.+)_table/', $name, $m) === 1) {
                return Str::snake($m[2]);
            }
            if (preg_match('/(?:add|update|alter)_(.+)_on_(.+)_table/', $name, $m) === 1) {
                return Str::snake($m[2]);
            }
            if (preg_match('/(.+)_to_(.+)_table/', $name, $m) === 1) {
                return Str::snake($m[2]);
            }
            if (preg_match('/(.+)_on_(.+)_table/', $name, $m) === 1) {
                return Str::snake($m[2]);
            }
        }

        if (preg_match('/(.+)_table/', $name, $m) === 1) {
            return Str::snake($m[1]);
        }

        return '';
    }
}
