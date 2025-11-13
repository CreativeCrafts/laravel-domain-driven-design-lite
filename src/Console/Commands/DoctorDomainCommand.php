<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use JsonException;
use RuntimeException;

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
                ];

                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->warnBox($message);
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

            $totals['violations'] = (int)($report['violationsCount'] ?? $report['violations'] ?? 0);
            $totals['uncovered'] = (int)($report['uncoveredCount'] ?? $report['uncovered'] ?? 0);
            $totals['warnings'] = (int)($report['warningsCount'] ?? $report['warnings'] ?? 0);
            $totals['errors'] = (int)($report['errorsCount'] ?? $report['errors'] ?? 0);
            $totals['allowed'] = (int)($report['allowedCount'] ?? $report['allowed'] ?? 0);
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

            $totals['violations'] = (int)($report['violations'] ?? $report['violationsCount'] ?? 0);
            $totals['uncovered'] = (int)($report['uncovered'] ?? $report['uncoveredCount'] ?? 0);
            $totals['warnings'] = (int)($report['warnings'] ?? $report['warningsCount'] ?? 0);
            $totals['errors'] = (int)($report['errors'] ?? $report['errorsCount'] ?? 0);
            $totals['allowed'] = (int)($report['allowed'] ?? $report['allowedCount'] ?? 0);
        } catch (JsonException $e) {
            $parseError = $e->getMessage();
        }

        $failed = $this->shouldFail($failOn, $totals, $parseError !== null);

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
        }

        return $failed ? self::FAILURE : self::SUCCESS;
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
}
