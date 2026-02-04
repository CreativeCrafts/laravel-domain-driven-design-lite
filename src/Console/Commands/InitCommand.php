<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\Manifest;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;
use Throwable;

final class InitCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:init
        {--module= : Starter module name in PascalCase}
        {--no-module : Skip module scaffolding}
        {--publish=all : One of all, quality, stubs, none}
        {--ci=show : One of show, write, none}
        {--ci-path=.github/workflows/ddd-lite.yml : Workflow path for --ci=write}
        {--dry-run : Preview actions without writing}
        {--force : Overwrite files where supported}
        {--yes : Skip prompts and accept defaults}';

    protected $description = 'Initialize DDD-Lite in a host app: publish configs, scaffold a starter module, and optionally add a CI snippet.';

    /**
     * @throws RandomException
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;
        $yes = $this->option('yes') === true;

        $noModule = $this->option('no-module') === true;
        $moduleOpt = $this->getStringOption('module');
        $module = $noModule ? null : ($moduleOpt !== null ? Str::studly($moduleOpt) : null);

        $publish = $this->getStringOption('publish') ?? '';
        $ci = $this->getStringOption('ci') ?? '';
        $ciPath = $this->getStringOption('ci-path') ?? '.github/workflows/ddd-lite.yml';

        if ($publish === '') {
            $publish = $yes ? 'all' : $this->requireString(
                $this->choice('Publish templates?', ['all', 'quality', 'stubs', 'none'], 0),
                'publish'
            );
        }
        if ($ci === '') {
            $ci = $yes ? 'show' : $this->requireString(
                $this->choice('CI snippet?', ['show', 'write', 'none'], 0),
                'ci'
            );
        }

        if (!$noModule && $module === null) {
            if ($yes) {
                $module = 'Core';
            } else {
                $create = $this->confirm('Scaffold a starter module?', true);
                if ($create) {
                    $module = Str::studly($this->requireString($this->ask('Module name', 'Core'), 'module name'));
                }
            }
        }

        $this->summary('DDD-Lite init plan', [
            'Publish' => $publish,
            'Module' => $module ?? '(skip)',
            'CI' => $ci,
            'CI path' => $ciPath,
            'Dry run' => $dry ? 'yes' : 'no',
            'Force' => $force ? 'yes' : 'no',
        ]);

        if ($dry) {
            $this->warnBox('Dry-run: no files will be written.');
            return self::SUCCESS;
        }

        if (!in_array($publish, ['all', 'quality', 'stubs', 'none'], true)) {
            throw new RuntimeException('Invalid --publish. Use one of: all, quality, stubs, none.');
        }
        if (!in_array($ci, ['show', 'write', 'none'], true)) {
            throw new RuntimeException('Invalid --ci. Use one of: show, write, none.');
        }

        if ($publish === 'all' || $publish === 'stubs') {
            $result = $this->call('vendor:publish', [
                '--tag' => 'ddd-lite-stubs',
                '--force' => $force,
            ]);
            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        if ($publish === 'all' || $publish === 'quality') {
            $result = $this->call('ddd-lite:publish:quality', [
                '--target' => 'all',
                '--force' => $force,
            ]);
            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        if ($module !== null) {
            $result = $this->call('ddd-lite:module', [
                'name' => $module,
                '--force' => $force,
                '--yes' => true,
            ]);
            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        if ($ci === 'show') {
            $this->line('');
            $this->info('CI snippet (GitHub Actions):');
            $this->line($this->ciSnippet());
        } elseif ($ci === 'write') {
            $this->writeCiFile($ciPath, $force);
        }

        $this->successBox('DDD-Lite init complete.');
        return self::SUCCESS;
    }

    private function requireString(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new RuntimeException("Invalid {$label} value.");
        }

        return $value;
    }

    /**
     * @throws RandomException
     * @throws JsonException
     */
    private function writeCiFile(string $path, bool $force): void
    {
        $manifest = Manifest::begin($this->files);

        try {
            $this->safe->writeNew($manifest, $path, $this->ciSnippet(), $force);
            $manifest->save();
            $this->line('CI file: ' . $this->rel(base_path($path)));
            $this->line('Manifest: ' . $manifest->id());
        } catch (Throwable $e) {
            $this->error('CI write failed: ' . $e->getMessage());
            $manifest->save();
            $manifest->rollback();
            throw $e;
        }
    }

    private function ciSnippet(): string
    {
        return <<<YAML
name: DDD-Lite Checks

on:
  push:
    branches: [main]
  pull_request:

jobs:
  ddd-lite:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - run: composer install --no-interaction --no-progress --prefer-dist
      - run: php artisan ddd-lite:doctor-ci --json --fail-on=error
YAML;
    }
}
