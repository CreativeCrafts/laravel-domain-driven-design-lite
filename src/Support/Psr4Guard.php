<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

final readonly class Psr4Guard
{
    public function __construct(
        private Filesystem $files = new Filesystem(),
    ) {
    }

    /**
     * Ensure "Modules\\" => "modules/" exists in composer.json autoload.psr-4.
     * Uses canonical manifest ops (trackUpdate) and respects dry-run.
     *
     * @throws JsonException|FileNotFoundException
     */
    public function ensureModulesMapping(Manifest $manifest, bool $dryRun = false): void
    {
        $composerPath = base_path('composer.json');
        if (!$this->files->exists($composerPath)) {
            throw new RuntimeException("composer.json not found at {$composerPath}");
        }

        $raw = (string)$this->files->get($composerPath);
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($json)) {
            throw new RuntimeException('composer.json is not valid JSON.');
        }

        $autoload = $json['autoload'] ?? [];
        if (!is_array($autoload)) {
            $autoload = [];
        }
        $psr4 = $autoload['psr-4'] ?? [];
        if (!is_array($psr4)) {
            $psr4 = [];
        }

        // Already present and correct? nothing to do.
        if (isset($psr4['Modules\\']) && $psr4['Modules\\'] === 'modules/') {
            return;
        }

        // Patch in our mapping.
        $psr4['Modules\\'] = 'modules/';
        $autoload['psr-4'] = $psr4;
        $json['autoload'] = $autoload;

        $encoded = json_encode($json, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        if ($dryRun) {
            // Do not write or track during dry-run; just return after validation.
            return;
        }

        // Back up an old file to a deterministic backup location, then write.
        $relative = 'composer.json';
        $backupRel = 'storage/app/ddd-lite_scaffold/backups/' . sha1($relative) . '.bak';
        $backupAbs = base_path($backupRel);

        // Ensure a backup directory exists.
        $this->files->ensureDirectoryExists(dirname($backupAbs));
        $this->files->put($backupAbs, $raw);

        // Write the new composer.json.
        $this->files->put($composerPath, $encoded);

        // Record the update with backup in manifest.
        $manifest->trackUpdate($relative, $backupRel);
    }

    /**
     * Ensure module directory casing aligns with PSR-4 (PascalCase module folder).
     * If $fix is false, only assert and emit messages via $log; no writes.
     */
    public function assertOrFixCase(string $module, bool $dryRun, bool $fix, callable $log): void
    {
        $modulesRoot = base_path('modules');
        $currentLower = strtolower($module);
        $target = $modulesRoot . DIRECTORY_SEPARATOR . $module;

        // If the correctly cased folder already exists, nothing to do.
        if (is_dir($target)) {
            return;
        }

        // If a lowercased variant exists (common on macOS/Windows), we can optionally fix.
        $maybeLower = $modulesRoot . DIRECTORY_SEPARATOR . $currentLower;
        if ($maybeLower !== $target && is_dir($maybeLower)) {
            if (!$fix) {
                $log("PSR-4 casing mismatch detected for module {$module}; run again with --fix-psr4 to normalize.");
                return;
            }

            if ($dryRun) {
                $log("Would rename {$maybeLower} -> {$target}");
                return;
            }

            // Force a case-only rename safely using a two-step move via a temp folder
            $tmp = $modulesRoot . DIRECTORY_SEPARATOR . '.' . $currentLower . '_tmp_' . substr(sha1((string)microtime(true)), 0, 8);

            // Step 1: lower -> tmp (ensure tmp doesn't exist)
            if (is_dir($tmp)) {
                // Extremely unlikely; adjust suffix
                $tmp .= '_' . substr(sha1((string)random_int(PHP_INT_MIN, PHP_INT_MAX)), 0, 4);
            }

            $step1 = @rename($maybeLower, $tmp);

            // If step1 failed, try direct rename as a fallback; some FS allow it
            if (!$step1) {
                $direct = @rename($maybeLower, $target);
                if ($direct) {
                    $log("Renamed {$maybeLower} -> {$target}");
                    return;
                }
                // If even direct failed, log and exit gracefully without throwing
                $log("Warning: could not normalize folder case for {$module}. Continuing.");
                return;
            }

            // Step 2: tmp -> Target (PascalCase)
            $step2 = @rename($tmp, $target);
            if ($step2) {
                $log("Renamed {$maybeLower} -> {$target}");
                return;
            }

            // If step2 failed, attempt to roll back to original location to avoid leaving tmp behind.
            @rename($tmp, $maybeLower);
            $log("Warning: could not normalize folder case for {$module}. Continuing.");
        }
    }
}
