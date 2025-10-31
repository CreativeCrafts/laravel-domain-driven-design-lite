<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Support\AppBootstrapEditor;
use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use CreativeCrafts\DomainDrivenDesignLite\Support\Psr4Guard;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use Throwable;

class ModuleScaffoldCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:module
        {name : Module name in PascalCase (e.g., Planner)}
        {--aggregate=Aggregate : Aggregate root name (e.g., Trip)}
        {--dry-run : Preview actions without writing}
        {--rollback= : Rollback a previous run using its manifest id}
        {--force : Overwrite existing files if they exist}
        {--fix-psr4 : Auto-rename lowercase module folders to PSR-4 compliant PascalCase}';

    protected $description = 'Scaffold a new DDD-lite module with DTO/Ulid contracts, model, repo, provider, routes, migration, and test dirs.';

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
            $this->info("Rollback complete for {$rollbackOpt}.");
            return self::SUCCESS;
        }

        $module = Str::studly((string)$this->argument('name'));

        $aggregateOpt = $this->option('aggregate');
        $aggregate = is_string($aggregateOpt) && $aggregateOpt !== '' ? Str::studly($aggregateOpt) : 'Aggregate';

        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;
        $fixPsr4 = $this->option('fix-psr4') === true;

        $this->line("Plan: create module [{$module}] with aggregate [{$aggregate}] under modules/{$module}");
        $this->confirmOrExit("Proceed to scaffold modules/{$module}?");

        $manifest = Manifest::begin($this->files);
        $guard = new Psr4Guard();

        try {
            // Ensure the composer has Modules mapping
            $guard->ensureModulesMapping($manifest);

            // If modules/<Module> already exists with lowercase dirs, optionally fix
            $guard->assertOrFixCase($module, $dry, $fixPsr4, fn ($msg) => $this->line($msg));

            // Track the module root directory (so rollback removes the whole tree)
            if (!$dry) {
                $this->safe->ensureDirTracked($manifest, "modules/{$module}");
            }

            // Create PascalCase directories (each tracked)
            $dirs = [
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
                "modules/{$module}/Routes",
                "modules/{$module}/tests/Feature",
                "modules/{$module}/tests/Unit/fakes",
            ];
            foreach ($dirs as $d) {
                if (!$dry) {
                    $this->safe->ensureDirTracked($manifest, $d);
                }
                $this->line("dir: {$d}");
            }

            // Providers
            $providerClass = "{$module}ServiceProvider";
            $moduleProvider = $this->render('module-service-provider.stub', [
                'Module' => $module,
            ]);
            $pathProvider = "modules/{$module}/App/Providers/{$providerClass}.php";
            if (!$dry) {
                $this->safe->writeNew($manifest, $pathProvider, $moduleProvider, $force);
            }

            // RouteServiceProvider
            $routeProvider = $this->render('route-service-provider.stub', [
                'Module' => $module,
            ]);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/App/Providers/RouteServiceProvider.php", $routeProvider, $force);
            }

            // EventServiceProvider
            $eventProvider = $this->render('event-service-provider.stub', [
                'Module' => $module,
            ]);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/App/Providers/EventServiceProvider.php", $eventProvider, $force);
            }

            // Routes
            $routesWeb = $this->render('routes.stub', []);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/Routes/web.php", $routesWeb, $force);
            }

            $routesApi = $this->render('routes-api.stub', [
                'Module' => $module,
                'module' => Str::kebab($module),
            ]);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/Routes/api.php", $routesApi, $force);
            }

            // Register module provider in bootstrap/app.php
            if (!$dry) {
                (new AppBootstrapEditor())->ensureModuleProvider($manifest, $module, $providerClass);
            }

            // Model (ULID)
            $table = Str::snake(Str::pluralStudly($aggregate));
            $fillable = '';
            $model = $this->render('model-ulid.stub', [
                'Module' => $module,
                'Name' => $aggregate,
                'table' => $table,
                'fillable' => $fillable,
            ]);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/App/Models/{$aggregate}.php", $model, $force);
            }

            // Migration (ULID)
            $migration = $this->render('migration-ulid.stub', [
                'table' => $table,
            ]);
            $timestamp = now()->format('Y_m_d_His');
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/Database/migrations/{$timestamp}_create_{$table}_table.php", $migration, $force);
            }

            // Domain DTO (Data)
            $dto = $this->render('dto.stub', [
                'Module' => $module,
                'NameBase' => $aggregate,
            ]);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/Domain/DTO/{$aggregate}Data.php", $dto, $force);
            }

            // Domain Contract
            $contract = $this->render('contract-repo.stub', [
                'Module' => $module,
                'Name' => "{$aggregate}RepositoryContract",
                'Dto' => "{$aggregate}Data",
                'DtoBase' => $aggregate,
            ]);
            if (!$dry) {
                $this->safe->writeNew($manifest, "modules/{$module}/Domain/Contracts/{$aggregate}RepositoryContract.php", $contract, $force);
            }

            // Final sanity: assert a case once more (new scaffold)
            $guard->assertOrFixCase($module, $dry, $fixPsr4, fn ($msg) => $this->line($msg));

            // Save manifest
            $manifest->save();
            $this->info("Module {$module} scaffolded. Manifest: " . $manifest->id());
            $this->line('Validation: PSR-4 mapping ensured, PascalCase directories created (tracked), stubs written (tracked).');
            $this->line('Next step: run "composer dump-autoload -o"');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            $manifest->save();
            $this->warn("Rolling back (" . $manifest->id() . ") ...");
            $manifest->rollback();
            $this->info("Rollback complete.");
            return self::FAILURE;
        }
    }
}
