<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\AppBootstrapEditor;
use CreativeCrafts\DomainDrivenDesignLite\Support\Psr4Guard;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use Throwable;

final class ModuleScaffoldCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:module
        {name : Module name in PascalCase (e.g., Planner)}
        {--dry-run : Preview actions without writing}
        {--rollback= : Rollback a previous run using its manifest id}
        {--force : Overwrite existing files if they exist}
        {--fix-psr4 : Auto-rename lowercase module folders to PSR-4 compliant PascalCase}';

    protected $description = 'Scaffold a new DDD-lite module skeleton (providers, routes, PSR-4 mapping). No generic domain artifacts are created.';

    /**
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

            // Safe post-rollback pruning of empty module directories when a module name is provided.
            $module = Str::studly((string)$this->argument('name'));
            if ($module !== '') {
                $this->pruneEmptyModuleTree($module);
            }

            $this->info('Rollback complete: ' . $rollbackOpt);
            $this->successBox('Rollback completed successfully.');
            return self::SUCCESS;
        }

        $module = Str::studly((string)$this->argument('name'));
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;
        $fixPsr4 = $this->option('fix-psr4') === true;

        $this->summary('Module scaffold plan', [
            'Module' => $module,
            'Module root' => "modules/{$module}",
            'Fix PSR-4' => $fixPsr4 ? 'yes' : 'no',
            'Dry run' => $dry ? 'yes' : 'no',
            'Force overwrite' => $force ? 'yes' : 'no',
        ]);

        $this->confirmOrExit("Proceed to scaffold modules/{$module}?");

        $manifest = $this->beginManifest();
        $guard = new Psr4Guard();

        try {
            $guard->ensureModulesMapping($manifest);
            $guard->assertOrFixCase($module, $dry, $fixPsr4, fn (string $msg) => $this->line($msg));

            // Track module root explicitly for a robust rollback
            if (!$dry) {
                $this->safe->ensureDirTracked($manifest, "modules/{$module}");
            }

            // Track ALL parents and leaves (ensures full clean-up in LIFO)
            $dirs = [
                // Root + parents
                "modules/{$module}",
                "modules/{$module}/App",
                "modules/{$module}/App/Http",
                "modules/{$module}/Domain",
                "modules/{$module}/Database",
                "modules/{$module}/Routes",
                "modules/{$module}/tests",
                // Leaves
                "modules/{$module}/App/Http/Controllers",
                "modules/{$module}/App/Http/Requests",
                "modules/{$module}/App/Models",
                "modules/{$module}/App/Providers",
                "modules/{$module}/App/Repositories",
                "modules/{$module}/Domain/DTO",
                "modules/{$module}/Domain/Contracts",
                "modules/{$module}/Domain/Actions",
                "modules/{$module}/Domain/Queries",
                "modules/{$module}/Database/migrations",
                "modules/{$module}/tests/Feature",
                "modules/{$module}/tests/Unit",
                "modules/{$module}/tests/Unit/fakes",
            ];

            foreach ($dirs as $d) {
                if (!$dry) {
                    $this->safe->ensureDirTracked($manifest, $d);
                }
                $this->line("dir: {$d}");
            }

            $providerClass = "{$module}ServiceProvider";
            $moduleProvider = $this->render('ddd-lite/module-service-provider.stub', [
                'Module' => $module,
            ]);
            $pathProvider = "modules/{$module}/App/Providers/{$providerClass}.php";
            if (!$dry) {
                $this->safe->writeNew($manifest, $pathProvider, $moduleProvider, $force);
            }

            $routeProvider = $this->render('ddd-lite/route-service-provider.stub', [
                'Module' => $module,
            ]);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/App/Providers/RouteServiceProvider.php", $routeProvider, $force);
            }

            $eventProvider = $this->render('ddd-lite/event-service-provider.stub', [
                'Module' => $module,
            ]);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/App/Providers/EventServiceProvider.php", $eventProvider, $force);
            }

            $routesWeb = $this->render('ddd-lite/routes.stub', []);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/Routes/web.php", $routesWeb, $force);
            }

            $routesApi = $this->render('ddd-lite/routes-api.stub', [
                'Module' => $module,
                'module' => Str::kebab($module),
            ]);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/Routes/api.php", $routesApi, $force);
            }

            if (!$dry) {
                try {
                    (new AppBootstrapEditor())->ensureModuleProvider($manifest, $module, $providerClass);
                } catch (Throwable $ex) {
                    // Non-fatal: log and continue to avoid failing the whole command for bootstrap/app.php anomalies
                    $this->warn('Could not register module provider in bootstrap/app.php: ' . $ex->getMessage());
                }
                // Re-assert case once provider is in place
                $guard->assertOrFixCase($module, false, $fixPsr4, fn (string $msg) => $this->line($msg));
                $manifest->save();
            }

            $this->info("Module {$module} scaffolded." . (!$dry ? ' Manifest: ' . $manifest->id() : ''));
            $this->successBox('Module scaffold completed successfully.');
            $this->line('Next step: run "composer dump-autoload -o"');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            $manifest->save();
            $this->warn('Rolling back (' . $manifest->id() . ') ...');
            $manifest->rollback();

            // Best-effort prune if a module name is available
            if ($module !== '') {
                $this->pruneEmptyModuleTree($module);
            }

            $this->info('Rollback complete.');
            return self::FAILURE;
        }
    }

    /**
     * Remove the module tree if it is empty after a rollback.
     */
    private function pruneEmptyModuleTree(string $module): void
    {
        $fs = new Filesystem();
        $root = base_path("modules/{$module}");

        if (!$fs->isDirectory($root)) {
            return;
        }

        // Only remove if empty (safety)
        $hasFiles = !empty($fs->allFiles($root));
        $hasDirs = !empty($fs->directories($root));

        if (!$hasFiles && !$hasDirs) {
            // Remove the module root; parent "modules" stays
            $fs->deleteDirectory($root);
        }
    }
}
