<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support\Doctor;

use Illuminate\Support\Arr;
use JsonException;

final class DoctorCiJson
{
    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<string, mixed>
     * @throws JsonException
     */
    public static function build(array $issues): array
    {
        $errors = self::countBy($issues, 'error');
        $warnings = self::countBy($issues, 'warning');

        $normalized = array_map(static function (array $i): array {
            $severity = is_string($i['severity'] ?? null) ? $i['severity'] : 'warning';
            $type = is_string($i['type'] ?? null) ? $i['type'] : 'unknown';
            $message = is_string($i['message'] ?? null) ? $i['message'] : '';
            $file = is_string($i['file'] ?? null) ? $i['file'] : '';
            $line = is_int($i['line'] ?? null) ? $i['line'] : (is_string($i['line'] ?? null) && is_numeric($i['line']) ? (int)$i['line'] : 0);
            $column = is_int($i['column'] ?? null) ? $i['column'] : (is_string($i['column'] ?? null) && is_numeric($i['column']) ? (int)$i['column'] : 0);
            $doctorKey = Arr::get($i, 'doctorKey');
            $suggestion = Arr::get($i, 'suggestion');
            $fixable = Arr::get($i, 'fixable');

            return [
                'type' => in_array($severity, ['error', 'warning'], true) ? $severity : 'warning',
                'code' => $type,
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'column' => $column,
                'doctorKey' => is_string($doctorKey) ? $doctorKey : '',
                'suggestion' => is_string($suggestion) ? $suggestion : '',
                'fixable' => (bool)$fixable,
            ];
        }, $issues);

        usort($normalized, static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['code']] <=> [$b['file'], $b['line'], $b['code']];
        });

        return [
            'version' => '1.0.0',
            'generatedAt' => gmdate('c'),
            'status' => $errors > 0 ? 'fail' : 'ok',
            'metadata' => [
                'packageVersion' => self::packageVersion(),
                'ci' => (bool)getenv('CI'),
            ],
            'totals' => [
                'errors' => $errors,
                'warnings' => $warnings,
                'fixable' => self::countFixable($normalized),
            ],
            'violations' => $normalized,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     */
    private static function countBy(array $issues, string $severity): int
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
     * @param array<int, array<string, mixed>> $violations
     */
    private static function countFixable(array $violations): int
    {
        $n = 0;
        foreach ($violations as $v) {
            if (!empty($v['fixable'])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @throws JsonException
     */
    private static function packageVersion(): string
    {
        $paths = [
            function_exists('base_path') ? base_path('composer.json') : null,
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'composer.json',
        ];

        foreach ($paths as $p) {
            if (is_string($p) && is_file($p)) {
                $json = @file_get_contents($p);
                if ($json === false) {
                    continue;
                }
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    $v = $data['version'] ?? null;
                    if (is_string($v) && $v !== '') {
                        return $v;
                    }
                }
            }
        }

        return 'unknown';
    }
}
