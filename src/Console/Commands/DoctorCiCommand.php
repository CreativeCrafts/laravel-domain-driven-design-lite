<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\Doctor\DoctorCiJson;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use SplFileInfo;

final class DoctorCiCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:doctor-ci
        {--json : Emit JSON result in a stable schema}
        {--fail-on=error : CI policy: none|any|error}
        {--paths= : Comma-separated absolute or relative paths to scan (defaults to modules/ and bootstrap/app.php)}';

    protected $description = 'CI-friendly diagnostics: filename vs class, provider placement, routing keys. Read-only.';

    /**
     * @throws JsonException
     * @throws FileNotFoundException
     */
    public function handle(): int
    {
        $this->prepare();

        // Default policy: fail CI when there is at least one "error" severity issue.
        $failOn = strtolower((string)($this->option('fail-on') ?? 'error'));
        if (!in_array($failOn, ['none', 'any', 'error'], true)) {
            throw new RuntimeException('Invalid --fail-on value. Use: none|any|error.');
        }

        $paths = $this->csvOption(allowRelative: true);
        if ($paths === []) {
            $paths = [
                base_path('modules'),
                base_path('bootstrap/app.php'),
            ];
        }

        $issues = $this->runDiagnostics($paths);

        if ($this->option('json') === true) {
            $payload = DoctorCiJson::build($issues);

            $this->line(
                json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRETTY_PRINT
                    | JSON_THROW_ON_ERROR
                )
            );
        } else {
            $this->line('DDD-lite Doctor CI');
            $this->twoColumn('Paths', implode(', ', array_map(function (string $p): string { return $this->rel($p); }, $paths)));

            foreach ($issues as $i) {
                $severity = is_string($i['severity'] ?? null) ? strtoupper($i['severity']) : 'WARNING';
                $type = is_string($i['type'] ?? null) ? $i['type'] : 'unknown';
                $file = is_string($i['file'] ?? null) ? $this->rel($i['file']) : '';
                $message = is_string($i['message'] ?? null) ? $i['message'] : '';

                $this->line(sprintf('[%s] %s | %s | %s', $severity, $type, $file, $message));
            }

            $this->line('');
            $this->twoColumn('Errors', (string)$this->countBySeverity($issues, 'error'));
            $this->twoColumn('Warnings', (string)$this->countBySeverity($issues, 'warning'));
        }

        $exit = 0;
        $errors = $this->countBySeverity($issues, 'error');
        $total = count($issues);

        if ($failOn === 'any' && $total > 0) {
            $exit = 1;
        } elseif ($failOn === 'error' && $errors > 0) {
            $exit = 1;
        }

        return $exit;
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, array<string, mixed>>
     * @throws FileNotFoundException
     */
    private function runDiagnostics(array $paths): array
    {
        $fs = $this->files;
        $out = [];

        foreach ($paths as $p) {
            if (is_dir($p)) {
                /** @var iterable<int, string>|iterable<int, SplFileInfo> $phpFiles */
                $phpFiles = $fs->allFiles($p, true);

                foreach ($phpFiles as $fileInfo) {
                    $file = is_string($fileInfo) ? $fileInfo : (string)$fileInfo;

                    if (!Str::endsWith($file, '.php')) {
                        continue;
                    }

                    if (Str::contains($file, '/Providers/') && Str::endsWith($file, 'ServiceProvider.php')) {
                        array_push($out, ...$this->checkFilenameVsClass($file, 'provider_classname_mismatch'));
                    }

                    if (Str::contains($file, '/Controllers/') && Str::endsWith($file, 'Controller.php')) {
                        array_push($out, ...$this->checkFilenameVsClass($file, 'controller_classname_mismatch'));
                    }
                }
            } elseif (is_file($p)) {
                if (Str::endsWith($p, 'bootstrap/app.php')) {
                    array_push($out, ...$this->checkProviderPlacement($p));
                    array_push($out, ...$this->checkRoutingKeys($p));
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws FileNotFoundException
     */
    private function checkFilenameVsClass(string $path, string $type): array
    {
        $code = (string)$this->files->get($path);
        $declared = $this->extractClassName($code);
        $expected = pathinfo($path, PATHINFO_FILENAME);

        if ($declared === '' || $declared !== $expected) {
            return [
                [
                    'type' => $type,
                    'severity' => 'error',
                    'file' => $path,
                    'declared' => $declared,
                    'expected' => $expected,
                    'message' => "Declared class '{$declared}' does not match filename '{$expected}'.",
                ]
            ];
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws FileNotFoundException
     */
    private function checkProviderPlacement(string $bootstrap): array
    {
        $code = (string)$this->files->get($bootstrap);

        $insideConfigure = Str::contains($code, 'Application::configure(')
            && Str::contains($code, '->withProviders(');

        $strayAppWithProviders = Str::contains($code, '$app')
            && Str::contains($code, '->withProviders(')
            && !Str::contains($code, 'Application::configure(');

        $issues = [];

        if (!$insideConfigure) {
            $issues[] = [
                'type' => 'provider_outside_configure',
                'severity' => 'error',
                'file' => $bootstrap,
                'message' => 'Providers must be registered inside Application::configure(...)->withProviders([...]).',
            ];
        }

        if ($strayAppWithProviders) {
            $issues[] = [
                'type' => 'stray_app_withProviders_call',
                'severity' => 'warning',
                'file' => $bootstrap,
                'message' => 'Found a stray $app->withProviders(...) call outside configure chain.',
            ];
        }

        return $issues;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws FileNotFoundException
     */
    private function checkRoutingKeys(string $bootstrap): array
    {
        $code = (string)$this->files->get($bootstrap);
        $hasRouting = Str::contains($code, '->withRouting(');

        if (!$hasRouting) {
            return [
                [
                    'type' => 'missing_withRouting',
                    'severity' => 'warning',
                    'file' => $bootstrap,
                    'message' => 'Missing ->withRouting(...) call.',
                ]
            ];
        }

        $needsApi = !Str::contains($code, 'api:');
        $needsChannels = !Str::contains($code, 'channels:');

        $out = [];

        if ($needsApi) {
            $out[] = [
                'type' => 'missing_routing_api',
                'severity' => 'warning',
                'file' => $bootstrap,
                'message' => 'withRouting(...) is missing api: key.',
            ];
        }

        if ($needsChannels) {
            $out[] = [
                'type' => 'missing_routing_channels',
                'severity' => 'warning',
                'file' => $bootstrap,
                'message' => 'withRouting(...) is missing channels: key.',
            ];
        }

        return $out;
    }

    private function extractClassName(string $code): string
    {
        $m = [];

        if (preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s/u', $code, $m) === 1) {
            return (string)$m[1];
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     */
    private function countBySeverity(array $issues, string $severity): int
    {
        $n = 0;

        foreach ($issues as $i) {
            if (($i['severity'] ?? 'warning') === $severity) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array<int, string>
     */
    private function csvOption(bool $allowRelative = false): array
    {
        $raw = (string)($this->option('paths') ?? '');

        if ($raw === '') {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));

        if ($allowRelative) {
            return array_map(
                static function (string $p): string {
                    return str_starts_with($p, DIRECTORY_SEPARATOR)
                    || preg_match('#^[A-Za-z]:#', $p) === 1
                        ? $p
                        : base_path($p);
                },
                $parts
            );
        }

        return $parts;
    }
}
