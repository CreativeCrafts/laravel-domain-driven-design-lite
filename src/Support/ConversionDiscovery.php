<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;

final readonly class ConversionDiscovery
{
    public function __construct(
        private Filesystem $fs = new Filesystem()
    ) {
    }

    /**
     * @param array<int,string> $only
     * @param array<int,string> $except
     * @param array<int,string> $paths
     */
    public function discover(string $module, array $only = [], array $except = [], array $paths = []): ConversionPlan
    {
        $roots = $paths !== [] ? $paths : [
            base_path('app/Http/Controllers'),
            base_path('app/Http/Requests'),
            base_path('app/Models'),
            base_path('app/Actions'),
            base_path('app/DTO'),
            base_path('app/Contracts'),
        ];

        $filters = $this->normalizeFilters($only, $except);
        $plan = new ConversionPlan();

        foreach ($roots as $root) {
            if (!$this->fs->isDirectory($root)) {
                continue;
            }

            /** @var array<int,SplFileInfo> $files */
            $files = $this->fs->allFiles($root, true);

            foreach ($files as $spl) {
                $abs = $spl->getPathname();
                $rel = $this->toRel($abs);

                if (!Str::endsWith($rel, '.php')) {
                    continue;
                }

                $kind = $this->inferKind($rel);
                if (!$filters->allow($kind)) {
                    continue;
                }

                $mapping = $this->map($module, $kind, $rel);
                if ($mapping === null) {
                    continue;
                }

                [$toRel, $toNs, $reason] = $mapping;
                $fromNs = $this->inferNamespace($rel);

                $plan->add(
                    new MoveCandidate(
                        fromAbs: base_path($rel),
                        toAbs: base_path($toRel),
                        fromRel: $rel,
                        toRel: $toRel,
                        fromNamespace: $fromNs,
                        toNamespace: $toNs,
                        reason: $reason
                    )
                );
            }
        }

        return $plan;
    }

    private function toRel(string $abs): string
    {
        $base = base_path();
        if (!Str::startsWith($abs, $base)) {
            throw new RuntimeException('Path is outside project: ' . $abs);
        }

        return ltrim(Str::after($abs, $base), DIRECTORY_SEPARATOR);
    }

    private function inferNamespace(string $fromRel): string
    {
        if (Str::startsWith($fromRel, 'app/')) {
            $tail = Str::after($fromRel, 'app/');
            $parts = explode('/', Str::beforeLast($tail, '.php'));
            return 'App\\' . implode('\\', array_map(static fn (string $p): string => Str::studly($p), $parts));
        }

        return 'App';
    }

    /**
     * @return array{0:string,1:string,2:string}|null
     */
    private function map(string $module, string $kind, string $fromRel): ?array
    {
        $studlyModule = Str::studly($module);

        return match ($kind) {
            'controllers' => [
                "modules/{$studlyModule}/App/Http/Controllers/" . Str::after($fromRel, 'app/Http/Controllers/'),
                $this->targetNs($studlyModule, 'App/Http/Controllers', $this->nsDirTail($fromRel, 'app/Http/Controllers/')),
                'Controllers to module/App/Http/Controllers',
            ],
            'requests' => [
                "modules/{$studlyModule}/App/Http/Requests/" . Str::after($fromRel, 'app/Http/Requests/'),
                $this->targetNs($studlyModule, 'App/Http/Requests', $this->nsDirTail($fromRel, 'app/Http/Requests/')),
                'Requests to module/App/Http/Requests',
            ],
            'models' => [
                "modules/{$studlyModule}/App/Models/" . Str::after($fromRel, 'app/Models/'),
                $this->targetNs($studlyModule, 'App/Models', $this->nsDirTail($fromRel, 'app/Models/')),
                'Models to module/App/Models',
            ],
            'actions' => [
                "modules/{$studlyModule}/Domain/Actions/" . Str::after($fromRel, 'app/Actions/'),
                $this->targetNs($studlyModule, 'Domain/Actions', $this->nsDirTail($fromRel, 'app/Actions/')),
                'Actions to module/Domain/Actions',
            ],
            'dto' => [
                "modules/{$studlyModule}/Domain/DTO/" . Str::after($fromRel, 'app/DTO/'),
                $this->targetNs($studlyModule, 'Domain/DTO', $this->nsDirTail($fromRel, 'app/DTO/')),
                'DTOs to module/Domain/DTO',
            ],
            'contracts' => [
                "modules/{$studlyModule}/Domain/Contracts/" . Str::after($fromRel, 'app/Contracts/'),
                $this->targetNs($studlyModule, 'Domain/Contracts', $this->nsDirTail($fromRel, 'app/Contracts/')),
                'Contracts to module/Domain/Contracts',
            ],
            default => null,
        };
    }

    private function nsDirTail(string $fromRel, string $prefix): string
    {
        $tail = Str::after($fromRel, $prefix);
        $dir = pathinfo($tail, PATHINFO_DIRNAME) ?: '';

        if ($dir === '.' || $dir === '') {
            return '';
        }

        return implode('\\', array_map(static fn (string $p): string => Str::studly($p), explode('/', $dir)));
    }

    private function targetNs(string $module, string $base, string $dirTail): string
    {
        $ns = 'Modules' . '\\' . $module . '\\' . str_replace('/', '\\', $base);
        return $dirTail !== '' ? $ns . '\\' . $dirTail : $ns;
    }

    private function inferKind(string $fromRel): string
    {
        return match (true) {
            Str::startsWith($fromRel, 'app/Http/Controllers/') => 'controllers',
            Str::startsWith($fromRel, 'app/Http/Requests/') => 'requests',
            Str::startsWith($fromRel, 'app/Models/') => 'models',
            Str::startsWith($fromRel, 'app/Actions/') => 'actions',
            Str::startsWith($fromRel, 'app/DTO/') => 'dto',
            Str::startsWith($fromRel, 'app/Contracts/') => 'contracts',
            default => 'other',
        };
    }

    private function normalizeFilters(array $only, array $except): Filters
    {
        $o = array_filter(array_map(static fn (string $v): string => strtolower(trim($v)), $only));
        $e = array_filter(array_map(static fn (string $v): string => strtolower(trim($v)), $except));
        return new Filters($o, $e);
    }
}
