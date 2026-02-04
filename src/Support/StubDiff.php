<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Filesystem\Filesystem;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class StubDiff
{
    /**
     * @return array<int,array{path:string,base:string,custom:?string,status:string}>
     */
    public function compare(string $baseRoot, string $customRoot): array
    {
        $fs = new Filesystem();

        $base = $this->collectFiles($baseRoot);
        $custom = $this->collectFiles($customRoot);

        $rows = [];
        foreach ($base as $rel => $baseAbs) {
            $customAbs = $custom[$rel] ?? null;
            if ($customAbs === null) {
                $rows[] = [
                    'path' => $rel,
                    'base' => $baseAbs,
                    'custom' => null,
                    'status' => 'missing',
                ];
                continue;
            }

            $same = $this->read($fs, $baseAbs) === $this->read($fs, $customAbs);
            $rows[] = [
                'path' => $rel,
                'base' => $baseAbs,
                'custom' => $customAbs,
                'status' => $same ? 'same' : 'diff',
            ];
        }

        foreach ($custom as $rel => $customAbs) {
            if (!array_key_exists($rel, $base)) {
                $rows[] = [
                    'path' => $rel,
                    'base' => $baseRoot . DIRECTORY_SEPARATOR . $rel,
                    'custom' => $customAbs,
                    'status' => 'extra',
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $rows;
    }

    /**
     * @return array<string,string>
     */
    private function collectFiles(string $root): array
    {
        $out = [];
        if (!is_dir($root)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $rel = ltrim(str_replace($root, '', $abs), DIRECTORY_SEPARATOR);
            $out[$rel] = $abs;
        }
        return $out;
    }

    private function read(Filesystem $fs, string $path): string
    {
        return $fs->exists($path) ? (string)$fs->get($path) : '';
    }
}
