<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class BootstrapInspector
{
    public function __construct(private Filesystem $fs = new Filesystem())
    {
    }

    /**
     * @throws FileNotFoundException
     */
    public function providerMentioned(string $fqcn): bool
    {
        $path = base_path('bootstrap/app.php');
        if (!$this->fs->exists($path)) {
            throw new RuntimeException('bootstrap/app.php not found.');
        }
        $src = (string)$this->fs->get($path);
        return str_contains($src, $fqcn);
    }

    /**
     * @throws FileNotFoundException
     */
    public function providerInsideConfigureChain(string $fqcn): bool
    {
        $path = base_path('bootstrap/app.php');
        if (!$this->fs->exists($path)) {
            throw new RuntimeException('bootstrap/app.php not found.');
        }

        $src = (string)$this->fs->get($path);

        $retPos = strpos($src, 'return Application::configure');
        if ($retPos === false) {
            return false;
        }
        $openParenPos = strpos($src, '(', $retPos);
        if ($openParenPos === false) {
            return false;
        }
        $closeParenPos = $this->findMatchingParen($src, $openParenPos);
        if ($closeParenPos === null) {
            return false;
        }
        $configureEnd = $closeParenPos + 1;

        $tail = (string)substr($src, $configureEnd);
        $create = strpos($tail, '->create()');
        if ($create === false) {
            return false;
        }
        $chain = (string)substr($tail, 0, $create);

        $withProvidersPattern = '/->withProviders\s*\(\s*\[(.*?)\]\s*\)/s';
        $m = [];
        if (preg_match($withProvidersPattern, $chain, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }
        $listBody = (string)$m[1][0];
        return str_contains($listBody, $fqcn);
    }

    public function hasStandaloneWithProvidersBlock(): bool
    {
        $path = base_path('bootstrap/app.php');
        if (!$this->fs->exists($path)) {
            throw new RuntimeException('bootstrap/app.php not found.');
        }
        $src = (string)$this->fs->get($path);

        $pattern = '/\$app\s*->withProviders\s*\(\s*\[(.*?)\]\s*\)\s*;?/s';
        return (bool)preg_match($pattern, $src);
    }

    /**
     * @throws FileNotFoundException
     */
    public function missingRoutingKeys(array $keys): array
    {
        $path = base_path('bootstrap/app.php');
        if (!$this->fs->exists($path)) {
            throw new RuntimeException('bootstrap/app.php not found.');
        }

        $src = (string)$this->fs->get($path);

        $retPos = strpos($src, 'return Application::configure');
        if ($retPos === false) {
            return $keys;
        }
        $openParenPos = strpos($src, '(', $retPos);
        if ($openParenPos === false) {
            return $keys;
        }
        $closeParenPos = $this->findMatchingParen($src, $openParenPos);
        if ($closeParenPos === null) {
            return $keys;
        }
        $configureEnd = $closeParenPos + 1;

        $tail = (string)substr($src, $configureEnd);
        $create = strpos($tail, '->create()');
        if ($create === false) {
            return $keys;
        }
        $chain = (string)substr($tail, 0, $create);

        $withPos = strpos($chain, '->withRouting(');
        if ($withPos === false) {
            return $keys;
        }

        $absWithPos = $configureEnd + $withPos;
        $open = strpos($src, '(', $absWithPos);
        if ($open === false) {
            return $keys;
        }
        $close = $this->findMatchingParen($src, $open);
        if ($close === null) {
            return $keys;
        }

        $args = (string)substr($src, $open + 1, $close - ($open + 1));

        $missing = [];
        foreach ($keys as $k) {
            if (!str_contains($args, $k . ':')) {
                $missing[] = $k;
            }
        }
        return $missing;
    }

    private function findMatchingParen(string $src, int $openPos): ?int
    {
        $len = strlen($src);
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $escaped = false;

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $src[$i];

            if ($inSingle || $inDouble) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($inSingle && $ch === "'") {
                    $inSingle = false;
                    continue;
                }
                if ($inDouble && $ch === '"') {
                    $inDouble = false;
                    continue;
                }
                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                continue;
            }

            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
