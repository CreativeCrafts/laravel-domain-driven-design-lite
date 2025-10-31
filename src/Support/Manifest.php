<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Random\RandomException;
use RuntimeException;

/**
 * Records all file and directory operations for rollback.
 * A manifest JSON is written to storage/app/ddd-lite_scaffold/{id}.json
 */
final class Manifest
{
    public function __construct(
        private readonly Filesystem $fs,
        private readonly string $id,
        private array $entries = []
    ) {
    }

    /**
     * @throws RandomException
     */
    public static function begin(Filesystem $fs): self
    {
        $id = now()->format('YmdHis') . '_' . bin2hex(random_bytes(3));
        return new self($fs, $id);
    }

    /**
     * @throws FileNotFoundException
     * @throws JsonException
     */
    public static function load(Filesystem $fs, string $id): ?self
    {
        $path = storage_path("app/ddd-lite_scaffold/{$id}.json");
        if (!$fs->exists($path)) {
            return null;
        }

        $raw = (string)$fs->get($path);

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            // Corrupt or old file; return minimal manifest so rollback doesn't fatal
            return new self($fs, $id, []);
        }

        $loadedId = isset($data['id']) && is_string($data['id']) ? $data['id'] : $id;
        $entries = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : [];

        return new self($fs, $loadedId, $entries);
    }

    public function id(): string
    {
        return $this->id;
    }

    /** Track that we created a brand-new file. */
    public function trackCreate(string $relativePath): void
    {
        $this->entries[] = [
            'type' => 'create',
            'path' => $relativePath,
        ];
    }

    /** Track that we overwrote a file and saved a backup. */
    public function trackUpdate(string $relativePath, string $backupAbsolutePath): void
    {
        $this->entries[] = [
            'type' => 'update',
            'path' => $relativePath,
            'backup' => $backupAbsolutePath,
        ];
    }

    /** Track that we created a directory (so we can delete it recursively on rollback). */
    public function trackMkdir(string $relativePath): void
    {
        $this->entries[] = [
            'type' => 'mkdir',
            'path' => $relativePath,
        ];
    }

    /** Track a rename so rollback can move it back. */
    public function trackRename(string $fromRelative, string $toRelative): void
    {
        $this->entries[] = [
            'type' => 'rename',
            'from' => $fromRelative,
            'to' => $toRelative,
        ];
    }

    /** Track a composer.json PSR-4 patch (so we can restore the file). */
    public function trackComposerPatch(array $before, array $after): void
    {
        $this->entries[] = [
            'type' => 'composer_psr4',
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * @throws JsonException
     */
    public function save(): void
    {
        $this->fs->ensureDirectoryExists(storage_path('app/ddd-lite_scaffold'));
        $this->fs->put(
            storage_path("app/ddd-lite_scaffold/{$this->id}.json"),
            json_encode(['id' => $this->id, 'entries' => $this->entries], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Reverts all tracked changes in reverse order.
     * - Deletes created files
     * - Restores updated files from backup
     * - Deletes created directories recursively
     * - Reverses renames (to <- from)
     * - Restores composer.json to 'before'
     *
     * @throws JsonException
     */
    public function rollback(): void
    {
        foreach (array_reverse($this->entries) as $entry) {
            $type = $entry['type'] ?? '';
            switch ($type) {
                case 'create':
                {
                    $pathRel = (string)($entry['path'] ?? '');
                    if ($pathRel !== '') {
                        $abs = base_path($pathRel);
                        if (is_file($abs)) {
                            @unlink($abs);
                        }
                    }
                    break;
                }

                case 'update':
                {
                    $pathRel = (string)($entry['path'] ?? '');
                    $backup = (string)($entry['backup'] ?? '');
                    if ($pathRel !== '') {
                        $abs = base_path($pathRel);
                        if ($backup !== '' && is_file($backup)) {
                            @copy($backup, $abs);
                            @unlink($backup);
                        }
                    }
                    break;
                }

                case 'mkdir':
                {
                    $pathRel = (string)($entry['path'] ?? '');
                    if ($pathRel !== '') {
                        $absDir = base_path($pathRel);
                        if (is_dir($absDir)) {
                            $this->removeDirectoryRecursive($absDir);
                        }
                    }
                    break;
                }

                case 'rename':
                {
                    $fromRel = (string)($entry['from'] ?? '');
                    $toRel = (string)($entry['to'] ?? '');
                    if ($fromRel !== '' && $toRel !== '') {
                        $absFrom = base_path($fromRel);
                        $absTo = base_path($toRel);
                        if (file_exists($absTo) || is_dir($absTo)) {
                            if (!mkdir($concurrentDirectory = dirname($absFrom), 0777, true) && !is_dir($concurrentDirectory)) {
                                throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                            }
                            @rename($absTo, $absFrom);
                        }
                    }
                    break;
                }

                case 'composer_psr4':
                {
                    $composer = base_path('composer.json');
                    if (is_file($composer)) {
                        $before = $entry['before'] ?? null;
                        if (is_array($before)) {
                            file_put_contents(
                                $composer,
                                json_encode($before, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
                            );
                        }
                    }
                    break;
                }
            }
        }
    }

    private function removeDirectoryRecursive(string $dir): void
    {
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
