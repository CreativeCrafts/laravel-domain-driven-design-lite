<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\PhpUseEditor;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;

final class BindContractCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:bind
        {module : Module name in PascalCase}
        {contract : Contract short name without "Contract" suffix, or FQCN}
        {implementation? : Implementation class short name or FQCN}
        {--dry-run : Preview without writing}
        {--force : Skip class existence checks}
        {--rollback= : Rollback a previous run via manifest id}';

    protected $description = 'Bind a Domain Contract to an implementation in ModuleServiceProvider::register().';

    /**
     * @throws RandomException
     * @throws JsonException|FileNotFoundException
     */
    public function handle(): int
    {
        $this->prepare();

        $rollback = (string)($this->option('rollback') ?? '');
        if ($rollback !== '') {
            $m = $this->loadManifestOrFail($rollback);
            $m->rollback();
            $this->info("Rollback complete for {$rollback}.");
            return self::SUCCESS;
        }

        $module = Str::studly((string)$this->argument('module'));
        $contractArg = (string)$this->argument('contract');
        $implArg = (string)($this->argument('implementation') ?? '');
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $moduleRoot = base_path("modules/{$module}");
        if (!is_dir($moduleRoot)) {
            throw new RuntimeException("Module not found: {$module}");
        }

        $providerPath = "{$moduleRoot}/App/Providers/{$module}ServiceProvider.php";
        if (!is_file($providerPath)) {
            throw new RuntimeException('ModuleServiceProvider not found: ' . $this->rel($providerPath));
        }

        $contractFqcn = $this->resolveContractFqcn($module, $contractArg);
        $implFqcn = $this->resolveImplementationFqcn($module, $contractArg, $implArg);

        if (!$force) {
            if (!class_exists($implFqcn)) {
                throw new RuntimeException("Implementation class not found or not autoloadable: {$implFqcn}");
            }
            if (!interface_exists($contractFqcn) && !class_exists($contractFqcn)) {
                throw new RuntimeException("Contract interface not found or not autoloadable: {$contractFqcn}");
            }
        }

        $this->twoColumn('Module', $module);
        $this->twoColumn('Contract', $contractFqcn);
        $this->twoColumn('Implementation', $implFqcn);
        $this->twoColumn('Provider', $this->rel($providerPath));
        $this->twoColumn('Dry run', $dry ? 'yes' : 'no');

        $original = (string)$this->files->get($providerPath);

        $editor = new PhpUseEditor();
        $codeWithUses = $editor->ensureImports($original, [$contractFqcn, $implFqcn]);

        $bindLine = '$this->app->bind(' . class_basename($contractFqcn) . '::class, ' . class_basename($implFqcn) . '::class);';
        $updated = $this->ensureBindingInRegister($codeWithUses, $bindLine);

        if ($dry) {
            $this->line('Preview complete. No changes written.');
            return self::SUCCESS;
        }

        if ($updated === $original) {
            $this->info('No changes detected.');
            return self::SUCCESS;
        }

        $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($providerPath) . '.bak');
        $this->files->ensureDirectoryExists(dirname($backup));
        $this->files->put($backup, $original);

        $manifest = $this->beginManifest();
        $manifest->trackUpdate($this->rel($providerPath), $backup);

        $this->files->put($providerPath, $updated);
        $manifest->save();

        $this->info('Binding added. Manifest: ' . $manifest->id());
        return self::SUCCESS;
    }

    /**
     * @return array<int, array{0:string,1:int,2:string}>
     */
    protected function getArguments(): array
    {
        return [
            ['module', InputArgument::REQUIRED, 'Module name in PascalCase'],
            ['contract', InputArgument::REQUIRED, 'Contract short name or FQCN'],
            ['implementation', InputArgument::OPTIONAL, 'Implementation short name or FQCN'],
        ];
    }

    private function resolveContractFqcn(string $module, string $arg): string
    {
        if (str_contains($arg, '\\')) {
            return ltrim($arg, '\\');
        }
        $base = Str::studly($arg);
        $name = str_ends_with($base, 'Contract') ? $base : $base . 'Contract';
        return "Modules\\{$module}\\Domain\\Contracts\\{$name}";
    }

    private function resolveImplementationFqcn(string $module, string $contractArg, string $implArg): string
    {
        if ($implArg !== '' && str_contains($implArg, '\\')) {
            return ltrim($implArg, '\\');
        }
        if ($implArg !== '') {
            $short = Str::studly($implArg);
            return "Modules\\{$module}\\App\\Repositories\\{$short}";
        }
        $base = Str::studly(str_replace('Contract', '', class_basename($contractArg)));
        return "Modules\\{$module}\\App\\Repositories\\Eloquent{$base}Repository";
    }

    private function ensureBindingInRegister(string $code, string $bindLine): string
    {
        $fnPos = strpos($code, 'function register(');
        if ($fnPos === false) {
            return $code;
        }
        $bracePos = strpos($code, '{', $fnPos);
        if ($bracePos === false) {
            return $code;
        }
        $end = $this->findMatchingBrace($code, $bracePos);
        if ($end === -1) {
            return $code;
        }

        $before = substr($code, 0, $bracePos + 1);
        $body = substr($code, $bracePos + 1, $end - $bracePos - 1);
        $after = substr($code, $end);

        if (str_contains($body, $bindLine)) {
            return $code;
        }

        $trimmed = rtrim($body);
        $nl = $trimmed === '' ? '' : PHP_EOL;
        $newBody = $trimmed . $nl . '        ' . $bindLine . PHP_EOL;

        return $before . $newBody . $after;
    }

    private function findMatchingBrace(string $code, int $openPos): int
    {
        $depth = 0;
        $len = strlen($code);
        for ($i = $openPos; $i < $len; $i++) {
            $c = $code[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }
}
