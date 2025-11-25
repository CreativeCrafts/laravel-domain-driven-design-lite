<?php

declare(strict_types=1);

namespace CreativeCrafts\DomainDrivenDesignLite\Support;

final class PhpUseEditor
{
    /**
     * @param array<int,string> $imports
     */
    public function ensureImports(string $code, array $imports): string
    {
        // Keep only non-empty strings and unique them
        $imports = array_values(array_unique(array_filter($imports, static fn (string $v): bool => $v !== '')));
        if ($imports === []) {
            return $code;
        }

        $namespacePos = strpos($code, "\nnamespace ");
        $nsEnd = $namespacePos === false ? 0 : strpos($code, ";\n", $namespacePos);
        $insertAfter = $nsEnd === false ? 0 : $nsEnd + 2;

        $before = substr($code, 0, $insertAfter);
        $after = substr($code, $insertAfter);

        $existing = [];
        if (preg_match_all('/^use\s+([^;]+);/m', $code, $m)) {
            foreach ($m[1] as $u) {
                $existing[] = trim((string)$u);
            }
        }

        $toAdd = [];
        foreach ($imports as $imp) {
            if (!in_array($imp, $existing, true)) {
                $toAdd[] = 'use ' . $imp . ';';
            }
        }

        if ($toAdd === []) {
            return $code;
        }

        $useBlock = implode(PHP_EOL, $toAdd) . PHP_EOL;
        return $before . $useBlock . $after;
    }
}
