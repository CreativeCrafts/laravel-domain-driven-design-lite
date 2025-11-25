<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

final class MakeRequestCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:request
        {module? : Module name in PascalCase}
        {name? : Request class name without suffix}
        {--suffix=Request : Class suffix}
        {--force : Overwrite if exists}
        {--dry-run : Preview only}
        {--rollback= : Rollback by manifest id}';

    protected $description = 'Generate a FormRequest class inside a module (modules/<Module>/App/Http/Requests).';

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
            try {
                $m = $this->loadManifestOrFail($rollback);
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                $this->warnBox('Rollback aborted.');
                return self::FAILURE;
            }

            $this->withProgress(1, static function (ProgressBar $bar) use ($m): void {
                $bar->setMessage('Rolling back changes');
                $m->rollback();
                $bar->advance();
            });

            $this->info('Rollback complete: ' . $rollback);
            $this->successBox('Rollback completed successfully.');
            return self::SUCCESS;
        }

        $moduleArg = $this->argument('module');
        $nameArg = $this->argument('name');

        if ($moduleArg === null || $nameArg === null) {
            $this->error('Arguments "module" and "name" are required unless using --rollback.');
            $this->warnBox('Provide both arguments or use --rollback=<manifest-id>.');
            return self::FAILURE;
        }

        $module = Str::studly((string)$moduleArg);
        $suffix = trim((string)($this->option('suffix') ?? 'Request'));
        $base = Str::studly((string)$nameArg);
        $class = $base . $suffix;

        $manifest = $this->beginManifest();

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException('Module not found: ' . $module);
        }

        $ns = "Modules\\{$module}\\App\\Http\\Requests";
        $dir = "{$moduleRoot}/App/Http/Requests";
        $path = "{$dir}/{$class}.php";

        $this->summary('Request scaffold plan', [
            'Module' => $module,
            'Class' => "{$ns}\\{$class}",
            'Path' => $this->rel($path),
            'Dry run' => $this->option('dry-run') ? 'yes' : 'no',
        ]);

        $code = $this->render('ddd-lite/request.stub', [
            'namespace' => $ns,
            'class' => $class,
        ]);

        if ($this->option('dry-run')) {
            $this->line('Preview complete. No changes written.');
            $this->warnBox('Dry-run mode: nothing was written to disk.');
            return self::SUCCESS;
        }

        $fs = $this->files;
        $force = $this->option('force') === true;

        if (!is_dir($dir)) {
            $this->withProgress(1, function (ProgressBar $bar) use ($fs, $dir, $manifest): void {
                $bar->setMessage('Ensuring directory exists');
                $fs->ensureDirectoryExists($dir);
                $manifest->trackMkdir($this->rel($dir));
                $bar->advance();
            });
        }

        if (!$force && $fs->exists($path)) {
            $current = (string)$fs->get($path);
            if ($current === $code) {
                $this->info('No changes detected.');
                $this->successBox('No changes needed.');
                return self::SUCCESS;
            }
            $this->error('Request already exists. Use --force to overwrite.');
            $this->warnBox('Use --force to overwrite the existing file.');
            return self::FAILURE;
        }

        $this->withProgress(1, function (ProgressBar $bar) use ($fs, $path, $code, $manifest): void {
            $bar->setMessage('Writing request file');

            if ($fs->exists($path)) {
                $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($path) . '.bak');
                $fs->ensureDirectoryExists(dirname($backup));
                $fs->put($backup, (string)$fs->get($path));
                $manifest->trackUpdate($this->rel($path), $backup);
            } else {
                $manifest->trackCreate($this->rel($path));
            }

            $fs->put($path, $code);

            $bar->advance();
        });

        $manifest->save();

        $this->info('Request created. Manifest: ' . $manifest->id());
        $this->successBox('Request created successfully.');
        return self::SUCCESS;
    }
}
