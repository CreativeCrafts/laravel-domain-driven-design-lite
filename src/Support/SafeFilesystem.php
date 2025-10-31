<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class SafeFilesystem
{
    public function __construct(private Filesystem $fs)
    {
    }

    /** Create a directory (not tracked). Prefer ensureDirTracked() in scaffolds. */
    public function ensureDir(string $relative): void
    {
        $this->fs->ensureDirectoryExists(base_path($relative));
    }

    /**
     * Create a directory and record the creation in the manifest
     * so rollback can delete it recursively.
     */
    public function ensureDirTracked(Manifest $manifest, string $relative): void
    {
        $abs = base_path($relative);
        if (!is_dir($abs)) {
            $this->fs->ensureDirectoryExists($abs);
            $manifest->trackMkdir($relative);
        }
    }

    /** Writes a brand-new file (tracked). Fails if it exists unless $force. */
    public function writeNew(Manifest $manifest, string $relativePath, string $contents, bool $force = false): void
    {
        $abs = base_path($relativePath);

        if (!$force && file_exists($abs)) {
            throw new RuntimeException("File already exists: {$relativePath}. Use --force to overwrite.");
        }

        $this->fs->ensureDirectoryExists(dirname($abs));
        $this->fs->put($abs, $contents);
        $manifest->trackCreate($relativePath);
    }

    /**
     * Overwrites an existing file, creating a backup for rollback.
     * If the file doesn't exist, it is treated as a create.
     */
    public function overwrite(Manifest $manifest, string $relativePath, string $contents): void
    {
        $abs = base_path($relativePath);
        $this->fs->ensureDirectoryExists(dirname($abs));

        if (file_exists($abs)) {
            $backup = storage_path('app/ddd-lite_scaffold/backups/' . str_replace(['\\', '/'], '_', $relativePath) . '.bak');
            $this->fs->ensureDirectoryExists(dirname($backup));
            @copy($abs, $backup);
            $manifest->trackUpdate($relativePath, $backup);
        } else {
            $manifest->trackCreate($relativePath);
        }

        $this->fs->put($abs, $contents);
    }
}
