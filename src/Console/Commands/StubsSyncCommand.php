<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Console\Commands;

use CreativeCrafts\DomainDrivenDesignLite\Console\BaseCommand;
use CreativeCrafts\DomainDrivenDesignLite\Support\StubDiff;
use JsonException;
use RuntimeException;
use Throwable;

final class StubsSyncCommand extends BaseCommand
{
    protected $signature = 'ddd-lite:stubs:sync
        {--base= : Base stubs root (defaults to package stubs)}
        {--custom= : Custom stubs root (defaults to app stubs)}
        {--mode=missing : missing|all}
        {--dry-run : Preview only}
        {--force : Overwrite existing files}';

    protected $description = 'Sync package stubs into the app stubs directory.';

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $this->prepare();

        $base = $this->getStringOption('base') ?? __DIR__ . '/../../../stubs/ddd-lite';
        $custom = $this->getStringOption('custom') ?? base_path('stubs/ddd-lite');
        $mode = $this->getStringOption('mode') ?? 'missing';
        $dry = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        if (!in_array($mode, ['missing', 'all'], true)) {
            throw new RuntimeException('Invalid --mode. Use missing|all.');
        }

        $rows = (new StubDiff())->compare($base, $custom);
        $targets = array_filter($rows, function (array $row) use ($mode): bool {
            if ($mode === 'all') {
                return $row['status'] !== 'same';
            }
            return $row['status'] === 'missing';
        });

        $this->summary('Stub sync plan', [
            'Base' => $base,
            'Custom' => $custom,
            'Mode' => $mode,
            'Dry run' => $dry ? 'yes' : 'no',
            'Force' => $force ? 'yes' : 'no',
            'Files' => (string)count($targets),
        ]);

        if ($dry) {
            $this->warnBox('Dry-run: no files will be written.');
            return self::SUCCESS;
        }

        $manifest = $this->beginManifest();

        try {
            foreach ($targets as $row) {
                $rel = $row['path'];
                $from = $row['base'];
                $to = rtrim($custom, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;

                $dir = dirname($to);
                if (!is_dir($dir)) {
                    $this->files->ensureDirectoryExists($dir);
                    $manifest->trackMkdir($this->rel($dir));
                }

                if ($this->files->exists($to)) {
                    if (!$force) {
                        continue;
                    }
                    $backup = storage_path('app/ddd-lite_scaffold/backups/' . sha1($to) . '.bak');
                    $this->files->ensureDirectoryExists(dirname($backup));
                    $this->files->put($backup, (string)$this->files->get($to));
                    $manifest->trackUpdate($this->rel($to), $this->rel($backup));
                } else {
                    $manifest->trackCreate($this->rel($to));
                }

                $this->files->put($to, (string)$this->files->get($from));
            }

            $manifest->save();
            $this->successBox('Stub sync complete.');
            $this->twoColumn('Manifest', $manifest->id());

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            try {
                $manifest->save();
                $manifest->rollback();
            } catch (Throwable) {
            }
            return self::FAILURE;
        }
    }
}
