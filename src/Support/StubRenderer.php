<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class StubRenderer
{
    public function __construct(private Filesystem $fs = new Filesystem())
    {
    }

    public function render(string $logicalName, array $vars): string
    {
        $path = $this->resolve($logicalName);
        $raw = (string)$this->fs->get($path);
        return $this->interpolate($raw, $vars);
    }

    public function resolve(string $logicalName): string
    {
        $candidates = $this->candidates($logicalName);
        foreach ($candidates as $abs) {
            if ($this->fs->exists($abs)) {
                return $abs;
            }
        }
        throw new RuntimeException('Stub not found: ' . $logicalName);
    }

    private function candidates(string $logicalName): array
    {
        $roots = [
            base_path('stubs'),
            base_path('stubs/ddd-lite'),
            base_path('resources/stubs'),
            base_path('resources/stubs/ddd-lite'),
            __DIR__ . '/../../stubs',
            __DIR__ . '/../../stubs/ddd-lite',
        ];

        $logicalName = ltrim($logicalName, '/\\');
        $out = [];
        foreach ($roots as $root) {
            $out[] = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $logicalName;
        }
        return $out;
    }

    private function interpolate(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{{ ' . $k . ' }}'] = (string)$v;
            $map['{{' . $k . '}}'] = (string)$v;
        }
        return strtr($template, $map);
    }
}
