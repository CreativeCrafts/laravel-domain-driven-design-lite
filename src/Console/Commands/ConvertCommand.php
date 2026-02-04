<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Support\ConversionPlan;
use CreativeCrafts\DomainDrivenDesignLite\Support\MoveCandidate;
use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\AppBootstrapEditor;
use CreativeCrafts\DomainDrivenDesignLite\Support\ConversionDiscovery;
use CreativeCrafts\DomainDrivenDesignLite\Support\NamespaceRewriter;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;

final class ConvertCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:convert
        {module : Target module name in PascalCase}
        {--plan-moves : Discover and print a move plan without applying}
        {--apply-moves : Apply discovered moves with AST-based namespace rewrites}
        {--review : Review and approve each move interactively (only with --apply-moves)}
        {--all : Apply all discovered moves without per-item prompts (only with --apply-moves)}
        {--only= : Comma-separated kinds to include (controllers,requests,models,actions,dto,contracts)}
        {--except= : Comma-separated kinds to exclude}
        {--paths= : Comma-separated absolute or relative paths to scan}
        {--with-shims : Include shim suggestions in plan output}
        {--suggest-contracts : Suggest domain contracts and bindings for moved repositories/actions}
        {--export-plan= : Optional path to write the discovered move plan as JSON}
        {--dry-run : Preview changes without writing}
        {--force : Overwrite existing files if present}
        {--rollback= : Roll back a previous conversion by manifest id}';

    protected $description = 'Convert an existing Laravel app to DDD-lite. Plan or apply class moves with safe rollback.';

    /**
     * @return int
     * @throws JsonException
     * @throws FileNotFoundException
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->prepare();

        $rollbackId = $this->getStringOption('rollback');
        if ($rollbackId !== null) {
            $manifest = $this->loadManifestOrFail($rollbackId);
            $manifest->rollback();
            $this->info('Rollback complete: ' . $rollbackId);
            return self::SUCCESS;
        }

        $module = Str::studly($this->getStringArgument('module'));
        if ($module === '') {
            throw new RuntimeException('Module is required.');
        }

        if ($this->option('plan-moves') === true) {
            $this->renderMovePlan($module);
            return self::SUCCESS;
        }

        if ($this->option('apply-moves') === true) {
            return $this->applyMoves($module);
        }

        return $this->handleSkeletonAndRegistration($module);
    }

    /**
     * @return array<int, array{0:string,1:int,2:string}>
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'Target module name in PascalCase'],
        ];
    }

    private function renderMovePlan(string $module): void
    {
        $only = $this->csvOption('only');
        $except = $this->csvOption('except');
        $paths = $this->csvOption('paths', allowRelative: true);
        $withShims = $this->option('with-shims') === true;
        $suggestContracts = $this->option('suggest-contracts') === true;
        $dry = $this->option('dry-run') === true;

        $opt = $this->option('export-plan');
        $exportPlanRaw = is_string($opt) ? $opt : '';
        $exportPlanPath = $exportPlanRaw !== '' ? $this->normalizePath($exportPlanRaw) : null;

        $discovery = new ConversionDiscovery();
        $plan = $discovery->discover($module, $only, $except, $paths);

        $this->line('');
        $this->info('DDD-lite Convert — Move Plan');
        $this->twoColumn('Module', $module);
        $this->twoColumn('Items', (string)$plan->count());
        $this->twoColumn('Only', implode(',', $only) ?: '-');
        $this->twoColumn('Except', implode(',', $except) ?: '-');
        $this->twoColumn('Paths', implode(',', $paths) ?: 'auto');
        if ($exportPlanPath !== null) {
            $this->twoColumn('Export plan', $this->rel($exportPlanPath));
        }

        $this->line('');
        if ($plan->isEmpty()) {
            $this->info('No candidates discovered. Nothing to move.');
            return;
        }

        // Group by "kind" to make large plans easier to digest.
        $grouped = $this->groupPlanByKind($plan);

        foreach ($this->orderedKindLabels() as $kind => $label) {
            if (!array_key_exists($kind, $grouped) || $grouped[$kind] === []) {
                continue;
            }

            $this->line('[' . $label . ']');
            $this->line(str_pad('FROM', 50) . ' → ' . str_pad('TO', 60) . ' | Namespace');
            $this->line(str_repeat('-', 120));

            foreach ($grouped[$kind] as $c) {
                $from = $this->rel($c->fromAbs);
                $to = $this->rel($c->toAbs);
                $ns = $c->fromNamespace . ' → ' . $c->toNamespace;
                $this->line(str_pad($from, 50) . ' → ' . str_pad($to, 60) . ' | ' . $ns);
            }

            $this->line('');
        }

        if ($withShims) {
            $this->info('Shim suggestion: when applying, you may generate temporary aliases at old locations.');
            $this->line('');
        }

        if ($suggestContracts) {
            $this->renderContractSuggestions($plan, $module, $paths);
        }

        $this->info('Summary by kind:');
        foreach ($this->orderedKindLabels() as $kind => $label) {
            if (!array_key_exists($kind, $grouped) || $grouped[$kind] === []) {
                continue;
            }

            $this->twoColumn($label, (string)count($grouped[$kind]));
        }

        // Optional JSON export (no effect if no path given).
        if ($exportPlanPath !== null && !$dry) {
            $this->writeExportPlanJson($exportPlanPath, $plan);
            $this->line('');
            $this->info('Exported move plan to: ' . $this->rel($exportPlanPath));
        }

        $this->line('');
        $this->info('Use --apply-moves to perform these moves with AST-based namespace rewrites.');
    }

    /**
     * @param array<int,string> $paths
     */
    private function renderContractSuggestions(ConversionPlan $plan, string $module, array $paths): void
    {
        $repo = [];
        $actions = [];

        foreach ($plan->items as $candidate) {
            $fromRel = $this->rel($candidate->fromAbs);
            $kind = $this->inferKindFromRel($fromRel);
            $class = pathinfo($fromRel, PATHINFO_FILENAME);

            if ($kind === 'models') {
                $repo[] = $class;
            } elseif ($kind === 'actions') {
                $actions[] = $class;
            }
        }

        if ($repo === [] && $actions === []) {
            // Fallback: scan provided paths for Models/Actions when plan is empty.
            foreach ($paths as $p) {
                if (!is_dir($p)) {
                    continue;
                }
                $files = $this->files->allFiles($p, true);
                foreach ($files as $file) {
                    $abs = (string)$file;
                    if (!str_ends_with($abs, '.php')) {
                        continue;
                    }
                    if (str_contains($abs, DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR)) {
                        $repo[] = pathinfo($abs, PATHINFO_FILENAME);
                    }
                    if (str_contains($abs, DIRECTORY_SEPARATOR . 'Actions' . DIRECTORY_SEPARATOR)) {
                        $actions[] = pathinfo($abs, PATHINFO_FILENAME);
                    }
                }
            }
        }

        if ($repo === [] && $actions === []) {
            return;
        }

        $this->line('');
        $this->info('Suggested contracts & bindings (manual):');

        foreach ($repo as $model) {
            $contract = "{$model}RepositoryContract";
            $impl = "{$model}Repository";
            $this->line("- ddd-lite:make:contract {$module} {$contract} --in={$model}");
            $this->line("- ddd-lite:make:repository {$module} {$model}");
            $this->line("- ddd-lite:bind {$module} {$contract} {$impl}");
        }

        foreach ($actions as $action) {
            $contract = "{$action}Contract";
            $this->line("- ddd-lite:make:contract {$module} {$contract} --in=Actions");
        }
    }

    /**
     * @throws FileNotFoundException
     * @throws JsonException
     * @throws RandomException
     */
    private function handleSkeletonAndRegistration(string $module): int
    {
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $moduleRoot = base_path('modules/' . $module);
        $providerFqcn = 'Modules\\' . $module . '\\App\\Providers\\' . $module . 'ServiceProvider';
        $providerPath = $moduleRoot . '/App/Providers/' . $module . 'ServiceProvider.php';
        $bootstrapPath = base_path('bootstrap/app.php');
        $composerPath = base_path('composer.json');

        $dirs = [
            $moduleRoot,
            $moduleRoot . '/App',
            $moduleRoot . '/App/Models',
            $moduleRoot . '/App/Repositories',
            $moduleRoot . '/App/Providers',
            $moduleRoot . '/App/Http',
            $moduleRoot . '/App/Http/Controllers',
            $moduleRoot . '/App/Http/Requests',
            $moduleRoot . '/Domain',
            $moduleRoot . '/Domain/Actions',
            $moduleRoot . '/Domain/Contracts',
            $moduleRoot . '/Domain/DTO',
            $moduleRoot . '/Domain/Queries',
            $moduleRoot . '/database',
            $moduleRoot . '/database/migrations',
            $moduleRoot . '/routes',
            $moduleRoot . '/tests',
            $moduleRoot . '/tests/Feature',
            $moduleRoot . '/tests/Unit',
        ];

        $composerNeedsPsr4 = $this->composerNeedsModulesPsr4($composerPath);
        $bootstrapNeedsProvider = $this->bootstrapNeedsProvider($bootstrapPath, $providerFqcn);
        $providerExists = $this->files->exists($providerPath);

        $this->twoColumn('Module', $module);
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');
        $this->twoColumn('Create dirs', (string)count($dirs));
        $this->twoColumn('Add PSR-4 Modules\\', $composerNeedsPsr4 ? 'yes' : 'no');
        $this->twoColumn('Register provider', $bootstrapNeedsProvider ? 'yes' : 'no');
        $this->twoColumn('Provider path', $this->rel($providerPath));

        if ($dry) {
            $this->line('Preview complete. No changes written.');
            return self::SUCCESS;
        }

        $manifest = $this->beginManifest();

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                $this->files->ensureDirectoryExists($dir);
                $manifest->trackMkdir($this->rel($dir));
            }
        }

        if ($providerExists && !$force) {
            $this->info('Provider already exists. Use --force to overwrite.');
        } else {
            $stub = $this->render('ddd-lite/provider.module.stub', ['Module' => $module]);

            if ($providerExists) {
                $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($providerPath) . '.bak');
                $this->files->ensureDirectoryExists(dirname($backup));
                $this->files->put($backup, (string)$this->files->get($providerPath));
                $this->files->put($providerPath, $stub);
                $manifest->trackUpdate($this->rel($providerPath), $backup);
            } else {
                $this->files->put($providerPath, $stub);
                $manifest->trackCreate($this->rel($providerPath));
            }
        }

        if ($composerNeedsPsr4) {
            $original = (string)$this->files->get($composerPath);
            $patched = $this->patchComposerModulesPsr4($original);
            if ($patched !== $original) {
                $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($composerPath) . '.bak');
                $this->files->ensureDirectoryExists(dirname($backup));
                $this->files->put($backup, $original);
                $this->files->put($composerPath, $patched);
                $manifest->trackUpdate($this->rel($composerPath), $backup);
            }
        }

        if ($bootstrapNeedsProvider) {
            $editor = new AppBootstrapEditor();
            // Ensure provider is registered inside the fluent configure(...) chain
            $editor->ensureModuleProvider($manifest, $module, $module . 'ServiceProvider');
            // Clean up any legacy standalone $app->withProviders([...]) blocks
            $editor->removeStandaloneWithProviders($manifest);
        }

        $manifest->save();

        $lastIdPath = storage_path('app/ddd-lite_scaffold/last_manifest_id.txt');
        $this->files->ensureDirectoryExists(dirname($lastIdPath));
        $this->files->put($lastIdPath, $manifest->id() . PHP_EOL);

        $this->info('Conversion complete. Manifest: ' . $manifest->id());
        $this->line('Run: composer dump-autoload -o');

        return self::SUCCESS;
    }

    /**
     * @return array<int,string>
     */
    private function csvOption(string $name, bool $allowRelative = false): array
    {
        $opt = $this->option($name);
        $raw = is_string($opt) ? $opt : '';
        if ($raw === '') {
            return [];
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($allowRelative) {
            return array_map(static function (string $p): string {
                return str_starts_with($p, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:#', $p) === 1
                    ? $p
                    : base_path($p);
            }, $parts);
        }
        return $parts;
    }

    /**
     * @throws FileNotFoundException
     * @throws JsonException
     */
    private function composerNeedsModulesPsr4(string $composerPath): bool
    {
        if (!is_file($composerPath)) {
            throw new RuntimeException('composer.json not found.');
        }
        /** @var array<string,mixed> $json */
        $json = json_decode((string)$this->files->get($composerPath), true, 512, JSON_THROW_ON_ERROR);
        $autoloadRoot = $json['autoload'] ?? [];
        $psr4 = is_array($autoloadRoot) ? ($autoloadRoot['psr-4'] ?? []) : [];
        /** @var array<string,string> $psr4 */
        return !array_key_exists('Modules\\', $psr4);
    }

    /**
     * @throws JsonException
     */
    private function patchComposerModulesPsr4(string $originalJson): string
    {
        /** @var array<string,mixed> $json */
        $json = json_decode($originalJson, true, 512, JSON_THROW_ON_ERROR);
        $autoloadRoot = $json['autoload'] ?? [];
        if (!is_array($autoloadRoot)) {
            $autoloadRoot = [];
        }
        $psr4 = $autoloadRoot['psr-4'] ?? [];
        if (!is_array($psr4)) {
            $psr4 = [];
        }
        $psr4['Modules\\'] = 'modules/';
        $autoloadRoot['psr-4'] = $psr4;
        $json['autoload'] = $autoloadRoot;
        return json_encode($json, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    /**
     * @throws FileNotFoundException
     * @throws JsonException
     * @throws RandomException
     */
    private function applyMoves(string $module): int
    {
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;
        $review = $this->option('review') === true;
        $applyAll = $this->option('all') === true;

        $only = $this->csvOption('only');
        $except = $this->csvOption('except');
        $paths = $this->csvOption('paths', allowRelative: true);

        $discovery = new ConversionDiscovery();
        $plan = $discovery->discover($module, $only, $except, $paths);

        $this->line('');
        $this->info('DDD-lite Convert — Apply Moves');
        $this->twoColumn('Module', $module);
        $this->twoColumn('Items', (string)$plan->count());
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');

        if ($plan->isEmpty()) {
            $this->info('No candidates discovered. Nothing to move.');
            return self::SUCCESS;
        }

        // Selection phase (interactive)
        $approved = [];
        if ($review) {
            foreach ($plan->items as $i => $c) {
                $this->line('');
                $this->twoColumn('From', $this->rel($c->fromAbs));
                $this->twoColumn('To', $this->rel($c->toAbs));
                $this->twoColumn('Namespace', $c->fromNamespace . ' → ' . $c->toNamespace);
                if ($this->confirm("[{$i}] Move this file?", true)) {
                    $approved[] = $c;
                }
            }
        } elseif ($applyAll) {
            $approved = $plan->items;
        } else {
            if ($this->confirm("Apply all " . count($plan->items) . " moves now?", true)) {
                $approved = $plan->items;
            }
        }

        if (empty($approved)) {
            $this->warn('No moves approved. Exiting.');
            return self::SUCCESS;
        }

        if ($dry) {
            foreach ($approved as $c) {
                $this->line('MOVE ' . $this->rel($c->fromAbs) . ' → ' . $this->rel($c->toAbs));
                $this->line('  NS ' . $c->fromNamespace . ' → ' . $c->toNamespace);
            }
            $this->line('Preview complete. No changes written.');
            return self::SUCCESS;
        }

        // Perform moves (keep existing error-handling shape to avoid regressions)
        $manifest = $this->beginManifest();
        $rewriter = new NamespaceRewriter();
        $fs = $this->files;

        foreach ($approved as $c) {
            $toDir = dirname($c->toAbs);
            if (!is_dir($toDir)) {
                $fs->ensureDirectoryExists($toDir);
                $manifest->trackMkdir($this->rel($toDir));
            }

            if (is_file($c->toAbs)) {
                if (!$force) {
                    $this->error('Destination exists: ' . $this->rel($c->toAbs) . ' (use --force to overwrite)');
                    $manifest->rollback();
                    return self::FAILURE;
                }
                $backupDest = storage_path('app/ddd-lite_scaffold/backups/' . sha1($c->toAbs) . '.bak');
                $fs->ensureDirectoryExists(dirname($backupDest));
                $fs->put($backupDest, (string)$fs->get($c->toAbs));
                $manifest->trackUpdate($this->rel($c->toAbs), $backupDest);
            }

            if (!is_file($c->fromAbs)) {
                $this->error('Source missing: ' . $this->rel($c->fromAbs));
                $manifest->rollback();
                return self::FAILURE;
            }

            $fs->move($c->fromAbs, $c->toAbs);
            $manifest->trackMove($this->rel($c->fromAbs), $this->rel($c->toAbs));

            $code = (string)$fs->get($c->toAbs);
            $newCode = $rewriter->rewrite($code, $c->toNamespace);

            if ($newCode !== $code) {
                $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($c->toAbs) . '.bak');
                $fs->ensureDirectoryExists(dirname($backup));
                $fs->put($backup, $code);
                $fs->put($c->toAbs, $newCode);
                $manifest->trackUpdate($this->rel($c->toAbs), $backup);
            }
        }

        $manifest->save();

        $lastIdPath = storage_path('app/ddd-lite_scaffold/last_manifest_id.txt');
        $this->files->ensureDirectoryExists(dirname($lastIdPath));
        $this->files->put($lastIdPath, $manifest->id() . PHP_EOL);

        $this->info('Apply-moves complete. Manifest: ' . $manifest->id());
        $this->line('Run: composer dump-autoload -o');

        return self::SUCCESS;
    }

    /**
     * @throws FileNotFoundException
     */
    private function bootstrapNeedsProvider(string $bootstrapPath, string $fqcn): bool
    {
        if (!is_file($bootstrapPath)) {
            throw new RuntimeException('bootstrap/app.php not found.');
        }
        $code = (string)$this->files->get($bootstrapPath);
        return !str_contains($code, $fqcn);
    }

    /**
     * @param ConversionPlan $plan
     * @return array<string, array<int, MoveCandidate>>
     */
    private function groupPlanByKind(ConversionPlan $plan): array
    {
        $grouped = [];

        foreach ($plan->items as $candidate) {
            $fromRel = $this->rel($candidate->fromAbs);
            $kind = $this->inferKindFromRel($fromRel);

            if (!array_key_exists($kind, $grouped)) {
                $grouped[$kind] = [];
            }

            $grouped[$kind][] = $candidate;
        }

        return $grouped;
    }

    /**
     * @return array<string,string>
     */
    private function orderedKindLabels(): array
    {
        return [
            'controllers' => 'Controllers',
            'requests' => 'Requests',
            'models' => 'Models',
            'actions' => 'Actions',
            'dto' => 'DTOs',
            'contracts' => 'Contracts',
            'other' => 'Other',
        ];
    }

    private function inferKindFromRel(string $fromRel): string
    {
        return match (true) {
            Str::startsWith($fromRel, 'app/Http/Controllers/') => 'controllers',
            Str::startsWith($fromRel, 'app/Http/Requests/') => 'requests',
            Str::startsWith($fromRel, 'app/Models/') => 'models',
            Str::startsWith($fromRel, 'app/Actions/') => 'actions',
            Str::startsWith($fromRel, 'app/DTO/') => 'dto',
            Str::startsWith($fromRel, 'app/Contracts/') => 'contracts',
            default => 'other',
        };
    }

    private function normalizePath(string $path): string
    {
        // Absolute path (Unix-like or Windows drive)
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return $path;
        }

        // Treat as relative to base_path()
        return base_path($path);
    }

    /**
     * @param string $exportAbs Absolute path to export file
     * @param ConversionPlan $plan
     * @throws JsonException
     */
    private function writeExportPlanJson(string $exportAbs, ConversionPlan $plan): void
    {
        $payload = [];

        foreach ($plan->items as $candidate) {
            $fromAbs = $candidate->fromAbs;
            $toAbs = $candidate->toAbs;
            $fromRel = $this->rel($fromAbs);
            $toRel = $this->rel($toAbs);
            $kind = $this->inferKindFromRel($fromRel);

            $payload[] = [
                'from_abs' => $fromAbs,
                'to_abs' => $toAbs,
                'from_rel' => $fromRel,
                'to_rel' => $toRel,
                'from_namespace' => $candidate->fromNamespace,
                'to_namespace' => $candidate->toNamespace,
                'kind' => $kind,
            ];
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->files->ensureDirectoryExists(dirname($exportAbs));
        $this->files->put($exportAbs, $json . PHP_EOL);
    }
}
