<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use JsonException;
use Random\RandomException;
use RuntimeException;
use Throwable;

final class PublishQualityCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:publish:quality
        {--target=all : One of all, phpstan, deptrac, pest-arch}
        {--force : Overwrite if exists}
        {--dry-run : Preview only}
        {--rollback= : Rollback by manifest id}';

    protected $description = 'Publish quality configs (PHPStan, Deptrac) and Pest architecture tests into the host app.';

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

        $target = $this->getStringOption('target') ?? 'all';
        $force = $this->option('force') === true;
        $dry = $this->option('dry-run') === true;

        if (!in_array($target, ['all', 'phpstan', 'deptrac', 'pest-arch'], true)) {
            throw new RuntimeException('Invalid --target. Use one of: all, phpstan, deptrac, pest-arch.');
        }

        $this->twoColumn('Target', $target);
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');

        $filesystem = $this->files;

        /** @var array<int, array{candidates: array<int, string>, dest: string, label: string}> $plan */
        $plan = [];

        if ($target === 'all' || $target === 'phpstan') {
            $plan[] = [
                'label' => 'phpstan.neon',
                'candidates' => [
                    'ddd-lite/phpstan/phpstan.app.neon.stub',
                    'ddd-lite/phpstan/phpstan.neon.stub',
                    'ddd-lite/phpstan/phpstan.neon.dist.stub',
                    'ddd-lite/phpstan.neon.stub',
                    'ddd-lite/phpstan.neon.dist.stub',
                    'ddd-lite/phpstan.app.neon.stub',
                ],
                'dest' => $this->hostPath('phpstan.neon'),
            ];
        }

        if ($target === 'all' || $target === 'deptrac') {
            $plan[] = [
                'label' => 'deptrac.yaml',
                'candidates' => [
                    'ddd-lite/deptrac/deptrac.app.yaml.stub',
                    'ddd-lite/deptrac/deptrac.yaml.stub',
                    'ddd-lite/deptrac/deptrac.package.yaml.stub',
                    'ddd-lite/deptrac.yaml.stub',
                    'ddd-lite/deptrac.app.yaml.stub',
                    'ddd-lite/deptrac.package.yaml.stub',
                ],
                'dest' => $this->hostPath('deptrac.yaml'),
            ];
        }

        if ($target === 'all' || $target === 'pest-arch') {
            $plan[] = [
                'label' => 'tests/Architecture/ArchitectureTest.php',
                'candidates' => [
                    'ddd-lite/pest-arch/ArchitectureTest.php.stub',
                    'ddd-lite/pest/ArchitectureTest.php.stub',
                    'ddd-lite/tests/Architecture/ArchitectureTest.php.stub',
                ],
                'dest' => $this->hostPath('tests/Architecture/ArchitectureTest.php'),
            ];
        }

        foreach ($plan as $row) {
            $this->twoColumn('Publish', $this->rel($row['dest']));
        }

        if ($dry) {
            $this->line('Preview complete. No changes written.');
            // Non-blocking Deptrac hint when applicable (no exit code change)
            $this->maybeSuggestDeptracInstall($target);

            return self::SUCCESS;
        }

        $manifest = $this->beginManifest();

        try {
            foreach ($plan as $row) {
                $dest = (string)$row['dest'];
                $code = $this->renderFromCandidates($row['candidates']);

                $dir = dirname($dest);
                if (!is_dir($dir)) {
                    $filesystem->ensureDirectoryExists($dir);
                    $manifest->trackMkdir($this->rel($dir));
                }

                if ($filesystem->exists($dest)) {
                    $current = (string)$filesystem->get($dest);

                    if ($current === $code && !$force) {
                        continue;
                    }

                    if (!$force) {
                        $this->error('File exists: ' . $this->rel($dest) . ' (use --force to overwrite)');

                        return self::FAILURE;
                    }

                    $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($dest) . '.bak');
                    $filesystem->ensureDirectoryExists(dirname($backup));
                    $filesystem->put($backup, $current);
                    $manifest->trackUpdate($this->rel($dest), $backup);
                } else {
                    $manifest->trackCreate($this->rel($dest));
                }

                $filesystem->put($dest, $code);
            }

            $manifest->save();
            $this->info('Published quality scaffolding. Manifest: ' . $manifest->id());

            // Non-blocking Deptrac hint when applicable (no exit code change)
            $this->maybeSuggestDeptracInstall($target);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            try {
                $manifest->save();
                $manifest->rollback();
            } catch (Throwable) {
            }

            return self::FAILURE;
        }
    }

    /**
     * @param array<int, string> $candidates
     */
    private function renderFromCandidates(array $candidates): string
    {
        foreach ($candidates as $stub) {
            try {
                return $this->render($stub, []);
            } catch (FileNotFoundException) {
                continue;
            }
        }

        throw new RuntimeException('None of the stub candidates exist for this artifact.');
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        $segments = preg_split('#[\\\\/]+#', $path) ?: [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        $prefix = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
        return rtrim($prefix . implode(DIRECTORY_SEPARATOR, $parts), DIRECTORY_SEPARATOR);
    }

    private function hostPath(string $relative): string
    {
        $root = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $candidate = $root . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR);
        $normalized = $this->normalizePath($candidate);
        if (!str_starts_with($normalized, $root)) {
            throw new RuntimeException('Refusing to write outside repository root: ' . $relative);
        }
        return $normalized;
    }

    /**
     * Non-blocking hint: if the target includes deptrac and the binary is missing, suggest installation.
     * Never affects exit code or file writes.
     */
    private function maybeSuggestDeptracInstall(string $target): void
    {
        if (!$this->targetIncludesDeptrac($target)) {
            return;
        }

        $bin = base_path('vendor/bin/deptrac');
        if (!is_file($bin)) {
            $this->warnBox(
                "Deptrac binary not found at vendor/bin/deptrac.\n" .
                "Install it in your application to run DDD-Lite domain checks:\n" .
                "composer require --dev deptrac/deptrac"
            );
        }
    }

    private function targetIncludesDeptrac(string $target): bool
    {
        return $target === 'all' || $target === 'deptrac';
    }
}
