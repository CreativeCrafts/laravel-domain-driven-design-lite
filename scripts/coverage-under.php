#!/usr/bin/env php
<?php
declare(strict_types=1);

// Simple Clover XML scanner to list classes/files under a given coverage threshold.
// Usage examples:
//   php scripts/coverage-under.php --clover=build/coverage/clover.xml --threshold=80
//   php scripts/coverage-under.php --clover=clover.xml --threshold=90 --fail

$options = getopt('', ['clover:', 'threshold::', 'fail']);
$cloverPath = $options['clover'] ?? 'build/coverage/clover.xml';
$threshold = isset($options['threshold']) ? (float) $options['threshold'] : 80.0;
$fail = array_key_exists('fail', $options);

if (! is_string($cloverPath) || $cloverPath === '') {
    fwrite(STDERR, "Error: --clover path must be provided.\n");
    exit(2);
}

if (! file_exists($cloverPath)) {
    fwrite(STDERR, "Error: Clover file not found at {$cloverPath}. Run coverage first (e.g., composer coverage:clover).\n");
    exit(2);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Error: Failed to parse clover XML at {$cloverPath}.\n");
    exit(2);
}

$underCovered = [];

// Clover structure: <coverage><project><package?><file><class><metrics .../></class></file></project></coverage>
// We'll iterate over all <file> nodes and then classes within.
$files = $xml->xpath('//file');
if ($files === false) {
    fwrite(STDERR, "Error: Unexpected clover structure (no <file> nodes).\n");
    exit(2);
}

foreach ($files as $file) {
    $fileName = (string) ($file['name'] ?? '');

    // First, attempt per-class metrics when present.
    $classes = $file->class ?? [];
    $hasAnyClass = false;

    foreach ($classes as $class) {
        $hasAnyClass = true;
        $className = (string) ($class['name'] ?? basename($fileName));
        $metrics = $class->metrics ?? null;

        $statements = null;
        $covered = null;
        if ($metrics) {
            $statements = isset($metrics['statements']) ? (int) $metrics['statements'] : null;
            $covered = isset($metrics['coveredstatements']) ? (int) $metrics['coveredstatements'] : null;
        }

        // Fallback: compute from line data within this class scope not readily available; skip if unknown
        if ($statements === null || $covered === null) {
            // As a pragmatic fallback, skip class-level calculation if metrics are not present
            // and rely on file-level metrics later.
            continue;
        }

        if ($statements > 0) {
            $pct = $covered / $statements * 100.0;
            if ($pct < $threshold) {
                $underCovered[] = [
                    'scope' => 'class',
                    'name' => $className,
                    'file' => $fileName,
                    'pct' => $pct,
                    'covered' => $covered,
                    'statements' => $statements,
                ];
            }
        }
    }

    // If no classes were present or class metrics were unavailable, use file-level metrics.
    if (! $hasAnyClass) {
        $metrics = $file->metrics ?? null;
        if ($metrics) {
            $statements = isset($metrics['statements']) ? (int) $metrics['statements'] : 0;
            $covered = isset($metrics['coveredstatements']) ? (int) $metrics['coveredstatements'] : 0;
            if ($statements > 0) {
                $pct = $covered / $statements * 100.0;
                if ($pct < $threshold) {
                    $underCovered[] = [
                        'scope' => 'file',
                        'name' => $fileName,
                        'file' => $fileName,
                        'pct' => $pct,
                        'covered' => $covered,
                        'statements' => $statements,
                    ];
                }
            }
        }
    }
}

usort($underCovered, function ($a, $b) {
    return $a['pct'] <=> $b['pct'];
});

if (empty($underCovered)) {
    echo "All classes/files meet the {$threshold}% coverage threshold.\n";
    exit(0);
}

$widthName = 0;
$widthFile = 0;
foreach ($underCovered as $row) {
    $widthName = max($widthName, strlen($row['name']));
    $widthFile = max($widthFile, strlen($row['file']));
}

printf("Under-covered units (< %.2f%%)\n", $threshold);
printf("%-6s  %-".$widthName."s  %-".$widthFile."s  %8s  %9s\n", 'Scope', 'Name', 'File', 'Coverage', 'Covered');
echo str_repeat('-', 8 + 2 + $widthName + 2 + $widthFile + 2 + 8 + 2 + 9) . "\n";
foreach ($underCovered as $row) {
    printf(
        "%-6s  %-".$widthName."s  %-".$widthFile."s  %7.2f%%  %d/%d\n",
        $row['scope'],
        $row['name'],
        $row['file'],
        $row['pct'],
        $row['covered'],
        $row['statements']
    );
}

if ($fail) {
    exit(1);
}

exit(0);
