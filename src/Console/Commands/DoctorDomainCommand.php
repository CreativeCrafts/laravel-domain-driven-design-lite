<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use FilesystemIterator;

final class DoctorDomainCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:doctor:domain
        {--config=deptrac.yaml : Deptrac configuration file to use}
        {--bin=vendor/bin/deptrac : Path to deptrac executable}
        {--json : Emit machine-readable JSON summary}
        {--strict : Treat uncovered as failure}
        {--stdin-report= : Deptrac JSON report payload (skips invoking deptrac)}
        {--fail-on=violations : Failure policy: violations|errors|uncovered|any}';

    protected $description = 'Run Deptrac and summarize DDD-Lite rules.';

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $bin = (string)$this->option('bin');
        $config = (string)$this->option('config');
        $wantJson = (bool)$this->option('json');
        $strict = (bool)$this->option('strict');
        $stdinReport = $this->option('stdin-report');
        $failOn = (string)($this->option('fail-on') ?? 'violations');

        if (is_string($stdinReport) && $stdinReport !== '') {
            return $this->handleStdinReport($stdinReport, $failOn, $wantJson);
        }

        // Conversion health is independent of Deptrac; compute it once here.
        $conversionHealth = $this->analyzeConversionHealth();

        // Fast-fail on missing deptrac binary (this is what the failing test is exercising).
        if (!is_file($bin)) {
            $totals = [
                'violations' => 0,
                'uncovered' => 0,
                'allowed' => 0,
                'warnings' => 0,
                'errors' => 0,
            ];

            $message = "Deptrac binary not found at {$bin}. Install it in your application: composer require --dev deptrac/deptrac";

            if ($wantJson) {
                $payload = [
                    'tool' => 'deptrac',
                    'config' => $config,
                    'bin' => $bin,
                    'result' => 'FAILED',
                    'time_ms' => 0,
                    'totals' => $totals,
                    'strict' => $strict,
                    'exit_code' => 1,
                    'parse_error' => $message,
                    'conversion_health' => $conversionHealth,
                ];

                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->warnBox($message);
                $this->renderConversionHealthSummary($conversionHealth);
            }

            return self::FAILURE;
        }

        if (!is_file($config)) {
            throw new RuntimeException("Config not found at {$config}");
        }

        $cmd = escapeshellcmd($bin)
            . ' analyse'
            . ' --config=' . escapeshellarg($config)
            . ' --formatter=json';

        $start = (int)(microtime(true) * 1000);
        $raw = [];
        $exit = 0;
        @exec($cmd . ' 2>&1', $raw, $exit);
        $elapsedMs = (int)(microtime(true) * 1000) - $start;

        $stdout = implode("\n", $raw);

        $totals = [
            'violations' => 0,
            'uncovered' => 0,
            'allowed' => 0,
            'warnings' => 0,
            'errors' => 0,
        ];

        $parseError = null;
        $decoded = $this->tryDecodeDeptracJson($stdout, $parseError);

        if (is_array($decoded)) {
            $report = $decoded['report'] ?? $decoded;
            if (is_array($report)) {
                $totals['violations'] = $this->extractInt($report, 'violationsCount', 'violations');
                $totals['uncovered'] = $this->extractInt($report, 'uncoveredCount', 'uncovered');
                $totals['warnings'] = $this->extractInt($report, 'warningsCount', 'warnings');
                $totals['errors'] = $this->extractInt($report, 'errorsCount', 'errors');
                $totals['allowed'] = $this->extractInt($report, 'allowedCount', 'allowed');
            }
        }

        $failed = $exit !== 0
            || $totals['errors'] > 0
            || $totals['violations'] > 0
            || ($strict && $totals['uncovered'] > 0);

        if ($wantJson) {
            $payload = [
                'tool' => 'deptrac',
                'config' => $config,
                'bin' => $bin,
                'result' => $failed ? 'FAILED' : 'PASSED',
                'time_ms' => $elapsedMs,
                'totals' => $totals,
                'strict' => $strict,
                'exit_code' => $exit,
                'parse_error' => $parseError,
                'conversion_health' => $conversionHealth,
            ];

            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->summary('ddd-lite:doctor:domain summary', [
                'Config' => $config,
                'Binary' => $bin,
                'Result' => $failed ? 'FAILED' : 'PASSED',
                'Time (ms)' => (string)$elapsedMs,
            ]);

            $this->line(
                sprintf(
                    'Totals: violations=%d, uncovered=%d, allowed=%d, warnings=%d, errors=%d',
                    $totals['violations'],
                    $totals['uncovered'],
                    $totals['allowed'],
                    $totals['warnings'],
                    $totals['errors'],
                )
            );

            if ($parseError !== null) {
                $this->warnBox('Could not parse Deptrac JSON output (falling back to exit code): ' . $parseError);
            }

            if (!$failed && $totals['uncovered'] > 0 && !$strict) {
                $this->warnBox('There are uncovered files. This is a WARNING by default. Use --strict to fail on uncovered.');
            }

            if ($exit !== 0 && $totals['violations'] === 0 && $totals['errors'] === 0) {
                $this->warnBox('Deptrac returned a non-zero exit (likely configured to fail on uncovered).');
            }

            $this->renderConversionHealthSummary($conversionHealth);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Handle the case where a JSON report is provided directly via --stdin-report.
     *
     * @param array<string,int> $totals
     */
    private function shouldFail(string $failOn, array $totals, bool $hasParseError): bool
    {
        if ($hasParseError) {
            return true;
        }

        return match ($failOn) {
            'errors' => $totals['errors'] > 0,
            'uncovered' => $totals['uncovered'] > 0,
            'any' => $totals['violations'] > 0
                || $totals['errors'] > 0
                || $totals['uncovered'] > 0,
            default => $totals['violations'] > 0,
        };
    }

    /**
     * @throws JsonException
     */
    private function handleStdinReport(string $reportJson, string $failOn, bool $wantJson): int
    {
        $totals = [
            'violations' => 0,
            'uncovered' => 0,
            'allowed' => 0,
            'warnings' => 0,
            'errors' => 0,
        ];

        $parseError = null;

        try {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode($reportJson, true, 512, JSON_THROW_ON_ERROR);
            $report = $decoded['report'] ?? $decoded;

            if (is_array($report)) {
                $totals['violations'] = $this->extractInt($report, 'violations', 'violationsCount');
                $totals['uncovered'] = $this->extractInt($report, 'uncovered', 'uncoveredCount');
                $totals['warnings'] = $this->extractInt($report, 'warnings', 'warningsCount');
                $totals['errors'] = $this->extractInt($report, 'errors', 'errorsCount');
                $totals['allowed'] = $this->extractInt($report, 'allowed', 'allowedCount');
            }
        } catch (JsonException $e) {
            $parseError = $e->getMessage();
        }

        $failed = $this->shouldFail($failOn, $totals, $parseError !== null);

        // Conversion health is still relevant when consuming stdin.
        $conversionHealth = $this->analyzeConversionHealth();

        if ($wantJson) {
            $payload = [
                'tool' => 'deptrac',
                'config' => '(stdin)',
                'bin' => '(stdin)',
                'result' => $failed ? 'FAILED' : 'PASSED',
                'time_ms' => 0,
                'totals' => $totals,
                'fail_on' => $failOn,
                'parse_error' => $parseError,
                'conversion_health' => $conversionHealth,
            ];

            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->summary('ddd-lite:doctor:domain summary', [
                'Config' => '(stdin)',
                'Binary' => '(stdin)',
                'Result' => $failed ? 'FAILED' : 'PASSED',
                'Time (ms)' => '0',
            ]);

            $this->line(
                sprintf(
                    'Totals: violations=%d, uncovered=%d, allowed=%d, warnings=%d, errors=%d',
                    $totals['violations'],
                    $totals['uncovered'],
                    $totals['allowed'],
                    $totals['warnings'],
                    $totals['errors'],
                )
            );

            if ($parseError !== null) {
                $this->warnBox('Could not parse Deptrac JSON report from stdin: ' . $parseError);
            }

            $this->renderConversionHealthSummary($conversionHealth);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<mixed,mixed> $arr
     */
    private function extractInt(array $arr, string ...$keys): int
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $arr)) {
                $v = $arr[$k];
                if (is_int($v)) {
                    return $v;
                }
                if (is_string($v) && is_numeric($v)) {
                    return (int)$v;
                }
            }
        }
        return 0;
    }

    /**
     * Try to decode Deptrac JSON output safely.
     *
     * @return array<string,mixed>|null
     */
    private function tryDecodeDeptracJson(string $stdout, ?string &$parseError): ?array
    {
        $parseError = null;

        if (isset($stdout[0]) && $stdout[0] === '{') {
            try {
                /** @var array<string,mixed> $data */
                $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

                return $data;
            } catch (JsonException $e) {
                $parseError = $e->getMessage();
            }
        }

        $startPos = strpos($stdout, '{');
        $endPos = strrpos($stdout, '}');

        if ($startPos !== false && $endPos !== false && $endPos > $startPos) {
            $slice = substr($stdout, (int)$startPos, (int)($endPos - $startPos + 1));

            try {
                /** @var array<string,mixed> $data */
                $data = json_decode($slice, true, 512, JSON_THROW_ON_ERROR);

                return $data;
            } catch (JsonException $e) {
                $parseError = $e->getMessage();

                return null;
            }
        }

        $parseError ??= 'No JSON payload detected in Deptrac output.';

        return null;
    }

    /**
     * Analyze basic "conversion health" by looking for legacy app/ classes
     * that are still referenced from modules/*.
     *
     * @return array<string,mixed>
     */
    private function analyzeConversionHealth(): array
    {
        $legacyRoots = [
            base_path('app/Http/Controllers'),
            base_path('app/Http/Requests'),
            base_path('app/Models'),
            base_path('app/Actions'),
            base_path('app/DTO'),
            base_path('app/Contracts'),
        ];

        /** @var array<string,array{class:string,path:string}> $legacyMap */
        $legacyMap = [];

        foreach ($legacyRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                if (strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $code = @file_get_contents($file->getPathname());
                if ($code === false) {
                    continue;
                }

                $namespace = null;
                $className = null;

                if (preg_match('/^namespace\s+([^;]+);/m', $code, $m)) {
                    $namespace = trim($m[1]);
                }

                if (preg_match('/\b(class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $code, $m)) {
                    $className = $m[2];
                }

                if ($namespace === null || $className === null) {
                    continue;
                }

                $fqn = $namespace . '\\' . $className;

                $rel = str_replace('\\', '/', $file->getPathname());
                $base = rtrim(str_replace('\\', '/', base_path()), '/');
                if (str_starts_with($rel, $base . '/')) {
                    $rel = substr($rel, strlen($base) + 1);
                }

                $legacyMap[$fqn] = [
                    'class' => $fqn,
                    'path' => $rel,
                ];
            }
        }

        if ($legacyMap === []) {
            return [
                'legacy_classes_in_app_referenced_from_modules' => [],
                'legacy_classes_in_app_referenced_from_modules_count' => 0,
            ];
        }

        $modulesRoot = base_path('modules');
        if (!is_dir($modulesRoot)) {
            return [
                'legacy_classes_in_app_referenced_from_modules' => [],
                'legacy_classes_in_app_referenced_from_modules_count' => 0,
            ];
        }

        // Collect all module PHP files and their contents at once.
        /** @var array<string,string> $moduleFiles */
        $moduleFiles = [];

        $modIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modulesRoot, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($modIterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $code = @file_get_contents($file->getPathname());
            if ($code === false) {
                continue;
            }

            $rel = str_replace('\\', '/', $file->getPathname());
            $base = rtrim(str_replace('\\', '/', base_path()), '/');
            if (str_starts_with($rel, $base . '/')) {
                $rel = substr($rel, strlen($base) + 1);
            }

            $moduleFiles[$rel] = $code;
        }

        if ($moduleFiles === []) {
            return [
                'legacy_classes_in_app_referenced_from_modules' => [],
                'legacy_classes_in_app_referenced_from_modules_count' => 0,
            ];
        }

        $legacyReferenced = [];

        foreach ($legacyMap as $fqn => $info) {
            foreach ($moduleFiles as $rel => $code) {
                if (str_contains($code, $fqn)) {
                    $legacyReferenced[] = [
                        'legacy_class' => $info['class'],
                        'legacy_path' => $info['path'],
                    ];
                    break;
                }
            }
        }

        return [
            'legacy_classes_in_app_referenced_from_modules' => $legacyReferenced,
            'legacy_classes_in_app_referenced_from_modules_count' => count($legacyReferenced),
        ];
    }

    /**
     * Render a short console summary of conversion health (non-JSON mode).
     *
     * @param array<string,mixed> $conversionHealth
     */
    private function renderConversionHealthSummary(array $conversionHealth): void
    {
        $entries = $conversionHealth['legacy_classes_in_app_referenced_from_modules'] ?? [];

        if (!is_array($entries) || count($entries) === 0) {
            return;
        }

        $this->line('');
        $this->info('[Conversion Health]');
        $this->line('Legacy app/ classes referenced from modules:');

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $path = isset($entry['legacy_path']) && is_string($entry['legacy_path']) ? $entry['legacy_path'] : '';
            $class = isset($entry['legacy_class']) && is_string($entry['legacy_class']) ? $entry['legacy_class'] : '';

            if ($path === '' && $class === '') {
                continue;
            }

            $label = $path !== '' ? $path : $class;
            $this->line(' - ' . $label);
        }
    }
}
