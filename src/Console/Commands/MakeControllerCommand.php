<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;

final class MakeControllerCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:make:controller
        {module? : Module name in PascalCase}
        {name? : Controller class name without suffix}
        {--suffix=Controller : Class suffix}
        {--resource : Generate resource-style methods}
        {--inertia : Use Inertia pages in methods}
        {--force : Overwrite if exists}
        {--dry-run : Preview only}
        {--rollback= : Rollback by manifest id}';

    protected $description = 'Generate a Controller inside a module (modules/<Module>/App/Http/Controllers).';

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
        $nameArg = $this->argument('name');

        if ($moduleArg === null || $nameArg === null) {
            $this->error('Arguments "module" and "name" are required unless using --rollback.');
            return self::FAILURE;
        }

        $module = Str::studly((string)$moduleArg);
        $suffix = trim((string)($this->option('suffix') ?? 'Controller'));
        $base = Str::studly((string)$nameArg);
        $class = $base . $suffix;

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException('Module not found: ' . $module);
        }

        $ns = "Modules\\{$module}\\App\\Http\\Controllers";
        $dir = "{$moduleRoot}/App/Http/Controllers";
        $path = "{$dir}/{$class}.php";

        $useInertia = $this->option('inertia') === true;
        $resource = $this->option('resource') === true;

        $inertiaIndex = $base . '/Index';
        $inertiaCreate = $base . '/Create';
        $inertiaShow = $base . '/Show';
        $inertiaEdit = $base . '/Edit';

        $this->twoColumn('Module', $module);
        $this->twoColumn('Class', "{$ns}\\{$class}");
        $this->twoColumn('Path', $this->rel($path));
        $this->twoColumn('Resource', $resource ? 'yes' : 'no');
        $this->twoColumn('Inertia', $useInertia ? 'yes' : 'no');
        $this->twoColumn('Dry run', $this->option('dry-run') ? 'yes' : 'no');

        $stubName = $useInertia ? 'controller.inertia.stub' : 'controller.stub';
        $code = $this->render('ddd-lite/' . $stubName, [
            'namespace' => $ns,
            'class' => $class,
            'inertia_page_index' => $inertiaIndex,
            'inertia_page_create' => $inertiaCreate,
            'inertia_page_show' => $inertiaShow,
            'inertia_page_edit' => $inertiaEdit,
        ]);

        if (!$resource) {
            $code = $this->stripResourceMethods($code);
        }

        if ($this->option('dry-run')) {
            $this->line('Preview complete. No changes written.');
            return self::SUCCESS;
        }

        $force = $this->option('force') === true;

        if (!is_dir($dir)) {
            $this->files->ensureDirectoryExists($dir);
        }

        $exists = $this->files->exists($path);
        if ($exists && !$force) {
            $current = (string)$this->files->get($path);
            if ($current === $code) {
                $this->info('No changes detected.');
                return self::SUCCESS;
            }
            $this->error('Controller already exists. Use --force to overwrite.');
            return self::FAILURE;
        }

        $manifest = $this->beginManifest();

        if (!is_dir($dir)) {
            $this->files->ensureDirectoryExists($dir);
            $manifest->trackMkdir($this->rel($dir));
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

        $manifest->save();
        $this->info('Controller created. Manifest: ' . $manifest->id());

        return self::SUCCESS;
    }

    private function stripResourceMethods(string $code): string
    {
        $patterns = [
            '/\n\s*public function create\(.*?\)\s*:\s*Response\s*\{.*?\}\n/s',
            '/\n\s*public function store\(.*?\)\s*:\s*RedirectResponse\s*\{.*?\}\n/s',
            '/\n\s*public function show\(.*?\)\s*:\s*Response\s*\{.*?\}\n/s',
            '/\n\s*public function edit\(.*?\)\s*:\s*Response\s*\{.*?\}\n/s',
            '/\n\s*public function update\(.*?\)\s*:\s*RedirectResponse\s*\{.*?\}\n/s',
            '/\n\s*public function destroy\(.*?\)\s*:\s*RedirectResponse\s*\{.*?\}\n/s',
        ];

        return (string)(preg_replace($patterns, '', $code) ?: $code);
    }
}
