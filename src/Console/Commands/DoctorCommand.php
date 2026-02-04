<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\AppBootstrapEditor;
use CreativeCrafts\DomainDrivenDesignLite\Support\BootstrapInspector;
use CreativeCrafts\DomainDrivenDesignLite\Support\ClassFixer;
use CreativeCrafts\DomainDrivenDesignLite\Support\PhpClassScanner;
use CreativeCrafts\DomainDrivenDesignLite\Support\Psr4Guard;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use JsonException;
use Random\RandomException;
use Throwable;

final class DoctorCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:doctor
        {--module= : Only check a specific module (PascalCase)}
        {--fix : Attempt to automatically fix detected issues}
        {--dry-run : Preview actions without writing}
        {--rollback= : Rollback a previous doctor run using its manifest id}
        {--json : Output JSON report (non-interactive)}
        {--deep : Run deep checks (doctor:domain + doctor-ci) after base checks}
        {--prefer= : Fix strategy when class and filename mismatch: "file" or "class"}';

    protected $description = 'Diagnose and optionally fix PSR-4, module casing, provider registration, routing keys, and filename/class mismatches.';

    /**
     * @throws RandomException
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $rollback = $this->option('rollback');
        if (is_string($rollback) && $rollback !== '') {
            $m = $this->loadManifestOrFail($rollback);
            $m->rollback();
            $this->info("Rollback complete for {$rollback}.");
            return self::SUCCESS;
        }

        $onlyModuleOpt = $this->option('module');
        $onlyModule = is_string($onlyModuleOpt) ? $onlyModuleOpt : '';
        $fix = $this->option('fix') === true;
        $dry = $this->option('dry-run') === true;
        $jsonOut = $this->option('json') === true;
        $deep = $this->option('deep') === true;
        $preferOpt = $this->option('prefer');
        $prefer = in_array($preferOpt, ['file', 'class'], true) ? $preferOpt : 'file';

        $guard = new Psr4Guard();
        $inspector = new BootstrapInspector();
        $editor = new AppBootstrapEditor();
        $scanner = new PhpClassScanner();
        $fixer = new ClassFixer();

        $manifest = null;
        $anyChange = false;

        $report = [
            'composer_psr4' => [
                'has_modules_mapping' => null,
                'changed' => false,
            ],
            'routing' => [
                'missing' => [],
                'changed' => false,
            ],
            'modules' => [],
        ];

        try {
            $hasMapping = $this->hasModulesPsr4();
            $report['composer_psr4']['has_modules_mapping'] = $hasMapping;

            if (!$hasMapping) {
                if ($fix) {
                    if ($dry) {
                        $this->line('[doctor] would add "Modules\\\\": "modules/" to composer.json');
                    } else {
                        $manifest = $this->beginManifest();
                        $guard->ensureModulesMapping($manifest);
                        $report['composer_psr4']['changed'] = true;
                        $anyChange = true;
                        $this->line('[doctor] added "Modules\\\\": "modules/" to composer.json');
                    }
                } else {
                    $this->warn('[doctor] composer.json is missing PSR-4 mapping: "Modules\\\\": "modules/". Use --fix to add it.');
                }
            } else {
                $this->line('[doctor] composer.json PSR-4 mapping for "Modules\\\\" is present.');
            }

            $modulesDir = base_path('modules');
            $modules = [];
            if (is_dir($modulesDir)) {
                foreach (scandir($modulesDir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $full = $modulesDir . DIRECTORY_SEPARATOR . $entry;
                    if (is_dir($full)) {
                        $modules[] = $entry;
                    }
                }
            }

            if ($onlyModule !== '') {
                $modules = array_values(array_filter($modules, static fn (string $m) => $m === $onlyModule));
                if ($modules === []) {
                    $this->warn("[doctor] No such module: {$onlyModule}");
                }
            }

            $missingRouting = [];
            try {
                $missingRouting = $inspector->missingRoutingKeys(['api', 'channels']);
            } catch (Throwable $e) {
                $report['routing']['missing'] = ['parse_error'];
                $this->warn('[doctor] bootstrap/app.php routing parse error: ' . $e->getMessage());
            }

            if ($missingRouting !== []) {
                $report['routing']['missing'] = $missingRouting;
                if ($fix) {
                    $map = [];
                    if (in_array('api', $missingRouting, true)) {
                        $map['api'] = "__DIR__ . '/../routes/api.php'";
                    }
                    if (in_array('channels', $missingRouting, true)) {
                        $map['channels'] = "__DIR__ . '/../routes/channels.php'";
                    }

                    if ($dry) {
                        $this->line('[doctor] would ensure withRouting keys: ' . implode(', ', array_keys($map)));
                    } else {
                        if ($manifest === null) {
                            $manifest = $this->beginManifest();
                        }
                        $editor->ensureRoutingKeys($manifest, $map);
                        $report['routing']['changed'] = true;
                        $anyChange = true;
                        $this->line('[doctor] ensured withRouting keys: ' . implode(', ', array_keys($map)));
                    }
                } else {
                    $this->warn('[doctor] withRouting missing keys: ' . implode(', ', $missingRouting) . ' Use --fix to correct.');
                }
            }

            foreach ($modules as $mod) {
                $moduleReport = [
                    'name' => $mod,
                    'status' => 'ok',
                    'issues' => [],
                    'actions' => [],
                ];

                try {
                    $guard->assertOrFixCase(
                        $mod,
                        $dry,
                        $fix,
                        fn (string $msg) => $this->line("[doctor][$mod] {$msg}")
                    );
                } catch (Throwable $e) {
                    $moduleReport['status'] = 'error';
                    $moduleReport['issues'][] = $e->getMessage();
                    $this->warn("[doctor][$mod] " . $e->getMessage());
                }

                $fqcn = "Modules\\{$mod}\\App\\Providers\\{$mod}ServiceProvider::class";

                $inside = false;
                try {
                    $inside = $inspector->providerInsideConfigureChain($fqcn);
                } catch (Throwable $e) {
                    $moduleReport['status'] = 'error';
                    $moduleReport['issues'][] = 'bootstrap/app.php parse error: ' . $e->getMessage();
                    $this->warn("[doctor][$mod] bootstrap/app.php parse error: " . $e->getMessage());
                }

                if (!$inside) {
                    $mentioned = false;
                    try {
                        $mentioned = $inspector->providerMentioned($fqcn);
                    } catch (Throwable) {
                    }

                    $msg = $mentioned
                        ? 'Provider registered outside Application::configure(...) chain.'
                        : 'Provider not registered in Application::configure(...) chain.';
                    $moduleReport['issues'][] = $msg;
                    $moduleReport['status'] = 'error';

                    if ($fix) {
                        if ($dry) {
                            if ($inspector->hasStandaloneWithProvidersBlock()) {
                                $this->line("[doctor][$mod] would remove standalone \$app->withProviders([...]);");
                                $moduleReport['actions'][] = 'remove standalone $app->withProviders([...]);';
                            }
                            $this->line("[doctor][$mod] would inject {$fqcn} into Application::configure(...)->withProviders([...]).");
                            $moduleReport['actions'][] = "inject {$fqcn} into chain";
                        } else {
                            if ($manifest === null) {
                                $manifest = $this->beginManifest();
                            }
                            if ($inspector->hasStandaloneWithProvidersBlock()) {
                                $editor->removeStandaloneWithProviders($manifest);
                                $anyChange = true;
                                $this->line("[doctor][$mod] removed standalone \$app->withProviders([...]).");
                            }
                            $editor->ensureModuleProvider($manifest, $mod, $mod . 'ServiceProvider');
                            $anyChange = true;
                            $this->line("[doctor][$mod] injected {$fqcn} into Application::configure(...)->withProviders([...]).");
                            if (!$inspector->providerInsideConfigureChain($fqcn)) {
                                $this->warn("[doctor][$mod] injection verification failed (please review bootstrap/app.php).");
                            }
                        }
                    } else {
                        $this->warn("[doctor][$mod] {$msg} Use --fix to correct.");
                    }
                } else {
                    $this->line("[doctor][$mod] provider is correctly registered inside the chain.");
                }

                $providersDir = base_path("modules/{$mod}/App/Providers");
                $providerFiles = is_dir($providersDir) ? array_values(
                    array_filter(
                        array_map(static fn (string $f) => $providersDir . DIRECTORY_SEPARATOR . $f, scandir($providersDir) ?: []),
                        static fn (string $p) => is_file($p) && str_ends_with($p, '.php')
                    )
                ) : [];

                foreach ($providerFiles as $path) {
                    $fileShort = pathinfo($path, PATHINFO_FILENAME);
                    $declaredShort = $scanner->shortClassFromFile($path);

                    if ($declaredShort === null) {
                        $moduleReport['issues'][] = 'No class declaration found in Providers/' . basename($path);
                        $moduleReport['status'] = 'error';
                        continue;
                    }

                    if ($declaredShort !== $fileShort) {
                        $moduleReport['issues'][] = "Class '{$declaredShort}' does not match filename '{$fileShort}.php' in Providers.";
                        $moduleReport['status'] = 'error';

                        if ($fix) {
                            if ($dry) {
                                $moduleReport['actions'][] = $prefer === 'file'
                                    ? "would change class to '{$fileShort}' in " . basename($path)
                                    : "would rename file to '{$declaredShort}.php' in Providers";
                                $this->line("[doctor][$mod] mismatch Providers/" . basename($path) . " — prefer={$prefer} (dry-run)");
                            } elseif ($prefer === 'file') {
                                if ($manifest === null) {
                                    $manifest = $this->beginManifest();
                                }
                                $fixer->renameClassInFile($manifest, $path, $declaredShort, $fileShort);
                                $anyChange = true;
                                $this->line("[doctor][$mod] changed class to '{$fileShort}' in Providers/" . basename($path));
                            } else {
                                $dest = dirname($path) . DIRECTORY_SEPARATOR . $declaredShort . '.php';
                                if ($manifest === null) {
                                    $manifest = $this->beginManifest();
                                }
                                $fixer->renameFile($manifest, $path, $dest);
                                $anyChange = true;
                                $this->line("[doctor][$mod] renamed Providers file to '{$declaredShort}.php'");
                            }
                        }
                    }
                }

                $controllersDir = base_path("modules/{$mod}/App/Http/Controllers");
                $controllerFiles = is_dir($controllersDir) ? array_values(
                    array_filter(
                        array_map(static fn (string $f) => $controllersDir . DIRECTORY_SEPARATOR . $f, scandir($controllersDir) ?: []),
                        static fn (string $p) => is_file($p) && str_ends_with($p, '.php')
                    )
                ) : [];

                foreach ($controllerFiles as $path) {
                    $fileShort = pathinfo($path, PATHINFO_FILENAME);
                    $declaredShort = $scanner->shortClassFromFile($path);

                    if ($declaredShort === null) {
                        $moduleReport['issues'][] = 'No class declaration found in Http/Controllers/' . basename($path);
                        $moduleReport['status'] = 'error';
                        continue;
                    }

                    if ($declaredShort !== $fileShort) {
                        $moduleReport['issues'][] = "Class '{$declaredShort}' does not match filename '{$fileShort}.php' in Controllers.";
                        $moduleReport['status'] = 'error';

                        if ($fix) {
                            if ($dry) {
                                $moduleReport['actions'][] = $prefer === 'file'
                                    ? "would change class to '{$fileShort}' in " . basename($path)
                                    : "would rename file to '{$declaredShort}.php' in Controllers";
                                $this->line("[doctor][$mod] mismatch Controllers/" . basename($path) . " — prefer={$prefer} (dry-run)");
                            } elseif ($prefer === 'file') {
                                $manifest = $manifest ?? $this->beginManifest();
                                $fixer->renameClassInFile($manifest, $path, $declaredShort, $fileShort);
                                $anyChange = true;
                                $this->line("[doctor][$mod] changed class to '{$fileShort}' in Controllers/" . basename($path));
                            } else {
                                $dest = dirname($path) . DIRECTORY_SEPARATOR . $declaredShort . '.php';
                                $manifest = $manifest ?? $this->beginManifest();
                                $fixer->renameFile($manifest, $path, $dest);
                                $anyChange = true;
                                $this->line("[doctor][$mod] renamed Controllers file to '{$declaredShort}.php'");
                            }
                        }
                    }
                }

                $report['modules'][] = $moduleReport;
            }

            if ($jsonOut) {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                if ($anyChange) {
                    if ($manifest === null) {
                        $manifest = $this->beginManifest(); // defensive; should not happen if anyChange=true
                    }
                    $manifest->save();
                    $this->info('[doctor] complete. Manifest: ' . $manifest->id());
                    $this->line('Tip: run "composer dump-autoload -o" if fixes were applied.');
                } else {
                    $this->info('[doctor] complete. No fixes applied.');
                }
            }

            if ($deep) {
                $this->line('');
                $this->info('[doctor] deep checks...');
                if ($jsonOut) {
                    $this->line('');
                    $this->info('[doctor] deep results:');
                }
                $domainArgs = [
                    '--json' => true,
                    '--stdin-report' => '{"summary":{"violations":0,"uncovered":0,"allowed":0,"warnings":0,"errors":0}}',
                ];
                if (is_file(base_path('deptrac.yaml'))) {
                    $domainArgs['--config'] = base_path('deptrac.yaml');
                }
                $this->call('ddd-lite:doctor:domain', $domainArgs);
                $this->call('ddd-lite:doctor-ci', [
                    '--json' => true,
                ]);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('[doctor] error: ' . $e->getMessage());
            if ($manifest !== null) {
                $manifest->save();
                $this->warn('Rolling back (' . $manifest->id() . ') ...');
                $manifest->rollback();
                $this->info('Rollback complete.');
            }
            return self::FAILURE;
        }
    }

    /**
     * @throws JsonException
     */
    private function hasModulesPsr4(): bool
    {
        $composer = base_path('composer.json');
        if (!is_file($composer)) {
            return false;
        }
        $raw = file_get_contents($composer);
        if ($raw === false) {
            return false;
        }
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            return false;
        }
        $autoload = isset($json['autoload']) && is_array($json['autoload']) ? $json['autoload'] : [];
        $psr4 = isset($autoload['psr-4']) && is_array($autoload['psr-4']) ? $autoload['psr-4'] : [];
        return array_key_exists('Modules\\', $psr4);
    }
}
