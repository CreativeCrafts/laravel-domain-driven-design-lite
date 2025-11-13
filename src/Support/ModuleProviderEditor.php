<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class ModuleProviderEditor
{
    public function __construct(
        private Filesystem $fs
    ) {
    }

    /**
     * @throws FileNotFoundException
     */
    public function addUsesAndRegistrations(string $module, string $moduleProviderPath, bool $addRoute, bool $addEvent): string
    {
        if (!$this->fs->exists($moduleProviderPath)) {
            throw new RuntimeException('ModuleServiceProvider not found: ' . $moduleProviderPath);
        }

        $code = (string)$this->fs->get($moduleProviderPath);
        $original = $code;

        $ns = "Modules\\{$module}\\App\\Providers";
        $routeUse = "use Modules\\{$module}\\App\\Providers\\RouteServiceProvider;";
        $eventUse = "use Modules\\{$module}\\App\\Providers\\EventServiceProvider;";
        $needsRouteUse = $addRoute && !str_contains($code, $routeUse);
        $needsEventUse = $addEvent && !str_contains($code, $eventUse);

        if ($needsRouteUse || $needsEventUse) {
            $insertPos = strpos($code, "\nuse ");
            if ($insertPos === false) {
                $nsPos = strpos($code, "namespace {$ns};");
                if ($nsPos === false) {
                    throw new RuntimeException('Namespace not found in ModuleServiceProvider.');
                }
                $nsEnd = strpos($code, "\n", $nsPos);
                $nsEnd = $nsEnd === false ? strlen($code) : $nsEnd;
                $insertion = '';
                if ($needsRouteUse) {
                    $insertion .= "\n{$routeUse}";
                }
                if ($needsEventUse) {
                    $insertion .= "\n{$eventUse}";
                }
                $code = substr($code, 0, $nsEnd) . $insertion . substr($code, $nsEnd);
            } else {
                $headerPos = strpos($code, "\n", $insertPos);
                $headerPos = $headerPos === false ? $insertPos : $headerPos;
                $insertion = '';
                if ($needsRouteUse) {
                    $insertion .= "\n{$routeUse}";
                }
                if ($needsEventUse) {
                    $insertion .= "\n{$eventUse}";
                }
                $code = substr($code, 0, $headerPos) . $insertion . substr($code, $headerPos);
            }
        }

        $registerNeedle = 'function register(): void';
        $registerPos = strpos($code, $registerNeedle);
        if ($registerPos === false) {
            throw new RuntimeException('register() method not found in ModuleServiceProvider.');
        }

        $bracePos = strpos($code, '{', $registerPos);
        if ($bracePos === false) {
            throw new RuntimeException('register() body not found.');
        }

        $closePos = $this->findMatchingBrace($code, $bracePos);
        if ($closePos === -1) {
            throw new RuntimeException('register() curly braces not balanced.');
        }

        $registerBody = substr($code, $bracePos + 1, $closePos - $bracePos - 1);

        $routeLine = '$this->app->register(RouteServiceProvider::class);';
        $eventLine = '$this->app->register(EventServiceProvider::class);';

        $needsRouteLine = $addRoute && !str_contains($registerBody, $routeLine);
        $needsEventLine = $addEvent && !str_contains($registerBody, $eventLine);

        if ($needsRouteLine || $needsEventLine) {
            $insertion = '';
            if ($needsRouteLine) {
                $insertion .= "\n        {$routeLine}";
            }
            if ($needsEventLine) {
                $insertion .= "\n        {$eventLine}";
            }
            $code = substr($code, 0, $closePos) . $insertion . substr($code, $closePos);
        }

        return $code;
    }

    private function findMatchingBrace(string $code, int $openPos): int
    {
        $depth = 0;
        $len = strlen($code);
        for ($i = $openPos; $i < $len; $i++) {
            $c = $code[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }
}
