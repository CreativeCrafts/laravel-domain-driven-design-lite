<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;

final class MakeModelCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:model
        {module? : Module name in PascalCase}
        {name? : Model class name in PascalCase}
        {--table= : Explicit table name}
        {--fillable= : Comma-separated list of fillable columns}
        {--guarded= : Comma-separated list of guarded columns}
        {--soft-deletes : Include SoftDeletes}
        {--no-timestamps : Disable timestamps}
        {--force : Overwrite existing file}
        {--dry-run : Preview without writing}
        {--rollback= : Rollback a previous run via manifest id}';

    protected $description = 'Generate a ULID-backed Eloquent Model inside a module.';

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
        $name = Str::studly($this->getStringArgument('name'));
        $force = $this->option('force') === true;
        $dry = $this->option('dry-run') === true;

        $fillableOpt = trim($this->getStringOption('fillable') ?? '');
        $guardedOpt = trim($this->getStringOption('guarded') ?? '');
        if ($fillableOpt !== '' && $guardedOpt !== '') {
            $this->error('Use either --fillable or --guarded, not both.');
            return self::FAILURE;
        }

        $softDeletes = $this->option('soft-deletes') === true;
        $timestamps = $this->option('no-timestamps') !== true;

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException('Module not found: ' . $module);
        }

        $ns = "Modules\\{$module}\\App\\Models";
        $dir = "{$moduleRoot}/App/Models";
        $path = "{$dir}/{$name}.php";

        $tableOpt = $this->getStringOption('table') ?? '';
        $table = $tableOpt !== '' ? $tableOpt : Str::snake(Str::pluralStudly($name));

        $this->twoColumn('Module', $module);
        $this->twoColumn('Class', "{$ns}\\{$name}");
        $this->twoColumn('Path', $this->rel($path));
        $this->twoColumn('Table', $table);
        $this->twoColumn('Soft deletes', $softDeletes ? 'yes' : 'no');
        $this->twoColumn('Timestamps', $timestamps ? 'yes' : 'no');
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');

        $softImport = $softDeletes ? 'use Illuminate\\Database\\Eloquent\\SoftDeletes;' : '';
        $softTrait = $softDeletes ? ', SoftDeletes' : '';
        $timestampsProperty = $timestamps ? '' : PHP_EOL . '    public bool $timestamps = false;' . PHP_EOL;
        $fillGuardBlock = $this->buildFillGuardBlock($fillableOpt, $guardedOpt);

        $code = $this->render('ddd-lite/model.ulid.stub', [
            'namespace' => $ns,
            'class' => $name,
            'table' => $table,
            'soft_deletes_import' => $softImport,
            'soft_deletes_trait' => $softTrait,
            'timestamps_property' => $timestampsProperty,
            'fill_guard_block' => $fillGuardBlock,
        ]);

        if ($dry) {
            $this->line('Preview complete. No changes written.');
            return self::SUCCESS;
        }

        $manifest = $this->beginManifest();

        if (!is_dir($dir)) {
            $this->files->ensureDirectoryExists($dir);
            $manifest->trackMkdir($this->rel($dir));
        }

        $exists = $this->files->exists($path);

        if ($exists && !$force) {
            $current = (string)$this->files->get($path);
            if ($current === $code) {
                $this->info('No changes detected. File already matches generated output.');
                return self::SUCCESS;
            }
            $this->error('Model already exists. Use --force to overwrite.');
            return self::FAILURE;
        }

        if ($exists) {
            $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($path) . '.bak');
            $this->files->ensureDirectoryExists(dirname($backup));
            $this->files->put($backup, (string)$this->files->get($path));
            $this->files->put($path, $code);

            $manifest->trackUpdate($this->rel($path), $this->rel($backup));
        } else {
            $this->files->put($path, $code);
            $manifest->trackCreate($this->rel($path));
        }

        $manifest->save();
        $this->info('Model created. Manifest: ' . $manifest->id());

        return self::SUCCESS;
    }

    private function buildFillGuardBlock(string $fillable, string $guarded): string
    {
        if ($fillable !== '') {
            $items = $this->csvToArray($fillable);
            $export = implode(', ', array_map(static fn (string $s): string => "'{$s}'", $items));
            return PHP_EOL . '    protected array $fillable = [' . $export . '];' . PHP_EOL;
        }

        if ($guarded !== '') {
            $items = $this->csvToArray($guarded);
            $export = implode(', ', array_map(static fn (string $s): string => "'{$s}'", $items));
            return PHP_EOL . '    protected array $guarded = [' . $export . '];' . PHP_EOL;
        }

        return PHP_EOL . '    protected array $guarded = [];' . PHP_EOL;
    }

    /**
     * @return array<int, string>
     */
    private function csvToArray(string $csv): array
    {
        $parts = array_filter(array_map(static fn (string $s): string => trim($s), explode(',', $csv)));
        return array_values(array_unique($parts));
    }
}
