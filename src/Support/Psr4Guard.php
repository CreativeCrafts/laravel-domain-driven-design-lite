<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

final readonly class Psr4Guard
{
    public function __construct(
        private Filesystem $fs = new Filesystem()
    ) {
    }

    /**
     * Ensure composer.json has "Modules\\": "modules/". If not, patch it and track it in a manifest.
     *
     * @throws JsonException
     */
    public function ensureModulesMapping(Manifest $manifest): void
    {
        $composer = base_path('composer.json');
        if (!is_file($composer)) {
            throw new RuntimeException('composer.json not found at project root.');
        }

        $raw = file_get_contents($composer);
        if ($raw === false) {
            throw new RuntimeException('composer.json could not be read.');
        }

        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new RuntimeException('composer.json is not valid JSON.');
        }

        $before = $json;
        $autoload = isset($json['autoload']) && is_array($json['autoload']) ? $json['autoload'] : [];
        $psr4 = isset($autoload['psr-4']) && is_array($autoload['psr-4']) ? $autoload['psr-4'] : [];

        if (!isset($psr4['Modules\\'])) {
            $psr4['Modules\\'] = 'modules/';
            $autoload['psr-4'] = $psr4;
            $json['autoload'] = $autoload;

            file_put_contents(
                $composer,
                json_encode($json, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            );

            $manifest->trackComposerPatch($before, $json);
        }
    }

    /**
     * Validate that the module path segments match its namespace case.
     * We require: modules/<Module>/(App|Domain|Database|Routes).
     * If $autoFix is true, perform safe renames; track them in Manifest.
     */
    public function assertOrFixCase(
        string $module,
        bool $dryRun,
        bool $autoFix,
        callable $logger,
        ?Manifest $manifest = null
    ): void {
        $base = base_path("modules/{$module}");
        $expected = [
            "{$base}/App",
            "{$base}/Domain",
            "{$base}/Database",
            "{$base}/Routes",
        ];

        $foundLower = [
            "{$base}/app",
            "{$base}/domain",
            "{$base}/database",
            "{$base}/routes",
        ];

        if (!is_dir($base)) {
            return;
        }

        $allOk = true;
        foreach ($expected as $dir) {
            if (!is_dir($dir)) {
                $allOk = false;
                break;
            }
        }
        if ($allOk) {
            return;
        }

        $hasLower = false;
        foreach ($foundLower as $lc) {
            if (is_dir($lc)) {
                $hasLower = true;
                break;
            }
        }
        if (!$hasLower) {
            return;
        }

        if (!$autoFix) {
            throw new RuntimeException("PSR-4 violation: detected lowercase module subdirectories under modules/{$module}. Re-run with --fix or rename to App/Domain/Database/Routes.");
        }

        foreach ($foundLower as $idx => $lc) {
            if (!is_dir($lc)) {
                continue;
            }
            $target = $expected[$idx];

            $relFrom = $this->rel($lc);
            $relTo = $this->rel($target);

            if ($dryRun) {
                $logger("would rename: {$relFrom} -> {$relTo}");
                continue;
            }

            $this->fs->ensureDirectoryExists(dirname($target));
            if (!@rename($lc, $target)) {
                throw new RuntimeException("Failed to rename {$relFrom} to {$relTo}");
            }
            $manifest?->trackRename($relFrom, $relTo);
            $logger("renamed: {$relFrom} -> {$relTo}");
        }
    }

    private function rel(string $abs): string
    {
        return ltrim(str_replace(base_path(), '', $abs), DIRECTORY_SEPARATOR);
    }
}
