<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\ModuleProviderEditor;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;

final class MakeProviderCommand extends BaseCommand
{
    /** @var string $signature */
    protected $signature = 'ddd-lite:make:provider
        {module? : Module name in PascalCase}
        {--type=route : Provider type: route|event}
        {--register : Also register in <Module>ServiceProvider}
        {--force : Overwrite if exists}
        {--dry-run : Preview without writing}
        {--rollback= : Rollback by manifest id}';

    protected $description = 'Generate a Route or Event Service Provider inside a module and optionally register it.';

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
            $this->info('Rollback complete: ' . $rollback);
            return self::SUCCESS;
        }

        $moduleArg = $this->argument('module');
        if ($moduleArg === null) {
            $this->error('Argument "module" is required unless using --rollback.');
            return self::FAILURE;
        }

        $module = Str::studly((string)$moduleArg);
        $type = strtolower((string)($this->option('type') ?? 'route'));
        if (!in_array($type, ['route', 'event'], true)) {
            $this->error('Invalid --type. Allowed: route, event');
            return self::FAILURE;
        }

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException('Module not found: ' . $module);
        }

        $dir = "{$moduleRoot}/App/Providers";
        $class = $type === 'route' ? 'RouteServiceProvider' : 'EventServiceProvider';
        $path = "{$dir}/{$class}.php";
        $stub = $type === 'route' ? 'provider.route.stub' : 'provider.event.stub';

        $code = $this->render('ddd-lite/' . $stub, ['module' => $module]);

        $this->twoColumn('Module', $module);
        $this->twoColumn('Type', $type);
        $this->twoColumn('Class', $class);
        $this->twoColumn('Path', $this->rel($path));
        $this->twoColumn('Register', $this->option('register') ? 'yes' : 'no');
        $this->twoColumn('Dry run', $this->option('dry-run') ? 'yes' : 'no');

        if ($this->option('dry-run')) {
            $this->line('Preview complete. No changes written.');
            return self::SUCCESS;
        }

        $manifest = $this->beginManifest();

        if (!is_dir($dir)) {
            $this->files->ensureDirectoryExists($dir);
            $manifest->trackMkdir($this->rel($dir));
        }

        $force = $this->option('force') === true;
        $exists = $this->files->exists($path);

        if ($exists && !$force) {
            $current = (string)$this->files->get($path);
            if ($current === $code) {
                $this->info('No changes detected.');
                return self::SUCCESS;
            }
            $this->error('Provider already exists. Use --force to overwrite.');
            return self::FAILURE;
        }

        if ($exists) {
            $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($path) . '.bak');
            $this->files->ensureDirectoryExists(dirname($backup));
            $this->files->put($backup, (string)$this->files->get($path));
            $manifest->trackUpdate($this->rel($path), $backup);
        } else {
            $manifest->trackCreate($this->rel($path));
        }

        $this->files->put($path, $code);

        if ($this->option('register')) {
            $moduleProviderPath = "{$dir}/{$module}ServiceProvider.php";
            if (!$this->files->exists($moduleProviderPath)) {
                throw new RuntimeException($module . 'ServiceProvider not found at ' . $moduleProviderPath);
            }

            $editor = new ModuleProviderEditor($this->files);
            $addRoute = $type === 'route';
            $addEvent = $type === 'event';

            $before = (string)$this->files->get($moduleProviderPath);
            $after = $editor->addUsesAndRegistrations($module, $moduleProviderPath, $addRoute, $addEvent);

            if ($after !== $before) {
                $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($moduleProviderPath) . '.bak');
                $this->files->ensureDirectoryExists(dirname($backup));
                $this->files->put($backup, $before);
                $this->files->put($moduleProviderPath, $after);
                $manifest->trackUpdate($this->rel($moduleProviderPath), $backup);
            }
        }

        $manifest->save();
        $this->info('Provider created. Manifest: ' . $manifest->id());

        return self::SUCCESS;
    }
}
