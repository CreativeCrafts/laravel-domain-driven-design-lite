<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class ClassFixer
{
    public function __construct(
        private Filesystem $fs = new Filesystem()
    ) {
    }

    public function renameClassInFile(Manifest $manifest, string $file, string $fromShort, string $toShort): void
    {
        if (!is_file($file)) {
            throw new RuntimeException("File not found: {$file}");
        }

        $code = (string)file_get_contents($file);
        $pattern = '/\b(final\s+)?class\s+' . $this->escape($fromShort) . '\b/u';
        $replacement = '$1class ' . $toShort;
        $updated = (string)preg_replace($pattern, $replacement, $code, 1);

        if ($updated === $code) {
            throw new RuntimeException("Class declaration not found or already renamed in {$file}");
        }

        $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($file) . '.bak');
        $this->fs->ensureDirectoryExists(dirname($backup));
        $this->fs->put($backup, $code);
        $manifest->trackUpdate($this->rel($file), $backup);

        $this->fs->put($file, $updated);
    }

    public function renameFile(Manifest $manifest, string $from, string $to): void
    {
        if (!is_file($from)) {
            throw new RuntimeException("File not found: {$from}");
        }
        $this->fs->ensureDirectoryExists(dirname($to));
        if (!@rename($from, $to)) {
            throw new RuntimeException("Failed to rename {$from} to {$to}");
        }
        $manifest->trackRename($this->rel($from), $this->rel($to));
    }

    private function escape(string $s): string
    {
        return preg_quote($s, '/');
    }

    private function rel(string $abs): string
    {
        return ltrim(str_replace(base_path(), '', $abs), DIRECTORY_SEPARATOR);
    }
}
