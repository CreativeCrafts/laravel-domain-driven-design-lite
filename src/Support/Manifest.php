<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Random\RandomException;
use RuntimeException;

final class Manifest
{
    /**
     * Storage directory (relative to storage_path()) for manifest files.
     */
    private const string DIR = 'app/ddd-lite_scaffold/manifests';

    /**
     * Unique manifest identifier.
     */
    private string $id;

    /**
     * Canonical list of atomic actions recorded by generators/fixers.
     *
     * @var array<int, array{type:string, path:string, backup:?string, to?:string}>
     */
    private array $actions = [];

    /**
     * @throws RandomException
     */
    public function __construct(
        private readonly Filesystem $fs,
        ?string $id = null,
    ) {
        $this->id = $id ?? bin2hex(random_bytes(8));
    }

    /**
     * Factory: begin a new manifest (kept for full backward compatibility).
     *
     * @throws RandomException
     */
    public static function begin(Filesystem $fs): self
    {
        return new self($fs);
    }

    /**
     * Load an existing manifest by id.
     *
     * @throws FileNotFoundException
     * @throws RandomException
     * @throws JsonException
     */
    public static function load(Filesystem $fs, string $id): self
    {
        $path = storage_path(self::DIR . '/' . $id . '.json');
        if (!$fs->exists($path)) {
            throw new RuntimeException('Manifest not found: ' . $path);
        }

        /** @var array{id:string,actions:array<int, array{type:string, path:string, backup:?string, to?:string}>} $data */
        $data = json_decode((string)$fs->get($path), true, 512, JSON_THROW_ON_ERROR);

        $m = new self($fs, $data['id']);
        $m->actions = $data['actions'];

        return $m;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function trackCreate(string $relativePath): void
    {
        $this->actions[] = [
            'type' => 'create',
            'path' => $relativePath,
            'backup' => null,
        ];
    }

    public function trackUpdate(string $relativePath, string $backupAbsPath): void
    {
        $this->actions[] = [
            'type' => 'update',
            'path' => $relativePath,
            'backup' => $backupAbsPath,
        ];
    }

    public function trackMkdir(string $relativeDir): void
    {
        $this->actions[] = [
            'type' => 'mkdir',
            'path' => rtrim($relativeDir, DIRECTORY_SEPARATOR),
            'backup' => null,
        ];
    }

    public function trackDelete(string $relativePath): void
    {
        $this->actions[] = [
            'type' => 'delete',
            'path' => $relativePath,
            'backup' => null,
        ];
    }

    public function trackMove(string $fromRelative, string $toRelative): void
    {
        $this->actions[] = [
            'type' => 'move',
            'path' => $fromRelative,
            'backup' => null,
            'to' => $toRelative,
        ];
    }

    /**
     * Persist manifest to disk (canonical format).
     *
     * @throws JsonException
     */
    public function save(): void
    {
        $dir = storage_path(self::DIR);
        $this->fs->ensureDirectoryExists($dir);

        $payload = [
            'id' => $this->id,
            'actions' => $this->actions,
            'created_at' => date(DATE_ATOM),
            'format' => 'canonical',
        ];

        $this->fs->put(
            $this->path(),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Reverse the recorded actions in strict LIFO order and remove the manifest file.
     *
     * @throws FileNotFoundException
     */
    public function rollback(): void
    {
        // Reverse in LIFO to undo in the correct order.
        foreach (array_reverse($this->actions) as $action) {
            $type = $action['type'];
            $rel = $action['path'];
            $abs = base_path($rel);

            if ($type === 'update') {
                $backup = $action['backup'];
                if (is_string($backup) && $this->fs->exists($backup)) {
                    $this->fs->ensureDirectoryExists(dirname($abs));
                    $this->fs->put($abs, (string)$this->fs->get($backup));
                }
            } elseif ($type === 'create') {
                if ($this->fs->exists($abs)) {
                    $this->fs->delete($abs);
                }
            } elseif ($type === 'mkdir') {
                // Remove only if empty to avoid harming unrelated files.
                if (is_dir($abs) && $this->isDirEmpty($abs)) {
                    $this->fs->deleteDirectory($abs);
                }
            } elseif ($type === 'delete') {
                // No-op by design.
            } elseif ($type === 'move') {
                // Move back: to → from
                $toRel = $action['to'] ?? null;
                if (is_string($toRel)) {
                    $fromAbs = $abs;            // stored as 'path'
                    $toAbs = base_path($toRel); // destination we moved to
                    if (is_file($toAbs) && !is_file($fromAbs)) {
                        $this->fs->ensureDirectoryExists(dirname($fromAbs));
                        $this->fs->move($toAbs, $fromAbs);
                    }
                }
            }
        }

        // --- Final safety pass: prune all empty directories related to this manifest ---
        // Collect candidate directories from mkdirs and from parent dirs of all touched paths.
        $candidates = [];

        foreach ($this->actions as $a) {
            $pathAbs = base_path($a['path']);
            // If we explicitly created a directory, include it.
            if ($a['type'] === 'mkdir') {
                $candidates[] = $pathAbs;
            }
            // Include the parent directory of any file path.
            $candidates[] = dirname($pathAbs);

            // For moves, include parent dirs of both "from" and "to".
            if ($a['type'] === 'move' && isset($a['to']) && is_string($a['to'])) {
                $candidates[] = dirname(base_path($a['to']));
            }
        }

        // Deduplicate and sort deepest-first, so children are pruned before parents.
        $candidates = array_values(array_unique(array_filter($candidates, static fn ($p) => is_string($p))));
        usort($candidates, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($candidates as $dir) {
            if (is_dir($dir) && $this->isTreeEmpty($dir)) {
                $this->fs->deleteDirectory($dir);
            }
        }

        // Remove the manifest file itself.
        $this->fs->delete($this->path());
    }

    private function path(): string
    {
        return storage_path(self::DIR . '/' . $this->id . '.json');
    }

    private function isDirEmpty(string $dir): bool
    {
        $scan = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        return $scan === [];
    }

    /**
     * True if there are no files anywhere under the directory (subdirs allowed but must be empty).
     */
    private function isTreeEmpty(string $dir): bool
    {
        // If there are any files at any depth, it's not safe to remove.
        $files = $this->fs->allFiles($dir);
        if (!empty($files)) {
            return false;
        }
        // If there are subdirectories, ensure they are also file-empty.
        foreach ($this->fs->directories($dir) as $sub) {
            if (!$this->isTreeEmpty($sub)) {
                return false;
            }
        }
        return true;
    }
}
