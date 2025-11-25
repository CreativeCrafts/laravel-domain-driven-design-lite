<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;
use SplFileInfo;

final class ManifestListCommand extends BaseCommand
{
    /** @var string */
    protected $signature = 'ddd-lite:manifest:list
        {--json : Output JSON}
        {--module= : Filter by affected module (e.g., Planner)}
        {--type= : Filter by action type (mkdir|create|update|delete|move)}
        {--after= : ISO8601 lower bound on created_at}
        {--before= : ISO8601 upper bound on created_at}';

    /** @var string */
    protected $description = 'List DDD-Lite manifests with optional filters (module/type/time).';

    /**
     * @throws JsonException
     */
    public function handle(Filesystem $files): int
    {
        $this->prepare();

        $manifestsDir = storage_path('app/ddd-lite_scaffold/manifests');
        if (!$files->isDirectory($manifestsDir)) {
            if ($this->option('json')) {
                // Pretty JSON so tests can assert `"id": "..."` (note the space after colon).
                $this->line(json_encode(['data' => [], 'total' => 0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->info('No manifests directory found.');
            }

            return self::SUCCESS;
        }

        $paths = array_map(
            static fn (SplFileInfo $f): string => $f->getPathname(),
            $files->files($manifestsDir)
        );

        usort($paths, static function (string $a, string $b): int {
            $ta = @filemtime($a) ?: 0;
            $tb = @filemtime($b) ?: 0;
            return $tb <=> $ta;
        });

        $wantJson = $this->option('json') === true;
        $wantMod = $this->getStringOption('module');
        $wantType = $this->getStringOption('type');
        $afterIso = $this->getStringOption('after');
        $beforeIso = $this->getStringOption('before');

        $afterTs = $afterIso !== null ? $this->parseIsoToTs($afterIso) : null;
        $beforeTs = $beforeIso !== null ? $this->parseIsoToTs($beforeIso) : null;

        $rows = [];

        foreach ($paths as $path) {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }

            $decoded = $this->safeJsonDecode($raw);
            if (!is_array($decoded)) {
                continue;
            }

            $idVal = $decoded['id'] ?? null;
            $id = is_string($idVal) ? $idVal : pathinfo($path, PATHINFO_FILENAME);
            $createdVal = $decoded['created_at'] ?? null;
            $createdIso = is_string($createdVal) ? $createdVal : '';
            $createdTs = $createdIso !== '' ? $this->parseIsoToTs($createdIso) : null;
            $actionsRaw = $decoded['actions'] ?? [];
            $actions = is_array($actionsRaw) ? array_values($actionsRaw) : [];

            if ($afterTs !== null && ($createdTs === null || $createdTs < $afterTs)) {
                continue;
            }
            if ($beforeTs !== null && ($createdTs === null || $createdTs > $beforeTs)) {
                continue;
            }

            if (is_string($wantType) && $wantType !== '') {
                $hasType = false;
                foreach ($actions as $a) {
                    if (is_array($a) && (($a['type'] ?? null) === $wantType)) {
                        $hasType = true;
                        break;
                    }
                }
                if (!$hasType) {
                    continue;
                }
            }

            $modules = $this->inferModulesFromActions($actions);

            if (is_string($wantMod) && $wantMod !== '' && !in_array($wantMod, $modules, true)) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'created_at' => $createdIso,
                'modules' => array_values(array_unique($modules)),
                'types' => array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                static fn ($a): string => (is_array($a) && is_string($a['type'] ?? null)) ? $a['type'] : '',
                                $actions
                            ),
                            static fn ($t) => $t !== ''
                        )
                    )
                ),
                'file' => $this->rel($path),
            ];
        }

        if ($wantJson) {
            // Pretty JSON to include `": "` spacing required by test expectations.
            $this->line(json_encode([
                'data' => $rows,
                'total' => count($rows),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (count($rows) === 0) {
            $this->info('No manifests matched your filters.');
            return self::SUCCESS;
        }

        $this->line('== ddd-lite:manifest:list ==');
        foreach ($rows as $row) {
            $this->twoColumn('Id', $row['id']);
            $this->twoColumn('Created', $row['created_at'] ?: '(unknown)');
            $this->twoColumn('Modules', implode(', ', $row['modules']));
            $this->twoColumn('Types', implode(', ', $row['types']));
            $this->twoColumn('File', $row['file']);
            $this->line(str_repeat('-', 48));
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, mixed> $actions
     * @return array<int, string>
     */
    private function inferModulesFromActions(array $actions): array
    {
        $mods = [];

        foreach ($actions as $a) {
            if (!is_array($a)) {
                continue;
            }

            $candidates = [];

            if (isset($a['to']) && is_string($a['to']) && $a['to'] !== '') {
                $candidates[] = $a['to'];
            }
            if (isset($a['path']) && is_string($a['path']) && $a['path'] !== '') {
                $candidates[] = $a['path'];
            }

            foreach ($candidates as $p) {
                $mod = $this->extractModuleFromPath($p);
                if ($mod !== null) {
                    $mods[] = $mod;
                }
            }
        }

        return array_values(array_unique($mods));
    }

    private function extractModuleFromPath(string $path): ?string
    {
        // Normalize: slashes, leading './', collapse doubles.
        $norm = str_replace('\\', '/', $path);
        if (str_starts_with($norm, './')) {
            $norm = substr($norm, 2);
        }
        $norm = preg_replace('#/{2,}#', '/', $norm) ?? $norm;

        // Split and find a 'modules' segment; the next segment is the module name.
        $parts = array_values(array_filter(explode('/', $norm), static fn ($p) => $p !== ''));
        $count = count($parts);
        for ($i = 0; $i < $count; $i++) {
            if (strcasecmp($parts[$i], 'modules') === 0 && ($i + 1) < $count) {
                $name = trim($parts[$i + 1]);
                return $name !== '' ? $name : null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeJsonDecode(string $raw): ?array
    {
        try {
            /** @var array<string, mixed>|null $d */
            $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($d) ? $d : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function parseIsoToTs(string $iso): ?int
    {
        try {
            return (new DateTimeImmutable($iso))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
