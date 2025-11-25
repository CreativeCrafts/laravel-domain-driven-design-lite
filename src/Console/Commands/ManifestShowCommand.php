<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

final class ManifestShowCommand extends BaseCommand
{
    /** @var string */
    protected $signature = 'ddd-lite:manifest:show
        {id : Manifest id to inspect}
        {--json : Emit machine-readable JSON}';

    /** @var string */
    protected $description = 'Show a single DDD-Lite manifest (actions and inferred module).';

    /**
     * Read-only: show a manifest without touching Manifest.php internals.
     *
     * @param Filesystem $fs
     * @return int
     * @throws JsonException
     * @throws FileNotFoundException
     */
    public function handle(Filesystem $fs): int
    {
        $this->prepare();

        $id = (string)$this->argument('id');
        if ($id === '') {
            throw new RuntimeException('Missing manifest id.');
        }

        $rawPath = storage_path('app/ddd-lite_scaffold/manifests/' . $id . '.json');
        if (!$fs->exists($rawPath)) {
            $this->error(sprintf('Manifest not found: %s', $rawPath));
            return self::FAILURE;
        }

        try {
            /** @var array{
             *   id?:string,
             *   actions?: mixed,
             *   created_at?:string,
             *   format?:string
             * } $data
             */
            $data = json_decode((string)$fs->get($rawPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('Invalid manifest JSON: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Ensure 'actions' is an array to avoid short-circuiting later output.
        $data['actions'] = is_array($data['actions'] ?? null) ? $data['actions'] : [];

        // Always prefer the filename-derived id (this is what tests and users pass).
        $dataId = $id;
        $rawId = is_string($data['id'] ?? null) ? (string)$data['id'] : null;

        // Infer a module from any path under modules/<Name>/ (robust against missing keys).
        $module = null;
        foreach ($data['actions'] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $p = is_string($a['path'] ?? null) ? $a['path'] : '';
            if ($p !== '' && preg_match('#^modules/([^/]+)/#', $p, $m1) === 1) {
                $module = $m1[1];
                break;
            }

            $typeIsMove = is_string($a['type'] ?? null) && $a['type'] === 'move';
            if ($typeIsMove && is_string($a['to'] ?? null)) {
                $t = $a['to'];
                // Correct: pass $t as a subject and capture into $m2
                if ($t !== '' && preg_match('#^modules/([^/]+)/#', $t, $m2) === 1) {
                    $module = $m2[1];
                    break;
                }
            }
        }

        $counts = [
            'create' => count(array_filter($data['actions'], static fn ($a): bool => is_array($a) && (($a['type'] ?? '') === 'create'))),
            'update' => count(array_filter($data['actions'], static fn ($a): bool => is_array($a) && (($a['type'] ?? '') === 'update'))),
            'delete' => count(array_filter($data['actions'], static fn ($a): bool => is_array($a) && (($a['type'] ?? '') === 'delete'))),
            'mkdir' => count(array_filter($data['actions'], static fn ($a): bool => is_array($a) && (($a['type'] ?? '') === 'mkdir'))),
            'move' => count(array_filter($data['actions'], static fn ($a): bool => is_array($a) && (($a['type'] ?? '') === 'move'))),
        ];

        if ($this->option('json')) {
            $payload = [
                'id' => $dataId,           // filename-derived id
                'raw_id' => $rawId,            // optional internal id for diagnostics
                'module' => $module,
                'created_at' => $data['created_at'] ?? null,
                'format' => $data['format'] ?? null,
                'counts' => $counts,
                'actions' => $data['actions'],
            ];

            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('== ddd-lite:manifest:show ==');
        $this->twoColumn('Manifest', $dataId);
        if ($rawId !== null && $rawId !== $dataId) {
            $this->twoColumn('Raw id', $rawId);
        }
        $this->twoColumn('Module', is_string($module) ? $module : '—');
        $this->twoColumn('Created', is_string($data['created_at'] ?? null) ? $data['created_at'] : '—');
        $this->twoColumn('Format', is_string($data['format'] ?? null) ? $data['format'] : '—');
        $this->twoColumn(
            'Actions',
            sprintf(
                'create:%d update:%d delete:%d mkdir:%d move:%d',
                $counts['create'],
                $counts['update'],
                $counts['delete'],
                $counts['mkdir'],
                $counts['move'],
            )
        );

        $this->line('');
        $this->info('Actions:');
        foreach ($data['actions'] as $idx => $a) {
            if (!is_array($a)) {
                continue;
            }
            $typeStr = is_string($a['type'] ?? null) ? $a['type'] : '';
            $pathStr = is_string($a['path'] ?? null) ? $a['path'] : '';
            $toStr = is_string($a['to'] ?? null) ? $a['to'] : null;
            $line = sprintf(
                '%2d) %-6s %s%s',
                $idx + 1,
                $typeStr,
                $pathStr,
                $toStr !== null ? (' → ' . $toStr) : ''
            );
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
